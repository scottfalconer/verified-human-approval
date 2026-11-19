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
use Symfony\Component\HttpFoundation\Request;
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
        // The demo trusts its own host only, and Symfony keeps that for the whole process.
        Request::setTrustedHosts([]);
    }

    #[DataProvider('projectionChanges')]
    public function testAnEditedOutcomeIsExplicitlyUntrustedButStillVisibleToTheAuditor(string $change): void
    {
        [$editor, $agent, $arguments, $id, $csrf, $completed] = $this->publishedWithApproval('integrity-ui');
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

    /**
     * An edit that leaves the record unreadable, not merely inconsistent, is the same integrity error everywhere: the
     * owner's page and routes, the agent's tools, a retry with the request key, and the auditor, who sees it flagged in
     * its place.
     *
     * @param \Closure(\PDO, string): void $change
     */
    #[DataProvider('unreadableChanges')]
    public function testARecordEditedPastReadingIsAnIntegrityErrorOnEverySurface(\Closure $change): void
    {
        [$editor, $agent, $arguments, $id, $csrf] = $this->publishedWithApproval('unreadable-record');
        $receipt = $this->database->pdo->query('SELECT * FROM verified_human_approval_receipts')->fetchAll();
        $change($this->database->pdo, $id);

        $page = $editor->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(409, $editor->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('[data-status="integrity_error"]'));
        self::assertStringContainsString('We cannot confirm this approval’s outcome', $page->text());
        foreach (['/status', '/evidence'] as $route) {
            $editor->request('GET', self::ORIGIN.'/approval/'.$id.$route);
            self::assertSame(409, $editor->getResponse()->getStatusCode(), $route);
            $error = json_decode((string) $editor->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(['integrity_error', 'record_integrity_error'], [$error['status'], $error['reason']], $route);
        }
        foreach (['options', 'cancel'] as $route) {
            $error = $this->post($editor, '/approval/'.$id.'/'.$route, $csrf, []);
            self::assertSame(409, $editor->getResponse()->getStatusCode(), $route);
            self::assertSame(['integrity_error', 'record_integrity_error'], [$error['status'], $error['reason']], $route);
        }
        foreach (['approval_status' => ['request_id' => $id], 'approval_handoff' => ['request_id' => $id], 'publish_alert' => $arguments] as $tool => $input) {
            $error = $agent->callTool($tool, $input);
            self::assertTrue($error->isError, $tool);
            self::assertSame(['integrity_error', 'record_integrity_error'], [$error->structuredContent['status'] ?? null, $error->structuredContent['reason'] ?? null], $tool);
        }

        $admin = $this->signedIn('manager');
        $page = $admin->request('GET', self::ORIGIN.'/admin/audit');
        self::assertSame(200, $admin->getResponse()->getStatusCode(), 'A damaged record does not take the audit page down.');
        $row = $page->filter('table.audit')->first()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('This record cannot be read', $row);
        self::assertStringContainsString('✗ Record integrity: The stored approval', $row);
        self::assertStringContainsString($id, $row);
        $admin->request('GET', self::ORIGIN.'/admin/audit/'.$id.'/evidence');
        self::assertSame(409, $admin->getResponse()->getStatusCode());
        $error = json_decode((string) $admin->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['integrity_error', 'record_integrity_error'], [$error['status'], $error['reason']]);
        self::assertSame($receipt, $this->database->pdo->query('SELECT * FROM verified_human_approval_receipts')->fetchAll(), 'No route replaces the original receipt.');
        self::assertSame(1, (int) $this->database->alert('riverside-evacuation')['mutation_count'], 'The alert that was published stays published, once.');
    }

    public static function unreadableChanges(): iterable
    {
        yield 'one character of the proposal' => [self::update("proposal = replace(proposal, 'Evacuat', 'Evacuat0')")];
        yield 'the proposal, cut short' => [self::update('proposal = substr(proposal, 1, 40)')];
        yield 'the result, to text that is not JSON' => [self::update("result = 'published'")];
        yield 'the status, to one that does not exist' => [self::update("status = 'done'")];
    }

    /**
     * Someone else who names a damaged record is told what they would be told of a request that does not exist, as long
     * as its proposal still shows whose the record is: a colleague, and another organization's account, agent and
     * administrator. A record whose proposal cannot be read shows no one, so whoever names it is told that its proposal
     * fails its check, and nothing about the rest of it.
     *
     * @param \Closure(\PDO, string): void $change
     */
    #[DataProvider('damagedRecords')]
    public function testSomeoneElseLearnsNothingOfADamagedRecordThatShowsWhoseItIs(\Closure $change, bool $proposalReadsBack): void
    {
        [, , , $id] = $this->publishedWithApproval('damaged-record');
        $change($this->database->pdo, $id);
        // The other organization's account administers it, so its administrator's evidence route is asked too.
        $this->database->pdo->exec("UPDATE demo_users SET is_admin = 1 WHERE id = 'other'");

        foreach (['reader', 'other'] as $user) {
            $browser = $this->signedIn($user);
            $agent = $this->mcp($user);
            $told = static function (string $requestId) use ($browser, $agent): array {
                $answers = [];
                foreach (['review page' => '/approval/'.$requestId, 'status' => '/approval/'.$requestId.'/status', 'receipt' => '/approval/'.$requestId.'/evidence', 'audit evidence' => '/admin/audit/'.$requestId.'/evidence'] as $surface => $path) {
                    $browser->request('GET', self::ORIGIN.$path);
                    $response = $browser->getResponse();
                    // An error page is compared by its status alone: the JSON answers are compared whole.
                    $answers[$surface] = $response->getStatusCode().(str_contains((string) $response->headers->get('Content-Type'), 'json') ? ' '.$response->getContent() : '');
                }
                $status = $agent->callTool('approval_status', ['request_id' => $requestId]);
                $answers['approval_status'] = json_encode($status->structuredContent ?? $status->content, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

                return $answers;
            };
            $answers = $told($id);
            if ($proposalReadsBack) {
                self::assertSame($told('apr_'.str_repeat('0', 32)), $answers, $user);
                continue;
            }
            self::assertSame('409', $answers['review page'], $user);
            foreach ('other' === $user ? ['status', 'receipt', 'audit evidence', 'approval_status'] : ['status', 'receipt', 'approval_status'] as $surface) {
                self::assertStringContainsString('"reason":"record_integrity_error","message":"The stored approval proposal fails its integrity check. Its outcome cannot be trusted."', $answers[$surface], $user.', '.$surface);
            }
        }
    }

    /** @return iterable<string, array{\Closure(\PDO, string): void, bool}> each edit, and whether the proposal still reads back after it */
    public static function damagedRecords(): iterable
    {
        yield 'the result, to text that is not JSON' => [self::update("result = 'published'"), true];
        yield 'the status, to one that does not exist' => [self::update("status = 'done'"), true];
        yield 'the execution record, cut short' => [static fn (\PDO $pdo, string $id) => $pdo->prepare('UPDATE verified_human_approval_executions SET record = substr(record, 1, 40) WHERE request_id = ?')->execute([$id]), true];
        yield 'one character of the proposal' => [self::update("proposal = replace(proposal, 'Evacuat', 'Evacuat0')"), false];
    }

    /**
     * The site's Issue alert button and its alert rule form send no request key, so asking again with the same content
     * reuses the proposal still waiting. When its stored record was edited, they say what the review page says, that
     * its outcome cannot be confirmed, and never that the request was refused.
     *
     * @param \Closure(\PDO, string): void $change
     */
    #[DataProvider('waitingChanges')]
    public function testAskingAgainOnTheSiteWhileTheWaitingRequestIsDamagedSaysItsOutcomeCannotBeConfirmed(\Closure $change, bool $namesRequest): void
    {
        $editor = $this->signedIn('editor');
        $issue = $editor->request('GET', self::ORIGIN.'/')->selectButton('Issue alert')->form();
        $manager = $this->signedIn('manager');
        $rule = $manager->request('GET', self::ORIGIN.'/admin/approval-policy')->selectButton('Propose this change')->form();
        $rule['approval_policy[staff]']->select('direct');
        foreach (['Issue alert' => [$editor, $issue], 'the alert rule form' => [$manager, $rule]] as $surface => [$browser, $form]) {
            $browser->submit($form);
            self::assertSame(1, preg_match('#^/approval/(apr_[a-f0-9]{32})\?#', (string) $browser->getResponse()->headers->get('Location'), $match), $surface);
            $change($this->database->pdo, $match[1]);

            $page = $browser->submit($form);
            self::assertSame(409, $browser->getResponse()->getStatusCode(), $surface);
            self::assertCount(1, $page->filter('[data-status="integrity_error"]'), $surface);
            self::assertStringContainsString('We cannot confirm this approval’s outcome', $page->text(), $surface);
            $named = $namesRequest ? 'Request '.$match[1].' · ' : '';
            self::assertSame($named.'Reason code: record_integrity_error', $page->filter('[data-status="integrity_error"] p.small')->text(), $surface.': the page names the request only when the error carries it.');
        }
        self::assertSame(0, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
        self::assertSame(1, (int) $this->database->policy('alert.publish')['version']);
    }

    /**
     * The error names the request only when a part other than the proposal does not read back. A record that reads
     * back but whose parts disagree, such as a proof beside a waiting request, fails a check that names none.
     *
     * @return iterable<string, array{\Closure(\PDO, string): void, bool}> each edit to a waiting request, and whether the error it causes names the request
     */
    public static function waitingChanges(): iterable
    {
        yield 'one character of the proposal' => [self::update("proposal = substr(proposal, 1, 20) || 'X' || substr(proposal, 22)"), false];
        yield 'the proof, to text that is not JSON' => [self::update("proof = 'approved'"), true];
        yield 'a proof, added while it waits' => [self::update("proof = '{}'"), false];
    }

    /** @return \Closure(\PDO, string): void an edit to the stored request's own row */
    private static function update(string $set): \Closure
    {
        return static fn (\PDO $pdo, string $id) => $pdo->prepare('UPDATE verified_human_approval_requests SET '.$set.' WHERE id = ?')->execute([$id]);
    }

    /**
     * An edit that still reads back, but leaves a part the audit page shows as something the application never writes,
     * is flagged in the row instead of taking the page down.
     *
     * @param \Closure(\PDO, string): void $change
     */
    #[DataProvider('unshowableChanges')]
    public function testTheAuditPageFlagsAStoredPartItCannotShow(\Closure $change, string $flagged): void
    {
        [, , , $id] = $this->publishedWithApproval('unshowable-part');
        $change($this->database->pdo, $id);

        $admin = $this->signedIn('manager');
        $page = $admin->request('GET', self::ORIGIN.'/admin/audit');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        $row = $page->filter('table.audit')->first()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('integrity error', $row);
        self::assertStringContainsString($flagged, $row);
        self::assertStringContainsString($id, $row);
    }

    public static function unshowableChanges(): iterable
    {
        yield 'the proof, to an empty object' => [static fn (\PDO $pdo, string $id) => $pdo->prepare("UPDATE verified_human_approval_requests SET proof = '{}' WHERE id = ?")->execute([$id]), '✗ Passkey check'];
        yield 'the time the action ran, to one that is not a time' => [static fn (\PDO $pdo, string $id) => $pdo->prepare("UPDATE verified_human_approval_executions SET record = replace(record, '\"recordedAt\":\"', '\"recordedAt\":\"not ') WHERE request_id = ?")->execute([$id]), '✗ Receipt check'];
    }

    /**
     * The editor adds a passkey, the agent asks to publish the evacuation alert, and the editor approves it.
     *
     * @return array{KernelBrowser, Client, array<string, string>, string, string, array<string, mixed>} the editor, the agent, the tool's arguments, the request id, the review page's token and the completed result
     */
    private function publishedWithApproval(string $requestKey): array
    {
        $editor = $this->signedIn('editor');
        $editor->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertSame(302, $editor->getResponse()->getStatusCode());
        $csrf = (string) $editor->request('GET', self::ORIGIN.'/account/passkeys')->filter('[data-csrf]')->first()->attr('data-csrf');
        $options = $this->post($editor, '/account/passkeys/options', $csrf, []);
        $passkey = new VirtualAuthenticator(self::ORIGIN);
        self::assertSame('registered', $this->post($editor, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $passkey->registration($options['publicKey'])])['status']);

        $agent = $this->mcp();
        $arguments = ['alert_id' => 'riverside-evacuation', 'request_key' => $requestKey];
        $id = $agent->callTool('publish_alert', $arguments)->structuredContent['requestId'];
        $csrf = (string) $editor->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($editor, '/approval/'.$id.'/options', $csrf, []);
        $completed = $this->post($editor, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $passkey->assertion($ceremony['publicKey'])]);
        self::assertSame('completed', $completed['status']);
        self::assertSame(1, (int) $this->database->alert('riverside-evacuation')['mutation_count']);

        return [$editor, $agent, $arguments, $id, $csrf, $completed];
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

    private function mcp(string $user = 'editor'): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('integrity-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
