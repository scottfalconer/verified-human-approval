<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Real concurrency: independent PHP processes, each with its own database connection, wait at one barrier
 * and then start together. A worker reads its payload with payload(), connects, calls start(), and
 * writes one JSON line to standard output. Workers only connect: the test prepares the schema and data first.
 */
final class ProcessRace
{
    /**
     * @param list<array<string, mixed>> $payloads one per worker
     *
     * @return list<array<string, mixed>> each worker's answer, in payload order
     */
    public static function run(string $script, array $payloads, string $directory, float $timeout = 20.0): array
    {
        $barrier = $directory.'/start-'.bin2hex(random_bytes(4));
        $workers = [];
        $results = [];
        try {
            foreach ($payloads as $index => $payload) {
                $ready = $barrier.'-ready-'.$index;
                $pipes = [];
                $process = proc_open([\PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 2));
                Assert::assertIsResource($process);
                fwrite($pipes[0], json_encode($payload + ['ready' => $ready, 'barrier' => $barrier], \JSON_THROW_ON_ERROR)."\n");
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready];
            }
            $deadline = microtime(true) + 15;
            $readyCount = 0;
            do {
                clearstatcache();
                $readyCount = \count(array_filter($workers, static fn (array $worker): bool => is_file($worker['ready'])));
                if ($readyCount === \count($workers)) {
                    break;
                }
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker['process'])['running'] && !is_file($worker['ready'])) {
                        Assert::fail('A worker exited before the barrier: '.stream_get_contents($worker['stderr']));
                    }
                }
                usleep(5000);
            } while (microtime(true) < $deadline);
            Assert::assertSame(\count($workers), $readyCount, 'Every worker reaches the start barrier.');
            file_put_contents($barrier, 'start', \LOCK_EX);

            $deadline = microtime(true) + $timeout;
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
            Assert::assertFalse($running, 'The workers finish within the bounded test timeout.');
            foreach ($workers as $worker) {
                $output = (string) stream_get_contents($worker['stdout']);
                $errors = (string) stream_get_contents($worker['stderr']);
                Assert::assertSame('', $errors, 'A worker must not hit an infrastructure exception.');
                $result = json_decode($output, true, 32, \JSON_THROW_ON_ERROR);
                Assert::assertIsArray($result);
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
            foreach (glob($barrier.'*') ?: [] as $file) {
                unlink($file);
            }
        }

        return $results;
    }

    /**
     * Starts one worker and lets it go as soon as it has connected, while the caller carries on, perhaps holding
     * a lock the worker will wait for. Returns a function that waits for the worker and gives its answer.
     *
     * @param array<string, mixed> $payload
     *
     * @return \Closure(): array<string, mixed>
     */
    public static function background(string $script, array $payload, string $directory, float $timeout = 20.0): \Closure
    {
        $barrier = $directory.'/start-'.bin2hex(random_bytes(4));
        $ready = $barrier.'-ready-0';
        $pipes = [];
        $process = proc_open([\PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 2));
        Assert::assertIsResource($process);
        fwrite($pipes[0], json_encode($payload + ['ready' => $ready, 'barrier' => $barrier], \JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        clearstatcache();
        while (!is_file($ready)) {
            if (!proc_get_status($process)['running'] || microtime(true) >= $deadline) {
                Assert::fail('The worker did not start: '.stream_get_contents($pipes[2]));
            }
            usleep(5000);
            clearstatcache();
        }
        file_put_contents($barrier, 'start', \LOCK_EX);

        return static function () use ($process, $pipes, $barrier, $timeout): array {
            try {
                $deadline = microtime(true) + $timeout;
                while (proc_get_status($process)['running']) {
                    Assert::assertLessThan($deadline, microtime(true), 'The worker finishes within the bounded test timeout.');
                    usleep(5000);
                }
                $errors = (string) stream_get_contents($pipes[2]);
                Assert::assertSame('', $errors, 'A worker must not hit an infrastructure exception.');
                $result = json_decode((string) stream_get_contents($pipes[1]), true, 32, \JSON_THROW_ON_ERROR);
                Assert::assertIsArray($result);

                return $result;
            } finally {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                foreach (glob($barrier.'*') ?: [] as $file) {
                    unlink($file);
                }
            }
        };
    }

    /**
     * For a worker: its payload.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $input = json_decode((string) fgets(\STDIN), true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($input) || !\is_string($input['ready'] ?? null) || !\is_string($input['barrier'] ?? null)) {
            throw new \RuntimeException('A worker needs its payload with ready and barrier paths.');
        }

        return $input;
    }

    /**
     * For a worker, once connected: signals that it is ready and waits until every worker is.
     *
     * @param array<string, mixed> $input
     */
    public static function start(array $input): void
    {
        file_put_contents((string) $input['ready'], 'ready', \LOCK_EX);
        $deadline = microtime(true) + 15;
        while (!is_file((string) $input['barrier'])) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('The barrier was not released.');
            }
            usleep(5000);
            clearstatcache(true, (string) $input['barrier']);
        }
    }
}
