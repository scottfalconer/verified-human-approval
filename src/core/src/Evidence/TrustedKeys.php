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
