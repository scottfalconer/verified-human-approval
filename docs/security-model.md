# Security model

Verified Human Approval protects a registered application action. The application freezes the proposed effect, obtains any required passkey approval, checks current authority and commits the effect with its execution record in one transaction.

One proposal can commit the protected effect at most once **within that transaction boundary**. The host application must put every route to that effect through the guarded mutation service.

## What the host must trust

The application code, action adapter, authorization provider, review page, credential enrollment process and evidence signing key are trusted components. The agent is an ordinary client with delegated access. Tool arguments do not select the authenticated user, add a passkey, change an approval rule or provide approval.

`ExecutionGuard` gives the protected mutation service a capability to run only the exact prepared action for the exact actor. Approval and ordinary authorization are both required checks. A generic entity update, CLI command or another route that bypasses this service bypasses the protection.

This is an application boundary, not a sandbox for someone who can change application code or write directly to its database. If a demonstration is meant to show independence from a coding agent, the running application, database and secrets must be outside that agent's write access.

## Transaction boundary

The current persistence implementation is SQLite. `PdoApprovalStore` owns the outer transaction and uses `BEGIN IMMEDIATE`. Business state, current authorization data, credentials, proposals and execution records must use the **same PDO connection**.

The initiating user and approving browser user must be the same account in the same tenant. Completion rechecks the original delegation's current permissions; the browser's broader privileges do not replace them. The adapter checks current resource versions before writing and checks the resulting business state before commit.

Failures roll back the protected effect, credential updates, consumed approval and result together. The service retires a failed ceremony. If the commit outcome is uncertain, it checks persisted state before reporting success, failure or uncertainty. A caller should read the existing request's status rather than start a new action blindly.

This transaction does not include remote HTTP or MCP calls, email, payments or nontransactional hooks. Only SQLite has the verified transaction implementation. Another database adapter must preserve the contract and pass the contention and rollback checks.

## What the passkey proves

The WebAuthn challenge binds a fresh 32-byte nonce to the digest of the frozen proposal, with a versioned domain separator. The evidence includes the nonce so a verifier can recompute that binding.

`web-auth/webauthn-lib` validates the signature, challenge, configured origin, relying party, credential ownership and required user verification. Device verification can use a fingerprint, face, PIN or passcode through WebAuthn. No separate biometric mechanism is needed. Biometric data stays with the authenticator; the application receives the assertion.

The browser review page displays the business proposal. The operating system's passkey prompt verifies access to the credential; it does not independently display or attest all business content. Approval establishes that the enrolled credential approved this proposal. It does not establish who wrote the content or whether a person understood it.

The demo uses registration without attestation. It therefore does not establish that the credential lives in particular hardware or prevent a deliberately enrolled software authenticator. User verification also does not establish which biometric or device unlock method was used.

## What must be frozen

The proposal is stored as canonical JSON, not rebuilt from later live values. It includes the actor, target, effect, preconditions, review content, policy version and approval statement.

The core rejects mutation of the frozen data around adapter callbacks. The adapter must still declare every dependency that matters and verify the actual final effect. For example, if an image affects what is being approved, freeze its content or content digest; a URL whose bytes can change is not enough.

## Protection after storage

With a receipt signer configured, `ApprovalService` signs the proposal, passkey proof and execution report inside the completing transaction. If signing fails, that transaction rolls back. Keep the signing key outside the database. The [evidence guide](evidence.md) explains the two signatures and provides a verification walkthrough.

The demo also records passkey enrollment and revocation in a signed audit log. `AnchoredCredentialRepository` accepts a current credential only if its identity matches a valid signed enrollment and no signed revocation is found. Inserting a new credential row or replacing its public key in the database does not supply that enrollment proof. Hosts must wire this repository and record enrollment events to get this protection; a plain credential repository alone does not provide it. The wrapper accepts any `RevocableCredentialRepository`, and the log keeps its rows through an `AuditStore`, so a host can use its own storage. A revocation and its signed entry must commit in one transaction: the demo's owner and administrator routes both do, and a failure to sign or store the entry leaves the passkey active.

These signatures make later changes detectable when verification runs. They do not stop someone with database write access from changing or deleting bytes. The default exporter refuses a completed action whose original receipt is missing. It does not replace that receipt with a fresh signature.

The service does not wait for someone to run verification. Each time `ApprovalService` reads a stored request, to report its outcome, return it again for a request key, offer it for approval or change it, it first checks that the stored status and result agree with the execution record. With a receipt signer configured, a completed request must also carry the receipt signed as it committed, and that receipt must verify against the application's trusted keys. A contradiction, a missing receipt, a receipt that names a key the application does not trust, or a signature that fails raises `record_integrity_error`. Present that as an outcome the application cannot vouch for, never as a refusal and never as a sign that nothing happened: the bundle's review page, its status route and its MCP tools answer `integrity_error`. Only the request's owner sees it; anyone else still gets `not_found`. This read check verifies the application's receipt. It does not repeat the passkey signature check, which the audit page and the offline verifier make with a trusted credential record.

The read check has two limits. Without a receipt signer the service can only check that the stored parts agree, and someone who can edit all of them can keep them consistent. With one, it is strict about history: a request that completed before signing was turned on has no receipt, and one whose receipt was signed by a key missing from the trusted keys cannot be verified, so both read as integrity errors. Turn signing on before the first action runs, and list every retired key when you rotate.

The person's downloaded receipt, and the copy in the agent's result, sit outside the application's database. Checked against the application's published keys, they show a later change to, or deletion of, the stored record. They cover only that person's own requests, and they depend on the person keeping them.

The demo's administrator page at `/admin/audit` rechecks completed actions against their receipts and the passkeys' signed enrollment records. It reads the store directly, so a request that fails the read check stays in its list, marked as an integrity error instead of showing its stored outcome; a status edited to something other than completed does not take the stored execution out of the receipt and passkey checks. It also verifies the audit chain. This checks the original historical records, not whether today's live content still matches an earlier approval.

Recorded times remain application claims, and an old assertion does not independently prove its historical revocation status. Deleting the newest audit entries cannot be detected without an independently retained checkpoint. A compromised application signing key can forge application reports or new enrollment records; it cannot recreate a passkey assertion under a separately trusted original user public key. Obtaining and retaining trusted verification keys is therefore part of the host's responsibility.

## Signing keys and rotation

Receipts and audit entries are checked against the application's trusted keys: every public key it has signed with, each with the window it signed in. A record names its key. A key the verifier was not given verifies nothing, and a record whose own time falls outside its key's window fails: a receipt's `recordedAt`, which it signs, or an entry's `at`. One current key signs new records. Rotating it adds the new key, closes the old key's window, and appends a `signing_key.rotated` entry, signed with the new key, so the chain records where trust moved. The audit log then refuses any later entry the old key signs, whatever time it claims. The bundle publishes the keys at `/approval/receipt-keys.json`, and gives the same keys to the approval service for its read check; the [evidence guide](evidence.md#rotate-the-signing-key) gives the procedure.

Rotation limits future exposure. It cannot stop someone who stole a key from backdating records into that key's window, because the times a record carries are the application's own claims:

- **Receipts** are not in the chain, so a backdated receipt signed with a stolen key verifies. Only the key's window bounds it.
- **Audit entries** must also fit the chain. Once a rotation is recorded, the old key cannot add an entry at its end. A backdated entry has to replace one from before the rotation, which breaks the chain at the next entry; `AuditLog::verify()`, which the audit page runs, reports it. `AuditLog::find()`, which passkey anchoring uses, counts an entry signed with a key whose window has closed only if a later entry signed with a key in use links back to it through the chain, so an entry replaced or inserted after the new key first signed anchors nothing.
- **Cutting the chain back** to before the rotation and rewriting it from there is shown only by a checkpoint kept outside the application.

So keep independent checkpoints of the audit head: its newest sequence and hash, where the application cannot write, on a schedule and after every rotation. `verified-human-approval:audit-head` prints one and checks a kept one; `AuditLog::head()` returns it without the bundle. If a key may have been exposed, follow the [compromise procedure](evidence.md#if-the-signing-key-is-compromised).

## Browser and account controls

The Symfony review flow uses an interactive session, ownership checks, CSRF protection, session-to-ceremony pairing, bounded request bodies, rate limits, escaped output, a restrictive content security policy, frame blocking and no-store responses. Delegated history reads also need an explicit result-read permission through `ActorProvider::assertCanReadApproval()`. Losing write permission need not remove permission to read an earlier result.

A token's scopes are checked where they are used: a tool decides with the validated token, and the authorizer rechecks the stored grant when an approval completes. Where the MCP endpoint is an OAuth protected resource, the bundle's `McpScopeChallengeSubscriber` can also refuse a token that lacks one of a tool's scopes before any MCP code runs. It answers HTTP 403 with a challenge naming every scope the operation needs and the protected resource metadata, so a client can ask the person for more and retry. It reads the tool from the parsed request body, never from a header. It grants nothing and replaces no check: the tools and the authorizer still decide, and ownership and permission refusals remain tool results, because they are not requests for a broader token. The demo registers it; the [guide](../src/symfony-bundle/docs/index.md#scope-challenges-over-http) says when to.

The demo keeps bearer-authenticated MCP access separate from human sessions, enrollment and administration. Its account controls illustrate responsibilities a production host must implement:

- A first approval passkey requires a one-time enrollment link and the owner's signed-in session. Setup issues initial links; an administrator needs fresh passkey confirmation to issue another.
- Adding another passkey requires fresh confirmation with an existing passkey. A password alone cannot enroll an approval credential.
- Passkey and agent-connection revocations are recorded and checked for subsequent use.
- Changing who must approve is itself a passkey-approved action. Its approval rule is fixed in code, so an administrator cannot switch off approval of rule changes through that rule editor.

The phone handoff code opens one request for one person, once, within two minutes. A Continue step avoids accidental redemption by link previewers. Redemption is locked; the scoped session can approve but cannot cancel or download the receipt. The tool that creates codes checks read scope and a rate budget. Its app-only visibility is a host convention, not authorization.

The demo's OAuth metadata fetch uses `NoPrivateNetworkHttpClient`, with bounded responses and a timeout, to reject private-network destinations and pin resolved addresses. Its OAuth server and personal tokens are reference implementations. A production host must connect its own identity and recovery processes.

## Deployment responsibilities

Share rate-limit and lock storage where a deployment runs on multiple hosts. Keep runtime settings, signing keys and database files outside the public document root. Keep assertions, tokens and complete approval content out of routine request logs.

This is a tested initial implementation, not an independent security audit. See [verification](verification.md) for automated checks, recorded real-device runs and remaining coverage limits. Integration and release work is described in the [Symfony guide](../src/symfony-bundle/docs/index.md).
