# Authentication ownership in the reference application

Verified Human Approval consumes the authenticated host application's actor and authorization services. It does not issue general login sessions, access tokens, OAuth authorization codes, client registrations, or discovery metadata. Your application's existing identity provider and Symfony Security firewall remain responsible for those.

The Riverside reference application needs accounts and an issuer so it can run independently. These live under `demo/`, outside the reusable package directories. The protocol endpoints use maintained providers:

| Responsibility | Owner |
| --- | --- |
| Authorization code, S256 PKCE, signed access tokens, refresh protocol and token validation | `league/oauth2-server-bundle` and `league/oauth2-server` |
| MCP protected-resource and authorization-server metadata, client ID metadata documents (CIMD), registration and consent | `omouren/mcp-oauth-bundle` |
| Resolve the local account and its agent connection; apply current local permissions | `AgentTokenAuthenticator`, `DemoActorProvider`, `DemoAuthorizer` |
| Persist provider records on the application's SQLite connection; revoke and expire local agent connections | `demo/src/OAuth/Storage` and `OAuthConnections` |
| Freeze the exact action, require and verify its passkey approval, authorize again at execution, and record the result | Verified Human Approval packages |

The `/mcp` firewall is stateless. League validates the bearer credential; the host resolves the actual `DemoUser` and hands the SDK a verified `AccessToken` request attribute. A request's claimed user ID is not authentication. The browser review uses the ordinary host session, or the approval package's narrowly scoped one-time phone handoff, and still requires the person's passkey. Personal setup tokens are a separate reference-only convenience.

## Connection and persistence rules

Consent opens a new local agent connection. Each code, access token, and refresh token records that connection's `grant_id`; it cannot borrow a replacement connection for the same user and client. Revoking a connection revokes its stored credentials. A connection expires after 30 days even if refreshed. The host rechecks the original connection before the approved action executes, including when it expires after the passkey prompt opens.

League handles OAuth protocol validation. `AtomicTokenController` wraps its token controller in the application's SQLite writer transaction, so overlapping requests cannot both consume a code or refresh token. A savepoint rolls back partial issuance on a rejected exchange while retaining explicit prior revocation. The provider storage managers are reference adapters, not a portable authorization server offered by this library.

The only token audience is this application's `/mcp`. `ResourceTargetGuard` rejects an explicit different resource and normalizes an omitted resource or this server's origin to `/mcp` before the provider's request listeners run. Only authorization-code and refresh grants are enabled. Plain PKCE and plaintext client secrets are disabled. The SDK scope policy supplies precise per-operation challenges. A reference-only `PreserveScopeChallenge` decorator keeps the OAuth provider’s general response listener from replacing an existing challenge with the whole server’s scope list; when none exists, the provider supplies it unchanged.

CIMD is the primary client discovery path. Fetches use Symfony's `NoPrivateNetworkHttpClient`, a 64 KiB download limit and a 10 second maximum duration. The provider caches metadata for five minutes. Its older dynamic registration route remains available with a per-address budget; upstream deliberately marks DCR deprecated. Tests assert that exact known warning only around that compatibility route while all other deprecations remain failures.

## Reference-only dependency compatibility

The root project pins `symfony/mcp-bundle` to `1.0.0-BETA1` and aliases it as `0.13.99`, because `omouren/mcp-oauth-bundle` 1.2 still declares `^0.13`. This is an explicit compatibility override in this reference application, not upstream's declaration of Symfony MCP 1.0 support. None of the reusable package manifests imposes the OAuth providers or this alias on consumers. Remove the override when the provider supports the MCP 1.0 constraint and rerun the acceptance suite.

`tests/Bundle/OAuthTest.php` exercises real League code issuance, S256 exchange, access validation, refresh, revocation, reconnection binding, rollback, expiry during passkey approval and two independent PHP processes racing to consume the same code or refresh token. Browser requests run through the real Symfony kernel; client metadata download responses are controlled fixtures. `tests/oauth_http_smoke.py` separately starts the real PHP HTTP server on an ephemeral port and exercises browser-session login and consent, S256 exchange, issued JWTs used by the SDK, refresh rotation, and connection revocation through the account page. Neither test connects to a hosted Claude account or tests a physical passkey device.

The OAuth replacement uses runtime schema 6 and fresh isolated test runtimes. It does not migrate or reset existing deployed demo accounts, signing keys, passkeys or grants. Keep an existing demo running on its matching code until an explicit migration or replacement-runtime deployment is arranged; never reset a public demo merely to update this library.

Run the focused checks from the repository root:

```bash
php vendor/bin/phpunit tests/Bundle/OAuthTest.php
PHP_BIN=php python3 tests/oauth_http_smoke.py
```
