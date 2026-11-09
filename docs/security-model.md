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

Normal service reads, retries and approval attempts also check that stored status and result fields agree with the execution record. With a receipt signer configured, a completed record must have a valid commit-time receipt under that signer's trusted public key. A contradiction, missing receipt or invalid signature raises `record_integrity_error`; it must not be presented as a known refusal or as evidence that nothing happened. This read check verifies the application's receipt, not the original WebAuthn signature independently. The audit page and offline verifier can perform that additional check with a trusted credential record.

Without a receipt signer, completed reads still check structural consistency but cannot authenticate an unsigned execution record. Enabling signing for an application with unsigned history, or changing the trusted signing key, requires an explicit policy for reading that history; the service does not silently treat it as verified.

The person's downloaded receipt, and the copy in the agent's result, sit outside the application's database. Checked against the application's published key, they show a later change to, or deletion of, the stored record. They cover only that person's own requests, and they depend on the person keeping them.

The demo's administrator page at `/admin/audit` keeps contradictory rows visible and reports an integrity warning. A mutable non-completed status cannot hide retained execution evidence from its receipt and passkey checks. It also verifies the audit chain. This checks the original historical records, not whether today's live content still matches an earlier approval.

Recorded times remain application claims, and an old assertion does not independently prove its historical revocation status. Deleting the newest audit entries cannot be detected without an independently retained checkpoint. A compromised application signing key can forge application reports or new enrollment records; it cannot recreate a passkey assertion under a separately trusted original user public key. Obtaining and retaining trusted verification keys is therefore part of the host's responsibility.

## Browser and account controls

The Symfony review flow uses an interactive session, ownership checks, CSRF protection, session-to-ceremony pairing, bounded request bodies, rate limits, escaped output, a restrictive content security policy, frame blocking and no-store responses. Delegated history reads also need an explicit result-read permission through `ActorProvider::assertCanReadApproval()`. Losing write permission need not remove permission to read an earlier result.

The demo keeps bearer-authenticated MCP access separate from human sessions, enrollment and administration. Its account controls illustrate responsibilities a production host must implement:

- A first approval passkey requires a one-time enrollment link and the owner's signed-in session. Setup issues initial links; an administrator needs fresh passkey confirmation to issue another.
- Adding another passkey requires fresh confirmation with an existing passkey. A password alone cannot enroll an approval credential.
- Passkey and agent-connection revocations are recorded and checked for subsequent use.
- Changing who must approve is itself a passkey-approved action. Its approval rule is fixed in code, so an administrator cannot switch off approval of rule changes through that rule editor.

The phone handoff code opens one request for one person, once, within two minutes. A Continue step avoids accidental redemption by link previewers. Redemption is locked; the scoped session can approve but cannot cancel or download the receipt. The tool that creates codes checks read scope and a rate budget. Its app-only visibility is a host convention, not authorization.

Remote clients connect through an existing OAuth server, league/oauth2-server with omouren/mcp-oauth-bundle: the authorization code flow with PKCE S256 only, single-use codes, rotating refresh tokens, the issuer on authorization responses (RFC 9207) and access tokens bound to the MCP endpoint (RFC 8707). The demo adds what approvals depend on. Each consent opens an agent connection whose ID is the delegation an approval records; a token works only while that connection is open and within the scopes both allow; revoking the connection ends its codes and tokens; and a connection ends after 30 days however often its client refreshes. Fetching a client ID metadata document uses `NoPrivateNetworkHttpClient`, with bounded responses and a timeout, to reject private-network destinations and pin resolved addresses. Open registration has a budget per address, a token request for another resource is refused, and the consent page cannot be framed. The personal tokens are a reference implementation. A production host must connect its own identity and recovery processes.

Each OAuth credential stores the exact grant that issued it. Reconnecting retires the old credentials and persists the new connection and code atomically. Token exchanges reserve the SQLite writer before checking validity, so overlapping requests cannot both consume one code or refresh token. The approval authorizer also checks connection expiry at completion, even when the person opened the review before that expiry.

For MCP, the demo checks missing token scopes at the HTTP boundary after authentication and returns HTTP 403 with the operation's required scopes and resource metadata. It derives tool identity from the parsed request and leaves malformed or inconsistent protocol headers to the SDK. Ownership and action-policy checks still run inside the application; they are not scope-upgrade requests.

## Deployment responsibilities

Share rate-limit and lock storage where a deployment runs on multiple hosts. Keep runtime settings, signing keys and database files outside the public document root. Keep assertions, tokens and complete approval content out of routine request logs.

This is a tested initial implementation, not an independent security audit. See [verification](verification.md) for automated checks, recorded real-device runs and remaining coverage limits. Integration and release work is described in the [Symfony guide](../src/symfony-bundle/docs/index.md).
