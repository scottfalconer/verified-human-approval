# Verified Human Approval: MCP presentation (`verified-human-approval/mcp`)

How a pending approval reaches a person through MCP. The Symfony bundle uses this package; it needs only the core package and the official PHP MCP SDK, so other frameworks can too.

Nothing here approves anything. The person approves on the application's own review page with a passkey, and that completion executes the stored proposal. A client's answer to a prompt is never treated as approval.

## What a client sees

| Client | What it gets |
| --- | --- |
| Every client the application does not prompt | The pending result at once: request ID, status, review URL, a message for the model, and the card's view under `approval` |
| A host that renders MCP Apps (`io.modelcontextprotocol/ui`) | The approval card beside the result. Its button opens the review page through `ui/open-link`, and it polls the status tool until the application records an outcome. It makes each of those requests only where the host declares it allows them: see [what the card asks of the host](#what-the-card-asks-of-the-host) |
| A client the application lists, on MCP 2026-07-28 or later, that declares `elicitation.url` | First a URL-mode elicitation (`InputRequiredResult`) pointing at the review page, with no content and no card. The pending result comes back when the client retries with its answer |

URL-mode elicitation is off by default and enabled per client by `clientInfo.name`; in the Symfony bundle, by listing them under the `url_elicitation` key's `clients`. Some clients declare URL mode and then decline, drop or fail on the request, so a declaration alone is not enough. This knowingly departs from the specification's advice not to change server behaviour on `clientInfo`: it is an interoperability allowlist, not a security decision, since the card and the link are always there; `'*'` switches to capability-only gating. The card's link is in every protected tool's `_meta.ui`: under 2026-07-28 there is no handshake to learn a client's extensions first, and `tools/list` must not vary per connection, so hosts without MCP Apps simply ignore it. The URL prompt is never sent under the handshake-era revisions, where the server would hold the call open while the person approves: the SDK's `InputRequiredShim` is on by default and would turn the result into a blocking `elicitation/create` there.

## The card

`ApprovalCard::URI` is the card's `ui://` resource and `ApprovalCard::resource()` its HTML. Link a protected tool to it with `meta: ApprovalCard::TOOL_META`. The card reads `structuredContent.approval`:

| Field | Meaning |
| --- | --- |
| `requestId` | The proposal |
| `status` | `pending`, `completed`, `cancelled`, `expired`, `invalidated` or `denied`, as recorded. An [error result](#error-results) carries `rejected` or `integrity_error` instead |
| `reviewUrl` | The application's review page |
| `statusTool` | The read-only tool the card polls, called with `request_id` |
| `statement` | The exact statement the person will approve |
| `approvedWithPasskey` | Whether this completed action used passkey approval. Direct execution does not claim that it did. |
| `completedAt` | When the action ran, RFC 3339 in UTC, or `null` until it has |
| `earlier` | Whether this call's request key named an earlier request that had already finished. The result is that request's recorded outcome, nothing ran for this call, and the card says "Completed earlier, on …" instead of claiming a new approval |
| `handoffTool` | Optional. An app-only tool that returns a one-time phone code, or `null` |

The card never runs WebAuthn. The verifier rejects embedded and cross-origin ceremonies, and the card runs on the host's sandbox origin, so no assertion it could obtain would pass the application's exact-origin check.

### What the card asks of the host

The card sends only the requests the host allows in its answer to `ui/initialize` (`hostCapabilities`):

| Host capability | With it | Without it |
| --- | --- | --- |
| `openLinks` | **Review and approve** opens the review page through `ui/open-link` | No button. The card points at the review link in the reply |
| `serverTools` | The card polls `statusTool`, and can offer the phone code | The card says it cannot check the result in this app, and asks the person to ask for the status after approving. It does not go on saying that nothing has changed, since it could not know |

A result without `statusTool` is treated like a host without `serverTools`.

The card waits for the answer to `ui/initialize` with no deadline, so a slow host still gets a working card; after 15 seconds without one it points at the review link in the reply. Every later request has a 15-second deadline. A `ui/open-link` request that gets no answer in that time is reported as "if the page did not open", not as a refusal: the host may be asking the person to confirm the link. A refusal is reported as one.

The card answers the host's `ping` and `ui/resource-teardown` requests, and refuses any other request with `-32601`.

### How the card follows a request

The card checks the status tool every 3 seconds for two minutes, every 10 seconds until five minutes, then every 30. It does not check while it is hidden, and checks at once when it comes back into view. A quarter of an hour after the result arrived or the person last used one of its buttons, it stops and says so; coming back into view checks once more, and using a button starts the quick checks again. A request lasts ten minutes unless the application's policy sets another lifetime, so the card usually reports `expired` before it stops. While a check fails, the card says it cannot confirm the current status.

Only the status tool changes the outcome the card reports. A reply that arrives after the application recorded an outcome, after another request replaced this one, or after the host ended the card is dropped. An `integrity_error` is shown as an outcome the card cannot confirm, never as "nothing changed". A host may also announce the card's own calls, as the Apps specification's diagram draws: a `tool-input` with the arguments of a call the card made, then that call's `tool-result`. The card leaves both alone, since the call's reply brings its result; a `tool-input` with other arguments still starts a new request.

When the host stops a call (`ui/notifications/tool-cancelled`), the card stops polling. That does not establish whether the application completed or cancelled the request. Read the existing request's status before starting another action after an interruption.

### The phone code

When `handoffTool` is set, the review page is on HTTPS and the host declares `serverTools`, the card also offers **Approve on your phone**, except inside Claude on a phone. It calls the tool with `request_id` and expects `structuredContent` with `requestId`, `expiresIn` (seconds) and `modules`: the QR code as one string of `0` and `1` per row, quiet zone included. The card draws those modules itself with SVG elements, so it never parses markup from the server, and takes the code down when it expires or the request leaves `pending`. The Symfony bundle supplies such a tool.

## The URL-mode elicitation and its retry

`ApprovalFlow::respond()` presents a protected tool's result:

1. The first call requests or reuses the proposal. A listed client gets an `InputRequiredResult` whose signed request state carries the request ID and a binding.
2. The binding hashes the caller (user, tenant and delegation), the tool name and the tool's arguments.
3. The retry must carry the same binding, or it fails with `continuation_mismatch`. It reads the stored state and never prepares or executes anything.
4. After `accept`, the model is told the person opened the page, which is not an approval. After `decline` or `cancel`, the request stays open on the site.

The SDK signs request state but does not encrypt it, and its expiry bounds replay without making it single-use. So the state carries only a reference. It signs with the key the server was built with (`Builder::setRequestState()`); on a server without one, `respond()` throws a `LogicException` for a call it would prompt, before it spends the budget or prepares anything. A keyless retry also reuses the pending proposal in the core, so a handler that is entered again never creates a second review URL.

A platform supplies a `ResultPresenter`: its review URL, its normal tool result, and its status tool's name.

## Rate limiting

The MCP tools specification asks a server to rate limit tool calls. `ApprovalFlow::respond()` spends one from the person's `ProposalBudget` on every call that may open a proposal, before the action is prepared. The retry of a URL prompt only reads, and spends nothing. Once the budget is spent, `respond()` throws an `ApprovalException` with the reason `rate_limited`, which `ApprovalFlow::errorResult()` turns into a refusal. A platform supplies the budget; the Symfony bundle's is a rate limiter, per person across their agent connections.

## The request key argument

A protected tool may take an optional request key and pass it to `ApprovalService::request()`. Describe that argument with `ApprovalFlow::REQUEST_KEY_DESCRIPTION`:

```php
#[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
```

A request key suits a client that generates a unique id for each new request and resends it to retry. A language model is not such a client. Asked for "your own id for this request", Claude models built a key from the arguments and the date in every run we tried, and often built the same key again in a later conversation. A key used before returns that earlier request and its outcome: after an operator took the demo's alert down, a repeated key answered `completed` and nothing was issued. With this description the same models left the argument out. That is safe: a repeated call without a key returns the same pending request, and a new request once the earlier one has finished.

When a key does name a request that was made before the call and has finished, `ApprovalFlow::respond()` says so. The result's `message` tells the model that this is the earlier request's recorded outcome, when it completed, that nothing ran now and how to ask again, and the card's view carries `earlier: true`. A client that retries after a timeout reads the same, and it is as true for it. `ApprovalFlow` takes the approval service's PSR-20 clock to tell an earlier request from one this call made.

## Error results

`ApprovalFlow::errorResult()` turns an `ApprovalException` into the error result a protected tool returns: `status`, `reason` and `message`, as text and as structured content that fits `ApprovalFlow::OUTPUT_SCHEMA`. Pass the request ID as the second argument when the tool knows it.

| `status` | When |
| --- | --- |
| `rejected` | The application refused: the reason says why |
| `integrity_error` | The stored request failed the core's check (`record_integrity_error`). The application cannot vouch for the outcome, and the action may have run, so the model must not report it as refused or as not having happened |
