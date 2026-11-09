<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Document;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Memo;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Notice;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * An approved action on Doctrine ORM entities: the flush nests in the approval transaction, a rollback resets the
 * entity manager, an outer ORM transaction is refused, and protected entities change only through an approval.
 */
abstract class EntityActionTestCase extends TestCase
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private ?string $location = null;
    private OrmFixture $fixture;

    protected function setUp(): void
    {
        $this->location = OrmFixture::prepare(static::KIND);
        $this->fixture = new OrmFixture(static::KIND, $this->location);
    }

    protected function tearDown(): void
    {
        if (null !== $this->location) {
            TestDatabase::drop(static::KIND, $this->location);
        }
    }

    public function testTheActionsFlushCommitsWithTheApprovalAndItsReceipt(): void
    {
        [$request, $ceremony] = $this->fixture->pending();
        $completed = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('completed', $completed->status);
        self::assertSame(['document' => 1, 'published_revision' => 3], $completed->result);

        $stored = $this->fixture->stored();
        self::assertSame(['Spring notice', 'Approved words.', 3, $request->request->id], [$stored['published_title'], $stored['published_body'], (int) $stored['published_revision'], $stored['approval_request_id']]);
        $receipt = (new ReceiptVerifier())->verify((new EvidenceExporter())->export($completed), $this->fixture->signer->keyId(), $this->fixture->signer->publicKey());
        self::assertSame(Receipt::COMMIT, $receipt['receiptSignedAt'], 'The receipt was signed after the flush, in the same transaction.');
        self::assertFalse($this->fixture->connection->isTransactionActive());
    }

    public function testAFailureAfterTheFlushRollsItBackAndResetsTheEntityManager(): void
    {
        [, $ceremony] = $this->fixture->pending();
        $this->fixture->action->breakPostcondition = true;
        $before = $this->fixture->manager();
        try {
            $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
            self::fail('A broken postcondition was reported as success.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
        }
        self::assertSame(1, $this->fixture->action->executions, 'The flush happened, then the transaction rolled back.');
        self::assertNull($this->fixture->stored()['published_title']);
        self::assertGreaterThan(0, $this->fixture->doctrine->resets);
        $after = $this->fixture->manager();
        self::assertNotSame($before, $after, 'The manager whose objects held the rolled-back change was replaced.');
        $document = $after->find(Document::class, 1);
        self::assertNotNull($document);
        self::assertNull($document->publishedTitle, 'Nothing in memory claims what never committed.');
    }

    public function testAnApprovalInsideAnOrmTransactionIsRefused(): void
    {
        $manager = $this->fixture->manager();
        try {
            $manager->wrapInTransaction(fn () => $this->fixture->service->request($this->fixture->actor, 'document.publish', ['id' => 1]));
            self::fail('The approval ran inside a transaction it does not own.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('wrapInTransaction()', $error->getMessage());
            self::assertStringContainsString('doctrine_transaction', $error->getMessage());
        }
        self::assertFalse($this->fixture->connection->isTransactionActive());
        self::assertSame(0, (int) $this->fixture->connection->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::REQUESTS));
        // wrapInTransaction() closes its entity manager on any failure; the action resets a closed one when it runs.
        self::assertFalse($manager->isOpen());
        [, $ceremony] = $this->fixture->pending();
        self::assertSame('completed', $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony))->status);
    }

    public function testAnEntityLoadedBeforeTheApprovalIsReadAsStoredNow(): void
    {
        $loaded = $this->fixture->manager()->find(Document::class, 1);
        self::assertNotNull($loaded);
        $this->edit(1, 'Edited words.');
        $request = $this->fixture->service->request($this->fixture->actor, 'document.publish', ['id' => 1]);
        self::assertSame('Edited words.', $request->request->prepared()->effect['body'], 'The proposal froze the stored draft, not the one in memory.');

        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $request->request->id);
        $this->edit(1, 'Edited again.');
        $outcome = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('invalidated', $outcome->status, 'The preconditions read the stored row, not a copy loaded earlier.');
        self::assertNull($this->fixture->stored()['published_title']);
    }

    public function testAProtectedFieldChangesOnlyInsideItsApprovedExecution(): void
    {
        $manager = $this->fixture->manager();
        $document = $manager->find(Document::class, 1);
        self::assertNotNull($document);
        $document->title = 'A new draft title';
        $manager->flush();
        self::assertSame('A new draft title', $this->fixture->stored()['title'], 'Drafts stay free to edit.');

        $document->publishedTitle = 'Published without approval';
        $this->assertRefusedFlush($manager, 'publishedTitle');
        self::assertNull($this->fixture->stored()['published_title']);
    }

    public function testAnApprovedActionCannotChangeAProtectedEntityItDidNotLock(): void
    {
        [, $ceremony] = $this->fixture->pending();
        $this->fixture->action->alsoPublish = 2;
        try {
            $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
            self::fail('A protected change nothing approved was flushed.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
            self::assertInstanceOf(ApprovalException::class, $error->getPrevious());
            self::assertSame('approval_required', $error->getPrevious()->reason);
        }
        self::assertNull($this->fixture->stored(1)['published_title']);
        self::assertNull($this->fixture->stored(2)['published_title']);
    }

    public function testInsertingOrDeletingAnApprovedStateIsAProtectedChange(): void
    {
        $manager = $this->fixture->manager();
        $draft = new Document(10, 'tenant-a', 'Draft', 'Draft words.');
        $manager->persist($draft);
        $manager->flush();
        $manager->remove($draft);
        $manager->flush();

        $published = new Document(11, 'tenant-a', 'Draft', 'Draft words.');
        $published->publishedBody = 'Published without approval';
        $manager->persist($published);
        $this->assertRefusedFlush($manager, 'inserted');
        $manager->clear();

        [, $ceremony] = $this->fixture->pending();
        $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        $manager = $this->fixture->manager();
        $document = $manager->find(Document::class, 1);
        self::assertNotNull($document);
        $manager->remove($document);
        $this->assertRefusedFlush($manager, 'deleted');
    }

    public function testAWholeProtectedEntityAndAConfiguredFieldNeedAnApproval(): void
    {
        $manager = $this->fixture->manager();
        $notice = $manager->find(Notice::class, 1);
        self::assertNotNull($notice);
        $notice->text = 'Closed today';
        $this->assertRefusedFlush($manager, Notice::class);
        $manager->clear();
        $manager->persist(new Notice(2, 'Another'));
        $this->assertRefusedFlush($manager, Notice::class);
        $manager->clear();

        $memo = $manager->find(Memo::class, 1);
        self::assertNotNull($memo);
        $memo->text = 'Still free to edit';
        $manager->flush();
        $memo->signedOff = 'by nobody';
        $this->assertRefusedFlush($manager, 'signedOff');
    }

    public function testAListenerThatChangesAProtectedFieldDuringAFlushIsCaught(): void
    {
        $manager = $this->fixture->manager();
        // Registered after the protection, as a listener added later would be: it runs after the check.
        $manager->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(OnFlushEventArgs $event): void
            {
                $work = $event->getObjectManager()->getUnitOfWork();
                foreach ($work->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Document) {
                        $entity->publishedBody = 'Slipped in by a listener';
                        $work->recomputeSingleEntityChangeSet($event->getObjectManager()->getClassMetadata(Document::class), $entity);
                    }
                }
            }
        });
        $document = $manager->find(Document::class, 1);
        self::assertNotNull($document);
        $document->title = 'An ordinary draft edit';
        $this->assertRefusedFlush($manager, 'publishedBody');
        self::assertSame('Spring notice', $this->fixture->stored()['title'], 'The whole flush rolled back.');
    }

    public function testOnlyAnExecutingApprovalCanPermitAProtectedChange(): void
    {
        $this->expectException(\LogicException::class);
        $this->fixture->protection->permit(new Notice(3, 'Self-approved'));
    }

    private function assertRefusedFlush(EntityManagerInterface $manager, string $mentioning): void
    {
        try {
            $manager->flush();
            self::fail('A protected change was flushed without an approval.');
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason);
            self::assertStringContainsString($mentioning, $error->getMessage());
        }
    }

    private function edit(int $id, string $body): void
    {
        $writer = TestDatabase::connect(static::KIND, $this->location);
        $writer->executeStatement('UPDATE orm_documents SET body = ?, revision = revision + 1 WHERE id = ?', [$body, $id]);
        $writer->close();
    }
}
