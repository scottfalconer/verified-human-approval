# MCP conformance

How the packages and the demo use the Model Context Protocol and its MCP Apps extension: what they implement, where they depart from the specification on purpose and why, and what limits clients meet. This page describes the code in this repository. It is not a certification. [Verification status](verification.md) says which clients have been tried, and when.

## Versions

- MCP specification [2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28). Clients on the 2025-11-25 revision, which starts with `initialize` and keeps a session, are served too.
- MCP Apps [2026-01-26](https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx), the extension `io.modelcontextprotocol/ui`.
- The PHP MCP SDK, `mcp/sdk` 0.9.0, and the Symfony MCP Bundle, `symfony/mcp-bundle` 1.0.0-BETA1: the locked versions. The packages require `mcp/sdk` `^0.9` and `symfony/mcp-bundle` `^1.0`.

## Tools and results

- **A protected action is an ordinary tool.** The demo's `publish_alert` is a plain `#[McpTool]` that calls `ApprovalFlow::respond()`. It declares a `title`, described parameters, annotations that match what it does, and `ApprovalFlow::OUTPUT_SCHEMA` as its `outputSchema`. The SDK checks the arguments against the tool's input schema before the tool runs, and a successful result's `structuredContent` against its output schema as it is sent.
- **Structured results come as text too.** An approval result, from a protected tool or the status tool, and an error result from `ApprovalFlow::errorResult()` carry `structuredContent` and the same JSON as text, as the [tools specification](https://modelcontextprotocol.io/specification/2026-07-28/server/tools) asks for backwards compatibility. A host without MCP Apps gets the review URL in that text. The phone-code tool's text is a sentence for the person; its structured content is for the card.
- **Pending is a result, not an error.** A tool that needs approval returns `status: pending` and the review URL. A protected tool's refusal is a tool execution error (`isError: true`) with `status: rejected`, a `reason` and a `message`, so the model can read why. A stored record that fails its integrity check is reported as `status: integrity_error`, never as `rejected`: it does not say whether the action ran. `ApprovalFlow::errorResult()` builds both.
- **A request id is a handle, not a capability.** Request ids are `apr_` and 128 random bits. On every call, the status tool, the phone-code tool and the review pages check that the request belongs to the caller's account and tenant, as the [security best practices](https://modelcontextprotocol.io/docs/2026-07-28/tutorials/security/security_best_practices) ask of state handles. Anyone else gets the answer a request that does not exist gets: from the status tool, a plain text error.
- **A retry does not run an action twice.** A repeated call from the same connection with the same arguments returns the same pending request, and makes a new one only after that request has finished. A tool may also take an optional request key. Its description, `ApprovalFlow::REQUEST_KEY_DESCRIPTION`, tells a model to leave the key out, because a key used before returns that earlier request and its outcome.
- **The tool list is the same for every connection.** `tools/list` lists every tool, those linked to the card included, to every client, as 2026-07-28 requires. Listing tools needs no scope.
- **Inputs, access and outputs.** The tools specification requires servers to validate inputs, control access, rate limit and sanitize outputs. The SDK validates arguments against the input schema, the action adapter validates what it reads, and the core refuses an input over 256 KiB. The tool checks the token's scopes, and the application's authorizer checks current permissions when the request is made, before the action runs and again before it commits. Approval attempts on the review page and phone codes each have a per-person budget from Symfony's rate limiter. Results are JSON the application builds from its stored record, and the card writes only text.

## Elicitation

- **The prompt uses multi round-trip requests.** For a pending approval, `ApprovalFlow::respond()` can return an `InputRequiredResult` whose `inputRequests` hold one URL-mode `elicitation/create` and whose `requestState` lets the retry continue, as the [multi round-trip pattern](https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr) describes. It never calls `ClientGateway::elicitUrl()`, which holds the HTTP request open on a handshake-era connection.
- **When it is sent.** Only when all of these hold: `url_elicitation.enabled` is true; the request uses the 2026-07-28 revision; the client declared `elicitation.url` on that request; and the client's `clientInfo.name` is in `url_elicitation.clients`, or that list holds `'*'`. It is off by default. A 2025-11-25 client never gets it, because the SDK's `InputRequiredShim` would turn it into a blocking `elicitation/create` that holds the call open while the person approves. Every other client gets the plain result with the review URL.
- **The request state.** The SDK signs it with HMAC-SHA256, using the server's `request_state.key` of at least 32 bytes, with an expiry inside the signed body. It does not encrypt it. The state carries a format name, the request id and a binding: SHA-256 over the caller (user, tenant and agent connection), the tool name and the arguments. A retry with another caller or other arguments fails with `continuation_mismatch`. These are the three things the pattern recommends against replay: the principal, an expiry and the originating request. A retry only reads the stored request, so replaying the state within its lifetime changes nothing.
- **The URL.** It is the application's own review page, `/approval/{id}`, on its configured origin, which must be HTTPS, or `http://localhost` in development. It carries no personal data and no credential. The page acts only for the signed-in person whose request it is, in the same tenant, and refuses anyone else. This meets the [elicitation specification](https://modelcontextprotocol.io/specification/2026-07-28/client/elicitation)'s rules that the URL is not pre-authenticated and that the server checks who opens it.
- **The answer approves nothing.** `accept` only means the person agreed to open the page. The application acts after its own passkey check, and the retry reports the outcome it recorded. The prompt does not authorize the client: its token and scopes stay as they were, and what the person approves is one stored action. So the server does not rely on URL-mode elicitation to authorize users, which the specification forbids.
- **Decline, cancel or no answer.** The request stays open, and the person can still approve or cancel it on the site. A retry without an answer reads the stored request instead of asking again, because the answer never decides the outcome.

Deviation:

- **The prompt is sent by client name.** The specification says implementations SHOULD NOT use `clientInfo` to change their behavior. Some clients declare URL mode and then decline, drop or fail on the request, and on 2026-07-28 the first answer carries no tool result, so such a client would leave the person without the link. The list is an interoperability allowlist, not a security decision: a client that gives a false name only receives a prompt it declared it can handle, and the card and the link are always there. `'*'` turns it into capability-only gating. The demo lists `claude-code`.

### The phone code is a pre-authenticated URL

The card's **Approve on your phone** button calls the `approval_handoff` tool, whose result carries a one-time link. Whoever redeems that link first gets a browser session scoped to one request, so it is a pre-authenticated URL. It is sent in a tool result, not in an elicitation, so the elicitation rule does not apply to it, but it is stated here.

- The code is 32 random bytes, works once and expires after two minutes. A Continue step keeps a link previewer from spending it.
- The scoped session can read that one review page and start the passkey step. It cannot cancel the request or download the receipt, and approving still needs the owner's passkey.
- The tool checks the same read permission as `approval_status`, has its own per-person budget, and makes a code only when the application's origin is HTTPS.

## MCP Apps

- **The card.** `ApprovalCard::resource()` serves `ui://verified-human-approval/approval-card.html` as `text/html;profile=mcp-app`, with `_meta.ui.prefersBorder: true`. In Symfony, `ApprovalCardApp` registers it through the MCP bundle's `#[AsMcpApp]`, which also declares the extension. A protected tool links to it with `_meta.ui.resourceUri` (`ApprovalCard::TOOL_META`). The card declares no `csp`, so the host's restrictive default applies; it loads nothing from the network.
- **Messages.** The card sends `ui/initialize` (protocol version 2026-01-26), `ui/notifications/initialized` and `ui/notifications/size-changed`. It sends `tools/call`, to the status tool and to the phone-code tool, only if the host declared `serverTools`, and `ui/open-link`, for the review page, only if the host declared `openLinks`. It handles the `tool-input`, `tool-result`, `tool-cancelled` and `host-context-changed` notifications, answers `ping` and `ui/resource-teardown`, and refuses any other request with `-32601`. It accepts messages from its parent window only, and posts nothing secret.
- **No passkey in the card.** The card never runs WebAuthn. It runs on the host's sandbox origin, and the verifier accepts only a top-level ceremony on the application's exact origin, so no assertion obtained in the card could pass.
- **The phone-code tool is app-only.** `approval_handoff` is a plain `#[McpTool]` whose `_meta.ui` names the card with `visibility: ["app"]`, so it can declare annotations and an output schema, which the MCP bundle's `#[AsMcpAppTool]` cannot. Visibility is a rule for hosts, not a server boundary: the tool checks the caller's read permission and its budget itself.

Deviations:

- **UI-linked tools are listed to every client.** The extension says servers SHOULD check the client's capabilities before registering UI-enabled tools. Under 2026-07-28 there is no handshake to learn them first, and `tools/list` must not vary per connection, so the tools are listed with their `_meta.ui` to every client. A host without MCP Apps ignores it.
- **Display modes and size.** The card sends an empty `appCapabilities`, so it does not declare `availableDisplayModes`, which the extension says a view MUST do for the modes it supports. It supports only `inline`, the default. It reports its height when it changes its own content, not when the host changes its width or fonts, which the extension suggests a view follow with a `ResizeObserver`.

Interoperability:

- **Theme colors.** On 18 November 2026, in the Claude iOS app, the card showed dark text on a dark surface: it set `data-theme` but no `color-scheme`, and its body was transparent. It now sets `color-scheme`, paints its own surface, and uses the host's colors only as a pair that is readable in the host's theme. [`card_smoke.py`](../tests/card_smoke.py), which CI does not run, checks the colors in Chromium under dark, light and mismatched hosts. The change has not been checked in the iOS app.

## Authorization

The packages contain no authorization server:

- **The host authenticates.** The host's Symfony firewall validates the bearer token and sets the SDK's `AccessToken` request attribute, so tools read its scopes with `RequestContext::getAccessToken()`. The host's actor provider maps the token to the person and their agent connection, and the host's authorizer rechecks that connection's current grant when an approval completes.
- **Scope challenges over HTTP.** `McpScopeChallengeSubscriber`, which an application opts into, applies the SDK's `ScopePolicy` before any MCP code runs. A token that lacks a scope the operation needs gets HTTP 403 with `WWW-Authenticate: Bearer error="insufficient_scope"`, every scope the operation needs in one challenge, the `resource_metadata` URL and a description, plus a JSON-RPC error naming the scopes. A scope the policy says another implies counts. A request that needs a scope and carries no validated token gets 401, and a body too large to inspect gets 413. The tool is read from the parsed body, never from a header. Ownership and permission refusals stay tool results, since a broader token would not change them. The subscriber exists because `symfony/mcp-bundle` 1.0 builds the HTTP transport itself and takes no PSR-15 middleware, so the SDK's `AuthorizationMiddleware` cannot be added ([symfony/ai#2883](https://github.com/symfony/ai/issues/2883)).

The demo's OAuth is reference application code, not part of a package. [demo/authentication.md](../demo/authentication.md) says which component owns what.

- **Maintained providers.** `league/oauth2-server-bundle` 1.2.3, on `league/oauth2-server` 9.4.1, issues and validates the tokens. `omouren/mcp-oauth-bundle` 1.2 adds the MCP parts: protected resource metadata (RFC 9728), authorization server metadata (RFC 8414), client ID metadata documents and the `iss` response parameter (RFC 9207).
- **Discovery.** The demo builds its metadata from its configured origin, never from request headers. A 401 from `/mcp` names the protected resource metadata in `WWW-Authenticate`.
- **Grants.** Only the authorization code and refresh token grants are enabled. Public clients must use PKCE with S256, the only method the metadata lists, and plaintext client secrets are off. A code works once and a refresh token rotates, both under the application's database write lock (`AtomicTokenController`).
- **The resource.** The only resource is the application's `/mcp`. `ResourceTargetGuard` refuses an explicit `resource` that names anything else, and treats an omitted one, or the bare origin, as `/mcp`.
- **Client ID metadata documents** are how a client such as claude.ai identifies itself. The demo accepts a document only over HTTPS from the domains the MCP OAuth bundle trusts by default (claude.ai, vscode.dev and cursor.com). It fetches the document with Symfony's `NoPrivateNetworkHttpClient`, follows no redirect, stops at 64 KiB or 10 seconds, and caches the result.
- **Personal tokens.** The demo also accepts the personal tokens that its setup prints, for local clients such as `mcp-remote` and the PHP examples. The application issues them and stores only their SHA-256 hash; they are not OAuth tokens.

Deviation:

- **`scopes_supported` lists all three scopes.** The specification intends it as the minimal set for basic functionality. Issuing an alert needs all three (`alert.read`, `alert.publish` and `approval.read`), so here the minimal set is the full set.

## Security considerations

- **Sessions on the 2025-11-25 revision are not bound to the bearer.** That revision's [security guidance](https://modelcontextprotocol.io/docs/2025-11-25/tutorials/security/security_best_practices#session-hijacking) says servers SHOULD bind session ids to user-specific information. It also says they MUST verify every inbound request and MUST NOT use sessions for authentication, and those hold: the `/mcp` firewall is stateless, every request authenticates its own token, and every tool checks that the person owns what it reads. The session keeps protocol state, such as the negotiated capabilities and the client's details, and a session id without a valid token reaches nothing.
- **DNS rebinding.** The SDK's protection stays on. The demo allows only its own host (`http.allowed_hosts`), and a request's `Origin`, if it sends one, must be the server's own.
- **No token passthrough.** The tools call no other service with the client's token. The demo accepts only the tokens its own OAuth server issued and the personal tokens it issued itself.
- **Request state is attacker-controlled input.** It is signed, bound to the caller, the tool and the arguments, and only ever read on a retry, as described under [Elicitation](#elicitation).
- **Fetching client metadata** cannot reach private networks, as described under [Authorization](#authorization).
- **The review pages** have their own protections: see [browser and account controls](security-model.md#browser-and-account-controls) in the security model.

## Interoperability limits

- **Clients tried.** Claude Desktop, claude.ai and Claude Code, with the dates and results in [verification status](verification.md#real-clients-and-devices), and the card in the Claude iOS app, as described under [MCP Apps](#mcp-apps). No other vendor's host or model has been tried.
- **The URL prompt** reaches only the clients an application lists; the demo lists `claude-code`. A client on the 2025-11-25 revision never gets it, and uses the card and the link.
- **Server instructions** may not reach the model: claude.ai did not pass them on in the run of 8 November 2026. The demo's tool descriptions repeat the essentials: where the alert id comes from, that a pending alert is not live, and to call `approval_status`.
- **Hosts without MCP Apps** show no card, and do not know the `app` visibility, so they can list `approval_handoff` to the model. The review URL is in the text of every approval result.
- **Beta dependencies.** `symfony/mcp-bundle` 1.0 is a beta. The demo's root `composer.json` installs it as `1.0.0-BETA1 as 0.13.99`, because `omouren/mcp-oauth-bundle` 1.2 declares `^0.13`; the packages do not carry that alias. See [reference-only dependency compatibility](../demo/authentication.md#reference-only-dependency-compatibility).
- **Upstream.** Two changes upstream would remove workarounds here. A public `RequestContext::getClientInfo()` in the SDK: `UrlPrompt` reads the request's metadata from the SDK's session, and if that changes it finds no client name and sends no prompt, while the card and the link still work. And `annotations` and `outputSchema` on `#[AsMcpAppTool]`, which would let the phone-code tool use it. A third is filed as [symfony/ai#2883](https://github.com/symfony/ai/issues/2883): PSR-15 middleware for the MCP bundle's transport, so that the SDK's `AuthorizationMiddleware` could replace the scope-challenge subscriber.
