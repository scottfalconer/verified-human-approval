<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * Builds the application's trusted keys from the bundle's configuration: the receipt signer's key, open from
 * receipt_keys.valid_from, then every retired key in receipt_keys.retired, in the order configured. Each
 * retired key is public material only, with the window it signed in; its private key is no longer needed.
 */
final class TrustedKeysFactory
{
    /** The fields of one retired key, as the configuration names them. */
    public const RETIRED_FIELDS = ['key_id', 'public_key', 'valid_from', 'valid_until'];

    /**
     * @param string|null $validFrom when the signer began signing, or null or empty when that was never recorded
     * @param mixed       $retired   the retired keys: a list of maps with key_id, public_key, valid_until and an optional valid_from
     */
    public static function fromConfiguration(EvidenceSigner $signer, ?string $validFrom, mixed $retired): TrustedKeys
    {
        $current = new TrustedKey($signer->keyId(), $signer->publicKey(), null === $validFrom || '' === $validFrom ? null : TrustedKey::time($validFrom));

        return new TrustedKeys([$current, ...self::retired($retired)]);
    }

    /**
     * Reads the retired keys. Each must have closed: a retired key without valid_until would keep vouching for
     * anything signed with it, at any time.
     *
     * @return list<TrustedKey>
     */
    public static function retired(mixed $retired): array
    {
        if (!\is_array($retired) || !array_is_list($retired)) {
            throw new \InvalidArgumentException('receipt_keys.retired must be a list of keys.');
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
}
