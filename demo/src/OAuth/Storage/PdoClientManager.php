<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\ClientFilter;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use VerifiedHumanApproval\Demo\DemoDatabase;

/**
 * The OAuth server's registered clients (from client metadata documents or registration), on the demo's own
 * SQLite connection. Storage only: league/oauth2-server decides what a client may do.
 */
final readonly class PdoClientManager implements ClientManagerInterface
{
    public function __construct(private DemoDatabase $database)
    {
    }

    public function save(ClientInterface $client): void
    {
        $this->database->pdo->prepare('INSERT INTO oauth2_clients (identifier, name, secret, redirect_uris, grants, scopes, active, allow_plain_text_pkce) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(identifier) DO UPDATE SET name = excluded.name, secret = excluded.secret, redirect_uris = excluded.redirect_uris,
            grants = excluded.grants, scopes = excluded.scopes, active = excluded.active, allow_plain_text_pkce = excluded.allow_plain_text_pkce')
            ->execute([
                $client->getIdentifier(), $client->getName(), $client->getSecret(),
                self::strings($client->getRedirectUris()), self::strings($client->getGrants()), self::strings($client->getScopes()),
                (int) $client->isActive(), (int) $client->isPlainTextPkceAllowed(),
            ]);
    }

    public function remove(ClientInterface $client): void
    {
        $this->database->pdo->prepare('DELETE FROM oauth2_clients WHERE identifier = ?')->execute([$client->getIdentifier()]);
    }

    public function find(string $identifier): ?ClientInterface
    {
        $query = $this->database->pdo->prepare('SELECT * FROM oauth2_clients WHERE identifier = ?');
        $query->execute([$identifier]);
        $row = $query->fetch();

        return \is_array($row) ? self::client($row) : null;
    }

    public function list(?ClientFilter $clientFilter): array
    {
        $query = $this->database->pdo->prepare('SELECT * FROM oauth2_clients ORDER BY identifier');
        $query->execute();
        $clients = array_values(array_map(self::client(...), $query->fetchAll()));
        if (null === $clientFilter) {
            return $clients;
        }
        $wanted = static fn (array $filter, array $values): bool => [] === $filter || [] !== array_intersect(array_map('strval', $filter), array_map('strval', $values));

        return array_values(array_filter($clients, static fn (ClientInterface $client): bool => $wanted($clientFilter->getGrants(), $client->getGrants())
            && $wanted($clientFilter->getRedirectUris(), $client->getRedirectUris()) && $wanted($clientFilter->getScopes(), $client->getScopes())));
    }

    /** @param array<string, mixed> $row */
    private static function client(array $row): ClientInterface
    {
        $identifier = (string) $row['identifier'];
        if ('' === $identifier) {
            throw new \UnexpectedValueException('A stored OAuth client has no identifier.');
        }
        $client = new Client((string) $row['name'], $identifier, null === $row['secret'] ? null : (string) $row['secret']);
        $client->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), self::decodeList($row['redirect_uris'])));
        $client->setGrants(...array_map(static fn (string $grant): Grant => new Grant($grant), self::decodeList($row['grants'])));
        $client->setScopes(...array_map(static fn (string $scope): Scope => new Scope($scope), self::decodeList($row['scopes'])));
        $client->setActive((bool) $row['active']);
        $client->setAllowPlainTextPkce((bool) $row['allow_plain_text_pkce']);

        return $client;
    }

    /** @param array<\Stringable> $values */
    private static function strings(array $values): string
    {
        return json_encode(array_values(array_map('strval', $values)), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /** @return list<non-empty-string> */
    private static function decodeList(mixed $json): array
    {
        $values = json_decode((string) $json, true, 4, \JSON_THROW_ON_ERROR);

        return \is_array($values) ? array_values(array_filter($values, static fn (mixed $value): bool => \is_string($value) && '' !== $value)) : [];
    }
}
