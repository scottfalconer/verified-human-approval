<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Tests\Support\VirtualAuthenticator;

/**
 * The root of trust and the administrator's view: who may add a passkey, what revoking does, and how
 * the audit page shows every action and catches records edited in the database.
 */
final class AccountAndAuditTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-audit-test-'.bin2hex(random_bytes(8));
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

    public function testAPasswordAloneCannotAddAnApprovalPasskey(): void
    {
        $browser = $this->signedIn('editor');
        $csrf = $this->panelCsrf($browser);
        $refused = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(403, $browser->getResponse()->getStatusCode());
        self::assertSame('authorization_required', $refused['reason']);

        // Someone else's link does not work for this account.
        $browser->request('GET', $this->accounts['manager']['passkey_link']);
        self::assertSame(410, $browser->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_credentials')->fetchColumn());
    }

    public function testALinkAddsOneFirstPasskeyAndAnotherNeedsAnExistingPasskey(): void
    {
        $browser = $this->signedIn('editor');
        $first = $this->enroll($browser, viaLink: true);
        $browser->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'The link worked once.');

        $csrf = $this->panelCsrf($browser);
        $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(403, $browser->getResponse()->getStatusCode(), 'A second passkey needs a confirmation, not just the password.');
        $this->confirm($browser, $first);
        $second = $this->enroll($browser, viaLink: false);
        self::assertTrue($this->approves('editor', $second), 'The passkey added after confirming approves.');
        $entries = $this->database->pdo->query("SELECT entry FROM verified_human_approval_audit WHERE type = 'passkey.enrolled' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(2, $entries);
        self::assertStringContainsString('"method":"passkey link"', $entries[0]);
        self::assertStringContainsString('"method":"existing passkey"', $entries[1]);
    }

    public function testAPasskeyInsertedStraightIntoTheDatabaseCannotApprove(): void
    {
        $browser = $this->signedIn('editor');
        $genuine = $this->enroll($browser, viaLink: true);
        $row = $this->database->pdo->query('SELECT * FROM verified_human_approval_credentials LIMIT 1')->fetch();
        // A copy of the row under a new ID, as an attacker with database access might add.
        $this->database->pdo->prepare('INSERT INTO verified_human_approval_credentials (credential_id, user_id, tenant_id, source_json, revoked, label) VALUES (?, ?, ?, ?, 0, ?)')
            ->execute(['forged', $row['user_id'], $row['tenant_id'], $row['source_json'], 'Forged']);
        $this->database->pdo->exec("DELETE FROM verified_human_approval_audit WHERE type = 'passkey.enrolled'");
        self::assertFalse($this->approves('editor', $genuine), 'Without its signed enrollment record, even the genuine passkey approves nothing.');
    }

    public function testRevokingAPasskeyOrDisconnectingAnAgentTakesEffectAtOnce(): void
    {
        $browser = $this->signedIn('editor');
        $passkey = $this->enroll($browser, viaLink: true);
        $id = $this->database->pdo->query('SELECT credential_id FROM verified_human_approval_credentials LIMIT 1')->fetchColumn();
        $page = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        $browser->submit($page->filter('form[action="/account/passkeys/'.$id.'/revoke"]')->form());
        self::assertFalse($this->approves('editor', $passkey));

        self::assertFalse($this->mcp('editor')->callTool('read_alert', ['alert_id' => 'riverside-evacuation'])->isError);
        $page = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        $browser->submit($page->filter('form[action="/account/connections/editor-personal/revoke"]')->form());
        $this->expectException(\Throwable::class);
        $this->mcp('editor')->callTool('read_alert', ['alert_id' => 'riverside-evacuation']);
    }

    public function testTheApprovalSheetSaysAnAgentAsked(): void
    {
        $id = $this->mcp('editor')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        $browser = $this->signedIn('editor');
        $sheet = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertStringContainsString('Requested by an agent using your personal token', $sheet->filter('.requester')->text());
    }

    public function testTheAuditPageShowsEveryActionAndCatchesEditedRecords(): void
    {
        $editor = $this->signedIn('editor');
        $passkey = $this->enroll($editor, viaLink: true);
        $id = $this->mcp('editor')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        self::assertSame('completed', $this->approve($editor, $id, $passkey)['status']);

        $editor->request('GET', self::ORIGIN.'/admin/audit');
        self::assertSame(403, $editor->getResponse()->getStatusCode(), 'Only administrators see the audit log.');
        $admin = $this->signedIn('manager');
        $page = $admin->request('GET', self::ORIGIN.'/admin/audit');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        $row = $page->filter('table.audit')->first()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('Issue the public alert', $row);
        self::assertStringContainsString('through an agent with their personal token', $row);
        self::assertStringContainsString('passkey verification required', $row);
        self::assertStringContainsString('✓ Receipt signed at commit matches the record', $row);
        self::assertStringContainsString('✓ Passkey signed this exact proposal', $row);
        self::assertStringContainsString('All 5 entries are signed and in an unbroken chain', $page->filter('section')->last()->text());
        $admin->request('GET', self::ORIGIN.'/admin/audit/'.$id.'/evidence');
        self::assertSame(200, $admin->getResponse()->getStatusCode(), 'An administrator can download the evidence.');

        // Someone with database access rewrites what was approved, keeping every hash consistent.
        $proposal = CanonicalJson::map(CanonicalJson::decode($this->database->pdo->query("SELECT proposal FROM verified_human_approval_requests WHERE id = '$id'")->fetchColumn()));
        $proposal['statement'] = 'I approve something else entirely.';
        $canonical = CanonicalJson::encode((object) $proposal);
        $this->database->pdo->prepare('UPDATE verified_human_approval_requests SET proposal = ?, digest = ? WHERE id = ?')->execute([$canonical, hash('sha256', $canonical), $id]);
        $row = $admin->request('GET', self::ORIGIN.'/admin/audit')->filter('table.audit')->first()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('something else entirely', $row);
        self::assertStringContainsString('✗ Receipt', $row);
        self::assertStringContainsString('✗ Passkey check: the passkey signature does not match this proposal', $row);
    }

    public function testADeletedReceiptIsReportedNotReplaced(): void
    {
        $editor = $this->signedIn('editor');
        $passkey = $this->enroll($editor, viaLink: true);
        $id = $this->mcp('editor')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        self::assertSame('completed', $this->approve($editor, $id, $passkey)['status']);

        // Someone with database access edits what the action recorded and deletes its receipt.
        $record = CanonicalJson::map(CanonicalJson::decode((string) $this->database->pdo->query("SELECT record FROM verified_human_approval_executions WHERE request_id = '$id'")->fetchColumn()));
        $record['recordedAt'] = '2020-01-01T00:00:00.000000Z';
        $this->database->pdo->prepare('UPDATE verified_human_approval_executions SET record = ? WHERE request_id = ?')->execute([CanonicalJson::encode((object) $record), $id]);
        $this->database->pdo->prepare('DELETE FROM verified_human_approval_receipts WHERE request_id = ?')->execute([$id]);

        $admin = $this->signedIn('manager');
        $row = $admin->request('GET', self::ORIGIN.'/admin/audit')->filter('table.audit')->first()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('✗ Receipt check: there is no receipt signed as the action committed', $row, 'Reading the audit page never signs a replacement.');
        $admin->request('GET', self::ORIGIN.'/admin/audit/'.$id.'/evidence');
        self::assertSame(409, $admin->getResponse()->getStatusCode(), 'No evidence is exported for a record whose receipt is gone.');
        self::assertSame('receipt_missing', json_decode((string) $admin->getResponse()->getContent(), true)['reason']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_receipts')->fetchColumn(), 'And nothing signed one since.');
    }

    public function testATakeDownIsRecordedAndTheLogShowsEdits(): void
    {
        $this->database->pdo->exec("UPDATE demo_alerts SET published_revision = revision, published_title = title, published_body = body WHERE id = 'riverside-evacuation'");
        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel()))->find('demo:all-clear'));
        $tester->execute([]);
        self::assertStringContainsString('The take-down is in the audit log', $tester->getDisplay());
        $admin = $this->signedIn('manager');
        self::assertStringContainsString('alert.taken_down · riverside-evacuation · by operator console', $admin->request('GET', self::ORIGIN.'/admin/audit')->filter('section')->last()->text());

        $this->database->pdo->exec("UPDATE verified_human_approval_audit SET entry = replace(entry, 'riverside-evacuation', 'something-else') WHERE type = 'alert.taken_down'");
        self::assertStringContainsString('The log has been altered', $admin->request('GET', self::ORIGIN.'/admin/audit')->filter('section')->last()->text());
    }

    public function testRevokingTheConfirmingPasskeyEndsItsConfirmationBeforeOptions(): void
    {
        $browser = $this->signedIn('editor');
        $first = $this->enroll($browser, viaLink: true);
        $this->confirm($browser, $first);
        $this->revokeFromAnotherBrowser('editor');

        $this->post($browser, '/account/passkeys/options', $this->panelCsrf($browser), []);
        self::assertSame(403, $browser->getResponse()->getStatusCode(), 'The confirmation ended with the passkey that gave it.');
        self::assertSame(0, $this->activePasskeys('editor'));
    }

    public function testRevokingTheConfirmingPasskeyStopsAnEnrollmentAlreadyUnderWay(): void
    {
        $browser = $this->signedIn('editor');
        $first = $this->enroll($browser, viaLink: true);
        $this->confirm($browser, $first);
        $csrf = $this->panelCsrf($browser);
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $this->revokeFromAnotherBrowser('editor');

        $replacement = new VirtualAuthenticator(self::ORIGIN);
        $result = $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $replacement->registration($options['publicKey'])]);
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertSame('rejected', $result['status'] ?? null);
        self::assertSame(0, $this->activePasskeys('editor'), 'No replacement passkey was added.');
        self::assertFalse($this->approves('editor', $replacement));
    }

    public function testRevokingTheAdministratorsConfirmingPasskeyStopsALinkBeingIssued(): void
    {
        $admin = $this->signedIn('manager');
        $passkey = $this->enroll($admin, viaLink: true);
        $this->confirm($admin, $passkey);
        $form = $admin->request('GET', self::ORIGIN.'/admin/audit')->filter('form[action="/admin/audit/links"]')->form(['user' => 'editor']);
        $this->revokeFromAnotherBrowser('manager');

        $admin->submit($form);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        self::assertSame(1, (int) $this->database->pdo->query("SELECT COUNT(*) FROM demo_passkey_links WHERE user_id = 'editor'")->fetchColumn(), 'Only the link setup issued.');
    }

    public function testIssuingAPasskeyLinkNeedsTheAdministratorsPasskey(): void
    {
        $admin = $this->signedIn('manager');
        $passkey = $this->enroll($admin, viaLink: true);
        $page = $admin->request('GET', self::ORIGIN.'/admin/audit');
        self::assertCount(0, $page->filter('form[action="/admin/audit/links"]'), 'No link without confirming first.');
        $admin->request('POST', self::ORIGIN.'/admin/audit/links', ['_token' => (string) $page->filter('form.inline input[name=_token]')->first()->attr('value'), 'user' => 'editor']);
        self::assertSame(403, $admin->getResponse()->getStatusCode());

        $this->confirm($admin, $passkey);
        $page = $admin->request('GET', self::ORIGIN.'/admin/audit');
        $admin->submit($page->filter('form[action="/admin/audit/links"]')->form(['user' => 'editor']));
        $notice = $admin->request('GET', self::ORIGIN.'/admin/audit')->filter('p.notice code')->text();
        self::assertStringStartsWith(self::ORIGIN.'/account/passkeys/link/', $notice);
        $editor = $this->signedIn('editor');
        $editor->request('GET', $notice);
        self::assertSame(302, $editor->getResponse()->getStatusCode(), 'The editor can use the new link.');
    }

    private function enroll(KernelBrowser $browser, bool $viaLink): VirtualAuthenticator
    {
        if ($viaLink) {
            $owner = $this->owner($browser);
            $browser->request('GET', $this->accounts[$owner]['passkey_link']);
            self::assertSame(302, $browser->getResponse()->getStatusCode());
        }
        $csrf = $this->panelCsrf($browser);
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        $result = $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $authenticator->registration($options['publicKey'])]);
        self::assertSame('registered', $result['status'] ?? null, json_encode($result));

        return $authenticator;
    }

    /** Revokes this person's active passkeys from a second browser, as after a lost device. */
    private function revokeFromAnotherBrowser(string $user): void
    {
        $other = $this->signedIn($user);
        $query = $this->database->pdo->prepare('SELECT credential_id FROM verified_human_approval_credentials WHERE user_id = ? AND revoked = 0');
        $query->execute([$user]);
        foreach ($query->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $page = $other->request('GET', self::ORIGIN.'/account/passkeys');
            $other->submit($page->filter('form[action="/account/passkeys/'.$id.'/revoke"]')->form());
        }
        self::assertSame(0, $this->activePasskeys($user));
    }

    private function activePasskeys(string $user): int
    {
        $query = $this->database->pdo->prepare('SELECT COUNT(*) FROM verified_human_approval_credentials WHERE user_id = ? AND revoked = 0');
        $query->execute([$user]);

        return (int) $query->fetchColumn();
    }

    private function confirm(KernelBrowser $browser, VirtualAuthenticator $passkey): void
    {
        $csrf = $this->panelCsrf($browser);
        $options = $this->post($browser, '/account/passkeys/confirm/options', $csrf, []);
        $result = $this->post($browser, '/account/passkeys/confirm/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $passkey->assertion($options['publicKey'])]);
        self::assertSame('confirmed', $result['status'] ?? null, json_encode($result));
    }

    /** Whether this passkey can approve a fresh request for the account. */
    private function approves(string $user, VirtualAuthenticator $passkey): bool
    {
        $id = $this->mcp($user)->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => bin2hex(random_bytes(8))])->structuredContent['requestId'];
        $result = $this->approve($this->signedIn($user), $id, $passkey);
        if (($result['status'] ?? null) === 'completed') {
            $this->database->pdo->exec('UPDATE demo_alerts SET published_revision = NULL, published_title = NULL, published_body = NULL');

            return true;
        }

        return false;
    }

    private function approve(KernelBrowser $browser, string $id, VirtualAuthenticator $passkey): array
    {
        $csrf = (string) $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        if (!isset($ceremony['ceremony_id'])) {
            return $ceremony;
        }
        try {
            $assertion = $passkey->assertion($ceremony['publicKey']);
        } catch (\Throwable) {
            return ['status' => 'no_usable_passkey'];
        }

        return $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $assertion]);
    }

    private function owner(KernelBrowser $browser): string
    {
        $page = $browser->request('GET', self::ORIGIN.'/');
        preg_match('/^([a-z]+) ·/', $page->filter('header .user-badge')->text(), $match);

        return $match[1];
    }

    private function panelCsrf(KernelBrowser $browser): string
    {
        return (string) $browser->request('GET', self::ORIGIN.'/account/passkeys')->filter('[data-passkey-panel], [data-csrf]')->first()->attr('data-csrf');
    }

    private function kernel(): DemoKernel
    {
        return new DemoKernel($this->runtime, 'test');
    }

    private function signedIn(string $user): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'alerts.example', 'HTTPS' => 'on']);
        $this->browsers[] = $browser;
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));

        return $browser;
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
        $value = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($value) ? $value : [];
    }

    private function mcp(string $user): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('audit-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
