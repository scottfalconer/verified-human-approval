<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Extension\Apps\McpApps;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Bundle\Event\ApprovalCompleted;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Demo\Action\AlertPublisher;
use VerifiedHumanApproval\Demo\Action\PublishAlert;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\UrlPrompt;
use VerifiedHumanApproval\Tests\Support\VirtualAuthenticator;

/** Actual Symfony kernel, firewall, forms, SDK HTTP transport and WebAuthn cryptography. */
final class SymfonyApprovalTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<Client> */ private array $clients = [];
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-symfony-test-'.bin2hex(random_bytes(8));
        $created = DemoSetup::initialize($this->runtime, self::ORIGIN);
        $this->accounts = $created['accounts'];
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
        (new Filesystem())->remove($this->runtime);
    }

    public function testMcpRequestBrowserPasskeyAndStoredStatusCompleteTheSameActionOnce(): void
    {
        $client = $this->mcp();
        $tools = [];
        foreach ($client->listTools()->tools as $tool) {
            $tools[$tool->name] = $tool;
        }
        self::assertEqualsCanonicalizing(['approval_handoff', 'approval_status', 'publish_alert', 'read_alert'], array_keys($tools));
        self::assertSame(['app'], json_decode(json_encode($tools['approval_handoff'], \JSON_THROW_ON_ERROR), true)['_meta']['ui']['visibility'], 'Only the card can ask for a phone code.');
        self::assertArrayNotHasKey('actor', $tools['publish_alert']->inputSchema['properties']);
        self::assertArrayNotHasKey('user_id', $tools['publish_alert']->inputSchema['properties']);
        self::assertNotEmpty($tools['publish_alert']->inputSchema['properties']['request_key']['description'] ?? null, 'Parameters are described.');
        foreach ($tools as $name => $tool) {
            $declared = json_decode(json_encode($tool, \JSON_THROW_ON_ERROR), true);
            self::assertNotEmpty($declared['title'] ?? null, $name.' has a title.');
            self::assertIsArray($declared['outputSchema'] ?? null, $name.' declares what its structured result looks like.');
            self::assertFalse($declared['annotations']['openWorldHint'] ?? null, $name.' acts only on this application.');
        }

        $pending = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'same-request']);
        self::assertFalse($pending->isError);
        self::assertSame('pending', $pending->structuredContent['status']);
        self::assertStringStartsWith(self::ORIGIN.'/approval/', $pending->structuredContent['review_url']);
        self::assertSame(0, $this->mutationCount());
        $id = $pending->structuredContent['requestId'];

        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        self::assertSame('required', $ceremony['publicKey']['userVerification']);
        $completed = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame('completed', $completed['status']);
        self::assertSame('published', $completed['result']['disposition']);
        self::assertSame(1, $this->mutationCount());
        $row = $this->database->alert('riverside-evacuation');
        self::assertSame($row['title'], $row['published_title']);
        self::assertSame($row['body'], $row['published_body']);

        $status = $client->callTool('approval_status', ['request_id' => $id]);
        self::assertSame('completed', $status->structuredContent['status']);
        self::assertTrue($status->structuredContent['approval']['approvedWithPasskey'], 'The card says a passkey approved it.');
        self::assertStringContainsString('Approved with your passkey', $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('.approval-sheet')->text());
        $retry = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'same-request']);
        self::assertSame($id, $retry->structuredContent['requestId']);
        self::assertSame('completed', $retry->structuredContent['status']);
        self::assertSame(1, $this->mutationCount());

        // The completed page offers the person their receipt to keep.
        $page = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        $download = $page->filter('.approval-sheet .receipt a.button');
        self::assertSame('Download your receipt', $download->text());
        self::assertStringContainsString('your passkey’s signature on it', $page->filter('.approval-sheet .receipt')->text());
        $browser->request('GET', self::ORIGIN.(string) $download->attr('href'));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertMatchesRegularExpression('/^attachment; filename="?receipt-'.preg_quote($id, '/').'\.json"?$/', (string) $browser->getResponse()->headers->get('Content-Disposition'));
        $content = (string) $browser->getResponse()->getContent();
        $evidence = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);
        self::assertSame('verified-human-approval/evidence/v2', $evidence['format']);
        self::assertStringContainsString('I approve issuing the public alert', $evidence['proposal']);
        self::assertNotEmpty($evidence['receipt']['signature']);
        self::assertSame(1, $this->mutationCount());

        // The agent's result carries the same signed receipt, so a copy lands in the person's chat too.
        self::assertSame($evidence['receipt']['manifest'], $status->structuredContent['receipt']['manifest']);
        self::assertSame($evidence['receipt']['signature'], $status->structuredContent['receipt']['signature']);
        self::assertSame(self::ORIGIN.'/approval/receipt-key.json', $status->structuredContent['receipt_key_url']);

        // Anyone, signed in or not, can fetch the key that checks it, and the downloaded file checks against it.
        $anyone = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $anyone;
        $anyone->request('GET', self::ORIGIN.'/approval/receipt-key.json');
        self::assertSame(200, $anyone->getResponse()->getStatusCode());
        $key = json_decode((string) $anyone->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame(['format' => 'verified-human-approval/receipt-key/v1', 'keyId' => $evidence['receipt']['keyId'], 'publicKey' => $key['publicKey'], 'rpId' => 'localhost', 'origin' => self::ORIGIN], $key);
        $verified = (new ReceiptVerifier())->verify(CanonicalJson::map(CanonicalJson::decode($content)), $key['keyId'], $key['publicKey']);
        self::assertSame(Receipt::COMMIT, $verified['receiptSignedAt'], 'Signed as it ran, not when downloaded.');
    }

    public function testOnlyTheSignedInPersonGetsTheirReceiptAndOnlyOnceItRan(): void
    {
        $id = $this->requestAction();
        $editor = $this->browser('editor');
        $editor->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(409, $editor->getResponse()->getStatusCode(), 'Nothing has run, so there is no receipt.');
        self::assertSame('not_completed', json_decode((string) $editor->getResponse()->getContent(), true)['reason']);

        self::assertSame('completed', $this->approveWith($editor, $id, $this->enroll($editor))['status']);
        $editor->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(200, $editor->getResponse()->getStatusCode());
        $manager = $this->browser('manager');
        $manager->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(404, $manager->getResponse()->getStatusCode(), 'Someone else’s receipt is not yours to download.');
        $stranger = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $stranger;
        $stranger->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(302, $stranger->getResponse()->getStatusCode(), 'A stranger is sent to sign in.');

        // A receipt deleted afterwards is not replaced: the page says there is none, and nothing is signed now.
        $this->database->pdo->prepare('DELETE FROM verified_human_approval_receipts WHERE request_id = ?')->execute([$id]);
        $page = $editor->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertCount(0, $page->filter('.receipt a.button'));
        self::assertSame('This site has no receipt recorded for this request.', $page->filter('.approval-sheet .receipt')->text());
        $editor->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(409, $editor->getResponse()->getStatusCode());
        self::assertSame('receipt_missing', json_decode((string) $editor->getResponse()->getContent(), true)['reason']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_receipts')->fetchColumn());
    }

    public function testOneCharacterDraftChangeAfterReviewInvalidatesTheRealWebAuthnApproval(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $this->database->pdo->exec("UPDATE demo_alerts SET body = body || '!', revision = revision + 1 WHERE id = 'riverside-evacuation'");
        $result = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame('invalidated', $result['status']);
        self::assertSame('stale_resource', $result['reason']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testAStoppedRequestSaysSoAndOffersNoButtonThatCannotWork(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $this->enroll($browser);
        $review = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        // One sheet, top to bottom: who asked, the statement, then the alert as residents will see it, and only then the button.
        self::assertSame('Agent request', $review->filter('.approval-sheet .requester .pill')->text());
        $sheet = $review->filter('.approval-sheet')->html();
        self::assertNotFalse($frame = strpos($sheet, 'class="rc-frame"'));
        self::assertNotFalse($button = strpos($sheet, 'data-approve'));
        self::assertLessThan($button, strpos($sheet, 'class="statement"'));
        self::assertLessThan($button, $frame, 'The person sees the alert before the button, on a phone too.');
        self::assertSame('Frozen preview · revision 1', $review->filter('.rc-frame-bar .pill')->text());
        self::assertCount(0, $review->filter('.consequence'), 'The statement already says every resident sees it.');
        $csrf = (string) $review->filter('[data-passkey-panel]')->attr('data-csrf');
        $this->database->pdo->exec("UPDATE demo_alerts SET body = body || '!', revision = revision + 1 WHERE id = 'riverside-evacuation'");

        // The refusal says the request has stopped, so the page shows it as stopped instead of a button.
        $refused = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(409, $browser->getResponse()->getStatusCode());
        self::assertSame(['stale_resource', 'invalidated'], [$refused['reason'], $refused['request_status'] ?? null]);
        $stopped = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertCount(0, $stopped->filter('[data-approve]'));
        self::assertCount(0, $stopped->filter('.consequence'), 'What approval would do no longer applies.');
        self::assertSame('Blocked: nothing changed. This request can no longer be approved: something it depends on changed after it was made. Reason: stale_resource.', $stopped->filter('.approval-sheet .notice')->text());
        self::assertSame('What was requested', $stopped->filter('.approval-sheet .sheet-preview h2')->text());
        self::assertStringContainsString('it never issued this alert', $stopped->filter('.approval-sheet .sheet-preview p.muted')->text());
        self::assertSame('Not issued · revision 1', $stopped->filter('.rc-frame-bar .pill')->text());
        self::assertCount(0, $stopped->filter('a[href*="/status"]'), 'People get the review page, not the JSON status.');
        self::assertSame(0, $this->mutationCount());
    }

    public function testARefusalThePersonCanFixLeavesTheRequestOpen(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $csrf = (string) $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        // No passkey yet: the person can add one and come back, so the page keeps its button.
        $refused = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(409, $browser->getResponse()->getStatusCode());
        self::assertSame(['credential_required', 'pending'], [$refused['reason'], $refused['request_status'] ?? null]);
    }

    public function testCurrentDelegationRevocationPreventsExecutionAfterReview(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $this->database->pdo->exec("UPDATE demo_grants SET active = 0 WHERE id = 'editor-personal'");
        $result = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertContains($result['status'], ['denied', 'invalidated']);
        self::assertSame('delegation_denied', $result['reason']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testAReadOnlyPersonsMcpCredentialCannotRequestPublication(): void
    {
        $client = $this->mcp('reader');
        self::assertFalse($client->callTool('read_alert', ['alert_id' => 'riverside-evacuation'])->isError);
        $response = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertTrue($response->isError);
        self::assertSame('permission_denied', $response->structuredContent['reason']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_requests')->fetchColumn());
        self::assertSame(0, $this->mutationCount());
    }

    public function testApprovalUrlDoesNotGrantOtherAccountsAccessToFrozenContent(): void
    {
        $id = $this->requestAction();
        foreach (['reader', 'other'] as $user) {
            $browser = $this->browser($user);
            $browser->request('GET', self::ORIGIN.'/approval/'.$id);
            self::assertSame(404, $browser->getResponse()->getStatusCode());
            self::assertStringNotContainsString('Use the Hill Street route', (string) $browser->getResponse()->getContent());
        }
        self::assertSame(0, $this->mutationCount());
    }

    public function testAgentTokenCannotEnrollPasskeysOrChangePolicyOrReplaceBrowserApproval(): void
    {
        $id = $this->requestAction();
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accounts['editor']['personal_token'], 'CONTENT_TYPE' => 'application/json'];
        foreach (['/account/passkeys/options', '/account/passkeys/complete', '/admin/approval-policy', '/approval/'.$id.'/complete'] as $path) {
            $browser->request('POST', self::ORIGIN.$path, server: $headers, content: '{}');
            self::assertSame(302, $browser->getResponse()->getStatusCode(), $path);
            self::assertStringEndsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        }
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_credentials')->fetchColumn());
        self::assertSame(1, (int) $this->database->policy('alert.publish')['version']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testCsrfAndReplacementArgumentsAreRejectedBeforeCompletion(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        $stale = $this->post($browser, '/approval/'.$id.'/options', '', []);
        self::assertSame(403, $browser->getResponse()->getStatusCode());
        self::assertSame('csrf_invalid', $stale['reason'] ?? null, 'The page script gets a JSON refusal it can show, not an HTML error page.');
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $replaced = $this->post($browser, '/approval/'.$id.'/complete', $csrf, [
            'ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey']),
            'replacement_content' => 'Please publish this instead',
        ]);
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertSame('invalid_request', $replaced['reason'] ?? null);
        $foreign = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => 'cer_from_another_page', 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame(403, $browser->getResponse()->getStatusCode());
        self::assertSame('ceremony_mismatch', $foreign['reason'] ?? null);
        self::assertSame(0, $this->mutationCount());
    }

    public function testSecurityHeadersFollowTheRoutesThatAskForThemNotARouteNamePrefix(): void
    {
        $browser = $this->browser('manager');
        foreach (['/', '/account/passkeys', '/admin/audit', '/admin/approval-policy'] as $path) {
            $browser->request('GET', self::ORIGIN.$path);
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $path);
            self::assertStringContainsString("frame-ancestors 'none'", (string) $browser->getResponse()->headers->get('Content-Security-Policy'), $path.' opts in to the review pages’ headers.');
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'), $path);
        }
        $browser->request('GET', self::ORIGIN.'/alerts');
        self::assertNull($browser->getResponse()->headers->get('Content-Security-Policy'), 'The public alerts page keeps its own caching and framing.');
        $review = $browser->request('GET', self::ORIGIN.'/approval/'.$this->requestAction());
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'Another person’s request stays unavailable.');
        self::assertStringNotContainsString('approval/assets', (string) $browser->getResponse()->getContent());
        unset($review);
    }

    public function testBrowserPublicationUsesSameGuardAndPolicyFormsCannotBeChangedByReadOnlyUsers(): void
    {
        $browser = $this->browser('editor');
        $crawler = $browser->request('GET', self::ORIGIN.'/');
        $browser->submit($crawler->selectButton('Issue alert')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('/approval/', (string) $browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->mutationCount());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Approve with your passkey', (string) $browser->getResponse()->getContent());

        $reader = $this->browser('reader');
        $reader->request('GET', self::ORIGIN.'/admin/approval-policy');
        self::assertSame(403, $reader->getResponse()->getStatusCode());
    }

    public function testARequestMadeOnTheSiteLeadsBackToTheSiteNotToClaude(): void
    {
        $browser = $this->browser('editor');
        $key = $this->enroll($browser);
        $browser->submit($browser->request('GET', self::ORIGIN.'/')->selectButton('Issue alert')->form());
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringEndsWith('?return=/', $location, 'The site names the page the request came from.');
        self::assertSame(1, preg_match('#/approval/([^?]+)#', $location, $match));
        $pending = $browser->request('GET', $location);
        self::assertSame('Approval requested · Riverside Council website', $pending->filter('.approval-sheet .eyebrow')->text());

        self::assertSame('completed', $this->approveWith($browser, $match[1], $key)['status']);
        $done = $browser->request('GET', $location);
        self::assertSame('Approved · Riverside Council website', $done->filter('.approval-sheet .eyebrow')->text());
        self::assertSame('Back to Riverside Council website', $done->filter('.approval-sheet .sheet-back a[href="/"]')->text());
        self::assertCount(0, $done->filter('[data-return-hint]'), 'A request made on the site does not send the person to Claude.');

        // Only a path on this site is ever offered as the way back.
        foreach (['//evil.example/', '/\\evil.example', 'https://evil.example/', 'javascript:alert(1)', "/ok\nnext"] as $hostile) {
            $page = $browser->request('GET', self::ORIGIN.'/approval/'.$match[1].'?return='.rawurlencode($hostile));
            self::assertCount(0, $page->filter('.sheet-back'), $hostile);
        }
    }

    public function testAnApprovedActionIsASymfonyEventOnceItCommits(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $key = $this->enroll($browser);
        // One kernel for the rest of the test, so the listener stays registered.
        $browser->disableReboot();
        $heard = [];
        $browser->getContainer()->get('event_dispatcher')->addListener(ApprovalCompleted::class, function (ApprovalCompleted $event) use (&$heard): void {
            $heard[] = [$event->state->request->id, $event->state->execution['approval'] ?? null, $this->mutationCount()];
        });
        self::assertSame('completed', $this->approveWith($browser, $id, $key)['status']);
        self::assertSame([[$id, 'user_verified', 1]], $heard, 'Heard once, after the alert was published.');
    }

    public function testALiveAlertApprovedWithAPasskeySaysSoAndAnyoneCanCheckIt(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        self::assertSame('completed', $this->approveWith($browser, $id, $this->enroll($browser))['status']);
        $public = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $public;

        $badge = $public->request('GET', self::ORIGIN.'/alerts')->filter('.vha-badge');
        self::assertCount(1, $badge, 'The live alert says it was approved with a passkey.');
        self::assertStringStartsWith('Approved with a passkey · ', $badge->filter('span')->last()->text());
        self::assertSame('/approval/'.$id.'/verified', $badge->attr('href'));

        $page = $public->request('GET', self::ORIGIN.'/approval/'.$id.'/verified');
        self::assertSame(200, $public->getResponse()->getStatusCode(), 'Anyone can open the record, without signing in.');
        self::assertSame('Approved with a passkey', $page->filter('h1')->text());
        self::assertStringContainsString('Evacuation order: Riverside Road area', $page->filter('.statement')->text());
        $html = (string) $public->getResponse()->getContent();
        $credential = (string) $this->database->pdo->query("SELECT json_extract(proof, '$.credentialId') FROM verified_human_approval_requests WHERE id = '".$id."'")->fetchColumn();
        foreach (['campaigns', 'editor-personal', '"userId"', $credential] as $private) {
            self::assertStringNotContainsString($private, $html, 'The public record names no account, tenant, connection or passkey.');
        }

        // Live text changed by any other path loses the badge; the original text gets it back.
        $this->database->pdo->exec("UPDATE demo_alerts SET published_body = published_body || ' Edited live.'");
        self::assertCount(0, $public->request('GET', self::ORIGIN.'/alerts')->filter('.vha-badge'));
        $this->database->pdo->exec("UPDATE demo_alerts SET published_body = replace(published_body, ' Edited live.', '')");
        self::assertCount(1, $public->request('GET', self::ORIGIN.'/alerts')->filter('.vha-badge'));

        // A stored record edited afterwards no longer verifies, so neither the badge nor the page vouches for it.
        $this->database->pdo->exec("UPDATE verified_human_approval_executions SET record = replace(record, '\"disposition\":\"published\"', '\"disposition\":\"edited\"') WHERE request_id = '".$id."'");
        self::assertCount(0, $public->request('GET', self::ORIGIN.'/alerts')->filter('.vha-badge'));
        $public->request('GET', self::ORIGIN.'/approval/'.$id.'/verified');
        self::assertSame(404, $public->getResponse()->getStatusCode());
    }

    public function testAnAlertARuleLetRunHasNoPasskeyBadge(): void
    {
        $issued = $this->mcp('manager')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('completed', $issued->structuredContent['status'], 'The emergency manager issues directly.');
        $public = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $public;
        $page = $public->request('GET', self::ORIGIN.'/alerts');
        self::assertSame('Evacuation order: Riverside Road area', $page->filter('.rc-title')->text());
        self::assertCount(0, $page->filter('.vha-badge'), 'Nobody approved it with a passkey, so the page does not say so.');
        $public->request('GET', self::ORIGIN.'/approval/'.$issued->structuredContent['requestId'].'/verified');
        self::assertSame(404, $public->getResponse()->getStatusCode());
    }

    public function testDirectPublicationServiceCannotBypassTheApplicationGuard(): void
    {
        $publisher = new AlertPublisher($this->database, new ExecutionGuard());
        $adapter = new PublishAlert($this->database, $publisher);
        $actor = new Actor('editor', 'campaigns');
        try {
            $publisher->publish($actor, $adapter->prepare($actor, ['alert_id' => 'riverside-evacuation']));
            self::fail('Direct publication should require the approval execution context.');
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason);
        }
        self::assertSame(0, $this->mutationCount());
    }

    public function testCurrentReadScopeAppliesToStatusAndIdempotentResultRetrieval(): void
    {
        $client = $this->mcp();
        $request = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'scope-test']);
        self::assertFalse($request->isError);
        $id = $request->structuredContent['requestId'];
        $this->database->pdo->prepare("UPDATE demo_grants SET scopes = ? WHERE id = 'editor-personal'")
            ->execute([json_encode(['alert.read', 'alert.publish'], \JSON_THROW_ON_ERROR)]);
        $status = $client->callTool('approval_status', ['request_id' => $id]);
        self::assertTrue($status->isError);
        self::assertNull($status->structuredContent);
        $retry = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'scope-test']);
        self::assertTrue($retry->isError);
        self::assertSame('result_read_denied', $retry->structuredContent['reason']);
        self::assertArrayNotHasKey('result', $retry->structuredContent);
        self::assertSame(0, $this->mutationCount());
    }

    public function testRuleChangesTakeTheAdministratorsPasskeyAndInvalidateWaitingRequests(): void
    {
        $id = $this->requestAction();
        $staff = $this->browser('editor');
        $staff->request('GET', self::ORIGIN.'/admin/approval-policy');
        self::assertSame(403, $staff->getResponse()->getStatusCode(), 'County staff cannot change who must verify.');
        $browser = $this->browser('manager');
        $managerKey = $this->enroll($browser, 'manager');
        $crawler = $browser->request('GET', self::ORIGIN.'/admin/approval-policy');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Propose this change')->form();
        $form['approval_policy[statement]'] = 'I approve {{unknown}}';
        $browser->submit($form);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'An unknown placeholder is refused before anyone approves.');
        self::assertSame(0, (int) $this->database->pdo->query("SELECT COUNT(*) FROM verified_human_approval_requests WHERE proposal LIKE '%policy.change%'")->fetchColumn());

        // Proposing a change changes nothing.
        $proposal = $this->proposeRule($browser, ['approval_policy[statement]' => 'I approve exactly “{{title}}” revision {{revision}}.']);
        self::assertSame(1, (int) $this->database->policy('alert.publish')['version'], 'Nothing changes on a password session alone.');
        $review = $browser->request('GET', self::ORIGIN.'/approval/'.$proposal);
        self::assertStringContainsString('Change who can issue public alerts', $review->filter('h1')->text());
        self::assertStringContainsString('The statement approvers confirm', $review->filter('.statement')->first()->text());
        // Next to the button: what the new rule does the moment it is approved, frozen with the proposal.
        self::assertStringContainsString('requests waiting under the old rule can no longer be approved', $review->filter('.approval-sheet .consequence')->text());
        // The administrator approves the exact change with their own passkey.
        self::assertSame('completed', $this->approveWith($browser, $proposal, $managerKey)['status']);
        self::assertSame(2, (int) $this->database->policy('alert.publish')['version']);

        $crawler = $staff->request('GET', self::ORIGIN.'/approval/'.$id);
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $rejected = $this->post($staff, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(409, $staff->getResponse()->getStatusCode());
        self::assertSame('policy_changed', $rejected['reason'], 'A request waiting under the old rule can no longer be approved.');
        self::assertSame(0, $this->mutationCount());

        // Letting staff issue directly is a rule change too: it waits for the administrator's passkey.
        $direct = $this->proposeRule($browser, ['approval_policy[staff]' => 'direct']);
        self::assertSame('pending', $this->mcp()->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['status'], 'Until approved, staff still verify.');
        self::assertSame('completed', $this->approveWith($browser, $direct, $managerKey)['status']);
        $newRequest = $this->mcp()->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'after-the-change']);
        self::assertSame('completed', $newRequest->structuredContent['status']);
        self::assertSame(1, $this->mutationCount());
    }

    public function testAnAgentAndAStaffPasswordCannotChangeTheRule(): void
    {
        $agent = $this->mcp('manager');
        self::assertNotContains('policy.change', array_map(static fn ($tool) => $tool->name, $agent->listTools()->tools));
        $this->database->pdo->exec("UPDATE demo_users SET is_admin = 0 WHERE id = 'manager'");
        $browser = $this->browser('manager');
        $browser->request('GET', self::ORIGIN.'/admin/approval-policy');
        self::assertSame(403, $browser->getResponse()->getStatusCode());
    }

    public function testBinaryReceiptKeysCannotBeInterpretedAsContainerParameters(): void
    {
        $settings = json_decode(file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
        $keypair = sodium_crypto_sign_seed_keypair(str_pad('%missing_parameter%', 32, "\0"));
        $secret = sodium_crypto_sign_secretkey($keypair);
        self::assertStringContainsString('%missing_parameter%', $secret);
        $settings['receipt_secret_key'] = ChallengeBinding::encode($secret);
        $settings['receipt_public_key'] = ChallengeBinding::encode(sodium_crypto_sign_publickey($keypair));
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));
        self::assertCount(4, $this->mcp()->listTools()->tools);
    }

    public function testBrowserApprovalAttemptsAreBoundedAcrossRequestsWithoutExecuting(): void
    {
        $settings = json_decode(file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
        $settings['approval_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf] = $this->begin($browser, $id);
        $second = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $limited = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(429, $browser->getResponse()->getStatusCode());
        self::assertSame('rate_limited', $limited['status']);
        self::assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
        $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $second['ceremony_id'], 'credential' => $authenticator->assertion($second['publicKey'])]);
        self::assertSame(429, $browser->getResponse()->getStatusCode());
        self::assertSame(0, $this->mutationCount());
        self::assertSame(2, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_ceremonies')->fetchColumn());
    }

    public function testEnrollmentAttemptsAreBoundedWithoutRegisteringAnything(): void
    {
        $settings = json_decode(file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
        $settings['enrollment_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));
        $browser = $this->browser('editor');
        $browser->request('GET', $this->accounts['editor']['passkey_link']);
        $crawler = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $limited = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(429, $browser->getResponse()->getStatusCode());
        self::assertSame('rate_limited', $limited['status']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_credentials')->fetchColumn());
    }

    public function testFailedLoginAttemptsAreThrottledBeforeAValidPasswordCanBeUsed(): void
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $crawler = $browser->request('GET', self::ORIGIN.'/login');
            $browser->submit($crawler->selectButton('Sign in')->form(['_username' => 'editor', '_password' => 'incorrect-password']));
            self::assertSame(302, $browser->getResponse()->getStatusCode());
        }
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => 'editor', '_password' => $this->accounts['editor']['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertStringEndsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->mutationCount());
    }

    private function requestAction(): string
    {
        $result = $this->mcp()->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertFalse($result->isError, json_encode($result->structuredContent));

        return $result->structuredContent['requestId'];
    }

    public function testTheAdministratorsRulePerRoleDecidesWhoMustVerify(): void
    {
        $staff = $this->mcp('editor')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('pending', $staff->structuredContent['status'], 'County staff must verify each exact alert.');
        self::assertSame(0, $this->mutationCount());
        $reader = $this->mcp('reader')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertTrue($reader->isError, 'A read-only colleague cannot issue alerts.');
        self::assertSame(0, $this->mutationCount());
        $manager = $this->mcp('manager')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('completed', $manager->structuredContent['status'], 'The emergency manager issues directly.');
        self::assertSame(1, $this->mutationCount());
    }

    public function testAnActionThatNeededNoPasskeyNeverSaysAPasskeyApprovedIt(): void
    {
        $issued = $this->mcp('manager')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('completed', $issued->structuredContent['status'], 'The emergency manager issues directly.');
        self::assertFalse($issued->structuredContent['approval']['approvedWithPasskey']);
        $sheet = $this->browser('manager')->request('GET', self::ORIGIN.'/approval/'.$issued->structuredContent['requestId'])->filter('.approval-sheet')->text();
        self::assertStringNotContainsString('Approved with your passkey', $sheet);
        self::assertStringContainsString('Completed without a passkey', $sheet);
        // The receipt is signed all the same, and its description claims no passkey signature.
        self::assertNotEmpty($issued->structuredContent['receipt']['signature']);
        self::assertStringContainsString('Your copy of this exact request and what ran', $sheet);
        self::assertStringNotContainsString('passkey’s signature', $sheet);
    }

    public function testTheApprovalPageShowsTheFinalPublicPageWhichGoesLiveUnchanged(): void
    {
        $public = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $public;
        $calm = $public->request('GET', self::ORIGIN.'/alerts');
        self::assertSame(200, $public->getResponse()->getStatusCode());
        self::assertSame('No active alerts', $calm->filter('.rc-title')->text(), 'Nothing is live before approval.');

        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        $review = $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('.rc-frame');
        self::assertCount(1, $review, 'The approval page shows the final public page.');
        self::assertStringContainsString(self::ORIGIN.'/alerts', $review->filter('.rc-frame-url')->text());
        $approvedTitle = $review->filter('.rc-title')->text();
        $approvedBody = $review->filter('.rc-body')->text();
        self::assertSame('Evacuation order: Riverside Road area', $approvedTitle);

        [$csrf, $ceremony] = $this->begin($browser, $id);
        $completed = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame('completed', $completed['status']);

        $live = $public->request('GET', self::ORIGIN.'/alerts');
        self::assertSame(200, $public->getResponse()->getStatusCode());
        self::assertSame($approvedTitle, $live->filter('.rc-title')->text());
        self::assertSame($approvedBody, $live->filter('.rc-body')->text());
        self::assertSame(1, $this->mutationCount());
    }

    public function testChangingThePublicPageLayoutAfterReviewInvalidatesTheApproval(): void
    {
        $site = \dirname(__DIR__, 2).'/demo';
        $id = $this->requestAction();
        $this->enroll($this->browser('editor'));
        // A deploy changes the alerts page's layout after the person reviewed it: the site now serves a
        // changed copy. The repository's own template is never touched.
        $changed = $this->runtime.'/deployed-page.html.twig';
        file_put_contents($changed, file_get_contents($site.'/templates/public/_page.html.twig')."{# layout changed after review #}\n");
        $settings = json_decode((string) file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
        $settings['layout_files'] = [$changed, $site.'/assets/riverside.css'];
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));

        $browser = $this->browser('editor');
        $csrf = (string) $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $refused = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(409, $browser->getResponse()->getStatusCode());
        self::assertSame('implementation_changed', $refused['reason']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testProtectedToolLinksTheApprovalCardWhichTheServerServes(): void
    {
        $client = $this->mcp();
        $tools = [];
        foreach ($client->listTools()->tools as $tool) {
            $tools[$tool->name] = json_decode(json_encode($tool, \JSON_THROW_ON_ERROR), true);
        }
        self::assertSame(ApprovalCard::URI, $tools['publish_alert']['_meta']['ui']['resourceUri']);
        self::assertArrayNotHasKey('ui', $tools['approval_status']['_meta'] ?? []);
        $resources = [];
        foreach ($client->listResources()->resources as $resource) {
            $resources[$resource->uri] = json_decode(json_encode($resource, \JSON_THROW_ON_ERROR), true);
        }
        self::assertSame(McpApps::MIME_TYPE, $resources[ApprovalCard::URI]['mimeType']);
        $card = json_decode(json_encode($client->readResource(ApprovalCard::URI)->contents[0], \JSON_THROW_ON_ERROR), true);
        self::assertSame(McpApps::MIME_TYPE, $card['mimeType']);
        self::assertStringContainsString('ui/open-link', $card['text']);
        self::assertStringNotContainsString('navigator.credentials', $card['text']);
        $capabilities = json_decode(json_encode($client->getServerCapabilities(), \JSON_THROW_ON_ERROR), true);
        self::assertArrayHasKey(McpApps::EXTENSION_ID, $capabilities['extensions'] ?? []);

        $pending = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        $view = $pending->structuredContent['approval'];
        self::assertSame($pending->structuredContent['requestId'], $view['requestId']);
        self::assertSame('pending', $view['status']);
        self::assertSame($pending->structuredContent['review_url'], $view['reviewUrl']);
        self::assertSame('approval_status', $view['statusTool']);
        self::assertStringContainsString('I approve issuing the public alert', $view['statement']);
        $status = $client->callTool('approval_status', ['request_id' => $view['requestId']]);
        self::assertSame('pending', $status->structuredContent['approval']['status']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testClaudeCodeGetsAUrlPromptWhoseAcceptIsNotAnApproval(): void
    {
        $first = $this->modernCall(['alert_id' => 'riverside-evacuation']);
        self::assertSame('input_required', $first['resultType'] ?? null, json_encode($first));
        $ask = $first['inputRequests'][UrlPrompt::KEY];
        self::assertSame('elicitation/create', $ask['method']);
        self::assertSame('url', $ask['params']['mode']);
        self::assertStringStartsWith(self::ORIGIN.'/approval/apr_', $ask['params']['url']);
        self::assertStringContainsString('I approve issuing the public alert', $ask['params']['message']);
        $id = substr($ask['params']['url'], \strlen(self::ORIGIN.'/approval/'));

        $accepted = $this->modernCall(['alert_id' => 'riverside-evacuation'], extra: $this->answer($first, 'accept'));
        self::assertFalse($accepted['isError'] ?? false, json_encode($accepted));
        self::assertSame($id, $accepted['structuredContent']['requestId']);
        self::assertSame('pending', $accepted['structuredContent']['status']);
        self::assertStringContainsString('not an approval', $accepted['structuredContent']['message']);
        self::assertSame(1, $this->pendingRequests(), 'Re-entering the handler must not create a second proposal.');
        self::assertSame(0, $this->mutationCount());
    }

    public function testADeclinedPromptLeavesTheRequestOpenOnTheSiteAndChangesNothing(): void
    {
        $first = $this->modernCall(['alert_id' => 'riverside-evacuation']);
        $declined = $this->modernCall(['alert_id' => 'riverside-evacuation'], extra: $this->answer($first, 'decline'));
        self::assertSame('pending', $declined['structuredContent']['status']);
        self::assertStringContainsString('nothing was approved or changed', $declined['structuredContent']['message']);
        self::assertStringStartsWith(self::ORIGIN.'/approval/', $declined['structuredContent']['review_url']);
        self::assertSame(0, $this->mutationCount());

        $id = $declined['structuredContent']['requestId'];
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $completed = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame('completed', $completed['status']);
        self::assertSame(1, $this->mutationCount());
    }

    public function testARetryWithChangedArgumentsCannotContinueTheApproval(): void
    {
        $first = $this->modernCall(['alert_id' => 'riverside-evacuation']);
        $changed = $this->modernCall(['alert_id' => 'riverside-evacuation', 'request_key' => 'another-request'], extra: $this->answer($first, 'accept'));
        self::assertTrue($changed['isError'] ?? false, json_encode($changed));
        self::assertSame('continuation_mismatch', $changed['structuredContent']['reason']);
        self::assertSame(1, $this->pendingRequests());
        self::assertSame(0, $this->mutationCount());
    }

    public function testARetryAfterThePasskeyApprovalReadsTheRecordedOutcomeOnce(): void
    {
        $first = $this->modernCall(['alert_id' => 'riverside-evacuation']);
        $id = substr($first['inputRequests'][UrlPrompt::KEY]['params']['url'], \strlen(self::ORIGIN.'/approval/'));
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame(1, $this->mutationCount());

        foreach ([1, 2] as $attempt) {
            $after = $this->modernCall(['alert_id' => 'riverside-evacuation'], extra: $this->answer($first, 'accept'));
            self::assertSame('completed', $after['structuredContent']['status'], 'attempt '.$attempt);
            self::assertSame('published', $after['structuredContent']['result']['disposition']);
            self::assertSame('completed', $after['structuredContent']['approval']['status']);
        }
        self::assertSame(1, $this->mutationCount());
    }

    public function testAClientTheApplicationHasNotListedGetsTheCardAndLinkOnly(): void
    {
        $result = $this->modernCall(['alert_id' => 'riverside-evacuation'], client: 'another-client');
        self::assertNotSame('input_required', $result['resultType'] ?? null);
        self::assertSame('pending', $result['structuredContent']['status']);
        self::assertSame('approval_status', $result['structuredContent']['approval']['statusTool']);
        self::assertSame(0, $this->mutationCount());
    }

    /** A raw MCP 2026-07-28 tools/call, shaped like Claude Code's, through a fresh kernel. */
    private function modernCall(array $arguments, string $client = 'claude-code', array $extra = [], string $user = 'editor'): array
    {
        $params = ['name' => 'publish_alert', 'arguments' => (object) $arguments, '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['url' => new \stdClass()]],
            'io.modelcontextprotocol/clientInfo' => ['name' => $client, 'version' => '2.1.317'],
        ]] + $extra;
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', self::ORIGIN.'/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('Authorization', 'Bearer '.$this->accounts[$user]['personal_token'])
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', 'publish_alert')
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withBody($factory->createStream(json_encode(['jsonrpc' => '2.0', 'id' => random_int(1, 1_000_000), 'method' => 'tools/call', 'params' => $params], \JSON_THROW_ON_ERROR)));
        $body = (string) (new SymfonyKernelHttpClient($this->runtime))->sendRequest($request)->getBody();
        if (1 === preg_match('/^data: (.+)$/m', $body, $match)) {
            $body = $match[1];
        }
        $message = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $message, $body);

        return $message['result'];
    }

    private function answer(array $asked, string $action): array
    {
        return ['inputResponses' => [UrlPrompt::KEY => ['action' => $action]], 'requestState' => $asked['requestState']];
    }

    private function pendingRequests(): int
    {
        return (int) (new \PDO('sqlite:'.$this->runtime.'/application.sqlite'))->query("SELECT COUNT(*) FROM verified_human_approval_requests WHERE status = 'pending'")->fetchColumn();
    }

    private function browser(string $user): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return $browser;
    }

    private function enroll(KernelBrowser $browser, string $user = 'editor'): VirtualAuthenticator
    {
        $browser->request('GET', $this->accounts[$user]['passkey_link']);
        self::assertSame(302, $browser->getResponse()->getStatusCode(), 'The one-time passkey link authorizes a first passkey.');
        $crawler = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        $result = $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $authenticator->registration($options['publicKey'])]);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame('registered', $result['status']);

        return $authenticator;
    }

    /** @param array<string, string> $fields */
    private function proposeRule(KernelBrowser $browser, array $fields): string
    {
        $crawler = $browser->request('GET', self::ORIGIN.'/admin/approval-policy');
        $form = $crawler->selectButton('Propose this change')->form();
        foreach ($fields as $name => $value) {
            str_ends_with($name, '[statement]') ? $form[$name] = $value : $form[$name]->select($value);
        }
        $browser->submit($form);
        self::assertSame(302, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        self::assertSame(1, preg_match('#/approval/([^/?]+)#', (string) $browser->getResponse()->headers->get('Location'), $match));

        return $match[1];
    }

    private function approveWith(KernelBrowser $browser, string $id, VirtualAuthenticator $authenticator): array
    {
        [$csrf, $ceremony] = $this->begin($browser, $id);

        return $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
    }

    private function begin(KernelBrowser $browser, string $id): array
    {
        $crawler = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $browser->getResponse()->headers->get('Content-Security-Policy'));
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return [$csrf, $ceremony];
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
        $value = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($value) ? $value : [];
    }

    private function mcp(string $user = 'editor'): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('verified-human-approval-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp',
            headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']],
            httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory,
        ));
        $this->clients[] = $client;

        return $client;
    }

    private function mutationCount(): int
    {
        return (int) $this->database->alert('riverside-evacuation')['mutation_count'];
    }
}
