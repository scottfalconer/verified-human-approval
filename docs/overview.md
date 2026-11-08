# Verified Human Approval: full overview

The detailed overview. For the short version and the five-minute quickstart, see the [README](../README.md).

## Exact approval for Symfony and MCP

**Let an agent request an application action. Let the application decide, by its administrator's rule, whether the person must verify it. When they must, let them approve its exact effect with a passkey, and execute that stored proposal once, under their current permissions.**

This repository contains a shared PHP approval library, the WebAuthn bridge, the MCP presentation (approval card, URL-mode elicitation and phone code) and a Symfony bundle, with a runnable Symfony reference application: Riverside Council's public alerts site. An early Drupal port of the same core is described at the end.

The rule is the application's, not the protocol's or the agent's. MCP carries the request and shows the person where to approve. The agent can only ask. The application holds the rule, applies it to every entry point, and runs nothing until a valid passkey assertion over the exact proposal arrives.

The supported execution boundary is a **local database transaction**. The business mutation, approval consumption, passkey counter update and execution record share that transaction. The reference application is verified on SQLite. Other database backends need deployment-specific verification.

## Try the application

Requirements: PHP 8.3+, Composer, and `pdo_sqlite`, `openssl`, `sodium`, `mbstring`, `dom` and the normal PHP JSON/XML extensions. CI runs PHP 8.3 and 8.4. The lockfile pins the tested dependency set, including Symfony 7.4, the official MCP SDK 0.9.0, Symfony MCP Bundle 1.0.0-BETA1 and WebAuthn Library 5.3.12.

```sh
composer install
php examples/symfony/bin/setup.php
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t examples/symfony/public examples/symfony/public/index.php
```

Setup generates four fictional accounts and separate personal agent tokens: `manager` (the emergency manager and administrator, who issues alerts directly), `editor` (county staff, who must approve each exact alert with a passkey), `reader` (read-only) and `other` (another organization). It prints passwords and tokens once; save them privately. The application stores password/token hashes. Setup refuses to overwrite an existing installation. Its database, signing key, sessions and cache stay under ignored `var/demo/`.

Open `http://localhost:8080` and sign in as `editor` using the generated password. Use **Your passkeys** to enroll through your browser and operating system. On a Mac this invokes Touch ID; other devices may use their own passkey provider or PIN. Physical Touch ID has been run end to end from a real Claude Desktop chat; see [verification results](verification.md).

The human account password belongs in the browser account flow. Give the agent only its individual personal token. The MCP firewall does not accept the browser session; the browser approval, enrollment and policy routes do not authenticate an agent bearer token.

Use `http://localhost`, not an arbitrary HTTP hostname or `127.0.0.1`, for this reference WebAuthn configuration. For a different deployment, set `EXACT_APPROVAL_ORIGIN` to a stable HTTPS origin **before** setup; use `EXACT_APPROVAL_RUNTIME` to select another private runtime directory. Changing the relying-party domain can require fresh passkey enrollment.

## Request an action through real MCP

In another terminal, provide the `editor` account’s personal token without putting it into source files:

```sh
read -r -s -p 'Personal agent token: ' EXACT_APPROVAL_TOKEN
export EXACT_APPROVAL_TOKEN
php examples/clients/mcp.php list
php examples/clients/mcp.php read riverside-evacuation
php examples/clients/mcp.php publish riverside-evacuation my-first-request
```

This client uses the official SDK and HTTP transport. The server is the Symfony MCP Bundle at `/mcp`. It exposes:

| Tool | Behavior |
| --- | --- |
| `read_alert` | Read one alert in the current account’s organization: its draft and the live version. |
| `publish_alert` | Ask to issue the alert. Under the administrator’s rule this issues directly, prepares the exact proposal and returns a review URL while approval is required, or refuses. |
| `approval_status` | Read an owned request’s authoritative outcome using a connection with explicit result-read scope. |
| `approval_handoff` | App-only (hidden from the model): the card asks it for a one-time phone code. |

The pending result resembles:

```json
{
  "requestId": "apr_…",
  "status": "pending",
  "reason": null,
  "result": null,
  "review_url": "http://localhost:8080/approval/apr_…"
}
```

Open that URL, review the final alerts page rendered from the proposal, its revision and the required statement, then approve with a passkey. The application’s completion endpoint executes the **stored proposal**. The agent never receives a reusable approval token and never resubmits replacement content.

```sh
php examples/clients/mcp.php status YOUR_REQUEST_ID
```

Reusing `my-first-request` with identical arguments returns the original request or result. Changing arguments under that key conflicts. A new intended action needs a new key. Without a key, a retry of the same request through the same connection returns its pending proposal instead of creating another. Once that proposal is completed, cancelled, expired or invalid, a new request prepares a fresh one. Protected execution is at most once per proposal either way.

### Show a refusal

Create another request. Open its review page, then edit the draft in a second application tab before approving. The pending request becomes invalid when approval is attempted, and the alert is not issued. A new review is needed even when the source revision changes but visible content is identical.

Also try the `reader` account’s token. The rule does not let that role issue alerts, so the request is refused before any review. A passkey never supplies missing rights.

### Administrator controls

`manager` is the human administrator. At `/admin/approval-policy`, **Who can issue public alerts** sets each role’s mode: issue directly, approve each exact alert with a passkey, or not allowed. It also sets the exact required statement, with a preview of its registered placeholders. Saving creates a new policy version, and every changed policy invalidates pending requests made under the old one. Unknown placeholders are rejected; templates are plain text and do not execute Twig or expressions.

"Issue directly" has an explicit meaning: new authorized requests from that role execute without passkey approval. Their execution records say `not_required`; they are not represented as signed human approvals. Old protected proposals cannot use a rule change to bypass their original approval flow. Agent credentials cannot change the rule, and the application applies it the same way to its own **Issue alert** button and to every MCP client.

## Remote clients, claude.ai and the phone

The demo includes a small MCP OAuth authorization server for remote clients such as claude.ai: protected resource metadata (RFC 9728) served with the SDK’s `ProtectedResourceMetadata`, authorization server metadata (RFC 8414), dynamic client registration (RFC 7591) or client ID metadata documents, PKCE with S256, the `iss` response parameter (RFC 9207) and rotating refresh tokens. A `401` from `/mcp` names the metadata in `WWW-Authenticate`. The person signs in and allows access on the site. The resulting token acts for that person and, like the personal token, can ask for actions but never approve one.

`examples/symfony/bin/public start` puts a second copy of the demo on a public HTTPS address through a Cloudflare quick tunnel, under launchd, and prints the connector URL. On that address the approval card also offers **Approve on your phone**: a one-time code (single use, two minutes) that opens only that request’s review on the phone. It is not a sign-in, and approving still takes the person’s own passkey, which reaches the phone through iCloud Keychain or Google Password Manager.

## Symfony AI example

The example uses Symfony AI’s actual `Agent`, `McpToolbox` and the official MCP client:

```sh
# Deterministic integration check: scripted model responses, real HTTP MCP calls.
php examples/clients/agent.php --scripted

# Optional real model; set your own API key and model explicitly.
export OPENAI_API_KEY='YOUR_KEY'
export EXACT_APPROVAL_MODEL='YOUR_SUPPORTED_MODEL'
php examples/clients/agent.php 'Please issue the Riverside Road evacuation alert.'
```

The scripted mode is tested and clearly labels itself. No paid model call was made for this build. A model can request the action and return its review link; the approval decision remains in the application. `EXACT_APPROVAL_MCP_URL` changes the endpoint for either client.

## Packages and adoption

| Package | Purpose | Framework dependency |
| --- | --- | --- |
| `packages/core` | Frozen proposals, policies, current authority, transaction coordination, guarded execution, receipts and evidence verification. | PHP and small supporting libraries; no Symfony kernel or Drupal. |
| `packages/webauthn` | Existing WebAuthn library integration, trusted credential repository, assertion verification and enrollment methods. | Symfony components used by WebAuthn; no application kernel. |
| `packages/mcp` | The MCP App approval card, the optional URL-mode elicitation and its bound retry. | The official MCP SDK; no framework. |
| `packages/symfony` | Bundle configuration, action autoconfiguration, trusted review routes, CSRF, response headers, rate limits, the card app, the phone code and the MCP status tool. | Existing Symfony application and Symfony MCP Bundle. |
| `packages/drupal/exact_approval` | Native permission policies, guarded node storage, saved-revision publish/delete actions, passkey UI, durable evidence and optional existing-endpoint MCP tools. | Drupal 11.4+, shared core and WebAuthn; no Symfony FrameworkBundle. |
| `examples/symfony` | The alerts site: account/token isolation, per-role policy form, protected publication service, MCP OAuth and runnable endpoint. | Reference application, not a required hosting service. |

These package names are local Composer packages, not published Packagist releases. The repository’s path repositories make `composer install` work immediately. Another application can add a Composer path repository pointing at `packages/*`, then require `exact-approval/symfony-bundle` and `exact-approval/webauthn` version `^0.1`.

An application adopting the component supplies four things:

1. A registered `ActionAdapter`: prepare the exact effect, declare its version and preconditions, apply it and verify final postconditions.
2. Current identity and permission adapters. Authenticated actors come from application security, never tool arguments. The stored initiating delegation is rechecked at completion.
3. Transactional persistence shared by application data, authority, credentials and approval records.
4. Its trusted credential enrollment and browser account flow.

Every supported entry point for the protected mutation must call a service that enforces `ExecutionGuard::assertExecuting()`. The example’s MCP and web publication routes use the same `AnnouncementPublisher`. A decorator cannot automatically protect arbitrary application writes or raw SQL.

See [Symfony integration](../packages/symfony/README.md), [WebAuthn integration](../packages/webauthn/README.md), [extension contracts](integration-contract.md), [security boundary](security-model.md) and [verification results](verification.md).

## Exactness and execution

The frozen proposal binds the action and implementation version, user, tenant and initiating delegation, target, effect, expected source state, dependencies declared by the adapter, exact resolved statement, policy, locale, ID and expiry. Canonical JSON bytes are persisted; later reads do not reconstruct old approvals from current content.

Canonicalization preserves empty objects versus arrays, null versus absence, and exact string bytes. Object member ordering is normalized. Decimals and integers beyond JSON’s exact interoperable integer range must be encoded as strings. Arbitrary PHP objects and floating point values are rejected.

The WebAuthn challenge commits to a fresh random nonce and the proposal digest. The upstream validator checks credential ownership, signature, configured RP and origin, and required user verification. The final transaction reloads authority and policy, checks source preconditions, applies the approved effect, checks postconditions, consumes the approval and writes the execution record. Nested mutable values cannot change the frozen expectation during callbacks.

The SQLite adapter obtains a write reservation before reading relevant state. All relevant application writers need to preserve the versioning/transaction contract. Competing completions return the existing result; competing proposals for the same source revision cannot both pass a version-changing action. A known failed transaction retires its ceremony. A retry requires a fresh ceremony. An ambiguous outcome is read back before another attempt can be considered.

No queue, additional daemon, new MCP protocol or proprietary destination-tool envelope is required. Protected actions are normal MCP tools; pending approval is a normal business result, not a spurious authentication error. Every pending result carries the review URL and a view for the MCP App approval card, which opens that page and shows only the recorded outcome. An application can also turn on a URL-mode elicitation (MCP 2026-07-28) for the clients it has tested; its retry is bound to the same caller, tool and arguments, and never approves anything. See [MCP presentation](../packages/mcp/README.md).

## Evidence

After completion, a signed-in owner can download `/approval/REQUEST_ID/evidence`. Signing happens after the action’s durable execution record is committed; signing failure does not rerun the action. The original proposal, assertion, nonce and execution report remain available for a later signing attempt.

An independent command verifies evidence using **separately trusted** application and credential files:

```sh
php bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json
```

The trust file formats are documented in [evidence verification](evidence.md). The verifier reports two separate claims: the credential approved this proposal, and the application signed this execution report. It does not independently witness the database effect or prove what a person understood. Never trust a key merely because it appears inside the evidence being checked.

## Tests

```sh
composer test
composer analyse
PHP_BIN=php python3 tests/http_smoke.py

# Optional browser acceptance tests; require Python Playwright and Chromium.
PHP_BIN=php python3 tests/browser_smoke.py   # the passkey flow through the real page scripts
PHP_BIN=php python3 tests/card_smoke.py      # the card in a scripted MCP Apps host; its QR code decoded
PHP_BIN=php python3 tests/phone_smoke.py     # a never-signed-in phone browser behind a stand-in HTTPS tunnel
```

The PHP suite includes process-level concurrency, real WebAuthn signature and registration validation, real Symfony Security/CSRF/MCP handling, transaction rollback and a separate evidence-verification process. The HTTP smoke test starts the actual application and uses the actual SDK over TCP. Test authenticators and a scripted model are explicitly confined to tests; there is no production “approve anyway” verifier.

Browser, real-device and hosted-client coverage are reported separately in [verification results](verification.md). Claude Code’s native prompt, a Claude Desktop chat with physical Touch ID, and claude.ai through the public tunnel with a physical iPhone approving the phone code have all been run end to end.

## What comes next

The demo’s OAuth server and personal tokens are reference implementations: a production deployment connects the application’s real identity provider and keeps the origin, and so the passkeys, stable.

For production adoption, replace the demo identity/persistence setup with the host application’s services, review every supported write path, choose a shared limiter/lock backend when deploying across hosts, and review enrollment/recovery/key management. External effects such as email, remote MCP calls and payments do not gain a local transaction’s exactly-once properties.

## An early Drupal port

The module in [packages/drupal/exact_approval](../packages/drupal/exact_approval/README.md) adds administrator rules for content types and native permissions, personal passkey enrollment, exact publication/deletion review, storage-boundary enforcement, and a persistent signed evidence log. An optional submodule adds three tools to the existing Drupal MCP endpoint. It does not install a second Symfony kernel or a separate approval service.

Start with the [Drupal example](../examples/drupal/README.md):

```sh
cd examples/drupal
composer install
php setup.php
cd web
php -S localhost:8090 .ht.router.php
```

Use the generated local demo credentials in `examples/drupal/var/demo.json`. Administrator controls are at `/admin/config/people/exact-approval`; personal keys are at `/user/exact-approval/keys`. See [Drupal verification](drupal-verification.md) for the installed-site, HTTP, concurrency and evidence checks.

The Drupal port shares the core, the WebAuthn bridge and the MCP card and elicitation. Its rules follow Drupal’s own content types and role permissions. It has not had the Symfony side’s latest work: the final-page preview, MCP OAuth and the phone code.

MIT license. See [LICENSE](../LICENSE).
