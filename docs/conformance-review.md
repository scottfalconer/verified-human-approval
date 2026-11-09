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

- **The demo runs its own OAuth authorization server.** It is reference code for connecting a remote client, with PKCE S256, public clients, single-use codes, rotating refresh tokens and tokens valid only for this server. A production application should connect its own authorization server. At the time of this review, the bundle documentation named `omouren/mcp-oauth-bundle`, but its version constraints did not allow installation beside the demo's `symfony/mcp-bundle` 1.0.0-BETA1. Check current compatibility before choosing that integration.
- **The URL prompt is sent only to clients named in `url_elicitation.clients`.** The spec says implementations "SHOULD NOT use [`clientInfo`] to change the behavior of the client or server". This is an interoperability allowlist for clients we have tested, not a security decision; the card and the plain link are always present, and `'*'` switches to capability-only gating.
- **Insufficient scope is reported as a tool error**, not as the spec's `HTTP 403` with `error="insufficient_scope"`. Scopes are checked per tool inside the JSON-RPC body, where a handler cannot set the HTTP status.
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

## Still open

There is no published Flex recipe. The bundle needs application-specific identity, policy, authorization, storage and verifier services before it can boot. The [integration guide](../src/symfony-bundle/docs/index.md) gives the current installation and configuration steps. Two recorded upstream suggestions are a public `RequestContext::getClientInfo()` in the SDK (the implementation reads the SDK's internal session key), and `annotations`/`outputSchema` parameters on `#[AsMcpAppTool]`, which would remove the plain-tool workaround above.
