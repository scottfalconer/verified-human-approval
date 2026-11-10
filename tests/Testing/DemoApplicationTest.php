<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Testing\KernelHttpClient;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

/**
 * The testing package against a whole application: the demo's kernel, firewall, pages and MCP endpoint, reached
 * only through what the package ships. This is the test a host writes for a protected action of its own.
 */
final class DemoApplicationTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private const ALERT = 'riverside-evacuation';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<Client> */ private array $clients = [];
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-testing-test-'.bin2hex(random_bytes(8));
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
        (new Filesystem())->remove($this->runtime);
        // The demo trusts its own host only, and Symfony keeps that for the whole process.
        Request::setTrustedHosts([]);
    }

    public function testAPersonRegistersAPasskeyAndApprovesWhatTheAgentAskedFor(): void
    {
        $this->expectOutputString('');
        $browser = $this->signedIn('editor');
        $passkey = new VirtualAuthenticator(self::ORIGIN);

        // The demo lets a new account add its first passkey once its one-time link was opened.
        $browser->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $account = PasskeyPanel::open($browser, self::ORIGIN.'/account/passkeys');
        self::assertSame('registration', $account->mode());
        $registered = $account->register($passkey);
        self::assertSame('registered', $registered->status, (string) $registered);

        // The agent asks through the MCP endpoint, with its bearer token. Nothing is published yet.
        $agent = $this->agent('editor');
        $pending = $agent->callTool('publish_alert', ['alert_id' => self::ALERT]);
        self::assertFalse($pending->isError, json_encode($pending->content));
        self::assertSame('pending', $pending->structuredContent['status']);
        self::assertSame(0, $this->mutationCount());
        $id = $pending->structuredContent['requestId'];
        $reviewUrl = $pending->structuredContent['review_url'];
        self::assertSame(self::ORIGIN.'/approval/'.$id, $reviewUrl);

        // The person opens the address the agent was given, and approves with their passkey.
        $review = PasskeyPanel::open($browser, $reviewUrl);
        self::assertSame('approval', $review->mode());
        self::assertTrue($review->canCancel());
        $answer = $review->approve($passkey);
        self::assertFalse($answer->refused(), (string) $answer);
        self::assertSame('completed', $answer->status, (string) $answer);
        self::assertSame('published', $answer->data['result']['disposition']);
        self::assertSame(1, $this->mutationCount());

        // The browser is left on the page as it now stands: approved, and with nothing more to approve.
        self::assertSame($reviewUrl, $browser->getInternalRequest()->getUri());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertNull(PasskeyPanel::find($browser));
        self::assertStringContainsString('Approved with your passkey', $browser->getCrawler()->filter('.approval-sheet')->text());

        // The agent reads the outcome from the application, not from the person.
        $status = $agent->callTool('approval_status', ['request_id' => $id]);
        self::assertSame('completed', $status->structuredContent['status']);
        self::assertTrue($status->structuredContent['approval']['approvedWithPasskey']);

        // The receipt the page offers checks against the keys the application publishes.
        $download = $browser->getCrawler()->filter('.approval-sheet .receipt a.button');
        $browser->request('GET', self::ORIGIN.$download->attr('href'));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $receipt = (string) $browser->getResponse()->getContent();
        $anyone = $this->browser();
        $anyone->request('GET', self::ORIGIN.'/approval/receipt-keys.json');
        self::assertSame(200, $anyone->getResponse()->getStatusCode());
        $keys = TrustedKeys::fromDocument(json_decode((string) $anyone->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR));
        $verified = (new ReceiptVerifier())->verify(CanonicalJson::map(CanonicalJson::decode($receipt)), $keys);
        self::assertSame(Receipt::COMMIT, $verified['receiptSignedAt']);
    }

    public function testTheAccountPagesPanelsRegisterConfirmAndRegisterAgain(): void
    {
        $browser = $this->signedIn('editor');
        $first = new VirtualAuthenticator(self::ORIGIN);
        $second = new VirtualAuthenticator(self::ORIGIN);

        // A password alone adds no passkey: the page has no panel until the one-time link was opened.
        $browser->request('GET', self::ORIGIN.'/account/passkeys');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertNull(PasskeyPanel::find($browser));

        $browser->request('GET', $this->accounts['editor']['passkey_link']);
        $registered = PasskeyPanel::open($browser, self::ORIGIN.'/account/passkeys')->register($first);
        self::assertSame('registered', $registered->status, (string) $registered);

        // The link is used up. The page, loaded again, now asks an enrolled passkey to confirm before another is added.
        $confirm = PasskeyPanel::on($browser);
        self::assertSame('confirm', $confirm->mode());
        self::assertFalse($confirm->canCancel());
        $stranger = $confirm->approve($second);
        self::assertTrue($stranger->refused(), (string) $stranger);
        self::assertSame('rejected', $stranger->status, 'A passkey the account never registered confirms nothing.');
        $confirmed = PasskeyPanel::on($browser)->approve($first);
        self::assertSame('confirmed', $confirmed->status, (string) $confirmed);

        $again = PasskeyPanel::on($browser);
        self::assertSame('registration', $again->mode());
        $registered = $again->register($second);
        self::assertSame('registered', $registered->status, (string) $registered);
        self::assertSame('confirm', PasskeyPanel::on($browser)->mode());
        self::assertSame(2, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_credentials')->fetchColumn());

        // Either passkey now approves.
        $id = $this->askedFor($this->agent('editor'));
        $answer = PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$id)->approve($second);
        self::assertSame('completed', $answer->status, (string) $answer);
    }

    public function testAChangeWhileThePersonLooksAtTheirDeviceInvalidatesTheRequest(): void
    {
        [$browser, $passkey] = $this->withAPasskey('editor');
        $agent = $this->agent('editor');
        $id = $this->askedFor($agent);

        // The two halves of approve(), with the draft edited in between.
        $panel = PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$id);
        $ceremony = $panel->begin();
        self::assertFalse($ceremony->refused(), (string) $ceremony);
        $this->editTheDraft();
        $answer = $panel->complete($ceremony->data['ceremony_id'], $passkey->assertion($ceremony->data['publicKey']));

        self::assertFalse($answer->refused(), 'The application answers HTTP 200: the status is what says the action did not run.');
        self::assertSame(200, $answer->httpStatus);
        self::assertSame('invalidated', $answer->status, (string) $answer);
        self::assertSame('stale_resource', $answer->reason);
        self::assertSame(0, $this->mutationCount());
        self::assertNull(PasskeyPanel::find($browser), 'The page, loaded again, offers nothing to approve.');
        self::assertSame('invalidated', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['status']);
    }

    public function testAChangeBeforeThePersonApprovesIsRefusedBeforeAnythingIsSigned(): void
    {
        [$browser, $passkey] = $this->withAPasskey('editor');
        $id = $this->askedFor($this->agent('editor'));
        $panel = PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$id);
        $this->editTheDraft();

        $answer = $panel->approve($passkey);

        self::assertTrue($answer->refused(), (string) $answer);
        self::assertSame(409, $answer->httpStatus);
        self::assertSame('rejected', $answer->status);
        self::assertSame('stale_resource', $answer->reason);
        self::assertSame('invalidated', $answer->data['request_status']);
        self::assertSame(0, $this->mutationCount());
        self::assertNull(PasskeyPanel::find($browser));
    }

    public function testAPersonWithoutAPasskeyIsRefusedAndTheRequestStaysPending(): void
    {
        $browser = $this->signedIn('editor');
        $id = $this->askedFor($this->agent('editor'));

        $answer = PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$id)->approve(new VirtualAuthenticator(self::ORIGIN));

        self::assertTrue($answer->refused(), (string) $answer);
        self::assertSame(409, $answer->httpStatus);
        self::assertSame('credential_required', $answer->reason);
        self::assertSame('pending', $answer->data['request_status']);
        self::assertSame(0, $this->mutationCount());
        self::assertSame('approval', PasskeyPanel::on($browser)->mode(), 'Still theirs to approve, once they have a passkey.');
    }

    public function testCancellingSettlesTheRequestWithoutPublishing(): void
    {
        [$browser, $passkey] = $this->withAPasskey('editor');
        $agent = $this->agent('editor');
        $id = $this->askedFor($agent);

        $answer = PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$id)->cancel();

        self::assertFalse($answer->refused(), (string) $answer);
        self::assertSame('cancelled', $answer->status);
        self::assertSame('cancelled_by_user', $answer->reason);
        self::assertSame(0, $this->mutationCount());
        self::assertNull(PasskeyPanel::find($browser));
        self::assertSame('cancelled', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['status']);

        // A new request for the same alert is a new decision, and the passkey still approves it.
        $next = $agent->callTool('publish_alert', ['alert_id' => self::ALERT, 'request_key' => 'after-cancelling'])->structuredContent['requestId'];
        self::assertNotSame($id, $next);
        self::assertSame('completed', PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$next)->approve($passkey)->status);
        self::assertSame(1, $this->mutationCount());
    }

    public function testAttemptsPastTheLimitAreRefusedAndTheRequestStaysPending(): void
    {
        $settings = json_decode((string) file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
        $settings['approval_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));
        [$browser, $passkey] = $this->withAPasskey('editor');
        $id = $this->askedFor($this->agent('editor'));
        $panel = PasskeyPanel::open($browser, self::ORIGIN.'/approval/'.$id);
        self::assertFalse($panel->begin()->refused());
        self::assertFalse($panel->begin()->refused());

        $answer = $panel->approve($passkey);

        self::assertTrue($answer->refused(), (string) $answer);
        self::assertSame(429, $answer->httpStatus);
        self::assertSame('rate_limited', $answer->status);
        self::assertSame(0, $this->mutationCount());
        self::assertSame('approval', PasskeyPanel::on($browser)->mode());
    }

    public function testAPageThatIsNotThePersonsToApproveSaysWhatTheApplicationAnswered(): void
    {
        $id = $this->askedFor($this->agent('editor'));
        $page = self::ORIGIN.'/approval/'.$id;

        try {
            PasskeyPanel::open($this->browser(), $page);
            self::fail('A browser nobody is signed in to found a panel.');
        } catch (\RuntimeException $signedOut) {
            self::assertSame('No passkey panel ([data-passkey-panel]) on the page: GET '.$page.' answered HTTP 302, redirecting to '.self::ORIGIN.'/login. Is the person signed in in this browser, and is the request still pending?', $signedOut->getMessage());
        }

        try {
            PasskeyPanel::open($this->signedIn('reader'), $page);
            self::fail('A colleague found a panel on a request that is not theirs.');
        } catch (\RuntimeException $notTheirs) {
            self::assertSame('No passkey panel ([data-passkey-panel]) on the page: GET '.$page.' answered HTTP 404. Is the person signed in in this browser, and is the request still pending?', $notTheirs->getMessage());
        }
        self::assertSame(0, $this->mutationCount());
    }

    public function testAPhoneThatCameThroughACodeApprovesAndCannotCancel(): void
    {
        [, $passkey] = $this->withAPasskey('editor');
        $agent = $this->agent('editor');
        $id = $this->askedFor($agent);
        $code = $agent->callTool('approval_handoff', ['request_id' => $id]);
        self::assertFalse($code->isError, json_encode($code->content));

        // The phone is signed in to nothing: the code opens this one request.
        $phone = $this->browser();
        $continue = $phone->request('GET', $code->structuredContent['url']);
        $phone->submit($continue->selectButton('Continue')->form());
        self::assertSame(303, $phone->getResponse()->getStatusCode());
        $phone->followRedirect();
        self::assertStringEndsWith('/approval/'.$id.'#return=handoff', $phone->getInternalRequest()->getUri());

        $panel = PasskeyPanel::on($phone);
        self::assertSame('approval', $panel->mode());
        self::assertFalse($panel->canCancel(), 'Whoever holds the code can approve with the person’s passkey, and nothing else.');
        try {
            $panel->cancel();
            self::fail('The phone cancelled a request its page offers no button to cancel.');
        } catch (\LogicException $noButton) {
            self::assertStringContainsString('has no cancel button ([data-cancel])', $noButton->getMessage());
        }

        $answer = $panel->approve($passkey);

        self::assertSame('completed', $answer->status, (string) $answer);
        self::assertSame(1, $this->mutationCount());
        self::assertNull(PasskeyPanel::find($phone));
        self::assertSame('Download your receipt from this request’s page in your signed-in browser.', $phone->getCrawler()->filter('.approval-sheet .receipt')->text());
        self::assertSame('completed', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['status']);
    }

    private function browser(): KernelBrowser
    {
        return $this->browsers[] = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'alerts.example', 'HTTPS' => 'on']);
    }

    private function signedIn(string $user): KernelBrowser
    {
        $browser = $this->browser();
        $login = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($login->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return $browser;
    }

    /**
     * A browser the person is signed in to, and the passkey they registered there through the demo's own page.
     *
     * @return array{KernelBrowser, VirtualAuthenticator}
     */
    private function withAPasskey(string $user): array
    {
        $browser = $this->signedIn($user);
        $browser->request('GET', $this->accounts[$user]['passkey_link']);
        $passkey = new VirtualAuthenticator(self::ORIGIN);
        $registered = PasskeyPanel::open($browser, self::ORIGIN.'/account/passkeys')->register($passkey);
        self::assertSame('registered', $registered->status, (string) $registered);

        return [$browser, $passkey];
    }

    /** An MCP client holding the person's agent token, whose requests the application's kernel answers in this process. */
    private function agent(string $user): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('testing-package-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp',
            headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']],
            httpClient: new KernelHttpClient(fn (): DemoKernel => new DemoKernel($this->runtime, 'test'), $factory, $factory),
            requestFactory: $factory,
            streamFactory: $factory,
        ));

        return $this->clients[] = $client;
    }

    /** Asks, as the agent, for the alert to be published; the identifier of the request that now waits for the person. */
    private function askedFor(Client $agent): string
    {
        $pending = $agent->callTool('publish_alert', ['alert_id' => self::ALERT]);
        self::assertSame('pending', $pending->structuredContent['status'] ?? null, json_encode($pending->content));

        return $pending->structuredContent['requestId'];
    }

    /** What makes the request stale: the draft is no longer the one the person was shown. */
    private function editTheDraft(): void
    {
        $this->database->pdo->exec("UPDATE demo_alerts SET body = body || '!', revision = revision + 1 WHERE id = '".self::ALERT."'");
    }

    private function mutationCount(): int
    {
        return (int) $this->database->alert(self::ALERT)['mutation_count'];
    }
}
