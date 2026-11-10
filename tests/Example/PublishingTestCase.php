<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Example;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Example\Publishing\Action\EditLiveArticle;
use VerifiedHumanApproval\Example\Publishing\Action\PublishArticle;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPermissions;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;
use VerifiedHumanApproval\Example\Publishing\PublishingKernel;
use VerifiedHumanApproval\Example\Publishing\Security\ApprovalActors;
use VerifiedHumanApproval\Example\Publishing\Setup\Installer;
use VerifiedHumanApproval\Testing\KernelHttpClient;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * The publishing example end to end, on one database: its own kernel, firewalls, forms, MCP tools and the real WebAuthn
 * verification (with a synthetic authenticator). Each of the four changes to what visitors see is asked for from the
 * editor's browser and from their agent, approved with the editor's passkey, and checked against what the database
 * holds afterwards, row for row.
 *
 * The passkey, the approval page's requests and the agent's connection come from the testing package (src/testing),
 * as they would in the tests of an application that copies this example.
 */
abstract class PublishingTestCase extends TestCase
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private const ORIGIN = 'http://localhost:47213';
    private const TOOLS = ['publish' => 'publish_article', 'edit' => 'edit_live_article', 'archive' => 'archive_articles', 'delete' => 'delete_article'];

    private ?string $location = null;
    private string $state;
    private Connection $database;
    /** @var array<string, array{password: string, agent_token: string, passkey_link: string}> */
    private array $accounts;
    /** @var array<string, string|false> the environment before this test */
    private array $environment = [];
    /** @var list<KernelBrowser> */
    private array $browsers = [];
    /** @var list<PublishingKernel> */
    private array $kernels = [];
    /** @var list<Client> */
    private array $clients = [];
    private ?KernelBrowser $editor = null;
    private VirtualAuthenticator $passkey;
    private string $form = '';

    protected function setUp(): void
    {
        $this->location = TestDatabase::create(static::KIND);
        $this->state = sys_get_temp_dir().'/vha-publishing-state-'.bin2hex(random_bytes(6));
        // The compiled container is shared by every test on this database; what differs per test is read at runtime.
        $shared = (getenv('VERIFIED_HUMAN_APPROVAL_TEST_CACHE') ?: sys_get_temp_dir().'/vha-publishing-'.getmypid()).'/publishing-'.static::KIND;
        $this->setEnvironment([
            'APP_CACHE_DIR' => $shared.'/cache', 'APP_LOG_DIR' => $shared.'/log', 'APP_STATE_DIR' => $this->state,
            'APP_ORIGIN' => self::ORIGIN, 'APP_SECRET' => bin2hex(random_bytes(32)),
            'DATABASE_URL' => self::databaseUrl(static::KIND, $this->location),
            'APPROVAL_RECEIPT_KEY_ID' => 'test-key', 'APPROVAL_RECEIPT_SECRET_KEY' => ChallengeBinding::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            'APPROVAL_RECEIPT_KEY_VALID_FROM' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'MCP_REQUEST_STATE_KEY' => bin2hex(random_bytes(32)),
        ]);
        $installer = $this->container()->get(Installer::class);
        \assert($installer instanceof Installer);
        $this->accounts = $installer->install(self::ORIGIN);
        $this->database = TestDatabase::connect(static::KIND, $this->location);
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
        if (isset($this->database)) {
            $this->database->close();
        }
        if (null !== $this->location) {
            TestDatabase::drop(static::KIND, $this->location);
        }
        if (isset($this->state)) {
            (new Filesystem())->remove($this->state);
        }
        foreach ($this->environment as $name => $value) {
            if (false === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv($name.'='.$value);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function channels(): iterable
    {
        yield 'from the web' => ['web'];
        yield 'from an agent' => ['mcp'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (array_keys(self::TOOLS) as $operation) {
            foreach (self::channels() as $from => [$channel]) {
                yield $operation.' '.$from => [$operation, $channel];
            }
        }
    }

    #[DataProvider('channels')]
    public function testPublishingPutsExactlyTheReviewedDraftLive(string $channel): void
    {
        $id = $this->draft('Bridge reopens', "The bridge reopens on Monday.\nBuses return to their usual route.");
        $before = $this->articles();
        $request = $this->ask($channel, 'publish', ['article_id' => $id]);
        self::assertSame($before, $this->articles(), 'Nothing changes before the approval.');
        self::assertNotContains($id, $this->publicIds());

        $preview = $this->review($request)->filter('.sheet-preview');
        self::assertSame('Bridge reopens', $preview->filter('.article-title')->text());
        self::assertSame("The bridge reopens on Monday.\nBuses return to their usual route.", $preview->filter('.article-body')->text(normalizeWhitespace: false));
        $completed = $this->approve($request);
        self::assertSame('completed', $completed['status'], json_encode($completed, \JSON_THROW_ON_ERROR));

        $expected = self::changed($before, [$id => ['status' => 'live', 'live_title' => 'Bridge reopens', 'live_body' => "The bridge reopens on Monday.\nBuses return to their usual route.", 'revision' => 1, 'live_request_id' => $request]]);
        self::assertSame($expected, $this->articles(), 'Exactly the reviewed draft went live, and nothing else changed.');
        self::assertContains($id, $this->badged(), 'The public page shows it was approved with a passkey.');
    }

    #[DataProvider('channels')]
    public function testALiveEditIsReviewedBesideTheLiveTextWhichStaysUntilApproved(string $channel): void
    {
        $id = $this->live('Ferry timetable', 'Ferries leave every hour.');
        $before = $this->articles();
        $request = $this->ask($channel, 'edit', ['article_id' => $id, 'title' => 'Ferry timetable for May', 'body' => "Ferries leave every half hour.\nThe last one is at 22:30."]);
        self::assertSame($before, $this->articles(), 'What visitors see stays until the approval.');
        self::assertContains($id, $this->badged());

        $preview = $this->review($request)->filter('.side-by-side');
        self::assertSame('Live now · revision 1', $preview->filter('.before h3')->text());
        self::assertSame('Ferries leave every hour.', $preview->filter('.before .article-body')->text());
        self::assertSame('After approval · revision 2', $preview->filter('.after h3')->text());
        self::assertSame('Ferry timetable for May', $preview->filter('.after .article-title')->text());
        $completed = $this->approve($request);
        self::assertSame('completed', $completed['status'], json_encode($completed, \JSON_THROW_ON_ERROR));

        [$title, $body] = ['Ferry timetable for May', "Ferries leave every half hour.\nThe last one is at 22:30."];
        $expected = self::changed($before, [$id => ['live_title' => $title, 'live_body' => $body, 'revision' => 2, 'live_request_id' => $request,
            // The draft follows the live text while the article is live.
            'draft_title' => $title, 'draft_body' => $body]]);
        self::assertSame($expected, $this->articles(), 'Exactly the reviewed text is live, and nothing else changed.');
        self::assertContains($id, $this->badged(), 'The new text carries its own approval.');
    }

    #[DataProvider('channels')]
    public function testOneApprovalArchivesTheWholeBatchAndNothingElse(string $channel): void
    {
        $first = $this->live('Summer bus times', 'The summer timetable starts in June.');
        $second = $this->live('Pool closed for repairs', 'The pool is closed this week.');
        $third = $this->live('Harbour festival', 'The festival is on 20 July.');
        $this->approve($this->ask('web', 'edit', ['article_id' => $second, 'title' => 'Pool closed for repairs', 'body' => 'The pool is closed this week and next.']));
        $before = $this->articles();
        $request = $this->ask($channel, 'archive', ['article_ids' => [$second, $first]]);
        self::assertSame($before, $this->articles());
        self::assertEqualsCanonicalizing([$first, $second, $third], $this->publicIds());

        // The proposal lists every article it archives, with its live revision.
        $rows = $this->review($request)->filter('.sheet-preview table.batch tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertEqualsCanonicalizing([['Summer bus times', '1'], ['Pool closed for repairs', '2']], $rows);
        $completed = $this->approve($request);
        self::assertSame('completed', $completed['status'], json_encode($completed, \JSON_THROW_ON_ERROR));

        $expected = self::changed($before, [
            $first => ['status' => 'archived', 'revision' => 2, 'live_request_id' => $request],
            $second => ['status' => 'archived', 'revision' => 3, 'live_request_id' => $request],
        ]);
        self::assertSame($expected, $this->articles(), 'Both are archived under the one approval, keeping their last live text; the third is untouched.');
        self::assertSame([$third], $this->publicIds());
    }

    #[DataProvider('channels')]
    public function testDeletingRemovesOnlyWhatTheProposalFroze(string $channel): void
    {
        $doomed = $this->live('Old parking rules', 'Parking is free on Sundays.');
        $kept = $this->draft('New parking rules', 'Parking is free on Sundays and holidays.');
        $before = $this->articles();
        $request = $this->ask($channel, 'delete', ['article_id' => $doomed]);
        self::assertSame($before, $this->articles());

        $preview = $this->review($request)->filter('.sheet-preview');
        self::assertStringContainsString('disappears for good', $preview->text());
        self::assertSame(['Old parking rules', 'Old parking rules'], $preview->filter('.article-title')->each(static fn (Crawler $title): string => $title->text()), 'What visitors see, and the draft.');
        $completed = $this->approve($request);
        self::assertSame('completed', $completed['status'], json_encode($completed, \JSON_THROW_ON_ERROR));

        $expected = $before;
        unset($expected[$doomed]);
        self::assertSame($expected, $this->articles(), 'Only the frozen article disappeared.');
        self::assertArrayHasKey($kept, $this->articles());
        self::assertNotContains($doomed, $this->publicIds());
        $history = $this->container()->get(DbalApprovalStore::class);
        \assert($history instanceof DbalApprovalStore);
        self::assertSame([$request, $before[$doomed]['live_request_id']], array_map(static fn ($state): string => $state->request->id, $history->forResource(ApprovalActors::TENANT, $doomed)), 'Its approvals stay on record after it is gone: the deletion, and the publication before it.');
    }

    #[DataProvider('operations')]
    public function testAChangeAfterTheRequestStopsIt(string $operation, string $channel): void
    {
        [$arguments, $change] = $this->scenario($operation, $channel);
        $request = $this->ask($channel, $operation, $arguments);
        $changed = null;
        $result = $this->approve($request, function () use ($change, &$changed): void {
            $change();
            $changed = $this->articles();
        });
        self::assertSame('invalidated', $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame('stale_resource', $result['reason']);
        self::assertSame($changed, $this->articles(), 'Nothing the request named changed after it stopped.');
        if ('edit' === $operation) {
            self::assertSame('Ferries leave every twenty minutes.', $this->articles()[$arguments['article_id']]['live_body'], 'It never overwrote the newer live text.');
        }
        self::assertStringContainsString('Blocked: nothing changed.', $this->review($request)->filter('.approval-sheet')->text());
        if ('mcp' === $channel) {
            self::assertSame('invalidated', $this->mcp()->callTool('approval_status', ['request_id' => $request])->structuredContent['status']);
        }
    }

    #[DataProvider('operations')]
    public function testRevokingPermissionBetweenRequestAndApprovalStopsIt(string $operation, string $channel): void
    {
        [$arguments] = $this->scenario($operation, $channel);
        $before = $this->articles();
        $request = $this->ask($channel, $operation, $arguments);
        if ('web' === $channel) {
            // The editor is made a reader after asking. Symfony signs a person out when their roles change, so they
            // sign in again, open the review and try to approve: it stops there, before any passkey prompt.
            $this->database->executeStatement("UPDATE publishing_users SET role = 'reader' WHERE id = 'editor'");
            $this->editor = $this->browser('editor');
            $result = $this->approve($request);
            self::assertSame(['rejected', 'permission_denied', 'denied'], [$result['status'], $result['reason'], $result['request_status'] ?? null], json_encode($result, \JSON_THROW_ON_ERROR));
        } else {
            // The agent's connection is revoked after the person began approving: the transaction that would execute
            // the change reads the connection again, and stops.
            $result = $this->approve($request, fn () => $this->database->executeStatement("UPDATE publishing_agent_connections SET revoked_at = 1 WHERE user_id = 'editor'"));
            self::assertSame(['denied', 'delegation_denied'], [$result['status'], $result['reason']], json_encode($result, \JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->articles(), 'The passkey adds no permission: nothing changed.');
        self::assertStringContainsString('Blocked: nothing changed.', $this->review($request)->filter('.approval-sheet')->text());
    }

    public function testABatchArchiveIsAllOrNothing(): void
    {
        $first = $this->live('Library hours', 'Open until 19:00.');
        $second = $this->live('Recycling days', 'Glass is collected on Tuesdays.');
        $request = $this->ask('mcp', 'archive', ['article_ids' => [$first, $second]]);
        // One of the two changes after the request, through its own approval.
        $this->approve($this->ask('web', 'edit', ['article_id' => $second, 'title' => 'Recycling days', 'body' => 'Glass is collected on Wednesdays.']));
        $before = $this->articles();
        $result = $this->approve($request);
        self::assertSame('rejected', $result['status'], 'The stale request cannot even be begun.');
        self::assertSame('stale_resource', $result['reason']);
        self::assertSame('invalidated', $result['request_status']);
        self::assertSame($before, $this->articles(), 'Neither was archived: the batch is all or nothing.');
        self::assertEqualsCanonicalizing([$first, $second], $this->publicIds());
    }

    public function testWhatAnApprovalReadsStaysLockedUntilItCommits(): void
    {
        $id = $this->live('Ferry timetable', 'Ferries leave every hour.');
        $agent = (string) $this->database->fetchOne("SELECT id FROM publishing_agent_connections WHERE user_id = 'editor'");
        $container = $this->container();
        $transaction = $container->get(DbalTransaction::class);
        $permissions = $container->get(EditorialPermissions::class);
        $action = $container->get(EditLiveArticle::class);
        \assert($transaction instanceof DbalTransaction && $permissions instanceof EditorialPermissions && $action instanceof EditLiveArticle);
        $other = TestDatabase::connect(static::KIND, (string) $this->location);
        TestDatabase::lockTimeout(static::KIND, $other, 300);
        // What the decision depends on: on SQLite the approval holds the only write lock; on PostgreSQL and MySQL the
        // action reads the article FOR UPDATE and the authorizer reads the account and the connection FOR SHARE.
        $writes = [
            'the editor’s role' => ["UPDATE publishing_users SET role = 'reader' WHERE id = ?", ['editor']],
            'the agent’s connection' => ['UPDATE publishing_agent_connections SET revoked_at = 1 WHERE id = ?', [$agent]],
            'the live text' => ["UPDATE publishing_articles SET live_body = 'Changed meanwhile.' WHERE id = ?", [$id]],
        ];
        $transaction->run(static function () use ($action, $permissions, $other, $writes, $id, $agent): void {
            $actor = new Actor('editor', ApprovalActors::TENANT, $agent);
            $permissions->assertAllowed($actor, $action->prepare($actor, ['article_id' => $id, 'title' => 'Ferry timetable', 'body' => 'Ferries leave every half hour.']));
            foreach ($writes as $what => [$sql, $parameters]) {
                try {
                    $other->executeStatement($sql, $parameters);
                    self::fail('Changed '.$what.' while an approval that read it was still open.');
                } catch (\Throwable $error) {
                    self::assertTrue(TestDatabase::isLockTimeout($error), $what.': '.$error->getMessage());
                }
            }
        });
        // Once it commits, they change as usual.
        foreach ($writes as [$sql, $parameters]) {
            self::assertSame(1, (int) $other->executeStatement($sql, $parameters));
        }
        $other->close();
    }

    public function testTheTripwireRefusesADirectOrmWriteToWhatVisitorsSee(): void
    {
        $id = $this->live('Ferry timetable', 'Ferries leave every hour.');
        $before = $this->articles();
        $attempts = [
            'change the live text' => static fn (Article $article, EntityManagerInterface $entities) => $article->putLive('Ferries cancelled', 'No ferries today.', 2, 'apr_'.str_repeat('0', 32)),
            'archive it' => static fn (Article $article, EntityManagerInterface $entities) => $article->archive(2, 'apr_'.str_repeat('0', 32)),
            'delete it' => static fn (Article $article, EntityManagerInterface $entities) => $entities->remove($article),
            'insert a live article' => static function (Article $article, EntityManagerInterface $entities): void {
                $forged = new Article('0123456789abcdef', 'Forged', 'Never approved.');
                $forged->putLive('Forged', 'Never approved.', 1, 'apr_'.str_repeat('0', 32));
                $entities->persist($forged);
            },
        ];
        foreach ($attempts as $what => $attempt) {
            $entities = $this->container()->get(EntityManagerInterface::class);
            \assert($entities instanceof EntityManagerInterface);
            $article = $entities->find(Article::class, $id);
            \assert($article instanceof Article);
            $attempt($article, $entities);
            try {
                $entities->flush();
                self::fail('Flushed without an approval: '.$what);
            } catch (ApprovalException $error) {
                self::assertSame('approval_required', $error->reason, $what);
            }
        }
        self::assertSame($before, $this->articles(), 'No direct write reached what visitors see.');

        // The draft is free: the same entity manager saves a draft change at once.
        $entities = $this->container()->get(EntityManagerInterface::class);
        \assert($entities instanceof EntityManagerInterface);
        $draft = $entities->find(Article::class, $this->draft('A draft', 'Draft text.'));
        \assert($draft instanceof Article);
        $draft->editDraft('A draft, edited', 'Edited text.');
        $entities->flush();
        self::assertSame('A draft, edited', $this->articles()[$draft->id()]['draft_title']);
    }

    public function testTheBadgeShowsOnlyWhileTheLiveTextIsWhatWasApproved(): void
    {
        $kept = $this->live('Lifeboat open day', 'Visit the lifeboat station on Sunday.');
        $edited = $this->live('Beach cleaning', 'Volunteers meet at the pier at 10:00.');
        $archived = $this->live('Old notice', 'This notice is out of date.');
        self::assertEqualsCanonicalizing([$kept, $edited, $archived], $this->badged());
        $badge = $this->publicPage()->filter('[data-article="'.$kept.'"] a.vha-badge');
        self::assertStringContainsString('Approved with a passkey', $badge->text());
        self::assertSame('/approval/'.$this->articles()[$kept]['live_request_id'].'/verified', $badge->attr('href'));

        // Plain SQL goes around the ORM and its tripwire. The article stays live, but its text is no longer what was approved.
        $this->database->executeStatement('UPDATE publishing_articles SET live_body = ? WHERE id = ?', ['Volunteers meet at the pier at 11:00.', $edited]);
        self::assertContains($edited, $this->publicIds());
        self::assertNotContains($edited, $this->badged());

        // Archived through an approval: off the public page.
        self::assertSame('completed', $this->approve($this->ask('web', 'archive', ['article_ids' => [$archived]]))['status']);
        self::assertNotContains($archived, $this->publicIds());
        // Put back live by plain SQL: listed again, never badged, since its last approval was the archive.
        $this->database->executeStatement("UPDATE publishing_articles SET status = 'live' WHERE id = ?", [$archived]);
        self::assertContains($archived, $this->publicIds());
        self::assertSame([$kept], $this->badged());
    }

    public function testTheBrowserAndTheAgentMakeTheSameProposalThroughTheSameService(): void
    {
        $id = $this->draft('Bridge reopens', 'The bridge reopens on Monday.');
        $web = $this->ask('web', 'publish', ['article_id' => $id]);
        $agent = $this->ask('mcp', 'publish', ['article_id' => $id]);
        $store = $this->container()->get(DbalApprovalStore::class);
        \assert($store instanceof DbalApprovalStore);
        $fromWeb = $store->get($web)?->request;
        $fromAgent = $store->get($agent)?->request;
        \assert(null !== $fromWeb && null !== $fromAgent);
        self::assertSame(CanonicalJson::encode((object) $fromWeb->prepared()->toArray()), CanonicalJson::encode((object) $fromAgent->prepared()->toArray()), 'One proposal, whichever way it was asked for.');
        self::assertSame($fromWeb->statement(), $fromAgent->statement());
        self::assertNull($fromWeb->actor()->delegationId);
        self::assertNotNull($fromAgent->actor()->delegationId, 'The agent’s request names its connection, which the approval re-checks.');
        self::assertStringContainsString('Agent request', $this->review($agent)->filter('.requester')->text());
        self::assertStringContainsString('Your request', $this->review($web)->filter('.requester')->text());

        // A retry with the same key returns the same request, and after approval its outcome, once.
        $first = $this->mcp()->callTool('publish_article', ['article_id' => $id, 'request_key' => 'bridge-1']);
        $retry = $this->mcp()->callTool('publish_article', ['article_id' => $id, 'request_key' => 'bridge-1']);
        self::assertSame($first->structuredContent['requestId'], $retry->structuredContent['requestId']);
        self::assertSame('completed', $this->approve($first->structuredContent['requestId'])['status']);
        $done = $this->mcp()->callTool('publish_article', ['article_id' => $id, 'request_key' => 'bridge-1']);
        self::assertSame('completed', $done->structuredContent['status']);
        self::assertSame(1, $this->articles()[$id]['revision'], 'Published once.');

        // The agent reads the outcome, with the receipt this site signed as the change committed.
        $status = $this->mcp()->callTool('approval_status', ['request_id' => $first->structuredContent['requestId']]);
        self::assertSame('completed', $status->structuredContent['status']);
        self::assertTrue($status->structuredContent['approval']['approvedWithPasskey']);
        $state = $store->get($first->structuredContent['requestId']);
        \assert(null !== $state);
        $anyone = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'localhost:47213']);
        $this->browsers[] = $anyone;
        $anyone->request('GET', self::ORIGIN.'/approval/receipt-keys.json');
        $keys = TrustedKeys::fromDocument(json_decode((string) $anyone->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR));
        self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify((new EvidenceExporter())->export($state), $keys)['receiptSignedAt']);
        self::assertSame($status->structuredContent['receipt']['signature'], $state->receipt['signature'] ?? null);

        // The other two requests named a draft that is now live: they can no longer be approved.
        self::assertSame('stale_resource', $this->approve($web)['reason']);
        self::assertSame('stale_resource', $this->approve($agent)['reason']);
    }

    public function testReadingNeedsNoApprovalAndAReaderCanAskForNothing(): void
    {
        $id = $this->draft('Bridge reopens', 'The bridge reopens on Monday.');
        $before = $this->articles();
        $list = $this->mcp('reader')->callTool('list_articles', []);
        self::assertFalse($list->isError);
        self::assertContains($id, array_column($list->structuredContent['articles'], 'id'));

        $refused = $this->mcp('reader')->callTool('publish_article', ['article_id' => $id]);
        self::assertTrue($refused->isError);
        self::assertSame('scope_denied', $refused->structuredContent['reason'], 'The reader’s agent token cannot ask.');
        $drafting = $this->mcp('reader')->callTool('save_draft', ['article_id' => $id, 'title' => 'Hijacked', 'body' => 'Not by a reader.']);
        self::assertTrue($drafting->isError);
        // Even a connection given the write scope by mistake asks for nothing: the reader is not an editor.
        $this->database->executeStatement("UPDATE publishing_agent_connections SET scopes = 'articles.read articles.write approval.read' WHERE user_id = 'reader'");
        $denied = $this->mcp('reader')->callTool('publish_article', ['article_id' => $id]);
        self::assertSame('permission_denied', $denied->structuredContent['reason'] ?? null, json_encode($denied->structuredContent, \JSON_THROW_ON_ERROR));
        // The browser's request goes to the same service, which refuses the same way.
        $service = $this->container()->get(ApprovalService::class);
        \assert($service instanceof ApprovalService);
        try {
            $service->request(new Actor('reader', ApprovalActors::TENANT), PublishArticle::ID, ['article_id' => $id]);
            self::fail('A reader asked for a publication.');
        } catch (ApprovalException $error) {
            self::assertSame('permission_denied', $error->reason);
        }
        $page = $this->browser('reader')->request('GET', self::ORIGIN.'/editor/articles/'.$id);
        self::assertCount(0, $page->filter('form[action$="/publish"]'), 'A reader is offered nothing to ask for.');
        self::assertSame($before, $this->articles());
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM verified_human_approval_requests'), 'No request was even recorded.');
    }

    /**
     * What each operation needs before it is asked for, its arguments, and a change, made after the request, to what it
     * named.
     *
     * @return array{array<string, mixed>, \Closure(): void}
     */
    private function scenario(string $operation, string $channel): array
    {
        if ('publish' === $operation || 'delete' === $operation) {
            $id = $this->draft('Bridge reopens', 'The bridge reopens on Monday.');

            // The draft is edited after the request, from the same channel.
            return [['article_id' => $id], function () use ($id, $channel): void {
                $text = ['title' => 'Bridge reopens on Tuesday', 'body' => 'The bridge reopens on Tuesday.'];
                if ('mcp' === $channel) {
                    self::assertFalse($this->mcp()->callTool('save_draft', ['article_id' => $id] + $text)->isError);
                } else {
                    $this->editor()->request('POST', self::ORIGIN.'/editor/articles/'.$id.'/draft', ['_token' => $this->form()] + $text);
                    self::assertSame(302, $this->editor()->getResponse()->getStatusCode());
                }
            }];
        }
        $id = $this->live('Ferry timetable', 'Ferries leave every hour.');
        $arguments = 'edit' === $operation
            ? ['article_id' => $id, 'title' => 'Ferry timetable', 'body' => 'Ferries leave every half hour.']
            : ['article_ids' => [$this->live('Library hours', 'Open until 19:00.'), $id]];

        // Another live edit, asked for from the same channel and approved first: what visitors see moves on.
        return [$arguments, function () use ($id, $channel): void {
            $newer = $this->ask($channel, 'edit', ['article_id' => $id, 'title' => 'Ferry timetable', 'body' => 'Ferries leave every twenty minutes.']);
            self::assertSame('completed', $this->approve($newer)['status']);
        }];
    }

    /** Asks for one of the four changes, from the editor's browser or their agent, and returns the pending request's id. */
    private function ask(string $channel, string $operation, array $arguments): string
    {
        if ('mcp' === $channel) {
            $result = $this->mcp()->callTool(self::TOOLS[$operation], $arguments);
            self::assertFalse($result->isError, json_encode($result->structuredContent, \JSON_THROW_ON_ERROR));
            self::assertSame('pending', $result->structuredContent['status']);
            self::assertStringStartsWith(self::ORIGIN.'/approval/apr_', $result->structuredContent['review_url']);

            return $result->structuredContent['requestId'];
        }
        [$path, $fields] = match ($operation) {
            'publish' => ['/editor/articles/'.$arguments['article_id'].'/publish', []],
            'edit' => ['/editor/articles/'.$arguments['article_id'].'/live', ['title' => $arguments['title'], 'body' => $arguments['body']]],
            'archive' => ['/editor/archive', ['article_ids' => $arguments['article_ids']]],
            'delete' => ['/editor/articles/'.$arguments['article_id'].'/delete', []],
            default => throw new \InvalidArgumentException($operation),
        };
        $browser = $this->editor();
        $browser->request('POST', self::ORIGIN.$path, ['_token' => $this->form()] + $fields);
        $location = (string) $browser->getResponse()->headers->get('Location');
        if (1 !== preg_match('#^/approval/(apr_[0-9a-f]{32})\?return=#', $location, $match)) {
            self::fail('Not sent to a review: '.$this->refusal());
        }

        return $match[1];
    }

    /**
     * Approves with the editor's passkey: opens the review, begins the ceremony and completes it, with $between run
     * after the person began and before they complete. Returns the completion's answer, or the refusal to begin.
     *
     * @return array<string, mixed>
     */
    private function approve(string $id, ?\Closure $between = null): array
    {
        $browser = $this->editor();
        $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        // The review page shows the panel only while the request waits for the person.
        $panel = PasskeyPanel::find($browser);
        if (null === $panel) {
            return ['status' => 'rejected', 'reason' => 'not_pending'];
        }
        $ceremony = $panel->begin();
        if ($ceremony->refused()) {
            return $ceremony->data;
        }
        if (null !== $between) {
            // The same browser may go elsewhere meanwhile, as another tab of the person's would: the panel keeps
            // what its page held.
            $between();
        }

        return $panel->complete($ceremony->data['ceremony_id'], $this->passkey->assertion($ceremony->data['publicKey']))->data;
    }

    private function review(string $id): Crawler
    {
        $crawler = $this->editor()->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $this->editor()->getResponse()->getStatusCode());

        return $crawler;
    }

    /** A new draft, saved from the editor's browser. */
    private function draft(string $title, string $body): string
    {
        $this->editor()->request('POST', self::ORIGIN.'/editor/articles', ['_token' => $this->form(), 'title' => $title, 'body' => $body]);
        $location = (string) $this->editor()->getResponse()->headers->get('Location');
        if (1 !== preg_match('#/editor/articles/([0-9a-f]{16})$#', $location, $match)) {
            self::fail('No draft: '.$this->refusal());
        }

        return $match[1];
    }

    /** A live article: a draft, published with the editor's passkey. */
    private function live(string $title, string $body): string
    {
        $id = $this->draft($title, $body);
        self::assertSame('completed', $this->approve($this->ask('web', 'publish', ['article_id' => $id]))['status']);

        return $id;
    }

    /** The editor's browser, signed in, with a passkey enrolled through their one-time link. */
    private function editor(): KernelBrowser
    {
        if (null === $this->editor) {
            $this->editor = $this->browser('editor');
            $this->passkey = $this->enroll($this->editor, 'editor');
        }

        return $this->editor;
    }

    /** The CSRF token of the newsroom's forms, in the editor's session. */
    private function form(): string
    {
        if ('' === $this->form) {
            $this->form = (string) $this->editor()->request('GET', self::ORIGIN.'/editor')->filter('input[name=_token]')->first()->attr('value');
        }

        return $this->form;
    }

    private function browser(string $user): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'localhost:47213']);
        $this->browsers[] = $browser;
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return $browser;
    }

    private function enroll(KernelBrowser $browser, string $user): VirtualAuthenticator
    {
        $browser->request('GET', $this->accounts[$user]['passkey_link']);
        self::assertSame(302, $browser->getResponse()->getStatusCode(), 'The one-time link lets the signed-in person add a passkey.');
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        $registered = PasskeyPanel::open($browser, self::ORIGIN.'/account/passkey')->register($authenticator);
        self::assertSame('registered', $registered->status, (string) $registered);

        return $authenticator;
    }

    private function mcp(string $user = 'editor'): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('publishing-example-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp',
            headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['agent_token']],
            httpClient: new KernelHttpClient($this->kernel(...), $factory, $factory), requestFactory: $factory, streamFactory: $factory,
        ));
        $this->clients[] = $client;

        return $client;
    }

    /** The articles table, as another connection reads it, by id. */
    private function articles(): array
    {
        $articles = [];
        foreach ($this->database->fetchAllAssociative('SELECT * FROM publishing_articles ORDER BY id') as $row) {
            $row['revision'] = null === $row['revision'] ? null : (int) $row['revision'];
            ksort($row);
            $articles[$row['id']] = $row;
        }

        return $articles;
    }

    private function publicPage(): Crawler
    {
        $visitor = new KernelBrowser($this->kernel(), ['HTTP_HOST' => 'localhost:47213']);
        $this->browsers[] = $visitor;
        $page = $visitor->request('GET', self::ORIGIN.'/');
        self::assertSame(200, $visitor->getResponse()->getStatusCode());

        return $page;
    }

    /** @return list<string> the articles on the public page */
    private function publicIds(): array
    {
        return $this->publicPage()->filter('[data-article]')->each(static fn (Crawler $article): string => (string) $article->attr('data-article'));
    }

    /** @return list<string> the articles on the public page that show "Approved with a passkey" */
    private function badged(): array
    {
        return array_values(array_filter($this->publicPage()->filter('[data-article]')->each(
            static fn (Crawler $article): ?string => $article->filter('.vha-badge')->count() > 0 ? (string) $article->attr('data-article') : null,
        )));
    }

    /** Why the newsroom refused: the message on the page it sent the editor back to. */
    private function refusal(): string
    {
        $response = $this->editor()->getResponse();
        if (!$response->isRedirect()) {
            return $response->getStatusCode().' '.substr((string) $response->getContent(), 0, 500);
        }

        return $this->editor()->followRedirect()->filter('.notice.error')->text('(no message)');
    }

    /**
     * The table as it was, with these changes to these rows.
     *
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $changes
     *
     * @return array<string, array<string, mixed>>
     */
    private static function changed(array $before, array $changes): array
    {
        foreach ($changes as $id => $change) {
            $before[$id] = $change + $before[$id];
            ksort($before[$id]);
        }

        return $before;
    }

    private function container(): ContainerInterface
    {
        $kernel = $this->kernel();
        $kernel->boot();
        $this->kernels[] = $kernel;
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    private function kernel(): PublishingKernel
    {
        return new PublishingKernel('test', false);
    }

    /** @param array<string, string> $values */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->environment[$name] ??= $_SERVER[$name] ?? getenv($name);
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    private static function databaseUrl(string $kind, string $location): string
    {
        if ('sqlite' === $kind || 'sqlite-wal' === $kind) {
            return 'sqlite:///'.$location;
        }
        $server = parse_url((string) getenv('postgresql' === $kind ? 'VERIFIED_HUMAN_APPROVAL_POSTGRES_URL' : 'VERIFIED_HUMAN_APPROVAL_MYSQL_URL'));
        \assert(\is_array($server) && isset($server['host']));
        $credentials = isset($server['user']) ? rawurlencode(urldecode($server['user'])).(isset($server['pass']) ? ':'.rawurlencode(urldecode($server['pass'])) : '').'@' : '';

        // With the charset in the URL, as Symfony's recipe writes it, DoctrineBundle needs no serverVersion.
        return ('postgresql' === $kind ? 'postgresql' : 'mysql').'://'.$credentials.$server['host'].(isset($server['port']) ? ':'.$server['port'] : '').'/'.$location
            .('mysql' === $kind ? '?charset=utf8mb4' : '?charset=utf8');
    }
}
