# Authentication ownership in the reference application

Verified Human Approval consumes the authenticated host application's actor and authorization services. It does not issue general login sessions, access tokens, OAuth authorization codes, client registrations, or discovery metadata. Your application's existing identity provider and Symfony Security firewall remain responsible for those.

The Riverside reference application needs accounts and an issuer so it can run independently. These live under `demo/`, outside the reusable package directories. The protocol endpoints use maintained providers:

| Responsibility | Owner |
| --- | --- |
| Authorization code, S256 PKCE, signed access tokens, refresh protocol and token validation | `league/oauth2-server-bundle` and `league/oauth2-server` |
| MCP protected-resource and authorization-server metadata, client ID metadata documents (CIMD) and registration | `omouren/mcp-oauth-bundle` |
| Ask the person about the exact authorization request League validated, and approve only that request | `ConsentGate` and `ConsentController`, in place of the provider's consent listener and page |
| Resolve the local account and its agent connection; apply current local permissions | `AgentTokenAuthenticator`, `DemoActorProvider`, `DemoAuthorizer` |
| Persist provider records on the application's SQLite connection; revoke and expire local agent connections | `demo/src/OAuth/Storage` and `OAuthConnections` |
| Freeze the exact action, require and verify its passkey approval, authorize again at execution, and record the result | Verified Human Approval packages |

The `/mcp` firewall is stateless. League validates the bearer credential; the host resolves the actual `DemoUser` and hands the SDK a verified `AccessToken` request attribute. A request's claimed user ID is not authentication. The browser review uses the ordinary host session, or the approval package's narrowly scoped one-time phone handoff, and still requires the person's passkey. Personal setup tokens are a separate reference-only convenience.

## Consent

Every authorization waits for the person on the consent page, which shows only what the server validated: the client's registered name; the host of the client ID metadata document that named it, or that the name is unverified when the client registered itself; the host its code returns to, with a warning when that is this computer; and the scopes its token will carry. Nothing in the page's link changes what it shows. Allow approves that one request, for the same person, client, redirect URI, scopes, resource, state and PKCE challenge, once and within ten minutes; any other authorization request is asked about again. A request for scopes the client was not registered with goes back to the client as `invalid_scope` without being shown.

## Connection and persistence rules

Consent opens a new local agent connection. Each code, access token, and refresh token records that connection's `grant_id`; it cannot borrow a replacement connection for the same user and client. Revoking a connection revokes its stored credentials. A connection expires after 30 days even if refreshed. The host rechecks the original connection before the approved action executes, including when it expires after the passkey prompt opens.

League handles OAuth protocol validation. `AtomicTokenController` wraps its token controller in the application's SQLite writer transaction, so overlapping requests cannot both consume a code or refresh token. A savepoint rolls back partial issuance on a rejected exchange while retaining explicit prior revocation. The provider storage managers are reference adapters, not a portable authorization server offered by this library.

The only token audience is this application's `/mcp`. Access tokens carry it as both their `aud` and `resource` claims, and the firewall finds the token's client in the record this server kept when it issued the token. `ResourceTargetGuard` rejects an explicit different resource and normalizes an omitted resource or this server's origin to `/mcp` before the provider's request listeners run. Only authorization-code and refresh grants are enabled. Plain PKCE and plaintext client secrets are disabled. The SDK scope policy supplies precise per-operation challenges. A reference-only `PreserveScopeChallenge` decorator keeps the OAuth provider’s general response listener from replacing an existing challenge with the whole server’s scope list; when none exists, the provider supplies it unchanged.

CIMD is the primary client discovery path. Fetches use Symfony's `NoPrivateNetworkHttpClient`, a 64 KiB download limit and a 10 second maximum duration. The provider caches metadata for five minutes.

Dynamic client registration (RFC 7591), which the MCP specification deprecates in favour of CIMD, is a setting of each runtime. A local demo offers it, with a per-address budget, for clients that still register themselves; set `VERIFIED_HUMAN_APPROVAL_DYNAMIC_CLIENT_REGISTRATION=0` when running `php demo/bin/setup.php` to turn it off. The public demo turns it off, so only a client that publishes a client ID metadata document, as claude.ai does, can connect there. The provider marks its registration route deprecated; tests assert that exact warning only around that route, and every other deprecation still fails.

## Reference-only dependency compatibility

The root project pins `symfony/mcp-bundle` to `1.0.0-BETA1` and aliases it as `0.13.99`, because `omouren/mcp-oauth-bundle` 1.2 still declares `^0.13`. This is an explicit compatibility override in this reference application, not upstream's declaration of Symfony MCP 1.0 support. None of the reusable package manifests imposes the OAuth providers or this alias on consumers. The override is needed only until the provider declares support for Symfony MCP 1.0.

`tests/Bundle/OAuthTest.php` exercises real League code issuance, S256 exchange, access validation, refresh, revocation, reconnection binding, rollback, expiry during passkey approval, two independent PHP processes racing to consume the same code or refresh token, and consent that approves only the request it showed. Browser requests run through the real Symfony kernel; client metadata download responses are controlled fixtures. `tests/oauth_http_smoke.py` separately starts the real PHP HTTP server on an ephemeral port and exercises browser-session login and consent, S256 exchange, issued JWTs used by the SDK, refresh rotation, and connection revocation through the account page. Neither test connects to a hosted Claude account or tests a physical passkey device.

This OAuth server needs runtime schema 6. The kernel refuses a runtime with an older schema instead of migrating it, and nothing upgrades or resets a runtime's accounts, signing keys, passkeys or grants on its own: an older runtime keeps working only on the code that made it, or is replaced with `php demo/bin/setup.php --reset`, which removes all of them.

Run the focused checks from the repository root:

```bash
php vendor/bin/phpunit tests/Bundle/OAuthTest.php
PHP_BIN=php python3 tests/oauth_http_smoke.py
```
