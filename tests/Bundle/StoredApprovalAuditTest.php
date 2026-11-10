<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

/** Exercise corrupted stored records through the actual owner, MCP and administrator routes. */
final class StoredApprovalAuditTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-integrity-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
        $this->database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
    }

    #[DataProvider('projectionChanges')]
    public function testAnEditedOutcomeIsExplicitlyUntrustedButStillVisibleToTheAuditor(string $change): void
    {
        $editor = $this->signedIn('editor');
        $editor->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertSame(302, $editor->getResponse()->getStatusCode());
        $csrf = (string) $editor->request('GET', self::ORIGIN.'/account/passkeys')->filter('[data-csrf]')->first()->attr('data-csrf');
        $options = $this->post($editor, '/account/passkeys/options', $csrf, []);
        $passkey = new VirtualAuthenticator(self::ORIGIN);
        self::assertSame('registered', $this->post($editor, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $passkey->registration($options['publicKey'])])['status']);

        $agent = $this->mcp();
        $arguments = ['alert_id' => 'riverside-evacuation', 'request_key' => 'integrity-ui'];
        $id = $agent->callTool('publish_alert', $arguments)->structuredContent['requestId'];
        $csrf = (string) $editor->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($editor, '/approval/'.$id.'/options', $csrf, []);
        $completed = $this->post($editor, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $passkey->assertion($ceremony['publicKey'])]);
        self::assertSame('completed', $completed['status']);
        $receipt = $this->database->pdo->query('SELECT * FROM verified_human_approval_receipts')->fetchAll();
        if ('result' === $change) {
            $result = $completed['result'];
            $result['published'] = false;
            $this->database->pdo->prepare('UPDATE verified_human_approval_requests SET result = ? WHERE id = ?')->execute([CanonicalJson::encode((object) $result), $id]);
        } else {
            $this->database->pdo->prepare("UPDATE verified_human_approval_requests SET status = 'cancelled', reason = 'cancelled_by_user' WHERE id = ?")->execute([$id]);
        }

        foreach (['/status', '/evidence'] as $route) {
            $editor->request('GET', self::ORIGIN.'/approval/'.$id.$route);
            self::assertSame(409, $editor->getResponse()->getStatusCode());
            $error = json_decode((string) $editor->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('integrity_error', $error['status']);
            self::assertSame('record_integrity_error', $error['reason']);
            self::assertArrayNotHasKey('result', $error, 'The edited result is not presented as the outcome.');
        }
        $page = $editor->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(409, $editor->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('[data-status="integrity_error"]'));
        self::assertStringContainsString('We cannot confirm this approval’s outcome', $page->text());
        self::assertStringNotContainsString('nothing changed', strtolower($page->text()));
        self::assertCount(0, $page->filter('[data-approve]'));
        foreach (['options', 'cancel'] as $route) {
            $error = $this->post($editor, '/approval/'.$id.'/'.$route, $csrf, []);
            self::assertSame(409, $editor->getResponse()->getStatusCode());
            self::assertSame('integrity_error', $error['status']);
            self::assertSame('record_integrity_error', $error['reason']);
        }
        foreach (['approval_status' => ['request_id' => $id], 'approval_handoff' => ['request_id' => $id], 'publish_alert' => $arguments] as $tool => $input) {
            $error = $agent->callTool($tool, $input);
            self::assertTrue($error->isError, $tool);
            self::assertSame('integrity_error', $error->structuredContent['status'], $tool);
            self::assertSame('record_integrity_error', $error->structuredContent['reason'], $tool);
            self::assertStringNotContainsString('nothing changed', strtolower(json_encode($error, \JSON_THROW_ON_ERROR)), $tool);
        }

        $admin = $this->signedIn('manager');
        $page = $admin->request('GET', self::ORIGIN.'/admin/audit');
        self::assertSame(200, $admin->getResponse()->getStatusCode(), 'A damaged record does not hide the audit log.');
        $row = $page->filter('table.audit')->first()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('integrity error', $row);
        self::assertStringContainsString('Record integrity:', $row);
        self::assertStringContainsString($id, $row);
        self::assertStringContainsString('Stored outcome: '.('result' === $change ? 'completed' : 'cancelled'), $row);
        self::assertStringContainsString('✓ Passkey signed this exact proposal', $row, 'The retained user signature can still be checked independently.');
        self::assertStringNotContainsString('✓ Receipt signed at commit matches the record', $row);
        $admin->request('GET', self::ORIGIN.'/admin/audit/'.$id.'/evidence');
        self::assertSame(409, $admin->getResponse()->getStatusCode());
        self::assertSame('record_integrity_error', json_decode((string) $admin->getResponse()->getContent(), true)['reason']);
        self::assertSame($receipt, $this->database->pdo->query('SELECT * FROM verified_human_approval_receipts')->fetchAll(), 'No route replaces the original receipt.');
        self::assertSame(1, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
    }

    public static function projectionChanges(): iterable
    {
        yield 'only the duplicate result was changed' => ['result'];
        yield 'the completed outcome was changed to cancelled' => ['cancelled'];
    }

    private function signedIn(string $user): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'alerts.example', 'HTTPS' => 'on']);
        $this->browsers[] = $browser;
        $page = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($page->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));

        return $browser;
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function mcp(): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('integrity-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts['editor']['personal_token']], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
