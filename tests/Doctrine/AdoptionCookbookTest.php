<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\CreateLegacyDraft;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\LegacyPage;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\PublishLegacyDraft;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/** Executable recipes for an existing non-null CMS workflow and generated revision metadata. */
final class AdoptionCookbookTest extends TestCase
{
    private string $location;
    private OrmFixture $fixture;
    private PublishLegacyDraft $publish;
    private ApprovalService $service;

    protected function setUp(): void
    {
        $this->location = OrmFixture::prepare('sqlite');
        $this->fixture = new OrmFixture('sqlite', $this->location, additionalActions: function (OrmFixture $fixture): array {
            $draft = new CreateLegacyDraft($fixture->doctrine, $fixture->guard, $fixture->transaction, $fixture->protection);
            $this->publish = new PublishLegacyDraft($fixture->doctrine, $fixture->guard, $fixture->transaction, $fixture->protection);

            return [$draft, $this->publish];
        });
        $this->service = $this->fixture->service;
        foreach (['legacy.create_draft' => 0, 'legacy.publish' => 1] as $action => $required) {
            $this->fixture->connection->insert('test_policies', ['action_id' => $action, 'version' => 1, 'statement' => 'I approve this exact change.', 'required' => $required]);
        }
    }

    protected function tearDown(): void
    {
        TestDatabase::drop('sqlite', $this->location);
    }

    public function testANonNullDraftStatusRequiresTheGuardEvenWhenCreationIsPolicyExempt(): void
    {
        $manager = $this->fixture->manager();
        $manager->persist(new LegacyPage(1, 'tenant-a', 'Unreviewed draft'));
        try {
            $manager->flush();
            self::fail('A non-null draft status bypassed protected insertion.');
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason);
            self::assertStringContainsString('::$status', $error->getMessage());
        }
        $manager->clear();

        $created = $this->service->request($this->fixture->actor, 'legacy.create_draft', ['id' => 1, 'body' => 'Private draft']);
        self::assertSame('completed', $created->status);
        self::assertSame(['id' => 1, 'status' => 'draft', 'revision' => 0], $created->result);
        self::assertNull($created->proof, 'A policy-exempt write has no human approval proof.');
        self::assertSame('not_required', $created->execution['approval']);
        self::assertSame('draft', $this->fixture->connection->fetchOne('SELECT status FROM orm_legacy_pages WHERE id = 1'));

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage('Status is set by this action');
        $this->service->request($this->fixture->actor, 'legacy.create_draft', ['id' => 2, 'body' => 'Injected publication', 'status' => 'published']);
    }

    public function testPublicationAndArchiveStatusCannotBeChangedByOrdinaryFlush(): void
    {
        $this->service->request($this->fixture->actor, 'legacy.create_draft', ['id' => 1, 'body' => 'Private draft']);
        foreach (['published', 'archived'] as $status) {
            $manager = $this->fixture->manager();
            $page = $manager->find(LegacyPage::class, 1);
            self::assertNotNull($page);
            $page->status = $status;
            try {
                $manager->flush();
                self::fail('An ordinary flush changed a protected workflow status.');
            } catch (ApprovalException $error) {
                self::assertSame('approval_required', $error->reason);
            }
            $manager->clear();
            self::assertSame('draft', $this->fixture->connection->fetchOne('SELECT status FROM orm_legacy_pages WHERE id = 1'));
        }
    }

    public function testExemptCreationStillRequiresCurrentApplicationAuthority(): void
    {
        $this->fixture->connection->executeStatement('UPDATE test_users SET enabled = 0 WHERE user_id = ? AND tenant = ?', ['alice', 'tenant-a']);
        try {
            $this->service->request($this->fixture->actor, 'legacy.create_draft', ['id' => 1, 'body' => 'Private draft']);
            self::fail('A disabled account created a draft.');
        } catch (ApprovalException $error) {
            self::assertSame('permission_denied', $error->reason);
        }
        self::assertSame(0, (int) $this->fixture->connection->fetchOne('SELECT COUNT(*) FROM orm_legacy_pages'));
    }

    public function testCompletedRetryUsesItsOriginalRevisionAndRejectsNewContentUnderTheOldKey(): void
    {
        $this->service->request($this->fixture->actor, 'legacy.create_draft', ['id' => 1, 'body' => 'Reviewed words']);
        $request = $this->service->request($this->fixture->actor, 'legacy.publish', ['id' => 1], 'publish-1');
        self::assertSame('pending', $request->status);
        self::assertSame(0, $request->request->prepared()->preconditions['revision']);
        self::assertSame(1, $request->request->prepared()->display['revision']);
        $ceremony = $this->service->begin($this->fixture->reviewer, $request->request->id);
        $completed = $this->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame(['id' => 1, 'status' => 'published', 'revision' => 1], $completed->result);

        $retry = $this->service->request($this->fixture->actor, 'legacy.publish', ['id' => 1], 'publish-1');
        self::assertSame(CanonicalJson::encode($completed->toArray()), CanonicalJson::encode($retry->toArray()));
        self::assertSame(CanonicalJson::encode($completed->receipt), CanonicalJson::encode($retry->receipt));
        self::assertSame(1, $this->publish->executions, 'Re-preparing a keyed retry never executes it again.');
        self::assertSame(1, (int) $this->fixture->connection->fetchOne('SELECT revision FROM orm_legacy_pages WHERE id = 1'));

        // Test-only SQL models a later, separately authorized edit. Production writes must use their guarded action.
        // The new body changes semantic intent even with the same page id.
        $this->fixture->manager()->clear();
        $this->fixture->connection->executeStatement('UPDATE orm_legacy_pages SET body = ? WHERE id = ?', ['New words for a later operation', 1]);
        try {
            $this->service->request($this->fixture->actor, 'legacy.publish', ['id' => 1], 'publish-1');
            self::fail('New content reused the old approval.');
        } catch (ApprovalException $error) {
            self::assertSame('idempotency_conflict', $error->reason);
        }
        self::assertSame(1, $this->publish->executions);
    }
}
