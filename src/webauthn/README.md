# WebAuthn approval bridge

This package implements the core `ApprovalVerifier` with [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-lib). Parsing, attestation validation, signature checking, RP/origin checks, user verification and counter handling use that library. There is no production test key or alternative confirmation mechanism.

The bridge is independent of a Symfony kernel. Applications supply credential ownership/storage through `CredentialRepository`. The SQLite reference repository uses the **same PDO connection** as the approval store, authorization records and protected business mutation, so a failed action rolls back the credential counter as well as the action and proof.

For signed enrollment, supply a raw `RevocableCredentialRepository` to `AnchoredCredentialRepository` together with the core `AuditLog`. This interface extends `CredentialRepository` with owner-and-tenant-scoped `revoke(Actor $owner, string $id): bool`; it does not require PDO. Wire the WebAuthn verifier to the anchored wrapper, and wire its inner repository explicitly to the raw host adapter. Credential insertion plus the enrollment anchor, and revocation plus its audit event, must each share the host transaction. A matched, unchanged credential source is a successful compare-and-replace even when its authenticator counter remains zero.

```php
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

$credentials = new PdoCredentialRepository($applicationPdo);
$credentials->initialize(); // An installation/migration step, not every request.

$verifier = new WebAuthnVerifier(
    new Configuration('app.example.org', 'https://app.example.org'),
    $credentials,
);
```

Pass `$verifier` to the approval core. The core constructs the proposal-bound challenge, persists the ceremony, and controls its expiry and one-time consumption. A WebAuthn signature counter is not a substitute for those rules: many valid passkeys always return a zero counter.

## Application identity and credentials

The actor is the authenticated application user and tenant. Browser review and agent requests may use different sessions; credential ownership does not depend on the initiating agent's delegation ID. The approval core separately reloads that delegation's current authority before execution.

`Credential` binds the application's user and tenant to an upstream serialized `CredentialRecord`. `add()` must never overwrite or reassign an existing credential ID. `update()` must compare and replace only the unchanged, active record. The included repository provides those checks and a `revoke()` method for a trusted account-management flow.

For existing passkey systems, implement `CredentialRepository` and `UserHandleProvider` against their registered keys and stable opaque user handles. The default `HashedUserHandleProvider` is for new registrations. Reusing a credential requires the original RP and compatible origin policy; this library does not make passkeys portable across unrelated websites.

## Trusted enrollment

The host controls enrollment. These methods perform WebAuthn validation, **not** application login, CSRF, expiry, one-time session consumption, account recovery or permission checks:

```php
$options = $verifier->registrationOptions(
    $authenticatedHuman,
    random_bytes(32),
    'Campaign editor',
);
// Save the complete options in the authenticated user's server-side session.
// Send the JSON to navigator.credentials.create({publicKey: ...}).

$credential = $verifier->register(
    $authenticatedHuman,
    $savedServerSideOptions,
    $browserResponse,
    'My passkey',
);
```

Before invoking enrollment, the host must require its trusted human account flow. An agent's MCP token must not reach enrollment, recovery or policy-administration routes. Completion must consume the saved options once, reject expired enrollment, and use the options from server-side storage rather than a copy submitted by the browser. Registration rejects a different owner, RP, challenge or missing user verification, and it cannot replace an existing credential.

`AnchoredCredentialRepository` accepts a passkey only while a signed enrollment entry in the audit log vouches for it. Its revocation is read from the whole chain, never from the store's lookup: an entry or row that names it, intact or damaged, stops the passkey for good, and so does an entry since its enrollment that was edited, retargeted or removed. So no write to the database undoes a revocation, except cutting the newest entries off the log: keep the head (`AuditLog::head()`) where the application cannot write, and `AuditLog::checkpoint()` shows the cut. It wraps any `RevocableCredentialRepository`, the raw storage: `PdoCredentialRepository`, or a host's own (a Drupal table, say), which must never overwrite, reassign or reactivate an ID, must treat ownership as user and tenant, and must compare and replace on the active source, matching an identical source from a zero-counter passkey. Call `anchor()` in the same transaction as `register()`, and `revoke()` in the same transaction as its signed entry, so a failure to sign or store the entry undoes the change. The audit rows can live in the host's storage too, through core's `AuditStore`.

## What is required from the authenticator

Both enrollment and approval request `userVerification: "required"`. Assertion verification also requires the user-presence flag. The first integration uses top-level browser ceremonies, a single explicitly configured origin, and no cross-origin iframe ceremonies or subdomain wildcard. The origin must use HTTPS; `http://localhost` is the explicit local-development exception. Do not derive configuration from untrusted Host headers or MCP tool arguments.

User verification means the authenticator verified its user using a supported local method. It can be Touch ID, Face ID, a PIN or another supported method. It does not prove a fingerprint was used or that the user understood the application's statement. The application review page, credential enrollment and account binding remain trusted.

No authenticator attachment restriction is imposed, allowing platform passkeys and a browser's native nearby-device path. Platform and physical-device compatibility must be tested separately from the unit suite.

## Historical evidence

`verifyEvidenceAssertion()` is read-only and accepts a **separately trusted credential registration**. It checks the assertion signature, challenge, RP, origin, user handle and required verification. It deliberately does not compare an old assertion against today's increasing counter, or claim to prove historical revocation state. It does not persist a counter or authorize an action.

The verifier combines that check with the application's signed execution report and says whether the report was signed as the action committed. Run `vendor/bin/verify-evidence.php` when this package is installed in an application, or `php src/webauthn/bin/verify-evidence.php` from this repository's root. The [receipt guide](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md) gives the full commands and trust-file formats. A public key included in an untrusted evidence file is not a trust source. Supply the trusted application keys separately, as the key set (every key the application signed with, and its window) or a one-key file, and optionally the credential registration: without it, the command checks the application's report and says the passkey signature was not checked. The command names the key that vouched for the report, and refuses a key the file does not list or a receipt dated outside its key's window.

## Verification

The tests use synthetic ES256 authenticators with real OpenSSL signatures and CBOR registration data. They exercise the actual upstream parser and validator, including rejection cases and transaction rollback. They do not simulate a human fingerprint or establish physical-device compatibility. Install the development-only [`verified-human-approval/testing`](../testing/README.md) package for the software authenticator and application test helpers. The [Symfony testing guide](../symfony-bundle/docs/testing.md) shows how to test your own enrollment, approval and MCP integration; the production verifier has no dependency on these helpers.
