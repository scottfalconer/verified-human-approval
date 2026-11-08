# Conformance review: Symfony usage and MCP standards

Reviewed 2026-11-08 at commit `e35e60e`. The question was whether this project uses the official PHP MCP SDK (`mcp/sdk` 0.9.0), the Symfony MCP bundle (1.0.0-BETA1) and Symfony AI the way their authors intend, and whether it follows the MCP specification and its best practices. Every point below was checked against a primary source: the vendor source, the SDK's own docs and ADRs, the bundle's documentation, or the specification text (2026-07-28 and 2025-11-25, plus the MCP Apps 2026-01-26 extension).

## What holds up

- **The non-blocking approval prompt is the SDK's intended API.** A tool returns an explicit `InputRequiredResult` with signed `requestState` ("the only way to ask several things in one round trip, to carry your own state"). `ClientGateway::elicitUrl()` would be wrong here: on a handshake-era connection it suspends the handler and holds the HTTP request open, which this project must never do. The state carries only a request id and a binding over the caller, the tool and a digest of the arguments, the shape the MRTR pattern recommends. The handler is re-enterable, as the SDK requires ("once per ask").
- **The card is a documented MCP App form.** `#[AsMcpApp]` with an `__invoke(): TextResourceContents` method that owns its content and `_meta.ui`; the app-only phone tool is `#[AsMcpAppTool(appOnly: true)]`. The card uses only `tools/call`, `ui/open-link` and `size-changed`, accepts messages only from its parent, and relies on the host's default CSP, which fits a card that loads nothing external.
- **Protecting `/mcp` with a Symfony firewall authenticator is what the bundle documents**: "validating a bearer token and loading the corresponding user is plain Symfony Security, wired like any other firewall." The 401 carries `WWW-Authenticate: Bearer resource_metadata=...` derived from the configured origin, as the SDK's authorization guide requires.
- **Every MUST we could find in the elicitation, tools, Apps, authorization and transport specifications is met**: URL mode only for clients declaring `elicitation.url`; a review URL that carries no user data and is not pre-authenticated; the same signed-in person completes what they started; "accept" is never authorization; refusals are `isError` tool results and a pending approval is not an error; PRM (RFC 9728) with `WWW-Authenticate` discovery; PKCE S256 only; exact redirect-URI matching; `iss` (RFC 9207) on success and error; rotating single-use refresh tokens; consent on every authorization behind CSRF and `frame-ancestors 'none'`; no token passthrough; audience bound by construction (opaque tokens looked up in this server's own table); Origin/Host validation, `MCP-Protocol-Version`, `Mcp-Method`/`Mcp-Name` and `serverInfo` handled by the SDK and left on.
- **No deprecated SDK API is called.** The Symfony AI example (`Agent` + `McpToolbox` + the SDK client) matches the bridge's own README.

## Deliberate deviations, stated

- **The demo runs its own OAuth authorization server.** The bundle docs say to "delegate to a dedicated OAuth server rather than hand-rolling one" and name `omouren/mcp-oauth-bundle` (on `league/oauth2-server-bundle`); the SDK's ADR 0001 says the same. Ours is a stand-in for an identity provider, kept minimal: PKCE S256 only, public clients, single-use codes, rotating refresh tokens that end after 30 days, tokens valid only for this server. That bundle cannot be installed beside `symfony/mcp-bundle` 1.0.0-BETA1 today (it requires `^0.13`); when it can, it is the production path.
- **The URL prompt is sent only to clients named in `url_elicitation.clients`.** The spec says implementations "SHOULD NOT use [`clientInfo`] to change the behavior of the client or server". This is an interoperability allowlist for clients we have tested, not a security decision; the card and the plain link are always present, and `'*'` switches to capability-only gating.
- **Insufficient scope is reported as a tool error**, not as the spec's `HTTP 403` with `error="insufficient_scope"`. Scopes are checked per tool inside the JSON-RPC body, where a handler cannot set the HTTP status.
- **Dynamic Client Registration is still offered.** The 2026-07-28 specification deprecates it in favour of Client ID Metadata Documents, which we also support; DCR stays for clients that need it.
- **`scopes_supported` lists all three scopes.** The spec wants the minimal set for basic functionality; an agent that issues alerts needs all three.
- **Tool visibility `["app"]` is a host rule, not a server boundary.** The spec places the MUSTs on hosts; the server protects the phone-code tool with the same read check as `approval_status` and a per-person budget.

## What we are changing next

Found by the review, in order of value; none changes what the application enforces.

1. Tool metadata: `outputSchema`, `title`, parameter descriptions and `openWorldHint: false` on every tool; register the phone-code tool so it carries annotations and a schema.
2. Hand the SDK the validated token (`AccessToken` request attribute) so tools use `hasScope()` instead of four hand-written scope checks.
3. Turn on the bundle's session lock; tie `request_state.ttl` to the approval lifetime with its own key; tell the model to call `approval_status` if a retry fails.
4. An automated handshake-era (2025-11-25) test, the path Claude Desktop and claude.ai use.
5. OAuth hygiene: cache Client ID Metadata Documents, require `client_name`, validate `resource` at the token endpoint, add `scope=` to the 401, one shared `ProtectedResourceMetadata`, rate-limit registration.
6. The card: handle `ui/notifications/tool-cancelled`, answer `ui/resource-teardown`, back off the polling.
7. Bundle maturity, as Symfony's bundle best practices ask: explicit service definitions with `verified_human_approval.*` ids, assets in `public/`, php-cs-fixer `@Symfony`, a CI matrix with lowest dependencies, the `VerifiedHumanApproval\BundleBundle` namespace, `LICENSE` and `docs/index.md` in the package, Monolog with an `mcp` channel.

Two upstream suggestions came out of it, to be filed after judging: a public `RequestContext::getClientInfo()` in the SDK (we read the session key the SDK uses internally), and `annotations`/`outputSchema` parameters on the bundle's `#[AsMcpAppTool]`.
