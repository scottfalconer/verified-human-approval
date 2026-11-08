<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Core;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalState;
use ExactApproval\Core\CanonicalJson;
use ExactApproval\Tests\Support\CoreFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovalServiceTest extends TestCase
{
    private CoreFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
    }

    public function testRequestFreezesHumanMeaningButDoesNotPerformProtectedMutation(): void
    {
        $state = $this->request();
        self::assertSame('pending', $state->status);
        self::assertSame('I approve publishing Summer Campaign, revision 17.', $state->request->statement());
        self::assertSame('Closing date: June 20.', $state->request->prepared()->effect['body']);
        self::assertSame(17, $state->request->prepared()->preconditions['revision']);
        self::assertSame('agent-1', $state->request->actor()->delegationId);
        self::assertNull($state->proof);
        self::assertNull($state->execution);
        self::assertSame(0, $this->fixture->effectCount());
        self::assertSame(0, (int) $this->fixture->article()['published']);
    }

    public function testApprovalCommitsOneEffectAndReplayReturnsTheStoredResult(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $proof = $this->fixture->proof($ceremony);
        $completed = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $proof);
        self::assertSame('completed', $completed->status);
        self::assertSame(1, $this->fixture->effectCount());
        self::assertSame(1, (int) $this->fixture->article()['published']);
        self::assertSame(17, $completed->result['source_revision']);
        self::assertSame(18, $completed->result['result_revision']);
        self::assertSame($state->request->canonical, $completed->request->canonical);
        self::assertNotNull($completed->proof);
        self::assertNotNull($completed->execution);

        $again = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $proof);
        self::assertSame('completed', $again->status);
        self::assertSame(CanonicalJson::encode($completed->result), CanonicalJson::encode($again->result));
        self::assertSame(CanonicalJson::encode($completed->execution), CanonicalJson::encode($again->execution));
        self::assertSame(1, $this->fixture->effectCount());
        self::assertSame(1, $this->fixture->action->executeCalls);
        self::assertSame(1, $this->fixture->verifier->verifyCalls);
        self::assertSame(1, (int) $this->fixture->pdo->query("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'")->fetchColumn());
    }

    public function testLostCompletionResponseCanBeRecoveredByAnAuthoritativeStatusRead(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        // Treat the response above as lost. Recover from persistence, not a second action call.
        $recovered = $this->fixture->service->get($this->fixture->actor, $state->request->id);
        self::assertSame('completed', $recovered->status);
        self::assertSame(18, $recovered->result['result_revision']);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testAnUnusedProofForAnotherProposalCannotBeSubstituted(): void
    {
        $a = $this->request(1);
        $b = $this->request(2);
        $ceremonyA = $this->fixture->service->begin($this->fixture->reviewer, $a->request->id);
        $ceremonyB = $this->fixture->service->begin($this->fixture->reviewer, $b->request->id);
        self::assertNotSame($ceremonyA->challenge, $ceremonyB->challenge);

        $this->assertRejected(fn () => $this->fixture->service->complete($this->fixture->reviewer, $ceremonyB->id, $this->fixture->proof($ceremonyA)));
        self::assertSame(0, $this->fixture->effectCount());

        // This is not a replay test: the original valid proof has not been used.
        $completedA = $this->fixture->service->complete($this->fixture->reviewer, $ceremonyA->id, $this->fixture->proof($ceremonyA));
        self::assertSame('completed', $completedA->status);
        self::assertSame(1, (int) $this->fixture->article(1)['published']);
        self::assertSame(0, (int) $this->fixture->article(2)['published']);
    }

    public function testDifferentUsersAndTenantsCannotReadReviewCancelOrCompleteARequest(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        foreach ([new Actor('bob', 'tenant-a'), new Actor('alice', 'tenant-b')] as $stranger) {
            foreach ([
                fn () => $this->fixture->service->get($stranger, $state->request->id),
                fn () => $this->fixture->service->begin($stranger, $state->request->id),
                fn () => $this->fixture->service->cancel($stranger, $state->request->id),
                fn () => $this->fixture->service->complete($stranger, $ceremony->id, $this->fixture->proof($ceremony)),
            ] as $attempt) {
                $this->assertRejected($attempt);
            }
        }
        self::assertSame('pending', $this->fixture->service->get($this->fixture->actor, $state->request->id)->status);
        self::assertSame(0, $this->fixture->effectCount());

        $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        // Check ownership before returning a cached completed result as well.
        $this->assertRejected(fn () => $this->fixture->service->get(new Actor('bob', 'tenant-a'), $state->request->id));
        $this->assertRejected(fn () => $this->fixture->service->complete(new Actor('bob', 'tenant-a'), $ceremony->id, $this->fixture->proof($ceremony)));
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testInitiatorCannotPrepareAnActionOnAnotherTenantsResource(): void
    {
        $this->assertRejected(fn () => $this->request(3));
        self::assertSame(0, $this->fixture->effectCount());
    }

    /** @return iterable<string, array{string}> */
    public static function resourceChanges(): iterable
    {
        yield 'one character' => ["UPDATE test_articles SET body = 'Closing date: June 21.' WHERE id = 1"];
        yield 'content whitespace' => ["UPDATE test_articles SET body = body || ' ' WHERE id = 1"];
        yield 'same content new revision' => ['UPDATE test_articles SET revision = revision + 1 WHERE id = 1'];
        yield 'same URL different asset digest' => ["UPDATE test_articles SET asset_version = 'sha256:changed-at-same-url' WHERE id = 1"];
        yield 'publication precondition' => ['UPDATE test_articles SET published = 1 WHERE id = 1'];
    }

    #[DataProvider('resourceChanges')]
    public function testMeaningfulSourceAndDependencyChangesInvalidateApproval(string $change): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->pdo->exec($change);
        $result = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('invalidated', $result->status);
        self::assertSame(0, $this->fixture->effectCount());
        self::assertNull($result->execution);
    }

    /** @return iterable<string, array{string}> */
    public static function policyChanges(): iterable
    {
        yield 'policy version' => ["UPDATE test_policies SET version = '2' WHERE action_id = 'article.publish'"];
        yield 'statement changed without version bump' => ["UPDATE test_policies SET statement = statement || '!' WHERE action_id = 'article.publish'"];
        yield 'protection disabled' => ["UPDATE test_policies SET required = 0 WHERE action_id = 'article.publish'"];
    }

    #[DataProvider('policyChanges')]
    public function testPolicyChangesInvalidateAlreadyPendingProposals(string $change): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->pdo->exec($change);
        $result = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('invalidated', $result->status);
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testImplementationVersionChangeInvalidatesPendingApproval(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->action->implementationVersion = '2';
        $result = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('invalidated', $result->status);
        self::assertSame(0, $this->fixture->effectCount());
    }

    /** @return iterable<string, array{string}> */
    public static function authorityRevocations(): iterable
    {
        yield 'user disabled' => ["UPDATE test_users SET enabled = 0 WHERE user_id = 'alice' AND tenant = 'tenant-a'"];
        yield 'user role removed' => ["UPDATE test_users SET can_publish = 0 WHERE user_id = 'alice' AND tenant = 'tenant-a'"];
        yield 'delegation revoked' => ["UPDATE test_delegations SET active = 0 WHERE id = 'agent-1'"];
        yield 'delegated scope removed' => ["UPDATE test_delegations SET can_publish = 0 WHERE id = 'agent-1'"];
    }

    #[DataProvider('authorityRevocations')]
    public function testFinalAuthorityComesFromCurrentUserAndInitiatingDelegation(string $change): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $checks = $this->fixture->authorizer->checks;
        $this->fixture->pdo->exec($change);
        $result = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('denied', $result->status);
        self::assertGreaterThan($checks, $this->fixture->authorizer->checks);
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testRevokedCredentialCannotCompleteAPreviouslyIssuedCeremony(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->pdo->exec("UPDATE test_credentials SET active = 0 WHERE id = 'alice-key'");
        $this->assertRejected(fn () => $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony)));
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testNoAcceptedCredentialHasNoWeakerApprovalFallback(): void
    {
        $state = $this->request();
        $this->fixture->pdo->exec("UPDATE test_credentials SET active = 0 WHERE user_id = 'alice'");
        $this->assertRejected(fn () => $this->fixture->service->begin($this->fixture->reviewer, $state->request->id));
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testApprovalsExpireAtTheirDeadline(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->clock->sleep(600);
        $result = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('expired', $result->status);
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testCancelledApprovalCannotBeCompleted(): void
    {
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        self::assertSame('cancelled', $this->fixture->service->cancel($this->fixture->reviewer, $state->request->id)->status);
        $result = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('cancelled', $result->status);
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testFreshCeremoniesHaveDistinctNoncesAndChallengesForTheSameProposal(): void
    {
        $state = $this->request();
        $first = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $second = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        self::assertNotSame($first->id, $second->id);
        self::assertNotSame($first->nonce, $second->nonce);
        self::assertNotSame($first->challenge, $second->challenge);
        self::assertSame('required', $first->options['userVerification']);
    }

    public function testSameIdempotencyKeyReusesTheOriginalProposalAndRejectsChangedInput(): void
    {
        $first = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'request-123');
        $again = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'request-123');
        self::assertSame($first->request->id, $again->request->id);
        self::assertSame($first->request->canonical, $again->request->canonical);
        $this->assertRejected(fn () => $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 2], 'request-123'));
        self::assertSame($first->request->canonical, $this->fixture->service->get($this->fixture->actor, $first->request->id)->request->canonical);
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testIdempotencyKeysAreScopedToUserTenantAndDelegation(): void
    {
        $first = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'same-key');
        $otherUser = $this->fixture->service->request(new Actor('bob', 'tenant-a'), 'article.publish', ['id' => 1], 'same-key');
        $otherDelegation = $this->fixture->service->request(new Actor('alice', 'tenant-a', 'agent-2'), 'article.publish', ['id' => 1], 'same-key');
        $otherTenant = $this->fixture->service->request(new Actor('alice', 'tenant-b'), 'article.publish', ['id' => 3], 'same-key');
        self::assertCount(4, array_unique([$first->request->id, $otherUser->request->id, $otherDelegation->request->id, $otherTenant->request->id]));
    }

    public function testIdempotentRetryAfterCompletionCannotPrepareAndExecuteAgain(): void
    {
        $first = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'once-only');
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $first->request->id);
        $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        $again = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'once-only');
        self::assertSame('completed', $again->status);
        self::assertSame($first->request->id, $again->request->id);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testKeylessRetryReusesThePendingProposal(): void
    {
        $first = $this->request();
        $again = $this->request();
        self::assertSame($first->request->id, $again->request->id);
        self::assertSame($first->request->canonical, $again->request->canonical);
        self::assertSame('pending', $again->status);
        self::assertSame(1, (int) $this->fixture->pdo->query("SELECT COUNT(*) FROM exact_approval_requests WHERE status = 'pending'")->fetchColumn());
        self::assertSame(0, $this->fixture->effectCount());
    }

    public function testKeylessReuseIsScopedToTheSameDelegation(): void
    {
        $first = $this->request();
        $otherAgent = $this->fixture->service->request(new Actor('alice', 'tenant-a', 'agent-2'), 'article.publish', ['id' => 1]);
        self::assertNotSame($first->request->id, $otherAgent->request->id);
    }

    public function testKeylessRetryAfterCancellationPreparesAFreshProposal(): void
    {
        $first = $this->request();
        self::assertSame('cancelled', $this->fixture->service->cancel($this->fixture->reviewer, $first->request->id)->status);
        $again = $this->request();
        self::assertNotSame($first->request->id, $again->request->id);
        self::assertSame('pending', $again->status);
    }

    public function testKeylessRetryDoesNotReuseAProposalWhoseSourceChanged(): void
    {
        $first = $this->request();
        $this->fixture->pdo->exec('UPDATE test_articles SET revision = revision + 1 WHERE id = 1');
        $again = $this->request();
        self::assertNotSame($first->request->id, $again->request->id);
        self::assertSame(18, $again->request->prepared()->preconditions['revision']);
        self::assertSame('invalidated', $this->fixture->service->get($this->fixture->reviewer, $first->request->id)->status);
    }

    public function testUnprotectedPolicyIsAnExplicitSeparatePathWithNoInventedApprovalProof(): void
    {
        $this->fixture->pdo->exec("UPDATE test_policies SET required = 0 WHERE action_id = 'article.publish'");
        $state = $this->request();
        self::assertSame('completed', $state->status);
        self::assertNull($state->proof);
        self::assertNotNull($state->execution);
        self::assertSame(1, $this->fixture->effectCount());
        self::assertSame(0, $this->fixture->verifier->verifyCalls);
    }

    public function testMutationServiceRejectsCallsOutsideTheGuardIncludingAfterAValidExecution(): void
    {
        $prepared = $this->fixture->action->prepare($this->fixture->actor, ['id' => 1]);
        $this->assertRejected(fn () => $this->fixture->action->execute($this->fixture->actor, $prepared));
        $this->assertRejected(fn () => $this->fixture->guard->within(new \stdClass(), $this->fixture->actor, $prepared, fn () => $this->fixture->action->execute($this->fixture->actor, $prepared)));
        self::assertSame(0, $this->fixture->effectCount());
        $state = $this->request();
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $state->request->id);
        $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        $this->assertRejected(fn () => $this->fixture->action->execute($this->fixture->actor, $prepared));
        self::assertSame(1, $this->fixture->effectCount());
    }

    private function request(int $id = 1): ApprovalState
    {
        return $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => $id]);
    }

    /** A deliberate core rejection may be a terminal outcome or an input/ownership exception. */
    private function assertRejected(callable $attempt): void
    {
        try {
            $result = $attempt();
            if ($result instanceof ApprovalState) {
                self::assertContains($result->status, ['denied', 'invalidated', 'expired', 'cancelled']);
                return;
            }
            self::fail('The unapproved operation did not reject the attempt.');
        } catch (ApprovalException $exception) {
            self::assertNotSame('', $exception->reason);
        }
    }
}
