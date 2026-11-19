<?php

declare(strict_types=1);

namespace App\Tests;

use App\Account\PasskeyEnrollment;
use App\Entity\Page;
use App\Kernel;
use App\Setup\Installer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Result\CallToolResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\Testing\KernelHttpClient;
use VerifiedHumanApproval\Testing\PanelAnswer;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * Application acceptance, using installed package APIs only.
 * Software authenticators generate real signatures for the production verifier; these tests do not prove a
 * physical person, device prompt, browser JavaScript implementation, or hosted MCP-client interoperability.
 */
final class ApplicationIntegrationTest extends TestCase
{
    private const ORIGIN = 'https://editorial.test';

    private string $runtime;
    private Connection $database;
    /** @var array<string, array{password: string, token: string, passkey_link: string}> */
    private array $accounts;
    /** @var array<string, array{process: string|false, server: mixed, env: mixed, hadServer: bool, hadEnv: bool}> */
    private array $environment = [];
    /** @var list<Kernel> */
    private array $kernels = [];
    /** @var array<string, KernelBrowser> */
    private array $browsers = [];
    /** @var array<string, Client> */
    private array $clients = [];
    private ?VirtualAuthenticator $passkey = null;

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/editorial-acceptance-'.bin2hex(random_bytes(8));
        mkdir($this->runtime.'/state', 0700, true);
        $cache = sys_get_temp_dir().'/editorial-acceptance-cache-'.getmypid();
        $this->setEnvironment([
            'APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_ORIGIN' => self::ORIGIN,
            'APP_CACHE_DIR' => $cache, 'APP_LOG_DIR' => $this->runtime.'/log',
            'APP_STATE_DIR' => $this->runtime.'/state', 'APP_SECRET' => bin2hex(random_bytes(32)),
            'DATABASE_URL' => 'sqlite:///'.$this->runtime.'/application.sqlite',
            'APPROVAL_RECEIPT_KEY_ID' => 'application-acceptance',
            'APPROVAL_RECEIPT_SECRET_KEY' => ChallengeBinding::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            'APPROVAL_RECEIPT_KEY_VALID_FROM' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
            'MCP_REQUEST_STATE_KEY' => bin2hex(random_bytes(32)),
        ]);
        $this->accounts = $this->container()->get(Installer::class)->install();
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->runtime.'/application.sqlite']);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // Some scenarios deliberately revoked the connection before disconnecting it.
            }
        }
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        if (isset($this->database)) {
            $this->database->close();
        }
        if (isset($this->runtime)) {
            (new Filesystem())->remove($this->runtime);
        }
        foreach ($this->environment as $name => $old) {
            false === $old['process'] ? putenv($name) : putenv($name.'='.$old['process']);
            if ($old['hadServer']) {
                $_SERVER[$name] = $old['server'];
            } else {
                unset($_SERVER[$name]);
            }
            if ($old['hadEnv']) {
                $_ENV[$name] = $old['env'];
            } else {
                unset($_ENV[$name]);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function channels(): iterable
    {
        yield 'web forms' => ['web'];
        yield 'MCP SDK' => ['mcp'];
    }

    public function testTrustedEnrollmentBindsTheAccountAndConsumesOnlyAValidCeremony(): void
    {
        $editor = $this->browser();
        $page = $editor->request('GET', self::ORIGIN.'/account/passkeys');
        self::assertSame(0, $page->filter('[data-mode="registration"]')->count(), 'A password session alone cannot enroll a passkey.');
        $reader = $this->browser('reader');
        $reader->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertSame(410, $reader->getResponse()->getStatusCode(), 'An enrollment grant belongs to one account.');
        $this->database->executeStatement('UPDATE app_enrollment_links SET expires_at = ? WHERE user_id = ?', [time() - 1, 'editor']);
        $editor->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertSame(410, $editor->getResponse()->getStatusCode(), 'Expired grants cannot authorize registration.');

        $token = $this->container()->get(PasskeyEnrollment::class)->issueLink('editor', 'acceptance operator');
        $link = self::ORIGIN.'/account/passkeys/link/'.$token;
        $editor->request('GET', $link);
        self::assertTrue($editor->getResponse()->isRedirect());
        $editor->followRedirect();
        $editor->request('POST', self::ORIGIN.'/account/passkeys/options', [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'invalid']);
        self::assertSame(403, $editor->getResponse()->getStatusCode(), 'A valid grant does not replace the CSRF check.');
        $key = new VirtualAuthenticator(self::ORIGIN);
        $panel = PasskeyPanel::open($editor, self::ORIGIN.'/account/passkeys');
        $ceremony = $panel->begin();
        $mismatch = $panel->complete(str_repeat('0', 32), $key->registration($ceremony->data['publicKey']));
        self::assertSame('rejected', $mismatch->status, 'A credential is bound to the saved browser ceremony.');
        self::assertNull($this->database->fetchOne('SELECT used_at FROM app_enrollment_links WHERE token_hash = ?', [hash('sha256', $token)]));
        $panel = PasskeyPanel::open($editor, self::ORIGIN.'/account/passkeys');
        $ceremony = $panel->begin();
        $invalid = $panel->complete($ceremony->data['ceremony_id'], $key->registration($ceremony->data['publicKey'], ['origin' => 'https://other.test']));
        self::assertSame('rejected', $invalid->status);
        self::assertNull($this->database->fetchOne('SELECT used_at FROM app_enrollment_links WHERE token_hash = ?', [hash('sha256', $token)]), 'Credential verification and grant consumption roll back together.');
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::CREDENTIALS));

        $registered = PasskeyPanel::open($editor, self::ORIGIN.'/account/passkeys')->register($key);
        self::assertSame('registered', $registered->status, (string) $registered);
        self::assertNotNull($this->database->fetchOne('SELECT used_at FROM app_enrollment_links WHERE token_hash = ?', [hash('sha256', $token)]));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::CREDENTIALS));
        $editor->request('GET', $link);
        self::assertSame(410, $editor->getResponse()->getStatusCode(), 'A consumed enrollment grant cannot register another passkey.');
    }

    public function testTheHostSignalsExpiredJsonCeremoniesAndReturnsLoginToTheExistingRequest(): void
    {
        $id = $this->createDraft('web', 'Session recovery', 'This request must remain pending.');
        $request = $this->ask('web', 'publish', $id);
        $browser = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'editorial.test', 'HTTPS' => 'on']);
        $this->browsers['expired'] = $browser;
        foreach (['/approval/'.$request.'/options', '/approval/'.$request.'/complete', '/account/passkeys/options', '/account/passkeys/complete'] as $path) {
            $browser->request('POST', self::ORIGIN.$path, [], [], ['HTTP_ACCEPT' => 'application/json']);
            self::assertSame(401, $browser->getResponse()->getStatusCode(), $path);
            self::assertSame(['status' => 'authentication_required'], json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        }
        self::assertSame(0, $this->executionsFor($request));
        $browser->request('GET', self::ORIGIN.'/approval/'.$request);
        self::assertTrue($browser->getResponse()->isRedirect());
        $login = $browser->followRedirect();
        $browser->submit($login->selectButton('Sign in')->form(['_username' => 'editor', '_password' => $this->accounts['editor']['password']]));
        self::assertSame('/approval/'.$request, parse_url((string) $browser->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        $page = $browser->followRedirect();
        self::assertSame(1, $page->filter('[data-status="pending"]')->count());
        self::assertSame(0, $this->executionsFor($request));
        $this->assertPublicMissing($id);
    }

    public function testAnEnrollmentAnchorWriteFailureRollsBackTheCredentialAndTrustedGrant(): void
    {
        $container = $this->container();
        $enrollment = $container->get(PasskeyEnrollment::class);
        $actor = new Actor('editor', 'editorial');
        $token = $enrollment->issueLink('editor', 'acceptance operator');
        $options = $container->get(WebAuthnVerifier::class)->registrationOptions($actor, random_bytes(32), 'Editor');
        $key = new VirtualAuthenticator(self::ORIGIN);
        $response = $key->registration($options);
        // This failure occurs after WebAuthn inserted the credential, in the signed enrollment anchor write.
        $this->database->executeStatement('CREATE TRIGGER refuse_test_anchor BEFORE INSERT ON '.ApprovalSchema::AUDIT." WHEN NEW.type = 'passkey.enrolled' BEGIN SELECT RAISE(ABORT, 'simulated anchor storage failure'); END");
        try {
            $enrollment->complete($actor, $options, $response, hash('sha256', $token));
            self::fail('A failed anchor write must not commit registration.');
        } catch (\Doctrine\DBAL\Exception $failure) {
            self::assertStringContainsString('simulated anchor storage failure', $failure->getMessage());
        } finally {
            $this->database->executeStatement('DROP TRIGGER refuse_test_anchor');
        }
        self::assertNull($this->database->fetchOne('SELECT used_at FROM app_enrollment_links WHERE token_hash = ?', [hash('sha256', $token)]));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::CREDENTIALS));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::AUDIT.' WHERE type = ?', ['passkey.enrolled']));
        $credential = $enrollment->complete($actor, $options, $response, hash('sha256', $token));
        self::assertNotNull($container->get(AnchoredCredentialRepository::class)->find($credential->id));
    }

    #[DataProvider('channels')]
    public function testAllProtectedOperationsAndSavedForwardDrafts(string $channel): void
    {
        $id = $this->createDraft($channel, 'Library opening', "The library opens Monday.\nVisitors enter on Oak Street.");
        self::assertNull($this->row($id)['status']);
        $this->assertPublicMissing($id);

        $beforePublish = $this->row($id);
        $publish = $this->ask($channel, 'publish', $id);
        $this->assertPendingUnchanged($publish, $beforePublish);
        $this->assertPublicMissing($id);
        $review = $this->review($publish);
        self::assertStringContainsString('Library opening', $review->text());
        self::assertStringContainsString('Visitors enter on Oak Street.', $review->text());
        $this->assertApproved($publish);
        $published = $this->row($id);
        self::assertSame('published', $published['status']);
        self::assertSame('Library opening', $published['live_title']);
        self::assertSame($publish, $published['live_request_id']);
        self::assertSame(1, (int) $published['live_revision']);
        $this->assertPublic($id, 'Library opening', 'Visitors enter on Oak Street.', true);

        // A genuinely saved working revision of a live page. The existing public snapshot and badge survive.
        $this->saveDraft($channel, $id, 'Library opening: revised', 'The library opens Tuesday.');
        $forward = $this->row($id);
        self::assertSame('Library opening: revised', $forward['draft_title']);
        self::assertSame('Library opening', $forward['live_title']);
        self::assertSame($publish, $forward['live_request_id']);
        $this->assertPublic($id, 'Library opening', 'Visitors enter on Oak Street.', true, 'The library opens Tuesday.');

        $promote = $this->ask($channel, 'publish', $id);
        $this->assertPendingUnchanged($promote, $forward);
        $this->assertApproved($promote);
        self::assertSame('Library opening: revised', $this->row($id)['live_title']);
        $this->assertPublic($id, 'Library opening: revised', 'The library opens Tuesday.', true);

        $beforeLiveEdit = $this->row($id);
        $edit = $this->ask($channel, 'edit-live', $id, ['title' => 'Library opening: confirmed', 'body' => 'The library opens at 09:00 Tuesday.']);
        $this->assertPendingUnchanged($edit, $beforeLiveEdit);
        $this->assertApproved($edit);
        $edited = $this->row($id);
        self::assertSame('Library opening: confirmed', $edited['live_title']);
        self::assertSame($beforeLiveEdit['draft_title'], $edited['draft_title'], 'A direct live edit preserves the working draft.');
        self::assertSame($beforeLiveEdit['draft_body'], $edited['draft_body']);
        $this->assertPublic($id, 'Library opening: confirmed', 'The library opens at 09:00 Tuesday.', true);

        $archive = $this->ask($channel, 'archive', $id);
        $this->assertPendingUnchanged($archive, $edited);
        $this->assertApproved($archive);
        self::assertSame('archived', $this->row($id)['status']);
        $this->assertPublicMissing($id);

        // Bring it live again so the deletion exercises published-to-delete, not only archived-to-delete.
        $republish = $this->ask($channel, 'publish', $id);
        $this->assertApproved($republish);
        self::assertSame('published', $this->row($id)['status']);
        $beforeDelete = $this->row($id);
        $delete = $this->ask($channel, 'delete', $id);
        $this->assertPendingUnchanged($delete, $beforeDelete);
        $this->assertApproved($delete);
        self::assertFalse($this->row($id));
        $this->assertPublicMissing($id);

        $history = $this->container()->get(ApprovalHistory::class)->forResource('editorial', $id);
        $completedIds = array_map(static fn (ApprovalState $state): string => $state->request->id, array_filter($history, static fn (ApprovalState $state): bool => 'completed' === $state->status));
        foreach ([$publish, $promote, $edit, $archive, $republish, $delete] as $requestId) {
            self::assertContains($requestId, $completedIds, 'Evidence survives deletion of its business resource.');
        }
        $this->assertReceipt($delete);
    }

    #[DataProvider('channels')]
    public function testChangingTheDraftWhileApprovingInvalidatesPublication(string $channel): void
    {
        $id = $this->createDraft($channel, 'First review', 'The exact original proposal.');
        $request = $this->ask($channel, 'publish', $id);
        [$panel, $ceremony] = $this->begin($request);
        $this->saveDraft($channel, $id, 'Newer working draft', 'This text was not the proposal.');
        $answer = $this->finish($panel, $ceremony);
        self::assertSame('invalidated', $answer->status, (string) $answer);
        self::assertSame('stale_resource', $answer->reason, (string) $answer);
        self::assertNull($this->row($id)['live_title']);
        self::assertSame('Newer working draft', $this->row($id)['draft_title']);
        self::assertSame(0, $this->executionsFor($request));
    }

    #[DataProvider('channels')]
    public function testAStaleLiveEditCannotReplaceANewerApprovedVersion(string $channel): void
    {
        $id = $this->published($channel, 'Timetable', 'The service runs hourly.');
        $older = $this->ask($channel, 'edit-live', $id, ['title' => 'Older proposal', 'body' => 'The service runs every 30 minutes.']);
        [$panel, $ceremony] = $this->begin($older);
        $newer = $this->ask($channel, 'edit-live', $id, ['title' => 'Current timetable', 'body' => 'The service runs every 15 minutes.']);
        $this->assertApproved($newer);
        $answer = $this->finish($panel, $ceremony);
        self::assertSame('invalidated', $answer->status, (string) $answer);
        self::assertSame('stale_resource', $answer->reason, (string) $answer);
        self::assertSame('Current timetable', $this->row($id)['live_title']);
        self::assertSame($newer, $this->row($id)['live_request_id']);
        self::assertSame(0, $this->executionsFor($older));
    }

    public function testRevokingTheInitiatingMcpGrantDuringApprovalStopsExecution(): void
    {
        $id = $this->createDraft('mcp', 'Revoked connection', 'This must stay private.');
        $request = $this->ask('mcp', 'publish', $id);
        [$panel, $ceremony] = $this->begin($request);
        $this->database->executeStatement('UPDATE app_connections SET revoked_at = ? WHERE user_id = ?', [time(), 'editor']);
        $answer = $this->finish($panel, $ceremony);
        self::assertSame('denied', $answer->status, (string) $answer);
        self::assertSame('delegation_denied', $answer->reason, (string) $answer);
        self::assertNull($this->row($id)['live_title']);
        self::assertSame(0, $this->executionsFor($request));
    }

    public function testRoleLossEndsTheBrowserSessionAndAlsoFailsFinalAuthorityCheck(): void
    {
        $id = $this->createDraft('web', 'Role removed', 'This must stay private.');
        $request = $this->ask('web', 'publish', $id);
        [$panel, $ceremony] = $this->begin($request);
        $csrf = $this->review($request)->filter('[data-passkey-panel]')->attr('data-csrf');
        $credential = $this->passkey()->assertion($ceremony->data['publicKey']);
        $this->database->executeStatement('UPDATE app_accounts SET role = ? WHERE id = ?', ['ROLE_READER', 'editor']);
        $browser = $this->browser();
        $browser->request('POST', self::ORIGIN.'/approval/'.$request.'/complete', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
            'HTTP_ORIGIN' => self::ORIGIN, 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], json_encode(['ceremony_id' => $ceremony->data['ceremony_id'], 'credential' => $credential], \JSON_THROW_ON_ERROR));
        // Symfony invalidates this host session when its roles change, before the approval controller runs.
        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->executionsFor($request));
        // Even a trusted host that retains its account session cannot reuse the old execution authority.
        $state = $this->container()->get(ApprovalService::class)->complete(new Actor('editor', 'editorial'), $ceremony->data['ceremony_id'], $credential);
        self::assertSame('denied', $state->status);
        self::assertSame('permission_denied', $state->reason);
        self::assertNull($this->row($id)['live_title']);
        self::assertSame(0, $this->executionsFor($request));
    }

    #[DataProvider('channels')]
    public function testRoleSpecificExemptionRecordsTheChangeWithoutAHumanApprovalBadge(string $channel): void
    {
        $id = $this->createDraft($channel, 'Publisher policy', 'An explicitly exempt publisher may publish this.', 'publisher');
        if ('mcp' === $channel) {
            $answer = $this->tool('publisher', 'publish_page', ['page_id' => $id, 'request_key' => 'exempt-publisher']);
            self::assertFalse($answer->isError);
            self::assertSame('completed', $answer->structuredContent['status']);
            $requestId = $answer->structuredContent['requestId'];
        } else {
            $browser = $this->browser('publisher');
            $browser->request('POST', self::ORIGIN.'/desk/pages/'.$id.'/publish', ['_token' => $this->csrf($browser)]);
            self::assertTrue($browser->getResponse()->isRedirect(), $browser->getResponse()->getContent());
            $requestId = $this->row($id)['live_request_id'];
        }
        self::assertSame('published', $this->row($id)['status']);
        $state = $this->container()->get(ApprovalService::class)->get(new Actor('publisher', 'editorial'), $requestId);
        self::assertSame('completed', $state->status);
        self::assertNull($state->proof);
        self::assertSame('not_required', $state->execution['approval']);
        self::assertNotNull($state->receipt, 'An exempt operation is still recorded and signed.');
        $this->assertPublic($id, 'Publisher policy', 'An explicitly exempt publisher may publish this.', false);
    }

    public function testReaderCannotWriteOrOpenAnotherAccountsApproval(): void
    {
        $id = $this->createDraft('web', 'Owner only', 'The reader may not approve this.');
        $request = $this->ask('web', 'publish', $id);
        $reader = $this->browser('reader');
        $reader->request('GET', self::ORIGIN.'/approval/'.$request);
        self::assertSame(404, $reader->getResponse()->getStatusCode());
        $before = $this->row($id);
        $reader->request('POST', self::ORIGIN.'/desk/pages/'.$id.'/publish', ['_token' => $this->csrf($reader)]);
        self::assertSame(403, $reader->getResponse()->getStatusCode());
        $tool = $this->tool('reader', 'publish_page', ['page_id' => $id]);
        self::assertTrue($tool->isError);
        self::assertSame($before, $this->row($id));
        self::assertSame(0, $this->executionsFor($request));
    }

    public function testMcpRetriesAndCompletedCeremonyReplayDoNotExecuteTwice(): void
    {
        $id = $this->createDraft('mcp', 'Retry safely', 'One publication.');
        $arguments = ['page_id' => $id, 'request_key' => 'stable-retry-key'];
        $first = $this->tool('editor', 'publish_page', $arguments);
        self::assertFalse($first->isError);
        $requestId = $first->structuredContent['requestId'];
        $again = $this->tool('editor', 'publish_page', $arguments);
        self::assertSame($requestId, $again->structuredContent['requestId']);
        [$panel, $ceremony] = $this->begin($requestId);
        $credential = $this->passkey()->assertion($ceremony->data['publicKey']);
        $completed = $panel->complete($ceremony->data['ceremony_id'], $credential);
        self::assertSame('completed', $completed->status, (string) $completed);
        $before = $this->row($id);
        $replayed = $panel->complete($ceremony->data['ceremony_id'], $credential);
        self::assertNotSame('pending', $replayed->status, (string) $replayed);
        $retry = $this->tool('editor', 'publish_page', $arguments);
        self::assertFalse($retry->isError, json_encode($retry->structuredContent));
        self::assertSame('completed', $retry->structuredContent['status']);
        self::assertSame($requestId, $retry->structuredContent['requestId']);
        self::assertSame($before, $this->row($id));
        self::assertSame(1, $this->executionsFor($requestId));
        self::assertSame(1, (int) $this->row($id)['live_revision']);
        $this->assertReceipt($requestId);

        foreach ([
            'edit_live_page' => ['title' => 'A revised public version', 'body' => 'This direct edit executes once.'],
            'archive_page' => [],
            'delete_page' => [],
        ] as $tool => $extra) {
            $arguments = ['page_id' => $id, 'request_key' => 'stable-retry-'.$tool] + $extra;
            $pending = $this->tool('editor', $tool, $arguments);
            self::assertFalse($pending->isError, json_encode($pending->structuredContent));
            self::assertSame('pending', $pending->structuredContent['status']);
            $requestId = $pending->structuredContent['requestId'];
            $this->assertApproved($requestId);
            $committed = $this->row($id);
            $retry = $this->tool('editor', $tool, $arguments);
            self::assertFalse($retry->isError, json_encode($retry->structuredContent));
            self::assertSame('completed', $retry->structuredContent['status']);
            self::assertSame($requestId, $retry->structuredContent['requestId']);
            self::assertSame($committed, $this->row($id));
            self::assertSame(1, $this->executionsFor($requestId));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unguardedWrites(): iterable
    {
        yield 'direct edit' => ['direct'];
        yield 'unloaded reference deletion' => ['lazy-delete'];
        yield 'earlier lifecycle callback' => ['pre-update'];
    }

    #[DataProvider('unguardedWrites')]
    public function testProtectedOrmWritesCannotBypassApproval(string $mode): void
    {
        $id = $this->published('web', 'Guarded snapshot', 'Only approved words.');
        $before = $this->row($id);
        $container = $this->container();
        $manager = $container->get(EntityManagerInterface::class);
        $manager->clear();
        if ('lazy-delete' === $mode) {
            $page = $manager->getReference(Page::class, $id);
            $manager->remove($page);
        } else {
            $page = $manager->find(Page::class, $id);
            if ('direct' === $mode) {
                $page->liveBody = 'This bypass must fail.';
            } else {
                $events = $manager->getEventManager();
                $protection = $container->get(ProtectedEntityListener::class);
                $events->removeEventListener([Events::preUpdate], $protection);
                $events->addEventListener([Events::preUpdate], new class {
                    public function preUpdate(PreUpdateEventArgs $event): void
                    {
                        if ($event->getObject() instanceof Page) {
                            $event->getObject()->liveBody = 'This callback bypass must fail.';
                        }
                    }
                });
                $events->addEventListener([Events::preUpdate], $protection);
                $page->draftTitle = 'An otherwise free working edit';
            }
        }
        try {
            $manager->flush();
            self::fail('A protected ORM change committed without an approved action.');
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason);
        }
        self::assertSame($before, $this->row($id));
    }

    public function testDamagedEvidenceRemovesTheBadgeWhileKeepingTheArticleAvailable(): void
    {
        $id = $this->published('web', 'Verified story', 'A visible article with signed approval.');
        $this->assertPublic($id, 'Verified story', 'A visible article with signed approval.', true);
        $row = $this->row($id);
        $requestId = $row['live_request_id'];
        $proposal = (string) $this->database->fetchOne('SELECT proposal FROM '.ApprovalSchema::REQUESTS.' WHERE id = ?', [$requestId]);
        $corrupt = str_replace('Verified story', 'Tampered story', $proposal);
        self::assertNotSame($proposal, $corrupt);
        $this->database->executeStatement('UPDATE '.ApprovalSchema::REQUESTS.' SET proposal = ? WHERE id = ?', [$corrupt, $requestId]);
        $this->assertPublic($id, 'Verified story', 'A visible article with signed approval.', false);
    }

    /** @return iterable<string, array{string, bool}> an edit that leaves a stored record unreadable, and whether its proposal still shows whose it is */
    public static function editsPastReading(): iterable
    {
        yield 'one character of the proposal' => ["proposal = substr(proposal, 1, 20) || 'X' || substr(proposal, 22)", false];
        yield 'the result, to text that is not JSON' => ["result = 'published'", true];
    }

    /** A record edited so far that it cannot be read back keeps its place in the history, and nothing in it is shown as what happened. */
    #[DataProvider('editsPastReading')]
    public function testARecordEditedPastReadingStaysInTheHistoryAsOneThatCannotBeRead(string $edit, bool $showsOwner): void
    {
        $id = $this->published('web', 'Recorded change', 'A page with an approval history.');
        $damaged = $this->row($id)['live_request_id'];
        $this->ask('web', 'archive', $id);
        $this->database->executeStatement('UPDATE '.ApprovalSchema::REQUESTS.' SET '.$edit.' WHERE id = ?', [$damaged]);

        foreach ($showsOwner ? ['editor' => 'View request', 'reader' => 'Another account’s request'] : ['editor' => $damaged, 'reader' => $damaged] as $account => $record) {
            $browser = $this->browser($account);
            $rows = $browser->request('GET', self::ORIGIN.'/desk/history/'.$id)->filter('tbody tr');
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $account.': a damaged record does not take the history down.');
            self::assertCount(2, $rows, $account);
            self::assertStringContainsString('Archive published page: Recorded change', $rows->eq(0)->text(), $account.': the records that read back are listed as before.');
            self::assertSame(['This record cannot be read', 'Integrity error', 'Cannot be confirmed', $record], $rows->eq(1)->filter('td')->each(static fn (Crawler $cell): string => $cell->text()), $account);
        }
        if ($showsOwner) {
            $editor = $this->browser();
            $editor->request('GET', self::ORIGIN.(string) $editor->getCrawler()->filter('tbody tr')->eq(1)->filter('a')->attr('href'));
            self::assertSame(409, $editor->getResponse()->getStatusCode(), 'Its owner’s link opens the page that says its outcome cannot be confirmed.');
        }
    }

    private function kernel(): Kernel
    {
        $kernel = new Kernel('test', false);
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private function container(): ContainerInterface
    {
        $kernel = $this->kernel();
        $kernel->boot();

        return $kernel->getContainer()->get('test.service_container');
    }

    private function browser(string $account = 'editor'): KernelBrowser
    {
        if (isset($this->browsers[$account])) {
            return $this->browsers[$account];
        }
        $browser = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'editorial.test', 'HTTPS' => 'on']);
        $this->browsers[$account] = $browser;
        $page = $browser->request('GET', self::ORIGIN.'/login');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $form = $page->filter('form')->first()->form(['_username' => $account, '_password' => $this->accounts[$account]['password']]);
        $browser->submit($form);
        self::assertTrue($browser->getResponse()->isRedirect(), 'The application login must establish a normal session. '.$browser->getResponse()->getContent());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return $browser;
    }

    private function passkey(): VirtualAuthenticator
    {
        if (null !== $this->passkey) {
            return $this->passkey;
        }
        $editor = $this->browser();
        $editor->request('GET', $this->accounts['editor']['passkey_link']);
        self::assertTrue($editor->getResponse()->isRedirect());
        $this->passkey = new VirtualAuthenticator(self::ORIGIN);
        $answer = PasskeyPanel::open($editor, self::ORIGIN.'/account/passkeys')->register($this->passkey);
        self::assertSame('registered', $answer->status, (string) $answer);

        return $this->passkey;
    }

    private function csrf(KernelBrowser $browser): string
    {
        $page = $browser->request('GET', self::ORIGIN.'/desk');
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        $inputs = $page->filter('input[name="_token"]');
        if ($inputs->count() > 0) {
            return (string) $inputs->first()->attr('value');
        }
        // A reader correctly has no writing form. Issue only a CSRF token in its existing authenticated
        // test session, so the forged write reaches the permission check instead of failing on missing CSRF.
        $request = $browser->getRequest();
        $stack = $browser->getContainer()->get('request_stack');
        $stack->push($request);
        try {
            $token = (string) $browser->getContainer()->get('security.csrf.token_manager')->getToken('desk');
            $request->getSession()->save();

            return $token;
        } finally {
            $stack->pop();
        }
    }

    private function createDraft(string $channel, string $title, string $body, string $account = 'editor'): string
    {
        if ('mcp' === $channel) {
            $answer = $this->tool($account, 'save_draft', ['title' => $title, 'body' => $body]);
            self::assertFalse($answer->isError, json_encode($answer->structuredContent));

            return $answer->structuredContent['id'];
        }
        $browser = $this->browser($account);
        $browser->request('POST', self::ORIGIN.'/desk/pages', ['_token' => $this->csrf($browser), 'title' => $title, 'body' => $body]);
        self::assertTrue($browser->getResponse()->isRedirect(), $browser->getResponse()->getContent());
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#/desk/pages/([a-f0-9]{32})$#D', $location, $match), $location);

        return $match[1];
    }

    private function saveDraft(string $channel, string $id, string $title, string $body): void
    {
        if ('mcp' === $channel) {
            $answer = $this->tool('editor', 'save_draft', ['page_id' => $id, 'title' => $title, 'body' => $body]);
            self::assertFalse($answer->isError);

            return;
        }
        $browser = $this->browser();
        $browser->request('POST', self::ORIGIN.'/desk/pages/'.$id.'/draft', ['_token' => $this->csrf($browser), 'title' => $title, 'body' => $body]);
        self::assertTrue($browser->getResponse()->isRedirect(), $browser->getResponse()->getContent());
    }

    private function ask(string $channel, string $operation, string $id, array $extra = []): string
    {
        if ('mcp' === $channel) {
            $tool = ['publish' => 'publish_page', 'edit-live' => 'edit_live_page', 'archive' => 'archive_page', 'delete' => 'delete_page'][$operation];
            $answer = $this->tool('editor', $tool, ['page_id' => $id, 'request_key' => bin2hex(random_bytes(8))] + $extra);
            self::assertFalse($answer->isError, json_encode($answer->structuredContent));
            self::assertSame('pending', $answer->structuredContent['status']);

            return $answer->structuredContent['requestId'];
        }
        $browser = $this->browser();
        $browser->request('POST', self::ORIGIN.'/desk/pages/'.$id.'/'.$operation, ['_token' => $this->csrf($browser)] + $extra);
        self::assertTrue($browser->getResponse()->isRedirect(), $browser->getResponse()->getContent());
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#/approval/(apr_[a-f0-9]{32})(?:\?|$)#', $location, $match), $location);

        return $match[1];
    }

    private function review(string $requestId): Crawler
    {
        $browser = $this->browser();
        $page = $browser->request('GET', self::ORIGIN.'/approval/'.$requestId);
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return $page;
    }

    /** @return array{PasskeyPanel, PanelAnswer} */
    private function begin(string $requestId): array
    {
        $this->passkey();
        $panel = PasskeyPanel::open($this->browser(), self::ORIGIN.'/approval/'.$requestId);
        $ceremony = $panel->begin();
        self::assertFalse($ceremony->refused(), (string) $ceremony);

        return [$panel, $ceremony];
    }

    private function finish(PasskeyPanel $panel, PanelAnswer $ceremony): PanelAnswer
    {
        return $panel->complete($ceremony->data['ceremony_id'], $this->passkey()->assertion($ceremony->data['publicKey']));
    }

    private function assertApproved(string $requestId): void
    {
        [$panel, $ceremony] = $this->begin($requestId);
        $answer = $this->finish($panel, $ceremony);
        self::assertSame('completed', $answer->status, (string) $answer);
        self::assertSame(1, $this->executionsFor($requestId));
    }

    private function published(string $channel, string $title, string $body): string
    {
        $id = $this->createDraft($channel, $title, $body);
        $this->assertApproved($this->ask($channel, 'publish', $id));

        return $id;
    }

    private function tool(string $account, string $name, array $arguments): CallToolResult
    {
        if (!isset($this->clients[$account])) {
            $factory = new Psr17Factory();
            $client = Client::builder()->setClientInfo('editorial-acceptance', '1.0')->setMaxRetries(0)->build();
            $client->connect(new HttpTransport(
                endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts[$account]['token']],
                httpClient: new KernelHttpClient($this->kernel(...), $factory, $factory), requestFactory: $factory, streamFactory: $factory,
            ));
            $this->clients[$account] = $client;
        }

        return $this->clients[$account]->callTool($name, $arguments);
    }

    private function row(string $id): array|false
    {
        return $this->database->fetchAssociative('SELECT * FROM app_pages WHERE id = ?', [$id]);
    }

    private function executionsFor(string $requestId): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::EXECUTIONS.' WHERE request_id = ?', [$requestId]);
    }

    private function assertPendingUnchanged(string $requestId, array $expected): void
    {
        self::assertSame('pending', $this->database->fetchOne('SELECT status FROM '.ApprovalSchema::REQUESTS.' WHERE id = ?', [$requestId]));
        self::assertSame($expected, $this->row($expected['id']), 'The action must not change content before approval.');
        self::assertSame(0, $this->executionsFor($requestId));
    }

    private function visitor(): KernelBrowser
    {
        if (!isset($this->browsers['visitor'])) {
            $this->browsers['visitor'] = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'editorial.test', 'HTTPS' => 'on']);
        }

        return $this->browsers['visitor'];
    }

    private function assertPublic(string $id, string $title, string $body, bool $badge, ?string $absent = null): void
    {
        $visitor = $this->visitor();
        $page = $visitor->request('GET', self::ORIGIN.'/pages/'.$id);
        self::assertSame(200, $visitor->getResponse()->getStatusCode());
        self::assertStringContainsString($title, $page->text());
        self::assertStringContainsString($body, $page->text());
        self::assertSame($badge ? 1 : 0, $page->filter('.vha-badge')->count());
        if (null !== $absent) {
            self::assertStringNotContainsString($absent, $page->text());
        }
    }

    private function assertPublicMissing(string $id): void
    {
        $visitor = $this->visitor();
        $visitor->request('GET', self::ORIGIN.'/pages/'.$id);
        self::assertSame(404, $visitor->getResponse()->getStatusCode());
    }

    private function assertReceipt(string $requestId): void
    {
        $editor = $this->browser();
        $editor->request('GET', self::ORIGIN.'/approval/'.$requestId.'/evidence');
        self::assertSame(200, $editor->getResponse()->getStatusCode());
        $evidence = CanonicalJson::map(CanonicalJson::decode((string) $editor->getResponse()->getContent()));
        $visitor = $this->visitor();
        $visitor->request('GET', self::ORIGIN.'/approval/receipt-keys.json');
        self::assertSame(200, $visitor->getResponse()->getStatusCode());
        $keys = TrustedKeys::fromDocument(json_decode((string) $visitor->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR));
        $verified = (new ReceiptVerifier())->verify($evidence, $keys);
        self::assertSame(Receipt::COMMIT, $verified['receiptSignedAt']);
    }

    private function setEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->environment[$name] = ['process' => getenv($name), 'server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'hadServer' => \array_key_exists($name, $_SERVER), 'hadEnv' => \array_key_exists($name, $_ENV)];
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }
}
