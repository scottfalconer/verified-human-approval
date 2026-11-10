<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Evidence;

use PHPUnit\Framework\TestCase;

/**
 * The public synthetic evidence in tests/fixtures/evidence/commit-v2, which the consumer check verifies with
 * an installed copy of the command, still verifies with this checkout's command.
 */
final class SyntheticFixtureTest extends TestCase
{
    public function testThePublishedSyntheticEvidenceVerifies(): void
    {
        $directory = \dirname(__DIR__).'/fixtures/evidence/commit-v2';
        [$exit, $output, $errors] = self::verify($directory.'/evidence.json', $directory.'/trusted-application.json', $directory.'/trusted-credential.json');
        self::assertSame(0, $exit, $errors);
        $result = json_decode($output, true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame([true, true, 'commit'], [$result['credentialApprovedExactProposal'], $result['applicationSignedExecutionReport'], $result['applicationReportSignedAt']]);

        [$exit, $output, $errors] = self::verify($directory.'/evidence.json', $directory.'/trusted-application.json');
        self::assertSame(0, $exit, $errors);
        self::assertNull(json_decode($output, true, 8, \JSON_THROW_ON_ERROR)['credentialApprovedExactProposal'], 'Without the passkey record, the passkey is reported as not checked.');
    }

    public function testMissingArgumentsProduceUsageWithoutWarnings(): void
    {
        [$exit, $output, $errors] = self::verify();
        self::assertSame(2, $exit);
        self::assertSame('', $output);
        self::assertStringStartsWith('Usage:', $errors);
        self::assertStringNotContainsString('Warning', $errors);
    }

    public function testDisabledArgumentGlobalsProduceUsageWithoutWarnings(): void
    {
        [$exit, $output, $errors] = self::runVerifierProcess([\PHP_BINARY, '-d', 'register_argc_argv=0', \dirname(__DIR__, 2).'/src/webauthn/bin/verify-evidence.php']);
        self::assertSame(2, $exit);
        self::assertSame('', $output);
        self::assertStringStartsWith('Usage:', $errors);
        self::assertStringNotContainsString('Warning', $errors);
    }

    public function testMissingEvidenceFilesAreVerificationFailures(): void
    {
        [$exit, $output, $errors] = self::verify(__FILE__.'.missing', __FILE__.'.missing-keys');
        self::assertSame(1, $exit);
        self::assertSame('', $output);
        self::assertStringStartsWith('Verification failed:', $errors);
    }

    /** @return array{int, string, string} */
    private static function verify(string ...$files): array
    {
        return self::runVerifierProcess([\PHP_BINARY, \dirname(__DIR__, 2).'/src/webauthn/bin/verify-evidence.php', ...$files]);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private static function runVerifierProcess(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }
}
