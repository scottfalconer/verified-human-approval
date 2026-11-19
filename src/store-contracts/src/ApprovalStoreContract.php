<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * The approval storage contract (the rules on ApprovalStore), run against one adapter through its harness.
 * Each check observes through a fresh connection. To run it for another adapter, write a StoreHarness for
 * it and a test class that extends this one and names that harness. If the store also keeps a history by resource
 * (ApprovalHistory), add the ApprovalHistoryContract trait to that class.
 */
abstract class ApprovalStoreContract extends TestCase
{
    private const WORKER = __DIR__.'/../bin/store-worker.php';

    private ?string $location = null;

    /** @return class-string<StoreHarness> */
    abstract protected static function harness(): string;

    protected function setUp(): void
    {
        $this->location = static::harness()::prepare();
    }

    protected function tearDown(): void
    {
        if (null !== $this->location) {
            static::harness()::destroy($this->location);
        }
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
        $verified = (new ReceiptVerifier())->verify(self::export($stored), TrustedKeys::single($harness->receiptKeyId(), $harness->receiptPublicKey()));
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

    /** @return iterable<string, array{'roll_back'|'begin_again'|'sql_roll_back'}> */
    public static function transactionsTheActionRollsBack(): iterable
    {
        yield 'it rolls the transaction back' => ['roll_back'];
        yield 'it rolls the transaction back and begins another' => ['begin_again'];
        yield 'it rolls the transaction back in SQL' => ['sql_roll_back'];
    }

    /**
     * An action that rolls the approval transaction back itself and returns as if it had succeeded leaves nothing to
     * record: the store refuses every write after it, which would otherwise commit on its own or in a transaction
     * that is not the approval's, so no outcome claims an effect that was undone.
     *
     * @param 'roll_back'|'begin_again'|'sql_roll_back' $how
     */
    #[DataProvider('transactionsTheActionRollsBack')]
    public function testAnActionThatRollsTheTransactionBackGetsNothingRecorded(string $how): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->breakAction($how);
        try {
            $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
            self::fail('An approval whose action rolled its transaction back was reported as completed.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
            self::assertInstanceOf(\LogicException::class, $error->getPrevious(), 'The store refused to write outside its transaction.');
        }

        $observer = $this->connect();
        self::assertSame(['requests' => 1, 'effects' => 0, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 0], $observer->counts());
        $stored = $observer->service()->get($harness->actor(), $request->request->id);
        self::assertSame(['pending', null, null, null], [$stored->status, $stored->proof, $stored->execution, $stored->receipt]);

        // The connection is left usable: once mended, a fresh ceremony completes the request, once.
        $harness->breakAction(null);
        $fresh = $harness->service()->begin($harness->reviewer(), $request->request->id);
        self::assertSame('completed', $harness->service()->complete($harness->reviewer(), $fresh->id, $harness->proof($fresh))->status);
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $this->connect()->counts());
    }

    /**
     * An action that commits the approval transaction itself takes its own write, and what came before it, out of
     * the approval. The store records no outcome for it: no execution record, no receipt, no completed status.
     */
    public function testAnActionThatCommitsTheTransactionItselfGetsNoOutcomeRecorded(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->breakAction('commit');
        try {
            $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
            self::fail('An approval whose action committed its transaction itself was reported as completed.');
        } catch (ApprovalException $error) {
            self::assertInstanceOf(\LogicException::class, $error->getPrevious(), 'The store refused to write outside its transaction.');
        }

        $observer = $this->connect();
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 1], $observer->counts(), 'Only what the action committed itself is there.');
        $stored = $observer->service()->get($harness->actor(), $request->request->id);
        self::assertSame(['pending', null, null, null], [$stored->status, $stored->proof, $stored->execution, $stored->receipt]);
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
            self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify(self::export($stored), TrustedKeys::single($harness->receiptKeyId(), $harness->receiptPublicKey()))['receiptSignedAt'], 'A later edit does not touch the historical receipt.');
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

    /** @return iterable<string, array{'authority_checked'|'before_commit', string}> */
    public static function changesDuringAnApproval(): iterable
    {
        foreach (['authority_checked' => 'just after its authority check', 'before_commit' => 'just before its commit'] as $point => $when) {
            foreach (StoreHarness::CHANGES as $change) {
                yield str_replace('_', ' ', $change).', '.$when => [$point, $change];
            }
        }
    }

    /**
     * The approval pauses, and another connection tries an ordinary change to what it read: the grant, the
     * permission, the article, the passkey or the policy. Either the change waits until the approval has committed,
     * or it commits first and the approval then never commits. A store that checked authority but let it change
     * before the commit would commit an approval after a revocation that committed first; only end states would
     * not show that.
     *
     * @param 'authority_checked'|'before_commit' $point
     */
    #[DataProvider('changesDuringAnApproval')]
    public function testAChangeToWhatAnApprovalReadWaitsForItOrStopsIt(string $point, string $change): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $committedFirst = null;
        $harness->faults->pauses[$point] = static function () use ($harness, $change, &$committedFirst): void {
            $committedFirst = $harness->attempt($change, 500);
        };
        try {
            $outcome = $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony))->status;
        } catch (ApprovalException $error) {
            $outcome = $error->reason;
        }
        self::assertNotNull($committedFirst, 'The approval reached its pause.');
        $observer = $this->connect();
        if ($committedFirst) {
            self::assertNotSame('completed', $outcome, 'The approval committed after a change to what it read had committed.');
            self::assertSame(0, $observer->counts()['effects']);
            self::assertNotSame('completed', $observer->service()->get($harness->reviewer(), $request->request->id)->status);
        } else {
            self::assertSame('completed', $outcome, 'The change was held off, so nothing stood in the approval\'s way.');
            self::assertSame(1, $observer->counts()['effects']);
            self::assertTrue($harness->attempt($change, 15000), 'The change goes ahead once the approval has committed.');
        }
    }

    /**
     * A failed attempt retires its ceremony in a recovery transaction. A second completion of the same ceremony that
     * waited meanwhile must read it as used, not as it was before the recovery committed.
     */
    public function testACeremonyRetiredAfterAFailedAttemptIsNotUsedByOneThatWaited(): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->breakAction('after_write');
        $directory = $this->raceDirectory();
        $waiting = null;
        // The recovery transaction is the first to reach its commit: the failed attempt never does.
        $harness->faults->pauses['before_commit'] = function () use ($harness, $ceremony, $directory, &$waiting): void {
            $waiting = ProcessRace::background(self::WORKER, [
                'operation' => 'complete', 'ceremonyId' => $ceremony->id, 'proof' => $harness->proof($ceremony),
                'harness' => static::harness(), 'location' => $this->location(),
            ], $directory);
            usleep(500000); // long enough for it to be waiting on what the recovery holds
        };
        try {
            $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
            self::fail('The broken action was reported as completed.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
        } finally {
            $other = null === $waiting ? null : $waiting();
            rmdir($directory);
        }
        self::assertSame(['status' => 'rejected', 'reason' => 'ceremony_consumed', 'executeCalls' => 0], $other);
        self::assertSame(['requests' => 1, 'effects' => 0, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 0], $this->connect()->counts());
        self::assertSame('pending', $this->connect()->service()->get($harness->actor(), $request->request->id)->status);
    }

    /**
     * Canonical bytes survive the database: a proposal near the 256 KiB limit, U+2028 and 4-byte characters, and an
     * empty object kept apart from an empty array, in the proposal and in the result.
     */
    public function testCanonicalDataKeepsItsBytesOnThisDatabase(): void
    {
        $harness = $this->connect();
        $title = "Line\u{2028}and paragraph\u{2029} \u{1F600} \u{1D11E} caf\u{E9}";
        $harness->writeArticle(1, $title, str_repeat("Approved text \u{2028} \u{1F600} \u{1D11E} ", 4400));
        $harness->action()->extraEffect = ['tags' => [], 'meta' => new \stdClass(), 'nested' => ['none' => null, 'object' => new \stdClass(), 'list' => []]];
        $harness->action()->extraResult = ['notes' => [], 'context' => new \stdClass()];
        [$request, $ceremony] = $this->pending($harness);
        self::assertGreaterThan(240 * 1024, \strlen($request->request->canonical), 'The proposal is near the 256 KiB limit.');
        $completed = $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        self::assertSame('completed', $completed->status);

        $stored = $this->connect()->service()->get($harness->actor(), $request->request->id);
        self::assertSame($request->request->canonical, $stored->request->canonical, 'The proposal comes back byte for byte.');
        self::assertSame($title, $stored->request->prepared()->effect['title']);
        $effect = CanonicalJson::encode((object) $stored->request->prepared()->effect);
        self::assertStringContainsString('"meta":{}', $effect);
        self::assertStringContainsString('"nested":{"list":[],"none":null,"object":{}}', $effect);
        self::assertStringContainsString('"tags":[]', $effect);
        self::assertSame(CanonicalJson::encode((object) $completed->result), CanonicalJson::encode((object) $stored->result));
        self::assertStringContainsString('"context":{},"notes":[]', CanonicalJson::encode((object) $stored->result));
        self::assertSame(CanonicalJson::encode((object) $completed->execution), CanonicalJson::encode((object) $stored->execution));
        self::assertSame(CanonicalJson::encode((object) $completed->proof), CanonicalJson::encode((object) $stored->proof));
        self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify(self::export($stored), TrustedKeys::single($harness->receiptKeyId(), $harness->receiptPublicKey()))['receiptSignedAt']);
    }

    /**
     * Edits someone with write access to the database could make to one part of a completed record, each with whether
     * the proposal still reads back afterwards, and so still shows whose record it is.
     *
     * @return iterable<string, array{\Closure(StoreHarness, string): void, bool}>
     */
    public static function editedRecords(): iterable
    {
        $edit = static fn (string $part, \Closure $change): \Closure => static fn (StoreHarness $harness, string $id) => $harness->editRecord($id, $part, $change);
        // The proposal changed, and its digest with it, so the two still match.
        $withDigest = static fn (\Closure $change): \Closure => static function (StoreHarness $harness, string $id) use ($change): void {
            $edited = '';
            $harness->editRecord($id, 'proposal', static function (string $proposal) use ($change, &$edited): string {
                return $edited = $change($proposal);
            });
            $harness->editRecord($id, 'digest', static fn (): string => hash('sha256', $edited));
        };
        $character = static fn (string $proposal): string => str_replace('Summer Campaign', 'Summer Campaigm', $proposal);
        $cut = static fn (string $json): string => substr($json, 0, 40);
        yield 'one character of the proposal' => [$edit('proposal', $character), false];
        yield 'the digest' => [$edit('digest', static fn (string $digest): string => ('0' === $digest[0] ? '1' : '0').substr($digest, 1)), false];
        yield 'the proposal and its digest, to match each other' => [$withDigest($character), true];
        yield 'the proposal and its digest, with a number for the person' => [$withDigest(static function (string $proposal): string {
            $payload = CanonicalJson::map(CanonicalJson::decode($proposal));
            $payload['actor']->userId = 5;

            return CanonicalJson::encode((object) $payload);
        }), false];
        yield 'the result, to text that is not JSON' => [$edit('result', static fn (): string => 'published'), true];
        yield 'the result, to a JSON list' => [$edit('result', static fn (): string => '[]'), true];
        yield 'the proof, cut short' => [$edit('proof', $cut), true];
        yield 'the execution record, cut short' => [$edit('execution', $cut), true];
        yield 'the status, to one that does not exist' => [$edit('status', static fn (): string => 'done'), true];
        yield 'the status, to cancelled' => [$edit('status', static fn (): string => 'cancelled'), true];
        yield 'the receipt signature, emptied' => [$edit('signature', static fn (): string => ''), true];
    }

    /**
     * Whatever part of a completed record is edited, every read through the service answers record_integrity_error:
     * never the stored outcome, never another refusal, never an exception of another kind, and nothing runs again.
     * The store itself either reads the record back for the service to check, or refuses it with that reason, keeping
     * what failed and, while the proposal reads back, its request. So someone else who names the record is told what
     * they would be told of a request that does not exist; only a record whose proposal no longer shows whose it is
     * is reported to whoever names it, and then only as a proposal that fails its check.
     *
     * @param \Closure(StoreHarness, string): void $edit
     */
    #[DataProvider('editedRecords')]
    public function testAnEditedRecordIsAnIntegrityErrorWhereverItIsRead(\Closure $edit, bool $proposalReadsBack): void
    {
        $harness = $this->connect();
        $request = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'edited-record');
        $ceremony = $harness->service()->begin($harness->reviewer(), $request->request->id);
        $proof = $harness->proof($ceremony);
        self::assertSame('completed', $harness->service()->complete($harness->reviewer(), $ceremony->id, $proof)->status);
        $id = $request->request->id;
        $edit($harness, $id);

        $observer = $this->connect();
        $service = $observer->service();
        $reads = [
            'the status' => static fn () => $service->get($observer->reviewer(), $id),
            'the agent’s status read' => static fn () => $service->get($observer->actor(), $id),
            'a retry with the same request key' => static fn () => $service->request($observer->actor(), 'article.publish', ['id' => 1], 'edited-record'),
            'a new review' => static fn () => $service->begin($observer->reviewer(), $id),
            'the finished ceremony' => static fn () => $service->complete($observer->reviewer(), $ceremony->id, $proof),
            'a cancellation' => static fn () => $service->cancel($observer->reviewer(), $id),
        ];
        foreach ($reads as $read => $operation) {
            try {
                $operation();
                self::fail($read.' presented an edited record.');
            } catch (ApprovalException $error) {
                self::assertSame('record_integrity_error', $error->reason, $read);
            }
        }
        $unknown = self::refusal(static fn () => $service->get(new Actor('bob', 'tenant-a'), 'apr_'.str_repeat('0', 32)));
        foreach ([new Actor('bob', 'tenant-a'), new Actor('alice', 'tenant-b', 'agent-1')] as $stranger) {
            $refused = self::refusal(static fn () => $service->get($stranger, $id));
            if ($proposalReadsBack) {
                self::assertEquals([$unknown->reason, $unknown->getMessage(), null], [$refused->reason, $refused->getMessage(), $refused->getPrevious()], 'Someone else learns nothing of the record.');
            } else {
                self::assertSame(['record_integrity_error', 'The stored approval proposal fails its integrity check. Its outcome cannot be trusted.'], [$refused->reason, $refused->getMessage()]);
            }
        }
        try {
            self::assertSame($id, $observer->store()->get($id)?->request->id, 'A record that reads back is left to the service to check.');
        } catch (ApprovalException $error) {
            self::assertSame('record_integrity_error', $error->reason);
            self::assertNotNull($error->getPrevious(), 'What failed is kept.');
            self::assertSame($proposalReadsBack ? $id : null, $error->request?->id, 'While the proposal reads back, the error carries its request.');
        }
        self::assertSame(['requests' => 1, 'effects' => 1, 'executions' => 1, 'receipts' => 1, 'credentialUses' => 1], $observer->counts(), 'Nothing ran again.');
    }

    /**
     * A pending proposal that no longer reads back is refused the same way when a retry without a request key finds
     * it: the agent is neither sent back to a damaged proposal nor given a second one in its place.
     */
    public function testAPendingProposalThatCannotBeReadIsAnIntegrityErrorForARetryWithoutAKey(): void
    {
        $harness = $this->connect();
        $pending = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1]);
        $harness->editRecord($pending->request->id, 'proposal', static fn (string $proposal): string => str_replace('Summer Campaign', 'Summer Campaigm', $proposal));

        $service = $this->connect()->service();
        $reads = [
            'a retry without a key' => static fn () => $service->request($harness->actor(), 'article.publish', ['id' => 1]),
            'the status' => static fn () => $service->get($harness->reviewer(), $pending->request->id),
            'a review' => static fn () => $service->begin($harness->reviewer(), $pending->request->id),
        ];
        foreach ($reads as $read => $operation) {
            try {
                $operation();
                self::fail($read.' presented a damaged proposal.');
            } catch (ApprovalException $error) {
                self::assertSame('record_integrity_error', $error->reason, $read);
            }
        }
        self::assertSame(['requests' => 1, 'effects' => 0, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 0], $this->connect()->counts());
    }

    /** @return iterable<string, array{'options'|'nonce'|'expires_at', \Closure(string): string}> */
    public static function editedCeremonies(): iterable
    {
        yield 'its options, cut short' => ['options', static fn (string $options): string => substr($options, 0, 20)];
        yield 'its options, to a JSON list' => ['options', static fn (): string => '[]'];
        yield 'its nonce, to text that is not base64url' => ['nonce', static fn (): string => 'not a nonce'];
        yield 'its expiry, to text that is not a time' => ['expires_at', static fn (): string => 'soon'];
    }

    /**
     * A passkey ceremony whose stored parts no longer read back is one nobody can use. Reading it is refused as
     * corrupt_ceremony, an approval error like any other, never an exception of another kind, keeping what failed;
     * nothing runs, and a fresh review still completes the request.
     *
     * @param 'options'|'nonce'|'expires_at' $part
     * @param \Closure(string): string       $edit
     */
    #[DataProvider('editedCeremonies')]
    public function testACeremonyThatCannotBeReadBackIsRefusedAsCorrupt(string $part, \Closure $edit): void
    {
        $harness = $this->connect();
        [$request, $ceremony] = $this->pending($harness);
        $harness->editCeremony($ceremony->id, $part, $edit);

        $observer = $this->connect();
        try {
            $observer->store()->transaction(static fn () => $observer->store()->getCeremony($ceremony->id));
            self::fail('A damaged ceremony was read back.');
        } catch (ApprovalException $error) {
            self::assertSame('corrupt_ceremony', $error->reason);
            self::assertNotNull($error->getPrevious(), 'What failed is kept.');
        }
        try {
            $observer->service()->complete($observer->reviewer(), $ceremony->id, $observer->proof($ceremony));
            self::fail('A damaged ceremony was used.');
        } catch (ApprovalException $error) {
            self::assertSame('corrupt_ceremony', $error->reason);
        }
        self::assertSame(['requests' => 1, 'effects' => 0, 'executions' => 0, 'receipts' => 0, 'credentialUses' => 0], $observer->counts());

        $fresh = $observer->service()->begin($observer->reviewer(), $request->request->id);
        self::assertSame('completed', $observer->service()->complete($observer->reviewer(), $fresh->id, $observer->proof($fresh))->status);
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

    /** Where this test's store is: set up before every test. */
    private function location(): string
    {
        return $this->location ?? throw new \LogicException('The store was not prepared.');
    }

    protected function connect(): StoreHarness
    {
        return static::harness()::connect($this->location());
    }

    /** @return array{ApprovalState, Ceremony} a pending request and one ceremony for it */
    private function pending(StoreHarness $harness): array
    {
        $request = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1]);
        self::assertSame('pending', $request->status);

        return [$request, $harness->service()->begin($harness->reviewer(), $request->request->id)];
    }

    /** @return array<string, mixed> the stored record as evidence, exported with no signing key */
    private static function export(ApprovalState $state): array
    {
        return (new EvidenceExporter())->export($state);
    }

    /** The refusal an operation must end in. */
    private static function refusal(\Closure $operation): ApprovalException
    {
        try {
            $operation();
        } catch (ApprovalException $error) {
            return $error;
        }
        self::fail('The operation was not refused.');
    }

    /**
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $payloads): array
    {
        $directory = $this->raceDirectory();
        try {
            return ProcessRace::run(self::WORKER, array_map(fn (array $payload): array => $payload + ['harness' => static::harness(), 'location' => $this->location()], $payloads), $directory);
        } finally {
            rmdir($directory);
        }
    }

    private function raceDirectory(): string
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('Real concurrency needs proc_open.');
        }
        $directory = sys_get_temp_dir().'/verified-human-approval-contract-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);

        return $directory;
    }
}
