<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

/**
 * The approval storage contract (the rules on ApprovalStore), run against one adapter through its harness.
 * Each check observes through a fresh connection. To run it for another adapter, write a StoreHarness for
 * it and a test class that extends this one and names that harness.
 */
abstract class ApprovalStoreContract extends TestCase
{
    private string $location;

    /** @return class-string<StoreHarness> */
    abstract protected static function harness(): string;

    protected function setUp(): void
    {
        $this->location = static::harness()::prepare();
    }

    protected function tearDown(): void
    {
        static::harness()::destroy($this->location);
    }

    public function testACompletionCommitsTheChangeProofExecutionAndReceiptTogether(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $completed = $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        self::assertSame('completed', $completed->status);

        $observer = $this->connect();
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $observer->counts());
        $stored = $observer->service()->get($harness->actor(), $request->request->id);
        self::assertSame($completed->request->canonical, $stored->request->canonical, 'The proposal comes back byte for byte.');
        self::assertSame($completed->receipt, $stored->receipt);
        // The stored proof and record still match the receipt signed at commit, so their canonical JSON survived.
        $verified = (new ReceiptVerifier())->verify(self::export($stored), $harness->receiptKeyId(), $harness->receiptPublicKey());
        self::assertSame(Receipt::COMMIT, $verified['receiptSignedAt']);
    }

    public function testAnUnrelatedOpenTransactionIsRefusedBeforeAnyChange(): void
    {
        $harness = $this->connect();
        try {
            $harness->withAmbientTransaction(static fn () => $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1]));
            self::fail('The approval ran inside a transaction it does not own.');
        } catch (\LogicException) {
        }
        self::assertSame(0, $this->connect()->counts()['requests']);
    }

    public function testAWriteOutsideTheStoresOwnTransactionIsRefused(): void
    {
        $harness = $this->connect();
        $pending = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1]);
        try {
            $harness->store()->save($pending->stopped('cancelled', 'unsafe_write'));
            self::fail('A write outside the owned transaction was accepted.');
        } catch (\LogicException) {
        }
        self::assertSame('pending', $this->connect()->service()->get($harness->actor(), $pending->request->id)->status);
    }

    /** @return iterable<string, array{\Closure(StoreHarness): void}> */
    public static function failuresBeforeCommit(): iterable
    {
        yield 'the action fails after its write' => [static fn (StoreHarness $harness) => $harness->breakAction('after_write')];
        yield 'the action breaks its postcondition' => [static fn (StoreHarness $harness) => $harness->breakAction('postcondition')];
        yield 'the execution record cannot be stored' => [static fn (StoreHarness $harness) => $harness->failInserts('execution')];
        yield 'the receipt cannot be stored' => [static fn (StoreHarness $harness) => $harness->failInserts('receipt')];
        yield 'the receipt cannot be signed' => [static function (StoreHarness $harness): void {
            $harness->faults->signer = true;
        }];
        yield 'the store fails just before commit' => [static function (StoreHarness $harness): void {
            $harness->faults->transactions = ['before_commit'];
        }];
    }

    /** @param \Closure(StoreHarness): void $inject */
    #[DataProvider('failuresBeforeCommit')]
    public function testAFailureBeforeCommitRollsBackEveryWrite(\Closure $inject): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $article = $this->connect()->article();
        $inject($harness);
        try {
            $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
            self::fail('An injected failure was reported as success.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
        }

        $observer = $this->connect();
        self::assertSame(['requests' => 1, 'effects' => 0, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 0], $observer->counts());
        self::assertSame($article, $observer->article(), 'The business row is exactly as it was.');
        $stored = $observer->service()->get($harness->actor(), $request->request->id);
        self::assertSame(['pending', null, null, null], [$stored->status, $stored->proof, $stored->execution, $stored->receipt]);
        self::assertTrue($observer->store()->getCeremony($ceremony->id)?->consumed, 'The failed attempt used its ceremony up.');

        // Once mended, a fresh ceremony completes it, once.
        $harness->breakAction(null);
        $harness->clearInsertFailures();
        $harness->faults->signer = false;
        $fresh = $harness->service()->begin($harness->reviewer(), $request->request->id);
        self::assertSame('completed', $harness->service()->complete($harness->reviewer(), $fresh->id, $harness->proof($fresh))->status);
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $this->connect()->counts());
    }

    public function testACommitWhoseAnswerWasLostIsRecoveredNotRepeated(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->faults->transactions = ['after_commit'];
        $recovered = $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        self::assertSame('completed', $recovered->status, 'Recovery read the outcome that was committed.');
        self::assertSame(1, $harness->executeCalls(), 'The action did not run again.');

        $observer = $this->connect();
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $observer->counts());
        $stored = $observer->service()->get($harness->actor(), $request->request->id);
        self::assertSame($stored->receipt, $recovered->receipt, 'It returns the original receipt, not a new one.');
        self::assertSame(CanonicalJson::encode((object) $stored->result), CanonicalJson::encode((object) $recovered->result));
    }

    /** @return iterable<string, array{'before_commit'|'after_commit', string, int}> */
    public static function lostOutcomes(): iterable
    {
        yield 'it had committed' => ['after_commit', 'completed', 1];
        yield 'it had rolled back' => ['before_commit', 'pending', 0];
    }

    /** @param 'before_commit'|'after_commit' $fault */
    #[DataProvider('lostOutcomes')]
    public function testWhenRecoveryCannotReachTheStoreTheOutcomeIsUncertainAndNothingIsReplayed(string $fault, string $outcome, int $effects): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->faults->transactions = [$fault, 'unavailable'];
        try {
            $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
            self::fail('An outcome nobody could read was reported as known.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_uncertain', $error->reason);
        }
        self::assertSame(1, $harness->executeCalls(), 'Nothing retried the action.');
        $observer = $this->connect();
        self::assertSame($effects, $observer->counts()['effects']);
        self::assertSame($outcome, $observer->service()->get($harness->actor(), $request->request->id)->status, 'A later status read settles it.');
    }

    public function testATerminalRecordCannotBeOverwrittenAndKeepsItsBytes(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        $before = $this->connect()->service()->get($harness->actor(), $request->request->id);
        try {
            $harness->store()->transaction(static fn () => $harness->store()->save(new ApprovalState($request->request, 'cancelled', 'overwrite')));
            self::fail('A terminal record was overwritten.');
        } catch (ApprovalException $error) {
            self::assertSame('immutable_approval', $error->reason);
        }
        $after = $this->connect()->service()->get($harness->actor(), $request->request->id);
        self::assertSame(
            [$before->status, $before->request->canonical, $before->receipt, CanonicalJson::encode((object) $before->proof), CanonicalJson::encode((object) $before->result)],
            [$after->status, $after->request->canonical, $after->receipt, CanonicalJson::encode((object) $after->proof), CanonicalJson::encode((object) $after->result)],
        );
    }

    public function testRequestKeysAndKeylessRetriesReuseTheOriginal(): void
    {
        $harness = $this->connect();
        $service = $harness->service();
        $first = $service->request($harness->actor(), 'article.publish', ['id' => 1], 'operation-1');
        self::assertSame($first->request->id, $service->request($harness->actor(), 'article.publish', ['id' => 1], 'operation-1')->request->id);
        self::assertSame($first->request->id, $service->request($harness->actor(), 'article.publish', ['id' => 1])->request->id, 'A keyless retry reuses the equivalent pending proposal.');
        $harness->editArticle();
        try {
            $service->request($harness->actor(), 'article.publish', ['id' => 1], 'operation-1');
            self::fail('A request key returned a proposal whose content has changed since.');
        } catch (ApprovalException $error) {
            self::assertSame('idempotency_conflict', $error->reason);
        }
        self::assertSame(1, $this->connect()->counts()['requests']);
    }

    public function testARequestBelongsToOneUserAndTenantAndItsKeyToOneConnection(): void
    {
        $harness = $this->connect();
        $request = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'operation-1');
        foreach ([new Actor('bob', 'tenant-a'), new Actor('alice', 'tenant-b')] as $stranger) {
            try {
                $harness->service()->get($stranger, $request->request->id);
                self::fail('Another account read the request.');
            } catch (ApprovalException $error) {
                self::assertSame('not_found', $error->reason);
            }
        }
        $otherConnection = new Actor('alice', 'tenant-a', 'agent-2');
        self::assertNotSame($request->request->id, $harness->service()->request($otherConnection, 'article.publish', ['id' => 1], 'operation-1')->request->id, 'Another connection’s key never reaches this one’s request.');
        self::assertSame('pending', $harness->service()->get($harness->reviewer(), $request->request->id)->status, 'The person, in their browser, reads their own request.');
    }

    public function testCompletionsOfOneCeremonyRacingCommitOnce(): void
    {
        $harness = $this->connect();
        [, $ceremony] = $this->pending($harness);
        $complete = ['operation' => 'complete', 'ceremonyId' => $ceremony->id, 'proof' => $harness->proof($ceremony)];
        $results = $this->race([$complete, $complete, $complete, $complete]);
        self::assertSame(array_fill(0, 4, 'completed'), array_column($results, 'status'));
        self::assertCount(1, array_unique(array_map(static fn (array $result): string => CanonicalJson::encode($result['result']), $results)));
        self::assertSame(1, array_sum(array_column($results, 'executeCalls')), 'One process ran the action.');
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $this->connect()->counts());
        self::assertSame(18, $this->connect()->article()['revision']);
    }

    public function testTwoCeremoniesForOneProposalRacingCommitOnce(): void
    {
        $harness = $this->connect();
        [$request, $first] = $this->pending($harness);
        $second = $harness->service()->begin($harness->reviewer(), $request->request->id);
        $results = $this->race([
            ['operation' => 'complete', 'ceremonyId' => $first->id, 'proof' => $harness->proof($first)],
            ['operation' => 'complete', 'ceremonyId' => $second->id, 'proof' => $harness->proof($second)],
        ]);
        self::assertSame(['completed', 'completed'], array_column($results, 'status'), 'The second reads the outcome the first recorded.');
        self::assertSame(1, array_sum(array_column($results, 'executeCalls')));
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $this->connect()->counts());
    }

    public function testTwoProposalsForTheSameSourceRevisionCannotBothCommit(): void
    {
        $harness = $this->connect();
        // Distinct keys make two proposals for the same revision.
        $a = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'proposal-a');
        $b = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'proposal-b');
        $ceremonyA = $harness->service()->begin($harness->reviewer(), $a->request->id);
        $ceremonyB = $harness->service()->begin($harness->reviewer(), $b->request->id);
        $results = $this->race([
            ['operation' => 'complete', 'ceremonyId' => $ceremonyA->id, 'proof' => $harness->proof($ceremonyA)],
            ['operation' => 'complete', 'ceremonyId' => $ceremonyB->id, 'proof' => $harness->proof($ceremonyB)],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['completed', 'invalidated'], $statuses);
        self::assertSame(['requests' => 2, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $this->connect()->counts());
        self::assertSame(18, $this->connect()->article()['revision']);
    }

    public function testRequestsRacingWithOneKeyMakeOneProposal(): void
    {
        $request = ['operation' => 'request', 'key' => 'same-client-operation'];
        $results = $this->race([$request, $request, $request]);
        self::assertSame(['pending', 'pending', 'pending'], array_column($results, 'status'));
        self::assertCount(1, array_unique(array_column($results, 'requestId')));
        self::assertSame(1, $this->connect()->counts()['requests']);
    }

    public function testKeylessRequestsRacingShareOnePendingProposal(): void
    {
        $request = ['operation' => 'request'];
        $results = $this->race([$request, $request, $request]);
        self::assertSame(['pending', 'pending', 'pending'], array_column($results, 'status'));
        self::assertCount(1, array_unique(array_column($results, 'requestId')));
        self::assertSame(1, $this->connect()->counts()['requests']);
    }

    public function testACompletionRacingAnOrdinaryEditCommitsOnlyTheReviewedContent(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $reviewed = $request->request->prepared()->preconditions['revision'];
        [$approval, $edit] = $this->race([
            ['operation' => 'complete', 'ceremonyId' => $ceremony->id, 'proof' => $harness->proof($ceremony)],
            ['operation' => 'edit'],
        ]);
        self::assertSame('done', $edit['status']);
        $observer = $this->connect();
        if ('completed' === $approval['status']) {
            // The approval came first: it published the reviewed revision, and the edit followed it.
            self::assertSame($reviewed, $approval['result']['source_revision']);
            self::assertSame($reviewed + 2, $observer->article()['revision']);
            self::assertSame(1, $observer->counts()['effects']);
            $stored = $observer->service()->get($harness->actor(), $request->request->id);
            self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify(self::export($stored), $harness->receiptKeyId(), $harness->receiptPublicKey())['receiptSignedAt'], 'A later edit does not touch the historical receipt.');
        } else {
            // The edit came first: what was reviewed is no longer the source, so nothing was published.
            self::assertSame('invalidated', $approval['status']);
            self::assertSame($reviewed + 1, $observer->article()['revision']);
            self::assertSame(0, $observer->counts()['effects']);
        }
    }

    public function testACompletionRacingAGrantRevocationNeverRunsWithoutAuthority(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        [$approval, $revocation] = $this->race([
            ['operation' => 'complete', 'ceremonyId' => $ceremony->id, 'proof' => $harness->proof($ceremony)],
            ['operation' => 'revoke_grant'],
        ]);
        self::assertSame('done', $revocation['status']);
        $effects = $this->connect()->counts()['effects'];
        if ('completed' === $approval['status']) {
            self::assertSame(1, $effects, 'The approval came first, while the grant was still active.');
        } else {
            self::assertSame('denied', $approval['status'], 'The revocation came first, so the request stopped.');
            self::assertSame(0, $effects);
        }
        self::assertSame($approval['status'], $this->connect()->service()->get($harness->reviewer(), $request->request->id)->status);
    }

    public function testALockTimeoutLeavesNothingHalfDoneAndRunsNothingTwice(): void
    {
        $harness = $this->connect();
        [, $ceremony] = $this->pending($harness);
        $release = $harness->holdWriteLock();
        $harness->lockTimeout(100);
        $refused = false;
        try {
            $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        } catch (\Throwable) {
            $refused = true;
        } finally {
            $release();
        }
        self::assertTrue($refused, 'A completion went ahead without the write lock.');
        self::assertSame(0, $harness->executeCalls(), 'Nothing began, so nothing can be replayed.');
        self::assertSame(['requests' => 1, 'effects' => 0, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 0], $this->connect()->counts());

        // The ceremony was never used, so the person can still finish.
        $harness->lockTimeout(15000);
        self::assertSame('completed', $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony))->status);
        self::assertSame(1, $harness->executeCalls());
    }

    private function connect(): StoreHarness
    {
        return static::harness()::connect($this->location);
    }

    /** @return array{ApprovalState, Ceremony} a pending request and one ceremony for it */
    private function pending(StoreHarness $harness): array
    {
        $request = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1]);
        self::assertSame('pending', $request->status);

        return [$request, $harness->service()->begin($harness->reviewer(), $request->request->id)];
    }

    /** @return array<string, mixed> the stored record as evidence; nothing is signed at export */
    private static function export(ApprovalState $state): array
    {
        $noKey = new class implements EvidenceSigner {
            public function keyId(): string
            {
                throw new \LogicException('Export signed something.');
            }

            public function sign(string $manifest): string
            {
                throw new \LogicException('Export signed something.');
            }

            public function publicKey(): string
            {
                throw new \LogicException('Export used the key.');
            }
        };

        return (new EvidenceExporter($noKey))->export($state);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $payloads): array
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('Real concurrency needs proc_open.');
        }
        $directory = sys_get_temp_dir().'/verified-human-approval-contract-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            return ProcessRace::run(__DIR__.'/store-worker.php', array_map(fn (array $payload): array => $payload + ['harness' => static::harness(), 'location' => $this->location], $payloads), $directory);
        } finally {
            rmdir($directory);
        }
    }
}
