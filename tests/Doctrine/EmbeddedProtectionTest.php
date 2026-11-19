<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutingApproval;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\Tests\Doctrine\Embedded\Page;

/**
 * Protected state held in embedded objects. Doctrine names an embedded field by its path (published.title), and the
 * flush check protects those fields as it protects the entity's own.
 */
final class EmbeddedProtectionTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private ExecutionGuard $guard;
    private object $owner;
    private ProtectedEntityListener $listener;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig([__DIR__.'/Embedded'], true)
            : ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/Embedded'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir().'/verified-human-approval-orm-proxies');
            $config->setProxyNamespace('VerifiedHumanApprovalOrmProxies');
            $config->setAutoGenerateProxyClasses(true);
        }
        $this->manager = new EntityManager($this->connection, $config);
        $this->guard = new ExecutionGuard();
        // The guard's owner stands in for the approval service, so the test can run a flush as an approved action.
        $this->owner = new \stdClass();
        $this->guard->bind($this->owner);
        $this->listener = new ProtectedEntityListener($this->guard, [Page::class => ['scheduled']]);
        $this->manager->getEventManager()->addEventListener([Events::onFlush, Events::preUpdate], $this->listener);
        (new SchemaTool($this->manager))->createSchema([$this->manager->getClassMetadata(Page::class)]);
        $this->connection->insert('embedded_pages', ['id' => 1, 'draft' => 'Draft words.', 'published_title' => 'Approved title', 'published_body' => 'Approved words.']);
    }

    public function testAProtectedEmbeddedFieldChangesOnlyInsideItsApprovedExecution(): void
    {
        $changes = [
            'the marked embedded object' => [static function (Page $page): void { $page->published->title = 'Not approved'; }, 'published.title'],
            'the embedded object the configuration names' => [static function (Page $page): void { $page->scheduled->body = 'Not approved'; }, 'scheduled.body'],
            'the field its embeddable marks' => [static function (Page $page): void { $page->review->signedOffBy = 'mallory'; }, 'review.signedOffBy'],
        ];
        foreach ($changes as $what => [$change, $field]) {
            $page = $this->page();
            $change($page);
            $this->assertRefused($field, $what);
            $this->manager->clear();
        }
        self::assertSame(['Approved title', 'Approved words.', null, null], $this->stored(['published_title', 'published_body', 'scheduled_body', 'review_signedOffBy']));

        // The draft and the embeddable's unmarked field stay free to edit.
        $page = $this->page();
        $page->draft = 'Edited draft.';
        $page->review->remark = 'Looks good.';
        $this->manager->flush();

        // An approved action that permits the page may change what it approved.
        $page->published->title = 'Approved again';
        $this->guard->within($this->owner, new Actor('alice', 'tenant-a'), self::action(), function () use ($page): void {
            $this->listener->permit($page);
            $this->manager->flush();
        }, new ExecutingApproval('apr_1', 'page.publish', 'user_verified'));
        self::assertSame(['Edited draft.', 'Looks good.', 'Approved again'], $this->stored(['draft', 'review_remark', 'published_title']));
    }

    public function testInsertingOrDeletingAnEmbeddedApprovedStateIsAProtectedChange(): void
    {
        // A draft whose embedded copies are empty holds nothing approved, so it comes and goes freely.
        $draft = new Page(2);
        $draft->draft = 'A new draft.';
        $this->manager->persist($draft);
        $this->manager->flush();
        $this->manager->remove($draft);
        $this->manager->flush();

        $published = new Page(3);
        $published->published->body = 'Never approved.';
        $this->manager->persist($published);
        $this->assertRefused('inserted');
        $this->manager->clear();

        $this->manager->remove($this->page());
        $this->assertRefused('deleted');
        self::assertSame(['Approved title'], $this->stored(['published_title']));
    }

    private function page(): Page
    {
        $page = $this->manager->find(Page::class, 1);
        self::assertNotNull($page);

        return $page;
    }

    private function assertRefused(string $mentioning, string $what = ''): void
    {
        try {
            $this->manager->flush();
            self::fail('A protected change was flushed without an approval: '.$what);
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason, $what);
            self::assertStringContainsString($mentioning, $error->getMessage(), $what);
        }
    }

    /**
     * @param list<string> $columns
     *
     * @return list<mixed>
     */
    private function stored(array $columns): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM embedded_pages WHERE id = 1');
        self::assertIsArray($row);

        return array_map(static fn (string $column): mixed => $row[$column], $columns);
    }

    private static function action(): PreparedAction
    {
        return new PreparedAction('page.publish', '1', ['resource' => 1], ['title' => 'Approved again'], [], ['title' => 'Approved again']);
    }
}
