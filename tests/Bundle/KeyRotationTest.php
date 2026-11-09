<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;

/**
 * The demo site through a rotation of its signing key, with the actual kernel: the bundle publishes the key
 * set, receipts signed before the rotation keep checking against it, the signed audit log records the move
 * and stays intact, and a checkpoint of its head shows the newest entries being cut off.
 */
final class KeyRotationTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<KernelBrowser> */ private array $browsers = [];
    /** @var list<DemoKernel> */ private array $kernels = [];
    /** @var list<Client> */ private array $clients = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-rotation-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
        $this->database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
    }

    public function testTheBundlePublishesTheKeySetAndReceiptsSignedBeforeARotationStillCheck(): void
    {
        $setup = $this->settings();
        $published = $this->publicJson('/approval/receipt-keys.json');
        self::assertSame([
            'format' => TrustedKeys::FORMAT, 'currentKeyId' => 'demo-application-1',
            'keys' => [['keyId' => 'demo-application-1', 'publicKey' => $setup['receipt_public_key'], 'validFrom' => $setup['receipt_key_valid_from'], 'validUntil' => null]],
            'rpId' => 'localhost', 'origin' => self::ORIGIN,
        ], $published);
        $first = $this->issue();

        $rotation = $this->console('demo:rotate-receipt-key', ['--reason' => 'scheduled rotation']);
        self::assertSame(Command::SUCCESS, $rotation->getStatusCode(), $rotation->getDisplay());
        self::assertStringContainsString('The site signs with key "demo-application-2"', $rotation->getDisplay());
        $rotated = $this->settings();
        self::assertSame('demo-application-2', $rotated['receipt_key_id']);
        self::assertStringNotContainsString($setup['receipt_secret_key'], (string) file_get_contents($this->runtime.'/settings.json'), 'The old private key is gone.');

        // Anyone can fetch the key set: the new key signs now, and the old one checks what it signed until then.
        $published = $this->publicJson('/approval/receipt-keys.json');
        self::assertSame('demo-application-2', $published['currentKeyId']);
        self::assertSame([
            ['keyId' => 'demo-application-2', 'publicKey' => $rotated['receipt_public_key'], 'validFrom' => $rotated['receipt_key_valid_from'], 'validUntil' => null],
            ['keyId' => 'demo-application-1', 'publicKey' => $setup['receipt_public_key'], 'validFrom' => $setup['receipt_key_valid_from'], 'validUntil' => $rotated['receipt_key_valid_from']],
        ], $published['keys']);
        self::assertSame('demo-application-2', $this->publicJson('/approval/receipt-key.json')['keyId'], 'The one-key file names the current key.');

        $second = $this->issue();
        $keys = TrustedKeys::fromDocument($published);
        self::assertSame('demo-application-1', (new ReceiptVerifier())->verify($first, $keys)['keyId'], 'Signed before the rotation, it still checks.');
        self::assertSame('demo-application-2', (new ReceiptVerifier())->verify($second, $keys)['keyId']);

        // The administrator's page checks both receipts, and the security log is one unbroken chain across the move.
        $audit = $this->browser('manager')->request('GET', self::ORIGIN.'/admin/audit');
        self::assertCount(2, $audit->filter('td.checks:contains("✓ Receipt signed at commit matches the record")'));
        $log = $audit->filter('section')->last()->text();
        self::assertStringContainsString('entries are signed and in an unbroken chain', $log);
        self::assertStringContainsString('signing_key.rotated · demo-application-1 → demo-application-2 · scheduled rotation', $log);
        self::assertStringContainsString('demo-application-1', $audit->filter('section')->last()->filter('table')->text());

        // The audit head, kept outside the site, later shows whether entries were cut off.
        $head = $this->console('verified-human-approval:audit-head');
        self::assertSame(Command::SUCCESS, $head->getStatusCode(), $head->getDisplay());
        $lines = explode("\n", trim($head->getDisplay()));
        $checkpoint = end($lines);
        self::assertMatchesRegularExpression('/^\d+:[0-9a-f]{64}$/', $checkpoint);
        $json = json_decode(trim($this->console('verified-human-approval:audit-head', ['--json' => true])->getDisplay()), true, 4, \JSON_THROW_ON_ERROR);
        self::assertSame($checkpoint, $json['seq'].':'.$json['hash']);
        self::assertSame(['demo-application-2', true], [$json['keyId'], $json['intact']]);
        $check = $this->console('verified-human-approval:audit-head', ['--check' => $checkpoint]);
        self::assertSame(Command::SUCCESS, $check->getStatusCode(), $check->getDisplay());
        self::assertStringContainsString('The checkpoint holds', $check->getDisplay());

        $this->database->pdo->prepare('DELETE FROM verified_human_approval_audit WHERE seq = ?')->execute([$json['seq']]);
        $check = $this->console('verified-human-approval:audit-head', ['--check' => $checkpoint]);
        self::assertSame(Command::FAILURE, $check->getStatusCode());
        self::assertStringContainsString('entries were cut off', $check->getDisplay());
    }

    public function testTheBundleCommandRecordsARotationOnlyOnceTheKeysSayItHappened(): void
    {
        // Nothing has rotated: the signer still uses the key a rotation would retire.
        $refused = $this->console('verified-human-approval:record-key-rotation', ['from' => 'demo-application-1']);
        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        self::assertStringContainsString('A rotation moves to a new key', $refused->getDisplay());

        // An operator switches the signer and retires the old key, then records the move with the bundle's command.
        $settings = $this->settings();
        $keypair = sodium_crypto_sign_keypair();
        $now = ApprovalRequest::timestamp(new \DateTimeImmutable());
        $settings['retired_receipt_keys'] = [['key_id' => 'demo-application-1', 'public_key' => $settings['receipt_public_key'], 'valid_from' => $settings['receipt_key_valid_from'], 'valid_until' => $now]];
        $settings['receipt_key_id'] = 'application-2026-11';
        $settings['receipt_secret_key'] = ChallengeBinding::encode(sodium_crypto_sign_secretkey($keypair));
        $settings['receipt_public_key'] = ChallengeBinding::encode(sodium_crypto_sign_publickey($keypair));
        $settings['receipt_key_valid_from'] = $now;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));

        $recorded = $this->console('verified-human-approval:record-key-rotation', ['from' => 'demo-application-1', '--reason' => 'suspected compromise']);
        self::assertSame(Command::SUCCESS, $recorded->getStatusCode(), $recorded->getDisplay());
        self::assertStringContainsString('trust moved from key "demo-application-1" to key "application-2026-11"', $recorded->getDisplay());
        $again = $this->console('verified-human-approval:record-key-rotation', ['from' => 'demo-application-1']);
        self::assertSame(Command::FAILURE, $again->getStatusCode());
        self::assertStringContainsString('already records the rotation', $again->getDisplay());
        self::assertSame(Command::SUCCESS, $this->console('verified-human-approval:audit-head')->getStatusCode(), 'The chain verifies across the move.');
    }

    /** The manager issues the alert directly, so its receipt is signed as it commits. Returns that evidence. */
    private function issue(): array
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('verified-human-approval-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp',
            headers: ['Authorization' => 'Bearer '.$this->accounts['manager']['personal_token']],
            httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory,
        ));
        $this->clients[] = $client;
        $result = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('completed', $result->structuredContent['status'] ?? null, json_encode($result->structuredContent));
        $state = (new PdoApprovalStore($this->database->pdo))->get($result->structuredContent['requestId']);
        self::assertNotNull($state);

        return (new EvidenceExporter())->export($state);
    }

    /** @return array<string, mixed> */
    private function publicJson(string $path): array
    {
        $anyone = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $anyone;
        $anyone->request('GET', self::ORIGIN.$path);
        self::assertSame(200, $anyone->getResponse()->getStatusCode(), $path.' opens without signing in.');

        return json_decode((string) $anyone->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    private function browser(string $user): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return $browser;
    }

    /** @param array<string, mixed> $input */
    private function console(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application($this->kernel()))->find($command));
        $tester->execute($input);

        return $tester;
    }

    /** A kernel reads the runtime's settings when it is made, so each step gets a new one. */
    private function kernel(): DemoKernel
    {
        return $this->kernels[] = new DemoKernel($this->runtime, 'test');
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return json_decode((string) file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
    }
}
