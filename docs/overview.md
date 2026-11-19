# Architecture and request lifecycle

Verified Human Approval connects an agent's request to a guarded application action. The application decides whether the action requires a person's passkey approval. When it does, the person approves a frozen proposal on the application's own site.

For a runnable walkthrough, start with the [demo](../demo/README.md). For a new application, use the [Symfony integration guide](../src/symfony-bundle/docs/index.md).

## Responsibilities

| Component | Responsibility |
| --- | --- |
| Host application | Accounts, current permissions, the approval rule for each action, trusted enrollment, business data and every protected write path. |
| Core (`verified-human-approval/core`) | Freeze proposals, manage requests and ceremonies, check current authority, coordinate the transaction and record signed receipts. Its PDO stores keep approvals and the signed audit log on SQLite. |
| WebAuthn bridge (`verified-human-approval/webauthn`) | Validate passkey assertions against the configured origin, relying party and trusted credential owner, keep passkeys, and verify receipts offline. |
| MCP presentation (`verified-human-approval/mcp`) | Return a review link, show an approval card where supported, and handle optional URL prompts and their retries. |
| Symfony bundle (`verified-human-approval/symfony-bundle`) | Wire the services, serve review routes, check CSRF, limit approval attempts and agent requests, and provide status and phone-handoff tools. |
| Doctrine storage (`verified-human-approval/doctrine`) | Keep approvals, the signed audit log and passkeys on the application's DBAL connection, in one transaction with its entities, and refuse unapproved changes to protected entities. |
| Storage contracts (`verified-human-approval/store-contracts`) | The tests a storage adapter must pass, with a fixture application and process races, to run against an adapter of your own. |
| Testing helpers (`verified-human-approval/testing`) | A software passkey, a client for the review page's passkey protocol and a PSR-18 client into the application's kernel, for the host's own integration tests. |

The demo adds account administration, an OAuth integration built on maintained packages, the alerts site and an audit page. Those are reference application code, not services that the bundle installs into another application.

## 1. Request the action

A protected MCP tool derives an `Actor` from the authenticated principal. Its user, tenant and delegation identify the person and the connection acting for them. Tool arguments cannot supply that identity.

The host checks the connection's current result-read permission before it calls `ApprovalFlow::respond()`, including on retries. On a call that may open a proposal, `respond()` first spends one from the person's budget for agent requests; once that is spent, it refuses the call as `rate_limited`. The core separately checks permission to perform the action.

`ApprovalService::request()` applies the action's current rule, an `ApprovalPolicy` from the host's `PolicyProvider`:

- A denied caller receives a refusal.
- An authorized caller whose rule requires approval receives a pending proposal.
- An authorized caller whose rule allows direct execution runs through the guarded action and receives a record marked `not_required`.

The application can use the same service from its own website. Its rule must apply at every supported entry point.

## 2. Freeze the proposal

An `ActionAdapter` prepares the exact effect and the state it depends on. The proposal binds the action and adapter version, actor, target, effect, source preconditions, declared dependencies, resolved statement, the rule and its version, locale, request ID and expiry.

The core stores canonical JSON and its SHA-256 digest. It preserves exact string bytes, empty objects versus arrays, and null versus an absent value. Object key order is normalized. Floating point values and arbitrary PHP objects are rejected; represent decimals and large integers as strings. A protected MCP tool's own arguments may hold decimals, which `ApprovalFlow` accepts; pass them to the action's input as strings.

A later read uses the stored proposal. It does not rebuild the approved content from the current draft. The adapter must include every dependency that could change the meaning or result of the action, such as the version of its renderer.

## 3. Review and approve

Every client receives a review URL. An MCP Apps host can show the card; configured clients can also receive a URL-mode elicitation. Neither a chat message nor a client's **Accept** response counts as approval.

The person reviews the stored proposal on the application's configured origin. A top-level WebAuthn ceremony signs a challenge bound to a fresh nonce and the proposal digest. The verifier checks the credential's trusted account binding, signature, origin, relying party, user presence and required user verification.

The authenticator can verify its user with a biometric, PIN or another local method. The application does not receive biometric data or proof of which local method was used. WebAuthn remains the single approval mechanism in this implementation.

On HTTPS, the optional phone handoff opens only one review in a scoped browser session. Its code works once, for two minutes, and the session it opens lasts ten minutes, both by the application's clock. It does not sign the person into the rest of the application, and its approval attempts have a budget of their own, apart from the signed-in person's. Approval still requires a passkey belonging to the proposal's user and tenant. The bundle's `phone_handoff: false` turns it off.

## 4. Execute in one transaction

The supplied `PdoApprovalStore` supports SQLite; the Doctrine adapter supports SQLite, PostgreSQL, MySQL and MariaDB. Both own the outer transaction. The action's data, current account and grant records, credentials and approval records must use **the same application connection**. See the [Doctrine guide](../src/doctrine/README.md) for supported versions and ORM integration.

During completion, the core reloads the stored proposal, checks current authority and the current rule, verifies the assertion, checks preconditions, executes the stored effect and checks postconditions. It consumes the proof, updates the credential counter and records the execution and signed receipt in that transaction.

The mutation service calls `ExecutionGuard::assertExecuting()`. Every supported write path must use that service. The guard does not intercept unrelated database writes.

With a receipt signer configured, a signing failure rolls back the change. An execution or postcondition failure also rolls back the business change and credential update. The original expectation stays immutable during adapter callbacks.

This boundary covers local database changes. A remote API call, email or payment cannot be rolled back by the local database. Other storage adapters must pass the published transaction contracts; external effects need their own delivery design.

## 5. Read the outcome and handle retries

A request key names one intended action. Reusing it with the same arguments and unchanged target and effect returns the existing request or result. When that request finished before the call, the MCP result says so: it is the earlier request's recorded outcome, nothing ran for this call, and the card shows it as an earlier request, with "Completed earlier, on …" for one that completed. Changed arguments or content produce an `idempotency_conflict`. Use the original request ID to read its recorded outcome. A new intended action needs a new key.

A key must be unique to one intended action, so let the client that generates it be a program. Asked to supply a key, Claude models built it from the arguments and the date, and often built the same key in a later conversation, where it returns the earlier outcome. Describe the argument so that an agent leaves it out: `ApprovalFlow::REQUEST_KEY_DESCRIPTION` in the MCP package does.

Without a key, a repeated call from the same connection reuses an equivalent pending proposal. It can create a new proposal after the earlier one reaches a terminal state. The core prevents a proposal from committing more than once.

The optional URL prompt carries signed continuation state bound to the caller, tool and arguments. Its retry reads the stored result; it does not prepare or execute a replacement action. The host must still check current read permission before returning that result.

If a connection fails after submission, the outcome may be unknown to the browser or client. Read the existing request's status before starting another action. Stopping a client call or declining a prompt does not cancel an application request. A successful cancellation on the application's review page records `cancelled`.

## 6. Verify the historical record

The passkey assertion binds the credential to the frozen proposal. The application's receipt binds that proposal, the proof and the execution report. With commit signing configured, the receipt is saved in the same transaction as the change. Export returns the original receipt and refuses if it is missing; it does not recreate it from editable records.

With the Symfony bundle, the person can download their own copy from the completed review page, and the agent's result carries the signed receipt. The application publishes its public keys at `/approval/receipt-keys.json`: the key that signs now and any it replaced, each with the window it signed in. A person who keeps their copy can later show that the stored record changed, but only for their own requests.

The offline verifier needs the application's public keys, trusted separately from the record, and optionally the credential's trusted record to check the passkey signature too. It reports the two signature checks separately and states when the application report was signed. It verifies the historical evidence, not the current live content or an independently observed database effect.

[Verify a receipt](evidence.md) gives a complete example, including a changed copy that fails verification. See the [security model](security-model.md) for trust assumptions and [integration contracts](integration-contract.md) for the interfaces.
