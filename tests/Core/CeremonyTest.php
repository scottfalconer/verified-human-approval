<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\ChallengeBinding;

/**
 * A passkey ceremony read back from storage loads as the approval service wrote it, and a part it cannot use is
 * refused as corrupt_ceremony, whatever PHP makes of it.
 */
final class CeremonyTest extends TestCase
{
    private const EXPIRY = '2026-11-08T10:05:00.000000Z';

    public function testACeremonyReadsBackAsTheServiceWroteIt(): void
    {
        $nonce = ChallengeBinding::encode(random_bytes(32));
        $ceremony = Ceremony::fromStorage('cer_1', 'req_1', $nonce, 'challenge', ApprovalRequest::timestamp(new \DateTimeImmutable(self::EXPIRY)), '{"challenge":"x"}', false);

        self::assertSame([$nonce, self::EXPIRY, ['challenge' => 'x']], [$ceremony->nonce, $ceremony->expiresAt, $ceremony->options]);
    }

    /** @return iterable<string, array{string, string, string}> the nonce, expiry and options as stored */
    public static function partsWithANulByte(): iterable
    {
        $nonce = ChallengeBinding::encode(str_repeat("\x01", 32));
        // SQLite keeps a NUL byte in text, and PHP's parser of exact times refuses one with an error of its own.
        yield 'its expiry, ending in one' => [$nonce, self::EXPIRY."\0", '{}'];
        yield 'its expiry, with one for its Z' => [$nonce, substr(self::EXPIRY, 0, -1)."\0", '{}'];
        yield 'its nonce, ending in one' => [$nonce."\0", self::EXPIRY, '{}'];
        yield 'its options, ending in one' => [$nonce, self::EXPIRY, "{}\0"];
    }

    #[DataProvider('partsWithANulByte')]
    public function testAPartWithANulByteIsRefusedAsCorrupt(string $nonce, string $expiresAt, string $options): void
    {
        try {
            Ceremony::fromStorage('cer_1', 'req_1', $nonce, 'challenge', $expiresAt, $options, false);
            self::fail('A ceremony with a NUL byte was read back.');
        } catch (ApprovalException $error) {
            self::assertSame('corrupt_ceremony', $error->reason);
            self::assertNotNull($error->getPrevious(), 'What failed is kept.');
        }
    }
}
