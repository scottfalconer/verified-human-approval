# Architecture and request lifecycle

Verified Human Approval connects an agent's request to a guarded application action. The application decides whether the action requires a person's passkey approval. When it does, the person approves a frozen proposal on the application's own site.

For a runnable walkthrough, start with the [demo](../demo/README.md). For a new application, use the [Symfony integration guide](../src/symfony-bundle/docs/index.md).

## Responsibilities

| Component | Responsibility |
| --- | --- |
| Host application | Accounts, current permissions, policy, trusted enrollment, business data and every protected write path. |
| Core | Freeze proposals, manage requests and ceremonies, check current authority, coordinate the transaction and record signed receipts. |
| WebAuthn bridge | Validate passkey assertions against the configured origin, relying party and trusted credential owner. |
| MCP presentation | Return a review link, show an approval card where supported, and handle optional URL prompts and their retries. |
| Symfony bundle | Wire the services, serve review routes, check CSRF, limit attempts and provide status and phone-handoff tools. |

The demo adds its own account administration, OAuth server, alerts site and audit page. Those are reference implementations, not services that the bundle installs into another application.

## 1. Request the action

A protected MCP tool derives an `Actor` from the authenticated principal. Its user, tenant and delegation identify the person and the connection acting for them. Tool arguments cannot supply that identity.

The host checks the connection's current result-read permission before it calls `ApprovalFlow::respond()`, including on retries. The core separately checks permission to perform the action.

`ApprovalService::request()` applies the current policy:

- A denied caller receives a refusal.
- An authorized caller whose policy requires approval receives a pending proposal.
- An authorized caller whose policy allows direct execution runs through the guarded action and receives a record marked `not_required`.

The application can use the same service from its own website. Its policy must apply at every supported entry point.

## 2. Freeze the proposal

An `ActionAdapter` prepares the exact effect and the state it depends on. The proposal binds the action and adapter version, actor, target, effect, source preconditions, declared dependencies, resolved statement, policy version, locale, request ID and expiry.

The core stores canonical JSON and its SHA-256 digest. It preserves exact string bytes, empty objects versus arrays, and null versus an absent value. Object key order is normalized. Floating point values and arbitrary PHP objects are rejected; represent decimals and large integers as strings.

A later read uses the stored proposal. It does not rebuild the approved content from the current draft. The adapter must include every dependency that could change the meaning or result of the action, such as the version of its renderer.

## 3. Review and approve

Every client receives a review URL. An MCP Apps host can show the card; configured clients can also receive a URL-mode elicitation. Neither a chat message nor a client's **Accept** response counts as approval.

The person reviews the stored proposal on the application's configured origin. A top-level WebAuthn ceremony signs a challenge bound to a fresh nonce and the proposal digest. The verifier checks the credential's trusted account binding, signature, origin, relying party, user presence and required user verification.

The authenticator can verify its user with a biometric, PIN or another local method. The application does not receive biometric data or proof of which local method was used. WebAuthn remains the single approval mechanism in this implementation.

On HTTPS, the optional phone handoff opens only one review in a scoped browser session. It does not sign the person into the rest of the application. Approval still requires a passkey belonging to the proposal's user and tenant.

## 4. Execute in one transaction

The supplied `PdoApprovalStore` supports SQLite and owns the outer transaction. The action's data, current account and grant records, credentials and approval records must use **the same PDO connection**.

During completion, the core reloads the stored proposal, checks current authority and policy, verifies the assertion, checks preconditions, executes the stored effect and checks postconditions. It consumes the proof, updates the credential counter and records the execution and signed receipt in that transaction.

The mutation service calls `ExecutionGuard::assertExecuting()`. Every supported write path must use that service. The guard does not intercept unrelated database writes.

With a receipt signer configured, a signing failure rolls back the change. An execution or postcondition failure also rolls back the business change and credential update. The original expectation stays immutable during adapter callbacks.

This boundary covers local database changes. A remote API call, email or payment cannot be rolled back by SQLite. Other databases, transaction managers and external effects need a separate implementation and review.

## 5. Read the outcome and handle retries

Use an explicit request key for an intended action. Reusing it with the same arguments and unchanged target and effect returns the existing request or result. Changed arguments or content produce an `idempotency_conflict`. Use the original request ID to read its recorded outcome. A new intended action needs a new key.

Without a key, a repeated call from the same connection reuses an equivalent pending proposal. It can create a new proposal after the earlier one reaches a terminal state. The core prevents a proposal from committing more than once.

The optional URL prompt carries signed continuation state bound to the caller, tool and arguments. Its retry reads the stored result; it does not prepare or execute a replacement action. The host must still check current read permission before returning that result.

If a connection fails after submission, the outcome may be unknown to the browser or client. Read the existing request's status before starting another action. Stopping a client call or declining a prompt does not cancel an application request. A successful cancellation on the application's review page records `cancelled`.

## 6. Verify the historical record

The passkey assertion binds the credential to the frozen proposal. The application's receipt binds that proposal, the proof and the execution report. With commit signing configured, the receipt is saved in the same transaction as the change. Export returns the original receipt and refuses if it is missing; it does not recreate it from editable records.

The offline verifier needs separately trusted public application and credential records. It reports the two signature checks separately and states when the application report was signed. It verifies the historical evidence, not the current live content or an independently observed database effect.

See [receipt verification](evidence.md) for a complete example, including a changed copy that fails verification. See the [security model](security-model.md) for trust assumptions and [integration contracts](integration-contract.md) for the interfaces.
