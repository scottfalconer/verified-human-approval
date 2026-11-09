# Protocol and bundle review

This page records an implementation review made on 2026-11-08 at commit `e35e60e`, followed by notes on later fixes. It covers the official PHP MCP SDK (`mcp/sdk` 0.9.0), Symfony MCP Bundle (1.0.0-BETA1), Symfony AI and the MCP specifications used by that build. It is a review record, not an independent certification or a claim about every future version.

The review used vendor source, SDK documentation and architecture decisions, bundle documentation, and the MCP 2026-07-28 and 2025-11-25 specifications plus the MCP Apps 2026-01-26 extension. For installation, use the [Symfony guide](../src/symfony-bundle/docs/index.md). For dated test evidence, use [verification status](verification.md).

## What holds up

- **The non-blocking approval prompt is the SDK's intended API.** A tool returns an explicit `InputRequiredResult` with signed `requestState` ("the only way to ask several things in one round trip, to carry your own state"). `ClientGateway::elicitUrl()` would be wrong here: on a handshake-era connection it suspends the handler and holds the HTTP request open, which this project must never do. The state carries only a request id and a binding over the caller, the tool and a digest of the arguments, the shape the MRTR pattern recommends. The handler is re-enterable, as the SDK requires ("once per ask").
- **The card is a documented MCP App form.** `#[AsMcpApp]` with an `__invoke(): TextResourceContents` method that owns its content and `_meta.ui`; the phone-code tool is a plain `#[McpTool]` whose `_meta.ui` links it to the card with app-only visibility, so it can carry annotations and an output schema, which `#[AsMcpAppTool]` cannot. The card uses only `tools/call`, `ui/open-link` and `size-changed`, accepts messages only from its parent, and relies on the host's default CSP, which fits a card that loads nothing external.
- **Protecting `/mcp` with a Symfony firewall authenticator is what the bundle documents**: "validating a bearer token and loading the corresponding user is plain Symfony Security, wired like any other firewall." The 401 carries `WWW-Authenticate: Bearer resource_metadata=...` derived from the configured origin, as the SDK's authorization guide requires.
- **The reviewed flows implement these protocol checks:** URL mode only for clients declaring `elicitation.url`; a review URL that carries no user data and is not pre-authenticated; approval by the same account and tenant; "accept" is never authorization; refusals are `isError` tool results and a pending approval is not an error; PRM (RFC 9728) with `WWW-Authenticate` discovery; PKCE S256 only; exact redirect-URI matching; `iss` (RFC 9207) on success and error; rotating single-use refresh tokens; consent on every authorization behind CSRF and `frame-ancestors 'none'`; no token passthrough; audience bound by construction (opaque tokens looked up in this server's own table); Origin/Host validation, `MCP-Protocol-Version`, `Mcp-Method`/`Mcp-Name` and `serverInfo` handled by the SDK and left on.
- **No deprecated SDK API is called.** The Symfony AI example (`Agent` + `McpToolbox` + the SDK client) matches the bridge's own README.

## Deliberate deviations, stated

- **The demo installs `omouren/mcp-oauth-bundle` through a Composer alias.** At the time of this review the demo ran its own authorization server, because that bundle's version constraints did not allow it beside `symfony/mcp-bundle` 1.0.0-BETA1. The demo now uses it with league/oauth2-server ([below](#an-existing-oauth-server)). Its 1.2 release still declares `symfony/mcp-bundle ^0.13`, but it reads only each server's HTTP transport and path from that bundle's configuration, which 1.0.0-BETA1 keeps in the same shape, so the root manifest requires `"symfony/mcp-bundle": "1.0.0-BETA1 as 0.13.99"`. Composer reports an alias as an exact constraint, so CI validates the root manifest without its constraint check; the packages are validated in full.
- **The URL prompt is sent only to clients named in `url_elicitation.clients`.** The spec says implementations "SHOULD NOT use [`clientInfo`] to change the behavior of the client or server". This is an interoperability allowlist for clients we have tested, not a security decision; the card and the plain link are always present, and `'*'` switches to capability-only gating.
- **Dynamic Client Registration is still offered.** The 2026-07-28 specification deprecates it in favour of Client ID Metadata Documents, which we also support; DCR stays for clients that need it.
- **`scopes_supported` lists all three scopes.** The spec wants the minimal set for basic functionality; an agent that issues alerts needs all three.
- **Tool visibility `["app"]` is a host rule, not a server boundary.** The spec places the MUSTs on hosts; the server protects the phone-code tool with the same read check as `approval_status` and a per-person budget.
- **Handshake-era sessions are not bound to the bearer's identity** (a 2025-11-25 SHOULD). The `/mcp` firewall is stateless, so every request authenticates its own token, and every tool checks that the person owns what it reads; the session holds only the negotiated capabilities and client details.
- **UI-linked tools are listed to every client.** The Apps extension says servers SHOULD check client capabilities first, but under 2026-07-28 there is no handshake and `tools/list` must not vary per connection; hosts without MCP Apps ignore `_meta.ui`.

## What changed after the review

Done the same day; none of it changes what the application enforces.

1. **Tool metadata.** Every tool declares `title`, `outputSchema` (one shared schema for the presenter's result, `ApprovalFlow::OUTPUT_SCHEMA`), described parameters and `openWorldHint: false`. The phone-code tool is now an ordinary `#[McpTool]` whose `_meta.ui` links it to the card with app-only visibility, so it carries annotations and a schema too.
2. **The SDK gets the validated token.** The `/mcp` authenticator sets the `AccessToken` request attribute; tools decide with `RequestContext::getAccessToken()->hasScope()`, and the actor provider reads the same token. The authorizer still rechecks the stored grant when an approval completes, which no token covers.
3. **Sessions and state.** The bundle's session lock is on; `request_state` has its own key, derived from the application secret, and a lifetime past the proposal's; the tool description, the server instructions and the prompt (which now names the request id) tell the model to call `approval_status` if a retry is refused. A retry carrying a verified state without an answer reads the stored request instead of starting over.
4. **A handshake-era test** (`tests/Bundle/HandshakeEraTest.php`): a 2025-11-25 client named like Claude Code, declaring URL-mode elicitation, gets a plain pending result, the card and the status, with a session in the file store and no shim-driven elicitation.
5. **OAuth hygiene.** Client ID Metadata Documents are cached (honouring `Cache-Control`, bounded, failures remembered briefly) and never fetched at the token endpoint, which compares the stored `client_id`; `client_name` is required; `resource` is validated at the token endpoint too; the 401 carries `scope=` and the metadata URL the SDK's `ProtectedResourceMetadata` derives, from one shared service; open registration has a budget per address; the consent page lists the scopes, the metadata document's host and warns about loopback-only clients; `[::1]` is a valid loopback.
6. **The card** stops on `ui/notifications/tool-cancelled`, answers `ui/resource-teardown`, and polls every 3 s, then 10 s, then 30 s, not while hidden.
7. **Docs and logging.** The OAuth server is labelled a stand-in with the recommended replacement named; the deviations above are stated in the overview and the security model; the wording about app-only tools, server workers and the passkey sandbox is corrected; the demo runs Monolog with the bundle's `mcp` channel.

## Bundle maturity

Also done, as Symfony's bundle best practices ask:

- **Explicit services.** No autowiring or autoconfiguration in the bundle: every service has a `verified_human_approval.` id, the classes applications inject are aliased to those ids, and the MCP elements and the headers subscriber carry their tags explicitly. A `requester_label` key names the requester-label service; `clock` defaults to the framework's.
- **Assets in `public/`**, published by `assets:install` and referenced with `asset()`, instead of a controller serving them.
- **Route-level opt-in** for the review pages' security headers (`_verified_human_approval_headers`), instead of a route-name prefix that the demo's own routes matched by accident; a `verified_human_approval.review_request_matcher` for `access_control`, instead of a hand-maintained path regex; JSON refusals on the JSON routes; neutral templates with blocks.
- **Symfony's coding standard** (`@Symfony`, `@Symfony:risky`), checked in CI, and **PHPStan level 8**.
- **CI on the lowest allowed versions and on Symfony 8.** The lowest-versions run found a real gap: `web-auth/webauthn-lib` 5.3.0 to 5.3.10 accept an assertion whose client data says `webauthn.create`, and one whose backup eligibility changed, so the packages now require 5.3.11.

## After the bundle work

- **One word for the demo's domain.** Its code said announcement while its tools and UI said alert; it is alert throughout now (`alert.publish`, `alert_id`, `demo_alerts`, `PublishAlert`).
- **Transactions out of the controllers.** Adding a passkey, revoking a connection and issuing a passkey link are small services over one transactional helper, instead of transactions opened by hand in controllers; the controllers declare `#[AsController]`, and debug is opt-in through `APP_DEBUG`.
- **The framework's rate limiter.** The approval budget is a framework rate limiter named `verified_human_approval`, prepended by the bundle, so it uses the framework's rate-limiter cache and lock and an application can override it per environment; a `lock_factory` key names the lock that keeps each phone code single-use.
- **One compiled container for the tests.** The demo's runtime settings reach the container as environment variables from an env var loader, so the compiled container names no runtime: a test run compiles it once, runs in 18 s instead of 31 s at PHP's default memory limit, and no secret is written into a compiled file. The layout test no longer rewrites the repository's template.
- **The example client** names itself and prints a URL prompt's review link instead of failing on it.

## After an independent review

A second review of the code (at `e4d6e65`) reproduced four defects and three smaller problems; all are fixed, each with a regression test:

- **A deleted receipt was replaced at export.** Someone with database access could edit a completed action, delete its receipt, and the audit page, which exports to check, would sign the edited record afresh. The exporter now refuses a completed action without its commit receipt; when a receipt was signed is part of what it signs (formats v2); the audit page flags a missing receipt.
- **A revoked passkey's confirmation lived on.** For two minutes after confirming, a session could still add a passkey or issue a passkey link even if the confirming passkey had been revoked meanwhile, from any browser. The confirming passkey must now be active at each use, and enrollment rechecks it inside its transaction.
- **"Approved with your passkey" after a direct run.** The review page and the card said it for every completed request; they now say so only when a passkey proof was recorded.
- **The evidence command failed once installed** (its autoloader path was one directory off), and lived in core although it uses WebAuthn. It now follows Composer's bin proxy and ships with the WebAuthn package.
- **The demo read client metadata whole before checking its size**, with no time limit. The download now stops at the limit.
- **Docs**: a mangled config path, links that broke outside the monorepo, a stale `PolicyProvider` signature, and a quickstart missing the first passkey's one-time link.

The same review's release advice is the roadmap below: a CI job that installs the packages into a separate Symfony application in production mode, splitting the monorepo into four Packagist packages, install steps for our own betas, a database adapter beyond SQLite with schema upgrades, scoped styles and translations, a kept evidence file from each release, PHP 8.5 in CI, and a security policy.

## Truthful outcomes and receipts the person keeps

- **Only what the site confirmed.** When the host stops a tool call, the card says it cannot tell whether anything changed. When the review page loses the site's answer after the passkey step, it says it cannot confirm the outcome and asks for a reload. Refusals carry `request_status`, so a request that stopped is shown as stopped.
- **The person's receipt.** The completed page offers the evidence bundle as the person's receipt, the agent's result carries the signed receipt, and `/approval/receipt-key.json` publishes the key that checks both. `EvidenceSigner` gained `publicKey()`, and `verify-evidence.php` takes the passkey record as an optional third file.

## Ready for other hosts

A first pass to let a non-Symfony host, such as a Drupal module, use core and WebAuthn directly, with no Drupal dependency, new format or new authentication mechanism:

- **The signed audit log's rows sit behind `AuditStore`**, with `PdoAuditStore` for SQLite. Formats and signatures are unchanged; a chain written before the change still verifies.
- **The anchored passkey repository wraps any `RevocableCredentialRepository`.**
- **A passkey revocation and its signed entry now commit together** in both demo routes; before, a failure to sign or store the entry left the passkey revoked with no record.
- **The `ApprovalStore` contract is written down and tested** (`tests/Contract`), including a lost commit answer and unreachable recovery, in both SQLite journals.
- **Original receipts export without a signing key.**
- **A CI job installs the packages into a separate project** on PHP 8.3 and 8.4 and checks them there, and checks what each archive ships.

The Drupal consumer now lives in a [separate repository](https://github.com/scottfalconer/verified-human-approval-drupal), with its own kernel tests. It is outside the Symfony hackathon entry. The library's CI here checks standalone package installation; it does not claim MySQL or PostgreSQL support. A host adapter must own the root transaction (Drupal's `commitOrRelease()` releases a nested savepoint instead of committing) and pass the storage contract before it is relied on.

## An existing OAuth server

On 2026-11-09 the demo's hand-written authorization server was replaced by league/oauth2-server 9 with its Symfony bundle, and omouren/mcp-oauth-bundle 1.2. league issues and checks the tokens: the authorization code flow with PKCE S256 (it refuses `plain`, which is also what it assumes when no method is named), single-use codes, rotating refresh tokens, and signed access tokens that its resource server checks on every request. omouren/mcp-oauth-bundle adds what MCP asks of the server: protected-resource and authorization-server metadata, the `WWW-Authenticate` challenge with `scope=`, client ID metadata documents, registration, the consent step, `iss` on authorization responses and tokens bound to the MCP endpoint (RFC 8707). OAuth issuance stays in the demo; the reusable packages did not change and still read only the validated `AccessToken`.

The demo keeps what belongs to the application:

- **Storage on the one connection.** league's clients, codes and tokens are SQLite tables on the demo's single PDO connection, through league's custom persistence managers (setup schema 6). Each credential stores its original grant ID. Existing demo runtimes need a reset; old unbound credentials are never assigned to whichever connection happens to be current.
- **Agent connections as delegations.** A connection opens when league saves the code it issues after consent; its ID is the delegation an approval records and the authorizer rechecks. A token works only while that original connection is open, within the scopes both allow. Reconnecting retires the old connection and its credentials before saving the new code, in one transaction. A connection ends after 30 days however often the client refreshes; completion checks that lifetime again.
- **Gaps closed around the bundles.** Fetching a client ID metadata document goes through `NoPrivateNetworkHttpClient`, stops at 64 KiB and has a time limit. Open registration has a budget per address. A request naming another resource is refused with `invalid_target`; one naming none, or only the origin, is taken to name the MCP endpoint (RFC 8707 §2), so the consent step, `iss` and the token binding apply to it as well. The consent page cannot be framed, and it lists the scopes, the host the client returns to and a warning for loopback-only clients. The device-code grant, which league's custom persistence also requires a store for, stays disabled.

The security checks the hand-written server passed are kept as acceptance tests in [`OAuthTest.php`](../tests/Bundle/OAuthTest.php), with new ones for claude.ai's metadata document with and without a resource, a client that names no resource, and `plain` PKCE. Three behaviours differ from the earlier server. Access tokens are signed JWTs bound to the endpoint by their `resource` claim; league still looks each one up, so a revoked token stops working at once. A loopback redirect URI matches on any port, as RFC 8252 allows. A metadata document is cached for five minutes whatever its `Cache-Control` says, without remembering failures, and can be fetched again at the token endpoint once that cache has expired.

## Follow-up integrity and interoperability fixes

The 9 November follow-up reproduced five gaps outside the existing acceptance tests. These fixes preserve the receipt format and keep OAuth protocol handling in the existing packages:

- **Stored state agrees with signed execution.** Service reads and retries reject contradictory status or result fields. With signing configured, every completed read verifies the original commit receipt with the trusted application key. The audit page keeps damaged rows visible; JSON and MCP responses use `integrity_error` rather than claiming a known refusal. See [`StoredApprovalIntegrityTest.php`](../tests/Evidence/StoredApprovalIntegrityTest.php) and [`StoredApprovalAuditTest.php`](../tests/Bundle/StoredApprovalAuditTest.php).
- **Credentials keep their original delegation.** The stored `grant_id` binds codes, access tokens and refresh tokens to the consent that issued them. Old credentials cannot inherit a replacement connection.
- **Token consumption is atomic.** A small decorator around League's token controller acquires SQLite's writer before the validity read and holds it through consumption and token persistence. Failed issuance rolls back; deliberate revocations on rejection are preserved. [`OAuthTest.php`](../tests/Bundle/OAuthTest.php) includes independent-process overlap, reconnect rollback and completion after connection expiry.
- **Missing token scope is an HTTP decision.** The demo's [`McpScopeGuard`](../demo/src/Security/McpScopeGuard.php) uses the SDK's parser, header validator and scope policy after authentication. It returns HTTP 403 with `insufficient_scope`, the complete scopes for that operation, and resource metadata. Tool identity comes from the parsed body; misleading modern headers reach the SDK's protocol rejection. Ownership and business refusals remain tool results. See [`ScopeAuthorizationTest.php`](../tests/Bundle/ScopeAuthorizationTest.php).
- **Old card replies cannot replace a recorded outcome.** Completion, host interruption, teardown and a replacement request invalidate outstanding callbacks. Host requests have bounded timeouts, and optional capabilities control which requests the card makes. [`card_lifecycle.mjs`](../tests/card_lifecycle.mjs) executes the actual script and delays both success and error replies; it is a state-machine test, not a browser rendering claim.

The new OAuth runtime still needs the separate live claude.ai sign-in and approval check recorded in [verification status](verification.md) before the public demo is frozen.

## Still open

There is no published Flex recipe. The bundle needs application-specific identity, policy, authorization, storage and verifier services before it can boot. The [integration guide](../src/symfony-bundle/docs/index.md) gives the current installation and configuration steps. Three recorded upstream suggestions are a public `RequestContext::getClientInfo()` in the SDK (the implementation reads the SDK's internal session key), `annotations`/`outputSchema` parameters on `#[AsMcpAppTool]`, which would remove the plain-tool workaround above, and allowing `symfony/mcp-bundle` 1.0 in omouren/mcp-oauth-bundle, which would remove the alias. They are not yet filed.
