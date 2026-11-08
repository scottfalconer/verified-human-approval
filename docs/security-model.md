# Security and execution boundary

## Guarantee

For a registered local action, one proposal can commit at most one protected business effect and one execution record, provided they share the same supported transaction. A configured required approval is an additional mandatory condition alongside current application authority.

The first persistence implementation is SQLite. `PdoApprovalStore` uses `BEGIN IMMEDIATE` and owns the outer transaction. Business state, current authorization rows, credentials, proposals and execution records must share the same PDO connection. Other adapters must preserve this contract and run the same contention and rollback tests.

## Trusted components

The application, its action adapter, ordinary authorization provider, code deployment, review UI and its evidence signing key are trusted. The agent is an ordinary application client with a personal delegated credential. It cannot choose the authenticated actor in tool arguments, approve anything, add a passkey or change a rule.

The database is not fully trusted after the fact. Records that matter are signed with the application's evidence key, which is kept outside the database (`settings.json` in the demo), so database access alone cannot forge them:
- each execution's receipt, signed inside the completing transaction;
- each passkey enrollment, recorded with the enrolled public key in a signed, hash-chained audit log.

Only a passkey whose signed enrollment record matches it can approve (`AnchoredCredentialRepository`). A credential row inserted or altered directly in the database approves nothing.

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

With a receipt signer configured (the bundle's `receipt_signer`), `ApprovalService` signs the receipt inside the completing transaction. If signing fails, nothing executes: there is no unrecorded action. Without a signer, the exporter signs at download time and marks the receipt `at_export`, which cannot show edits made in between. Exporting never re-executes anything.

## Web and operational controls

The Symfony flow requires an interactive session, ownership checks, CSRF, request-to-ceremony session pairing, bounded request bodies, rate limits, output escaping, restrictive content security policy, frame blocking and no-store responses. The demo separates stateless MCP bearer authentication from human sessions, credential enrollment and policy administration.

A password is not enough to add an approval passkey, because whoever holds it (including an agent the person handed it to) could otherwise enroll a passkey of their own and approve without the person:
- **The first passkey** needs a one-time link, from setup or from an administrator who has just confirmed with their own passkey. The link works once, only for its own account, and only in that account's signed-in session.
- **Any further passkey** needs a fresh confirmation with an existing passkey.

Revoking a passkey or disconnecting an agent takes effect at once, and both are recorded. Production hosts should map this onto their own identity, enrollment and recovery processes.

**Changing who must verify is itself an approved action.** The administrator proposes the rule change, then approves that exact change with their own passkey. Its policy is fixed in code, so no rule change can switch off approval of rule changes. An agent cannot propose one.

**The phone code.** It opens one review, for one person, for two minutes, once, and its redemption is locked. Opening the link only shows a Continue button, so link previewers cannot use the code up. The scoped session can approve with the passkey but cannot cancel, which needs the signed-in person. The tool that mints codes checks the connection's read scope and has a budget. Its "app only" visibility is a convention hosts follow, not a server boundary.

**OAuth client metadata** (client ID metadata documents) is fetched through Symfony's `NoPrivateNetworkHttpClient`, so a client ID cannot make the server fetch from loopback, private or link-local addresses. The resolved address is pinned against DNS rebinding.

Limiter/lock storage must be shared appropriately in a multi-host deployment. Runtime secrets and database files must not be exposed by the web document root. Do not log assertions, tokens or full approval content in normal request logs.

## Tamper evidence and the administrator's view

The demo's audit page (`/admin/audit`, administrators only) lists, for every action in the organization:
- who asked, through which agent connection;
- under which rule version;
- whether a person verified it, with which passkey, and when.

It re-checks each completed action on every visit. The receipt is checked against the signature made at commit. The person's passkey signature is checked against the public key in that passkey's signed enrollment record, using a challenge recomputed from the stored proposal. Editing what was approved, or when it happened, makes a check fail visibly.

The same page lists passkeys, one-time links and agent connections, with revocation. It also shows the security log, verified as a chain: enrollments, revocations, links issued and alert take-downs.

What this cannot show:
- **Cut-off entries.** Truncating the newest log entries is not detectable without an external copy of the newest sequence number, so the page shows it.
- **A full server compromise.** Whoever holds both the database and the signing key can forge anything.
- **Which authenticator.** Without attestation, the authenticator type is what the authenticator reports.

## Explicit limits

- This is a tested initial implementation, not a third-party security audit or a claim of being bulletproof.
- One local transaction does not cover remote MCP/HTTP calls, emails, payments or nontransactional hooks.
- The Drupal adapter is implemented using Drupal's connection and transaction manager, and is verified with SQLite. PostgreSQL/MySQL deployments are not verified in this build. See `drupal-verification.md` and the module README for its storage and authentication boundaries.
- The demo's OAuth server and personal tokens are reference implementations. Production deployments connect their own identity provider.
- Without attestation (the demo uses `none`, which iCloud Keychain and Google Password Manager require), the application cannot prove a passkey lives in hardware. A person who deliberately enrolls an automatable software authenticator can approve without being present. The audit view shows each passkey's reported authenticator and how it was enrolled. Deployments that must prevent this can require attestation, at the cost of synced passkeys.
- User verification does not attest an exact biometric modality.
- Independent evidence establishes credential approval and an application-signed report, not independent observation of the database or UI.
- Recorded end-to-end runs so far: Claude Code's native prompt, a Claude Desktop chat with Touch ID, and claude.ai through a public tunnel with an iPhone approving the phone code. See `verification.md`.
