# Security and execution boundary

## Guarantee

For a registered local action, one proposal can commit at most one protected business effect and one execution record, provided they share the same supported transaction. A configured required approval is an additional mandatory condition alongside current application authority.

The first persistence implementation is SQLite. `PdoApprovalStore` uses `BEGIN IMMEDIATE` and owns the outer transaction. Business state, current authorization rows, credentials, proposals and execution records must share the same PDO connection. Other adapters must preserve this contract and run the same contention and rollback tests.

## Trusted components

The application, its action adapter, ordinary authorization provider, database, code deployment, review UI, credential-to-account association, credential enrollment and recovery are trusted. The agent is an ordinary application client with a personal delegated credential. It cannot choose the authenticated actor in tool arguments or change the approval policy through its agent scope.

The component does not sandbox an actor who can rewrite application code, replace the review UI, modify the database directly or control its trusted enrollment process. Run an actual demonstration outside a coding agent’s writable application/code/database environment if the claim involves independence from that coding agent.

## Guarding the mutation

`ExecutionGuard` holds a private execution capability established by the approval service. A mutation service checks the exact actor and prepared effect before writing. Every supported route to that action must use the guarded mutation service. A generic entity update, CLI path or direct database write added by a host can undermine the integration if it bypasses that boundary.

No approval voter is added to an affirmative authorization voting strategy. Ordinary authorization and the approval guard are separate mandatory checks.

## Authority and historical reads

The initiating user and approving browser user must match account and tenant. Browser approval does not replace the initiating delegation with the browser’s broader privileges. `Authorizer` reloads current user status, permissions and delegation scope inside the execution transaction.

Core history reads are owner-based. A host must also apply its explicit result-read authorization to delegated clients. The Symfony `ActorProvider::assertCanReadApproval()` seam and example `approval.read` scope provide this. A user may need to read an outcome after losing permission to perform the original action; write authority is not the right history-read check.

## Proof and exactness

The challenge is SHA-256 over a versioned domain separator, the proposal digest and a fresh 32-byte nonce. The nonce is included in exported evidence, allowing an independent verifier to recompute the binding.

WebAuthn validation comes from `web-auth/webauthn-lib`. It enforces signature, challenge, exact configured origin, RP, credential/account association and required user verification. A native verification can be a biometric, PIN or passcode. Required UV does not prove fingerprint-only authentication or the person’s understanding of the statement.

The browser page supplies the business review. The operating system passkey sheet authenticates for the application; it is not an independent trusted display of arbitrary business content.

Canonical proposals are not reconstructed from live values. The implementation rejects nested object mutation around adapter callbacks and uses a fresh immutable baseline for final postconditions. Adapters must still declare every meaningful dependency and check actual final application effects. A mutable asset URL is insufficient if asset bytes affect the action.

## Failure and concurrency

All version checks and writes participate in the transaction. A write that has already committed cannot be undone by a subsequent authority change; transactions define that ordering.

Failed execution and postconditions roll back protected state, credential updates, consumed approval and result together. The service then retires the failed ceremony. If commit outcome is uncertain, it checks persisted state and returns an existing completion where available; otherwise it reports uncertainty or known failure. It never claims automatic rollback of remote effects.

Signing is a separate export step over the durable execution report. A signer outage is not an instruction to reexecute the action.

## Web and operational controls

The Symfony flow requires an interactive session, ownership checks, CSRF, request-to-ceremony session pairing, bounded request bodies, rate limits, output escaping, restrictive content security policy, frame blocking and no-store responses. The demo separates stateless MCP bearer authentication from human sessions, credential enrollment and policy administration.

The bundled credential enrollment is a minimal local demonstration. Production hosts should supply their established enrollment/recovery policy and decide how existing credentials, recent authentication and account recovery affect the ability to add approval keys. An MCP token is not sufficient to enroll one.

Limiter/lock storage must be shared appropriately in a multi-host deployment. Runtime secrets and database files must not be exposed by the web document root. Do not log assertions, tokens or full approval content in normal request logs.

## Explicit limits

- This is a tested initial implementation, not a third-party security audit or a claim of being bulletproof.
- One local transaction does not cover remote MCP/HTTP calls, emails, payments or nontransactional hooks.
- The Drupal adapter is implemented using Drupal's connection and transaction manager, and is verified with SQLite. PostgreSQL/MySQL deployments are not verified in this build. See `drupal-verification.md` and the module README for its storage and authentication boundaries.
- Demo personal tokens are not OAuth federation or a complete OAuth server.
- User verification does not attest an exact biometric modality.
- Independent evidence establishes credential approval and an application-signed report, not independent observation of the database or UI.
- Physical device, external browser UI and hosted-agent compatibility still need recorded end-to-end runs.
