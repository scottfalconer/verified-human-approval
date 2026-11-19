<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\ChallengeBinding;

/**
 * One public key the application signs or has signed with, and the window it was trusted to sign in. A record
 * signed with it verifies only if the time the record claims falls inside that window: a key retired after a
 * rotation keeps checking what it signed, but nothing it signs that claims a later time.
 *
 * A null validFrom means no earlier limit, for a key whose start was never recorded; a null validUntil means
 * the key is still trusted to sign. Both ends are inclusive.
 */
final readonly class TrustedKey
{
    public function __construct(
        public string $keyId,
        public string $publicKey,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validUntil = null,
    ) {
        if ('' === $keyId) {
            throw new \InvalidArgumentException('A trusted key needs an identifier.');
        }
        try {
            $bytes = ChallengeBinding::decode($publicKey);
        } catch (\InvalidArgumentException) {
            $bytes = '';
        }
        if (\SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($bytes)) {
            throw new \InvalidArgumentException(\sprintf('The trusted key "%s" needs a base64url Ed25519 public key.', $keyId));
        }
        if (null !== $validFrom && null !== $validUntil && $validUntil < $validFrom) {
            throw new \InvalidArgumentException(\sprintf('The trusted key "%s" ends before it starts.', $keyId));
        }
    }

    /** Whether this key was trusted to sign at $time. */
    public function covers(\DateTimeImmutable $time): bool
    {
        return (null === $this->validFrom || $time >= $this->validFrom) && (null === $this->validUntil || $time <= $this->validUntil);
    }

    /** Whether the key has a window at all. A key without one, such as a lone configured key, trusts any time. */
    public function bounded(): bool
    {
        return null !== $this->validFrom || null !== $this->validUntil;
    }

    /**
     * Whether a record that claims the timestamp $claimed was signed inside this key's window. A key without a
     * window accepts any claim; otherwise the claim must be a valid time.
     */
    public function coversClaim(mixed $claimed): bool
    {
        if (!$this->bounded()) {
            return true;
        }
        $time = \is_string($claimed) ? self::parse($claimed) : null;

        return null !== $time && $this->covers($time);
    }

    /** Whether this key made $signature, a base64url Ed25519 signature, over the evidence manifest or audit entry $manifest. */
    public function signed(string $manifest, string $signature): bool
    {
        return Receipt::signatureValid($this->publicKey, $manifest, $signature);
    }

    /** @return array{keyId: string, publicKey: string, validFrom: ?string, validUntil: ?string} as the published key set lists it */
    public function toArray(): array
    {
        return [
            'keyId' => $this->keyId, 'publicKey' => $this->publicKey,
            'validFrom' => null === $this->validFrom ? null : ApprovalRequest::timestamp($this->validFrom),
            'validUntil' => null === $this->validUntil ? null : ApprovalRequest::timestamp($this->validUntil),
        ];
    }

    /** One key as the published key set lists it: keyId, publicKey, and validFrom and validUntil, each a time or null. */
    public static function fromArray(mixed $key): self
    {
        $key = $key instanceof \stdClass ? get_object_vars($key) : $key;
        if (!\is_array($key) || !\is_string($key['keyId'] ?? null) || !\is_string($key['publicKey'] ?? null)) {
            throw new \InvalidArgumentException('Each trusted key needs a "keyId" and a "publicKey".');
        }
        $time = static function (string $field) use ($key): ?\DateTimeImmutable {
            $value = $key[$field] ?? null;
            if (null !== $value && !\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('The trusted key\'s "%s" must be a time or null.', $field));
            }

            return null === $value ? null : self::time($value);
        };

        return new self($key['keyId'], $key['publicKey'], $time('validFrom'), $time('validUntil'));
    }

    /**
     * A time from a key's window: an RFC 3339 date and time with its UTC offset, such as 2026-11-09T12:00:00Z.
     * A time without an offset is refused, so the window never depends on the server's time zone.
     */
    public static function time(string $value): \DateTimeImmutable
    {
        return self::parse($value) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not an RFC 3339 time with an offset, such as 2026-11-09T12:00:00Z.', $value));
    }

    private static function parse(string $value): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        // A date such as February 30 parses by rolling over; it is not a time anyone wrote.
        $problems = \DateTimeImmutable::getLastErrors();

        return false === $problems || 0 === $problems['warning_count'] + $problems['error_count'] ? $time : null;
    }
}
