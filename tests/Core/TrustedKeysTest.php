<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/** The key set: keys named by id, each trusted only within its window, read from either published file. */
final class TrustedKeysTest extends TestCase
{
    public function testAKeyCoversOnlyItsWindowBothEndsIncluded(): void
    {
        $key = new TrustedKey('application-1', self::publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'), TrustedKey::time('2026-11-09T12:00:00Z'));
        self::assertTrue($key->covers(TrustedKey::time('2026-11-01T00:00:00Z')));
        self::assertTrue($key->covers(TrustedKey::time('2026-11-09T14:00:00+02:00')), 'The same instant, written with an offset.');
        self::assertFalse($key->covers(TrustedKey::time('2026-11-09T12:00:00.000001Z')));
        self::assertFalse($key->covers(TrustedKey::time('2026-10-31T23:59:59.999999Z')));
        self::assertTrue($key->coversClaim('2026-11-05T08:00:00.000000Z'));
        self::assertFalse($key->coversClaim('2026-11-10T08:00:00.000000Z'));
        self::assertFalse($key->coversClaim('yesterday'), 'A claim that is not a time is outside any window.');
        self::assertFalse($key->coversClaim(null));

        $lone = new TrustedKey('application-1', self::publicKey());
        self::assertFalse($lone->bounded());
        self::assertTrue($lone->coversClaim(null), 'A key without a window trusts any claim, as a single key always has.');
    }

    /** @return iterable<string, array{string}> */
    public static function notTimes(): iterable
    {
        yield 'no offset' => ['2026-11-09T12:00:00'];
        yield 'a date only' => ['2026-11-09'];
        yield 'a date that does not exist' => ['2026-02-30T12:00:00Z'];
        yield 'words' => ['next Tuesday'];
    }

    #[DataProvider('notTimes')]
    public function testAWindowNeedsAnUnambiguousTime(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrustedKey::time($value);
    }

    public function testAKeyNeedsAnIdAPublicKeyAndAWindowThatDoesNotEndBeforeItStarts(): void
    {
        foreach ([
            static fn () => new TrustedKey('', self::publicKey()),
            static fn () => new TrustedKey('application-1', 'not a key'),
            static fn () => new TrustedKey('application-1', ChallengeBinding::encode(random_bytes(16))),
            static fn () => new TrustedKey('application-1', self::publicKey(), TrustedKey::time('2026-11-09T12:00:00Z'), TrustedKey::time('2026-11-01T00:00:00Z')),
            static fn () => new TrustedKeys([]),
            static fn () => new TrustedKeys([new TrustedKey('application-1', self::publicKey()), new TrustedKey('application-1', self::publicKey())]),
        ] as $case => $build) {
            try {
                $build();
                self::fail('Case '.$case.' was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheKeySetDocumentRoundTripsAndTheOneKeyFileIsAOneKeySet(): void
    {
        $current = new TrustedKey('application-2', self::publicKey(), TrustedKey::time('2026-11-09T12:00:00Z'));
        $retired = new TrustedKey('application-1', self::publicKey(), null, TrustedKey::time('2026-11-09T12:00:00Z'));
        $keys = new TrustedKeys([$current, $retired]);
        self::assertSame([
            ['keyId' => 'application-2', 'publicKey' => $current->publicKey, 'validFrom' => '2026-11-09T12:00:00.000000Z', 'validUntil' => null],
            ['keyId' => 'application-1', 'publicKey' => $retired->publicKey, 'validFrom' => null, 'validUntil' => '2026-11-09T12:00:00.000000Z'],
        ], $keys->toArray());
        $document = json_decode(json_encode(['format' => TrustedKeys::FORMAT, 'keys' => $keys->toArray()], \JSON_THROW_ON_ERROR), false, 8, \JSON_THROW_ON_ERROR);
        self::assertEquals($keys, TrustedKeys::fromDocument(get_object_vars($document)), 'Read back as JSON objects, as the command reads a file.');
        self::assertNull($keys->get('application-3'));

        foreach ([['format' => TrustedKeys::SINGLE_KEY_FORMAT], []] as $format) {
            $one = TrustedKeys::fromDocument($format + ['keyId' => 'application-1', 'publicKey' => $retired->publicKey, 'rpId' => 'approval.test', 'origin' => 'https://approval.test']);
            self::assertEquals(TrustedKeys::single('application-1', $retired->publicKey), $one);
            self::assertFalse($one->all()[0]->bounded());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadable(): iterable
    {
        yield 'an unknown format' => [['format' => 'verified-human-approval/receipt-keys/v3', 'keys' => []]];
        yield 'a key set without keys' => [['format' => TrustedKeys::FORMAT]];
        yield 'a key set with no key' => [['format' => TrustedKeys::FORMAT, 'keys' => []]];
        yield 'keys as a map' => [['format' => TrustedKeys::FORMAT, 'keys' => ['a' => ['keyId' => 'a', 'publicKey' => self::publicKey()]]]];
        yield 'a key without its public key' => [['format' => TrustedKeys::FORMAT, 'keys' => [['keyId' => 'a']]]];
        yield 'a window that is not a time' => [['format' => TrustedKeys::FORMAT, 'keys' => [['keyId' => 'a', 'publicKey' => self::publicKey(), 'validFrom' => 1762689600]]]];
        yield 'a one-key file without its key' => [['format' => TrustedKeys::SINGLE_KEY_FORMAT, 'keyId' => 'a']];
    }

    /** @param array<string, mixed> $document */
    #[DataProvider('unreadable')]
    public function testAnUnreadableKeyFileIsRefusedNotGuessed(array $document): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrustedKeys::fromDocument($document);
    }

    private static function publicKey(): string
    {
        return ChallengeBinding::encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }
}
