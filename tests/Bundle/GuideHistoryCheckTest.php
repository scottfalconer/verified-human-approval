<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\StoreContracts\ApprovalStoreContract;
use VerifiedHumanApproval\StoreContracts\StoreHarness;
use VerifiedHumanApproval\Tests\Contract\DbalSqliteStoreHarness;
use VerifiedHumanApproval\Tests\Contract\SqliteStoreHarness;

/**
 * The check the integration guide gives for a state an approval history lists, run as the guide prints it: on both
 * supplied stores, it shows a listed state's stored outcome exactly when ApprovalService answers that outcome for the
 * same request, however the record was edited in the database.
 */
final class GuideHistoryCheckTest extends TestCase
{
    private const GUIDE = __DIR__.'/../../src/symfony-bundle/docs/index.md';

    /** @var class-string<StoreHarness>|null */
    private ?string $harness = null;
    private ?string $location = null;

    /** @var list<StoreHarness> every connection the test opened, closed when it ends */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        if (null !== $this->harness && null !== $this->location) {
            $this->harness::destroy($this->location);
        }
    }

    /** @return iterable<string, array{class-string<StoreHarness>, bool, \Closure(StoreHarness, string): void}> */
    public static function records(): iterable
    {
        $unchanged = static function (): void {
        };
        // The result and the execution record changed alike, so the stored parts still agree: only the receipt tells.
        $agreeing = static function (StoreHarness $harness, string $id): void {
            $unpublish = static function (\stdClass $result): \stdClass {
                $result->published = false;

                return $result;
            };
            $harness->editRecord($id, 'result', static fn (string $result): string => CanonicalJson::encode($unpublish(CanonicalJson::decode($result))));
            $harness->editRecord($id, 'execution', static function (string $record) use ($unpublish): string {
                $execution = CanonicalJson::decode($record);
                $execution->result = $unpublish($execution->result);

                return CanonicalJson::encode($execution);
            });
        };
        foreach (['PdoApprovalStore' => SqliteStoreHarness::class, 'DbalApprovalStore' => DbalSqliteStoreHarness::class] as $store => $harness) {
            yield $store.', pending' => [$harness, false, $unchanged];
            yield $store.', completed' => [$harness, true, $unchanged];
            yield $store.', the result and the execution record, edited to agree' => [$harness, true, $agreeing];
            foreach (ApprovalStoreContract::editedRecords() as $edited => [$edit]) {
                yield $store.', '.$edited => [$harness, true, $edit];
            }
        }
    }

    /**
     * @param class-string<StoreHarness>           $harness
     * @param \Closure(StoreHarness, string): void $edit
     */
    #[DataProvider('records')]
    public function testTheGuidesCheckShowsAStoredOutcomeOnlyWhereTheServiceAnswersIt(string $harness, bool $approve, \Closure $edit): void
    {
        $this->harness = $harness;
        $this->location = $harness::prepare();
        $writer = $this->connect();
        $id = $writer->service()->request($writer->actor(), 'article.publish', ['id' => 1], 'history')->request->id;
        if ($approve) {
            $ceremony = $writer->service()->begin($writer->reviewer(), $id);
            self::assertSame('completed', $writer->service()->complete($writer->reviewer(), $ceremony->id, $writer->proof($ceremony))->status);
        }
        $edit($writer, $id);

        $reader = $this->connect();
        $history = $reader->store();
        self::assertInstanceOf(ApprovalHistory::class, $history);
        $listed = $history->forResource('tenant-a', '1');
        self::assertCount(1, $listed);
        try {
            $answer = $reader->service()->get($reader->reviewer(), $id)->status;
        } catch (ApprovalException $error) {
            $answer = $error->reason;
        }
        // The bundle's TrustedKeys when no key was retired: the signer's key alone. A request that cannot be read
        // back is listed as an UnreadableApproval, which has no outcome to show.
        $trustedKeys = TrustedKeys::single($reader->receiptKeyId(), $reader->receiptPublicKey());
        $shown = $listed[0] instanceof ApprovalState && self::guideCheck()($listed[0], $trustedKeys);

        self::assertSame($answer, $shown ? $listed[0]->status : 'record_integrity_error', 'The check shows the stored outcome only where the service answers it.');
    }

    /** A new connection to the test's store. */
    private function connect(): StoreHarness
    {
        \assert(null !== $this->harness && null !== $this->location);
        $connection = $this->harness::connect($this->location);
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * The guide's check over the $state and $trustedKeys it names. eval() runs the guide's own block, so the test holds
     * the code a reader copies, not a copy of it here that could drift: its imports first, where PHP allows them, then
     * its statements in a closure that returns the $showOutcome they decide.
     *
     * @return \Closure(ApprovalState, TrustedKeys): bool
     */
    private static function guideCheck(): \Closure
    {
        if (1 !== preg_match('/^A state in the list is as stored\b.*?^```php\n((?:use [\w\\\\]+;\n)*)(.*?)^```$/ms', (string) file_get_contents(self::GUIDE), $block)) {
            self::fail('The guide gives no PHP block after "A state in the list is as stored".');
        }
        [, $imports, $statements] = $block;
        $check = eval($imports.'return static function (\\'.ApprovalState::class.' $state, \\'.TrustedKeys::class." \$trustedKeys): bool {\n".$statements."return \$showOutcome;\n};");
        self::assertInstanceOf(\Closure::class, $check);

        return $check;
    }
}
