<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

/** Fresh OS processes and PDO connections exercise the real SQLite write race. */
final class ConcurrencyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('This test needs process creation; the reference validation environment enables it.');
        }
        $this->directory = sys_get_temp_dir().'/verified-human-approval-race-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    public function testFourProcessesCompletingOneCeremonyCommitOneBusinessEffect(): void
    {
        $database = $this->directory.'/application.sqlite';
        $fixture = new CoreFixture($database);
        $request = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $fixture->service->begin($fixture->reviewer, $request->request->id);
        $payload = ['operation' => 'complete', 'database' => $database, 'ceremonyId' => $ceremony->id, 'assertion' => $fixture->proof($ceremony)];
        $results = $this->race([$payload, $payload, $payload, $payload]);

        self::assertSame(['completed', 'completed', 'completed', 'completed'], array_column($results, 'status'));
        self::assertCount(1, array_unique(array_map(static fn (array $result): string => CanonicalJson::encode($result['result']), $results)));
        self::assertSame(1, $fixture->effectCount());
        self::assertSame(18, (int) $fixture->article()['revision']);
        self::assertSame(1, (int) $fixture->pdo->query('SELECT COUNT(*) FROM verified_human_approval_executions')->fetchColumn());
        self::assertSame(1, (int) $fixture->pdo->query("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'")->fetchColumn());
    }

    public function testDistinctProposalsCompetingForTheSameSourceRevisionCannotBothCommit(): void
    {
        $database = $this->directory.'/application.sqlite';
        $fixture = new CoreFixture($database);
        // Identical keyless requests now share one pending proposal; distinct keys make two.
        $first = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'proposal-a');
        $second = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'proposal-b');
        $ceremonyA = $fixture->service->begin($fixture->reviewer, $first->request->id);
        $ceremonyB = $fixture->service->begin($fixture->reviewer, $second->request->id);
        $results = $this->race([
            ['operation' => 'complete', 'database' => $database, 'ceremonyId' => $ceremonyA->id, 'assertion' => $fixture->proof($ceremonyA)],
            ['operation' => 'complete', 'database' => $database, 'ceremonyId' => $ceremonyB->id, 'assertion' => $fixture->proof($ceremonyB)],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['completed', 'invalidated'], $statuses);
        self::assertSame(1, $fixture->effectCount());
        self::assertSame(1, (int) $fixture->pdo->query('SELECT COUNT(*) FROM verified_human_approval_executions')->fetchColumn());
        self::assertSame(18, (int) $fixture->article()['revision']);
        self::assertSame(1, (int) $fixture->pdo->query("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'")->fetchColumn());
    }

    public function testConcurrentIdempotentRequestsProduceOneFrozenProposal(): void
    {
        $database = $this->directory.'/application.sqlite';
        $fixture = new CoreFixture($database);
        $payload = ['operation' => 'request', 'database' => $database, 'idempotencyKey' => 'same-client-operation'];
        $results = $this->race([$payload, $payload, $payload]);
        self::assertSame(['pending', 'pending', 'pending'], array_column($results, 'status'));
        self::assertCount(1, array_unique(array_column($results, 'requestId')));
        self::assertSame(1, (int) $fixture->pdo->query('SELECT COUNT(*) FROM verified_human_approval_requests')->fetchColumn());
        self::assertSame(0, $fixture->effectCount());
    }

    /** @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    private function race(array $payloads): array
    {
        return ProcessRace::run(\dirname(__DIR__).'/Support/complete-worker.php', $payloads, $this->directory);
    }
}
