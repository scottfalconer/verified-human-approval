<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

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
        $barrier = $this->directory.'/start';
        $workers = [];
        $results = [];
        try {
            foreach ($payloads as $index => $payload) {
                $ready = $this->directory.'/ready-'.$index;
                $pipes = [];
                $process = proc_open(
                    [\PHP_BINARY, \dirname(__DIR__).'/Support/complete-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    \dirname(__DIR__, 2),
                );
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode($payload + ['ready' => $ready, 'barrier' => $barrier], \JSON_THROW_ON_ERROR)."\n");
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready];
            }
            $deadline = microtime(true) + 15;
            do {
                clearstatcache();
                $readyCount = \count(array_filter($workers, static fn (array $worker): bool => is_file($worker['ready'])));
                if ($readyCount === \count($workers)) {
                    break;
                }
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker['process'])['running'] && !is_file($worker['ready'])) {
                        self::fail('Concurrency worker exited before the barrier: '.stream_get_contents($worker['stderr']));
                    }
                }
                usleep(5000);
            } while (microtime(true) < $deadline);
            self::assertSame(\count($workers), $readyCount, 'All independent workers must reach the start barrier.');
            file_put_contents($barrier, 'start', \LOCK_EX);

            $deadline = microtime(true) + 20;
            do {
                $running = false;
                foreach ($workers as $worker) {
                    $running = proc_get_status($worker['process'])['running'] || $running;
                }
                if (!$running) {
                    break;
                }
                usleep(5000);
            } while (microtime(true) < $deadline);
            self::assertFalse($running, 'Concurrent completion must finish within the bounded test timeout.');
            foreach ($workers as $worker) {
                $output = stream_get_contents($worker['stdout']);
                $errors = stream_get_contents($worker['stderr']);
                self::assertSame('', $errors, 'Worker must not encounter an infrastructure exception.');
                $result = json_decode($output, true, 32, \JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                $results[] = $result;
            }
        } finally {
            foreach ($workers as $worker) {
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                proc_close($worker['process']);
            }
        }

        return $results;
    }
}
