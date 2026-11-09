<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

/**
 * Every public key the application has signed receipts and its audit log with: the current signer's and the
 * ones it replaced, each with the window it signed in. A record names its key by id; an id the set does not
 * list verifies nothing, and neither does a record whose claimed time falls outside its key's window. One
 * current signer signs new records; rotating it adds a key here and closes the old one's window.
 *
 * A single public key is a one-key set without a window, which is how a lone key has always been trusted.
 */
final readonly class TrustedKeys
{
    /** The published key set: every key, with its window. */
    public const FORMAT = 'verified-human-approval/receipt-keys/v2';
    /** The earlier published file: one key, without a window. */
    public const SINGLE_KEY_FORMAT = 'verified-human-approval/receipt-key/v1';
    /** The fields of one retired key in an application's settings, such as the Symfony bundle's receipt_keys.retired. */
    public const RETIRED_FIELDS = ['key_id', 'public_key', 'valid_from', 'valid_until'];

    /** @var non-empty-array<string, TrustedKey> by key id, in the order given */
    private array $keys;

    /** @param iterable<TrustedKey> $keys */
    public function __construct(iterable $keys)
    {
        $byId = [];
        foreach ($keys as $key) {
            if (isset($byId[$key->keyId])) {
                throw new \InvalidArgumentException(\sprintf('The key id "%s" is listed more than once.', $key->keyId));
            }
            $byId[$key->keyId] = $key;
        }
        if ([] === $byId) {
            throw new \InvalidArgumentException('A key set needs at least one key.');
        }
        $this->keys = $byId;
    }

    /** One trusted public key, without a window: what a single configured key has always meant. */
    public static function single(string $keyId, string $publicKey): self
    {
        return new self([new TrustedKey($keyId, $publicKey)]);
    }

    /**
     * An application's trusted keys from its settings: the signer's key, open from $validFrom, then every retired
     * key, in the order given. A retired key is public material only, with the window it signed in, and never the
     * signer's own key: a rotation moves to a new key pair. The Symfony bundle builds its keys from receipt_keys
     * this way; any other host can read its own settings the same way.
     *
     * @param string|null $validFrom when the signer began signing, an RFC 3339 time, or null or empty when that was never recorded
     * @param mixed       $retired   a list of maps with key_id, public_key, valid_until and an optional valid_from
     */
    public static function forSigner(EvidenceSigner $signer, ?string $validFrom, mixed $retired = []): self
    {
        $current = new TrustedKey($signer->keyId(), $signer->publicKey(), null === $validFrom || '' === $validFrom ? null : TrustedKey::time($validFrom));
        $keys = self::retired($retired);
        foreach ($keys as $position => $key) {
            if (hash_equals($current->publicKey, $key->publicKey)) {
                throw new \InvalidArgumentException(\sprintf('Retired key %d is the current signing key. A rotation moves to a new key pair.', $position + 1));
            }
        }

        return new self([$current, ...$keys]);
    }

    /**
     * Reads retired keys from settings: a list of maps naming only the RETIRED_FIELDS. Each must have closed: a
     * retired key without valid_until would keep vouching for anything signed with it, at any time.
     *
     * @return list<TrustedKey>
     */
    public static function retired(mixed $retired): array
    {
        if (!\is_array($retired) || !array_is_list($retired)) {
            throw new \InvalidArgumentException('The retired keys must be a list of keys.');
        }
        $keys = [];
        foreach ($retired as $position => $key) {
            if (!\is_array($key) || [] !== array_diff(array_keys($key), self::RETIRED_FIELDS)) {
                throw new \InvalidArgumentException(\sprintf('Retired key %d must name only %s.', $position + 1, implode(', ', self::RETIRED_FIELDS)));
            }
            foreach (['key_id', 'public_key', 'valid_until'] as $field) {
                if (!\is_string($key[$field] ?? null) || '' === $key[$field]) {
                    throw new \InvalidArgumentException(\sprintf('Retired key %d needs %s.', $position + 1, $field));
                }
            }
            $from = $key['valid_from'] ?? null;
            if (null !== $from && !\is_string($from)) {
                throw new \InvalidArgumentException(\sprintf('Retired key %d: valid_from must be a time.', $position + 1));
            }
            $keys[] = new TrustedKey($key['key_id'], $key['public_key'], null === $from || '' === $from ? null : TrustedKey::time($from), TrustedKey::time($key['valid_until']));
        }

        return $keys;
    }

    /**
     * The keys a published key file names. It reads the key set (format verified-human-approval/receipt-keys/v2)
     * and the earlier one-key file (verified-human-approval/receipt-key/v1, or no format at all, as a
     * hand-written trust file has), which becomes a one-key set without a window.
     *
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): self
    {
        $format = $document['format'] ?? null;
        if (self::FORMAT === $format) {
            if (!\is_array($document['keys'] ?? null) || !array_is_list($document['keys'])) {
                throw new \InvalidArgumentException('The key set needs "keys", a list of keys.');
            }

            return new self(array_map(TrustedKey::fromArray(...), $document['keys']));
        }
        if (null !== $format && self::SINGLE_KEY_FORMAT !== $format) {
            throw new \InvalidArgumentException(\sprintf('Unknown key file format "%s".', \is_string($format) ? $format : get_debug_type($format)));
        }
        if (!\is_string($document['keyId'] ?? null) || !\is_string($document['publicKey'] ?? null)) {
            throw new \InvalidArgumentException('The key file needs "keyId" and "publicKey".');
        }

        return self::single($document['keyId'], $document['publicKey']);
    }

    public function get(string $keyId): ?TrustedKey
    {
        return $this->keys[$keyId] ?? null;
    }

    /** @return non-empty-list<TrustedKey> */
    public function all(): array
    {
        return array_values($this->keys);
    }

    /** @return non-empty-list<array{keyId: string, publicKey: string, validFrom: ?string, validUntil: ?string}> each key as the published key set lists it */
    public function toArray(): array
    {
        return array_map(static fn (TrustedKey $key): array => $key->toArray(), $this->all());
    }
}
