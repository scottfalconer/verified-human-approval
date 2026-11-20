<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

/**
 * An authorization request waiting for the person's answer on the consent page. All it shows comes from the
 * request league/oauth2-server validated, never from the link that opened the page, and `binding` names that
 * request exactly, so the person's answer applies to it and nothing else.
 */
final readonly class ConsentRequest
{
    /**
     * @param string       $id        which page asked; an answer must name it
     * @param string       $resumeUri the authorization request to repeat once the person answers
     * @param string|null  $publisher the host of the client ID metadata document that named the client, if one did
     * @param list<string> $scopes    what the token will carry
     */
    public function __construct(
        public string $id,
        public string $binding,
        public string $resumeUri,
        public string $clientName,
        public ?string $publisher,
        public ReturnAddress $returnAddress,
        public array $scopes,
    ) {
    }

    /**
     * One authorization attempt: the person, the client and the metadata document it named, where the code
     * returns, the scopes and resource, and the state and PKCE challenge only this attempt carries.
     *
     * @param list<string> $scopes
     */
    public static function bind(string $user, string $clientId, ?string $metadataUrl, string $redirectUri, array $scopes, string $resource, ?string $state, ?string $codeChallenge, ?string $codeChallengeMethod): string
    {
        sort($scopes);

        return hash('sha256', json_encode([$user, $clientId, $metadataUrl, $redirectUri, $scopes, $resource, $state, $codeChallenge, $codeChallengeMethod], \JSON_THROW_ON_ERROR));
    }

    /**
     * The scopes a token for this request carries, as league/oauth2-server-bundle settles them at the token
     * endpoint: those asked for, or the client's own when it asks for none.
     *
     * @param list<string> $asked
     * @param list<string> $clientScopes
     *
     * @return list<string>
     */
    public static function grantedScopes(array $asked, array $clientScopes): array
    {
        return array_values(array_unique([] === $asked ? $clientScopes : $asked));
    }
}
