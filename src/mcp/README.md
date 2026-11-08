# Verified Human Approval: MCP presentation (`verified-human-approval/mcp`)

How a pending approval reaches a person through MCP. The Symfony bundle uses this package; it needs only the core package and the official PHP MCP SDK, so other frameworks can too.

Nothing here approves anything. The person approves on the application's own review page with a passkey, and that completion executes the stored proposal. A client's answer to a prompt is never treated as approval.

## What a client sees

| Client | What it gets |
| --- | --- |
| Every client | The pending result at once: request ID, status, review URL, a message for the model, and the card's view under `approval` |
| A host that renders MCP Apps (`io.modelcontextprotocol/ui`) | The approval card beside the result. Its button opens the review page through `ui/open-link`, and it polls the status tool until the application records an outcome: every 3 seconds at first, then every 10, then every 30, not while hidden, and for a quarter of an hour at most. It stops when the host cancels the call (`ui/notifications/tool-cancelled`) and answers `ui/resource-teardown` |
| A client the application lists, on MCP 2026-07-28 or later, that declares `elicitation.url` | Also a URL-mode elicitation (`InputRequiredResult`) pointing at the review page |

URL-mode elicitation is off by default and enabled per client by `clientInfo.name`. Some clients declare URL mode and then decline, drop or fail on the request, so a declaration alone is not enough. This knowingly departs from the specification's advice not to change server behaviour on `clientInfo`: it is an interoperability allowlist, not a security decision, since the card and the link are always there; `'*'` switches to capability-only gating. It is never sent under the handshake-era revisions, where the server would hold the call open while the person approves: the SDK's `InputRequiredShim` is on by default and would turn the result into a blocking `elicitation/create` there.

## The card

`ApprovalCard::URI` is the card's `ui://` resource and `ApprovalCard::resource()` its HTML. Link a protected tool to it with `meta: ApprovalCard::TOOL_META`. The card reads `structuredContent.approval`:

| Field | Meaning |
| --- | --- |
| `requestId` | The proposal |
| `status` | `pending`, `completed`, `cancelled`, `expired`, `invalidated` or `denied`, as recorded |
| `reviewUrl` | The application's review page |
| `statusTool` | The read-only tool the card polls, called with `request_id` |
| `statement` | The exact statement the person will approve |
| `handoffTool` | Optional. An app-only tool that returns a one-time phone code, or `null` |

The card never runs WebAuthn. The verifier rejects embedded and cross-origin ceremonies, and the card runs on the host's sandbox origin, so no assertion it could obtain would pass the application's exact-origin check.

When `handoffTool` is set and the review page is on HTTPS, the card also offers **Approve on your phone**, except inside Claude on a phone. It calls the tool with `request_id` and expects `structuredContent` with `requestId`, `expiresIn` (seconds) and `modules`: the QR code as one string of `0` and `1` per row, quiet zone included. The card draws those modules itself with SVG elements, so it never parses markup from the server, and takes the code down when it expires or the request leaves `pending`. The Symfony bundle supplies such a tool.

## The URL-mode elicitation and its retry

`ApprovalFlow::respond()` presents a protected tool's result:

1. The first call requests or reuses the proposal. A listed client gets an `InputRequiredResult` whose signed request state carries the request ID and a binding.
2. The binding hashes the caller (user, tenant and delegation), the tool name and the tool's arguments.
3. The retry must carry the same binding, or it fails with `continuation_mismatch`. It reads the stored state and never prepares or executes anything.
4. After `accept`, the model is told the person opened the page, which is not an approval. After `decline` or `cancel`, the request stays open on the site.

The SDK signs request state but does not encrypt it, and its expiry bounds replay without making it single-use. So the state carries only a reference. A keyless retry also reuses the pending proposal in the core, so a handler that is entered again never creates a second review URL.

A platform supplies a `ResultPresenter`: its review URL, its normal tool result, and its status tool's name.
