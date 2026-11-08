<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Symfony;

use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Demo\DemoKernel;
use ExactApproval\Demo\DemoSetup;
use ExactApproval\Tests\Support\VirtualAuthenticator;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

/** "Approve on your phone": a one-time code that opens one approval, and nothing else, on another device. */
final class HandoffTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private string $runtime;
    private array $accounts;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/exact-approval-handoff-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) $browser->getKernel()->shutdown();
        (new Filesystem())->remove($this->runtime);
    }

    public function testAPhoneCodeOpensOnlyThatApprovalAndStillNeedsThePasskey(): void
    {
        $agent = $this->mcp('editor');
        $id = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        $other = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'second'])->structuredContent['requestId'];
        self::assertNotSame($id, $other);
        $authenticator = $this->enrollOnComputer('editor');

        $code = $agent->callTool('approval_handoff', ['request_id' => $id]);
        self::assertFalse($code->isError, json_encode($code->content));
        self::assertStringStartsWith(self::ORIGIN.'/approval/handoff/', $code->structuredContent['url']);
        self::assertSame($code->structuredContent['url'], self::scan($code->structuredContent['modules']), 'The rows the card draws are a QR code for the link.');
        self::assertSame(120, $code->structuredContent['expiresIn']);
        self::assertSame('approval_handoff', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['approval']['handoffTool'], 'On a public HTTPS address the card offers the phone.');
        self::assertTrue($this->mcp('reader')->callTool('approval_handoff', ['request_id' => $id])->isError, 'Only the requester can get a code.');

        $phone = $this->browser();
        $phone->request('GET', $code->structuredContent['url']);
        self::assertSame(302, $phone->getResponse()->getStatusCode());
        self::assertStringEndsWith('/approval/'.$id.'#return=handoff', (string) $phone->getResponse()->headers->get('Location'));
        $crawler = $phone->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $phone->getResponse()->getStatusCode());
        self::assertStringContainsString('Evacuation order', $crawler->filter('.statement')->text());

        foreach (['/', '/account/passkeys', '/admin/approval-policy', '/approval/'.$id.'/evidence', '/approval/'.$other] as $path) {
            $phone->request('GET', self::ORIGIN.$path);
            self::assertContains($phone->getResponse()->getStatusCode(), [302, 403, 404], 'The phone session reaches nothing but its one approval: '.$path);
            self::assertNotSame(200, $phone->getResponse()->getStatusCode(), $path);
        }

        $again = $this->browser();
        $again->request('GET', $code->structuredContent['url']);
        self::assertSame(410, $again->getResponse()->getStatusCode(), 'A code works once.');

        $csrf = (string) $phone->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($phone, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(200, $phone->getResponse()->getStatusCode(), (string) $phone->getResponse()->getContent());
        $done = $this->post($phone, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame('completed', $done['status'], 'Approved on the phone with the person’s own passkey.');
        $database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
        self::assertSame(1, (int) $database->announcement('riverside-evacuation')['mutation_count']);
        self::assertSame('completed', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['status'], 'The card on the computer sees the outcome.');
    }

    public function testNoCodeWithoutAPublicHttpsAddress(): void
    {
        $runtime = $this->runtime.'-local';
        $accounts = DemoSetup::initialize($runtime, 'http://localhost:8080')['accounts'];
        try {
            $factory = new Psr17Factory();
            $agent = Client::builder()->setClientInfo('handoff-test', '1.0')->setMaxRetries(0)->build();
            $agent->connect(new HttpTransport(endpoint: 'http://localhost:8080/mcp', headers: ['Authorization' => 'Bearer '.$accounts['editor']['personal_token']], httpClient: new SymfonyKernelHttpClient($runtime), requestFactory: $factory, streamFactory: $factory));
            $id = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
            $refused = $agent->callTool('approval_handoff', ['request_id' => $id]);
            self::assertTrue($refused->isError);
            self::assertStringContainsString('public HTTPS address', $refused->content[0]->text);
            self::assertNull($agent->callTool('approval_status', ['request_id' => $id])->structuredContent['approval']['handoffTool'], 'On a local address the card does not offer the phone at all.');
        } finally {
            (new Filesystem())->remove($runtime);
        }
    }

    /** Draws the rows the way the card does, then reads them with a real QR decoder. */
    private static function scan(array $rows): string
    {
        $size = \count($rows);
        $scale = 8;
        $image = imagecreatetruecolor($size * $scale, $size * $scale);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        foreach ($rows as $y => $row) {
            self::assertMatchesRegularExpression('/^[01]{'.$size.'}$/D', $row);
            for ($x = 0; $x < $size; ++$x) {
                if ($row[$x] === '1') imagefilledrectangle($image, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $black);
            }
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return (string) (new \chillerlan\QRCode\QRCode())->readFromBlob($png);
    }

    private function enrollOnComputer(string $user): VirtualAuthenticator
    {
        $browser = $this->browser();
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        $crawler = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        $result = $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $authenticator->registration($options['publicKey'])]);
        self::assertSame('registered', $result['status'], json_encode($result));

        return $authenticator;
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'alerts.example', 'HTTPS' => 'on']);
        $this->browsers[] = $browser;

        return $browser;
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, JSON_THROW_ON_ERROR));
        $value = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($value) ? $value : [];
    }

    private function mcp(string $user): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('handoff-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
