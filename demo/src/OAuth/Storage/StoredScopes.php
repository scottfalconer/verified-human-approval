<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;

/** Scopes as the storage keeps them: a JSON list of names. */
final class StoredScopes
{
    /** @param array<Scope> $scopes */
    public static function encode(array $scopes): string
    {
        return json_encode(array_values(array_map('strval', $scopes)), \JSON_THROW_ON_ERROR);
    }

    /** @return list<Scope> */
    public static function decode(mixed $json): array
    {
        $names = json_decode((string) $json, true, 4, \JSON_THROW_ON_ERROR);

        return \is_array($names) ? array_values(array_map(static fn (string $name): Scope => new Scope($name), array_filter($names, 'is_string'))) : [];
    }
}
