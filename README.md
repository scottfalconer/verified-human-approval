# Exact Approval for Symfony, Drupal and MCP

**Let an agent request an application action. Let the person approve its exact effect. Execute that stored proposal once, under the person’s current permissions.**

This repository contains a shared PHP approval library, a Symfony bundle, and a working Drupal module. Both integrations use the same immutable proposal, WebAuthn verification and execution core. The Drupal module consumes that core and the WebAuthn bridge without loading Symfony FrameworkBundle.

The supported execution boundary is a **local database transaction**. The business mutation, approval consumption, passkey counter update and execution record share that transaction. Both reference applications are verified on SQLite. The Drupal adapter uses Drupal's own connection and transaction manager; other database backends still need deployment-specific verification.

## Drupal publish and delete approval

The module in [packages/drupal/exact_approval](packages/drupal/exact_approval/README.md) adds administrator rules for content types and native permissions, personal passkey enrollment, exact publication/deletion review, storage-boundary enforcement, and a persistent signed evidence log. An optional submodule adds three tools to the existing Drupal MCP endpoint. It does not install a second Symfony kernel or a separate approval service.

Start with the [Drupal example](examples/drupal/README.md):

```sh
cd examples/drupal
composer install
php setup.php
cd web
php -S localhost:8090 .ht.router.php
```

Use the generated local demo credentials in `examples/drupal/var/demo.json`. Administrator controls are at `/admin/config/people/exact-approval`; personal keys are at `/user/exact-approval/keys`. See [Drupal verification](docs/drupal-verification.md) for the installed-site, HTTP, concurrency and evidence checks.

## Try the application

Requirements: PHP 8.3+, Composer, and `pdo_sqlite`, `openssl`, `sodium`, `mbstring`, `dom` and the normal PHP JSON/XML extensions. PHP 8.4.24 is the runtime verified in this build. The lockfile pins the tested dependency set, including Symfony 7.4, the official MCP SDK 0.9.0, Symfony MCP Bundle 1.0.0-BETA1 and WebAuthn Library 5.3.12.

```sh
composer install
php examples/symfony/bin/setup.php
php -S localhost:8080 -t examples/symfony/public examples/symfony/public/index.php
```

Setup generates three fictional accounts and separate personal agent tokens. It prints passwords and tokens once; save them privately. The application stores password/token hashes. Setup refuses to overwrite an existing installation. Its database, signing key, sessions and cache stay under ignored `var/demo/`.

Open `http://localhost:8080` and sign in as `editor` using the generated password. Use **Passkeys** to enroll through your browser and operating system. On a suitable Mac this can invoke Touch ID; other devices may use their own passkey provider or PIN. This build has tested cryptographic assertions, not a physical Touch ID or Face ID ceremony.

The human account password belongs in the browser account flow. Give the agent only its individual personal token. The MCP firewall does not accept the browser session; the browser approval, enrollment and policy routes do not authenticate an agent bearer token.

Use `http://localhost`, not an arbitrary HTTP hostname or `127.0.0.1`, for this reference WebAuthn configuration. For a different deployment, set `EXACT_APPROVAL_ORIGIN` to a stable HTTPS origin **before** setup; use `EXACT_APPROVAL_RUNTIME` to select another private runtime directory. Changing the relying-party domain can require fresh passkey enrollment.

## Request an action through real MCP

In another terminal, provide the `editor` account’s personal token without putting it into source files:

```sh
read -r -s -p 'Personal agent token: ' EXACT_APPROVAL_TOKEN
export EXACT_APPROVAL_TOKEN
php examples/clients/mcp.php list
php examples/clients/mcp.php read summer-campaign
php examples/clients/mcp.php publish summer-campaign my-first-request
```

This client uses the official SDK and HTTP transport. The server is the Symfony MCP Bundle at `/mcp`. It exposes:

| Tool | Behavior |
| --- | --- |
| `read_announcement` | Read the current account’s announcement and publication state. |
| `publish_announcement` | Prepare the exact publication, preserving source revision, content, target and policy. Return a review URL while approval is required. |
| `approval_status` | Read an owned request’s authoritative outcome using a connection with explicit result-read scope. |

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

Open that URL, review the actual title, body, destination, revision and required statement, then approve with a passkey. The application’s completion endpoint executes the **stored proposal**. The agent never receives a reusable approval token and never resubmits replacement content.

```sh
php examples/clients/mcp.php status YOUR_REQUEST_ID
```

Reusing `my-first-request` with identical arguments returns the original request or result. Changing arguments under that key conflicts. A new intended action needs a new key. Without a supplied key, request retries can create separate pending proposals; protected execution is still at most once per proposal.

### Show a refusal

Create another publication request. Open its review page, then edit the draft in a second application tab before approving. The pending request becomes invalid when approval is attempted, and publication is blocked. A new review is needed even when the source revision changes but visible content is identical.

Also try the `reader` account’s token. Its user and delegation lack publication permission. A passkey never supplies missing rights.

### Administrator controls

The `editor` demo account is also a human administrator. At `/admin/approval-policy`, change the exact required statement, preview its registered placeholders, and save a versioned policy. Every changed policy invalidates pending requests from the old policy. Unknown placeholders are rejected; templates are plain text and do not execute Twig or expressions.

The **Require approval** switch has an explicit meaning: when an administrator disables it, new authorized requests can execute without passkey approval. Their execution records say `not_required`; they are not represented as signed human approvals. Old protected proposals cannot use the change to bypass their original approval flow. Agent credentials cannot change this setting.

## Symfony AI example

The example uses Symfony AI’s actual `Agent`, `McpToolbox` and the official MCP client:

```sh
# Deterministic integration check: scripted model responses, real HTTP MCP calls.
php examples/clients/agent.php --scripted

# Optional real model; set your own API key and model explicitly.
export OPENAI_API_KEY='YOUR_KEY'
export EXACT_APPROVAL_MODEL='YOUR_SUPPORTED_MODEL'
php examples/clients/agent.php 'Please publish the summer-campaign announcement.'
```

The scripted mode is tested and clearly labels itself. No paid model call was made for this build. A model can request the action and return its review link; the approval decision remains in the application. `EXACT_APPROVAL_MCP_URL` changes the endpoint for either client.

## Packages and adoption

| Package | Purpose | Framework dependency |
| --- | --- | --- |
| `packages/core` | Frozen proposals, policies, current authority, transaction coordination, guarded execution, receipts and evidence verification. | PHP and small supporting libraries; no Symfony kernel or Drupal. |
| `packages/webauthn` | Existing WebAuthn library integration, trusted credential repository, assertion verification and enrollment methods. | Symfony components used by WebAuthn; no application kernel. |
| `packages/symfony` | Bundle configuration, action autoconfiguration, trusted review routes, CSRF, response headers, rate limits and MCP status tool. | Existing Symfony application and Symfony MCP Bundle. |
| `packages/drupal/exact_approval` | Native permission policies, guarded node storage, saved-revision publish/delete actions, passkey UI, durable evidence and optional existing-endpoint MCP tools. | Drupal 11.4+, shared core and WebAuthn; no Symfony FrameworkBundle. |
| `examples/symfony` | Account/token isolation, application tables, policy form, protected publication service and runnable endpoint. | Reference application, not a required hosting service. |

These package names are local Composer packages, not published Packagist releases. The repository’s path repositories make `composer install` work immediately. Another application can add a Composer path repository pointing at `packages/*`, then require `exact-approval/symfony-bundle` and `exact-approval/webauthn` version `^0.1`.

An application adopting the component supplies four things:

1. A registered `ActionAdapter`: prepare the exact effect, declare its version and preconditions, apply it and verify final postconditions.
2. Current identity and permission adapters. Authenticated actors come from application security, never tool arguments. The stored initiating delegation is rechecked at completion.
3. Transactional persistence shared by application data, authority, credentials and approval records.
4. Its trusted credential enrollment and browser account flow.

Every supported entry point for the protected mutation must call a service that enforces `ExecutionGuard::assertExecuting()`. The example’s MCP and web publication routes use the same `AnnouncementPublisher`. A decorator cannot automatically protect arbitrary application writes or raw SQL.

See [Symfony integration](packages/symfony/README.md), [WebAuthn integration](packages/webauthn/README.md), [extension contracts](docs/integration-contract.md), [security boundary](docs/security-model.md) and [verification results](docs/verification.md).

## Exactness and execution

The frozen proposal binds the action and implementation version, user, tenant and initiating delegation, target, effect, expected source state, dependencies declared by the adapter, exact resolved statement, policy, locale, ID and expiry. Canonical JSON bytes are persisted; later reads do not reconstruct old approvals from current content.

Canonicalization preserves empty objects versus arrays, null versus absence, and exact string bytes. Object member ordering is normalized. Decimals and integers beyond JSON’s exact interoperable integer range must be encoded as strings. Arbitrary PHP objects and floating point values are rejected.

The WebAuthn challenge commits to a fresh random nonce and the proposal digest. The upstream validator checks credential ownership, signature, configured RP and origin, and required user verification. The final transaction reloads authority and policy, checks source preconditions, applies the approved effect, checks postconditions, consumes the approval and writes the execution record. Nested mutable values cannot change the frozen expectation during callbacks.

The SQLite adapter obtains a write reservation before reading relevant state. All relevant application writers need to preserve the versioning/transaction contract. Competing completions return the existing result; competing proposals for the same source revision cannot both pass a version-changing action. A known failed transaction retires its ceremony. A retry requires a fresh ceremony. An ambiguous outcome is read back before another attempt can be considered.

No queue, additional daemon, new MCP protocol or proprietary destination-tool envelope is required. Protected actions are normal MCP tools; pending approval is a normal business result, not a spurious authentication error. This initial version uses the portable review URL plus status flow rather than negotiated URL elicitation.

## Evidence

After completion, a signed-in owner can download `/approval/REQUEST_ID/evidence`. Signing happens after the action’s durable execution record is committed; signing failure does not rerun the action. The original proposal, assertion, nonce and execution report remain available for a later signing attempt.

An independent command verifies evidence using **separately trusted** application and credential files:

```sh
php bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json
```

The trust file formats are documented in [evidence verification](docs/evidence.md). The verifier reports two separate claims: the credential approved this proposal, and the application signed this execution report. It does not independently witness the database effect or prove what a person understood. Never trust a key merely because it appears inside the evidence being checked.

## Tests

```sh
composer test
composer analyse
PHP_BIN=php python3 tests/http_smoke.py

# Optional browser acceptance test; requires Python Playwright and Chromium.
PHP_BIN=php python3 tests/browser_smoke.py
```

The PHP suite includes process-level concurrency, real WebAuthn signature and registration validation, real Symfony Security/CSRF/MCP handling, transaction rollback and a separate evidence-verification process. The HTTP smoke test starts the actual application and uses the actual SDK over TCP. Test authenticators and a scripted model are explicitly confined to tests; there is no production “approve anyway” verifier.

Browser, real-device and hosted-client coverage are reported separately in [verification results](docs/verification.md). This release does not claim live Claude, Cowork, ChatGPT.com, Touch ID or Face ID verification.

## What comes next

The Drupal adapter is implemented and verified on a real Drupal 11.4.12 site. The next integration work is physical-device acceptance and hosted agent-client authentication: choose a stable origin, connect the application's real identity provider, reuse or enroll a real approval passkey, and record a full personal-user flow. The Symfony example's personal development token and the Drupal test fixture's personal Basic authentication are development connections, not completed OAuth authorization servers.

For production adoption, replace the demo identity/persistence setup with the host application’s services, review every supported write path, choose a shared limiter/lock backend when deploying across hosts, and review enrollment/recovery/key management. External effects such as email, remote MCP calls and payments do not gain a local transaction’s exactly-once properties.

MIT license. See [LICENSE](LICENSE).
