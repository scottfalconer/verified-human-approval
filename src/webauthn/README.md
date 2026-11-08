# WebAuthn approval bridge

This package implements the core `ApprovalVerifier` with [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-lib). Parsing, attestation validation, signature checking, RP/origin checks, user verification and counter handling use that library. There is no production test key or alternative confirmation mechanism.

The bridge is independent of a Symfony kernel. Applications supply credential ownership/storage through `CredentialRepository`. The SQLite reference repository uses the **same PDO connection** as the approval store, authorization records and protected business mutation, so a failed action rolls back the credential counter as well as the action and proof.

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

## What is required from the authenticator

Both enrollment and approval request `userVerification: "required"`. Assertion verification also requires the user-presence flag. The first integration uses top-level browser ceremonies, a single explicitly configured origin, and no cross-origin iframe ceremonies or subdomain wildcard. The origin must use HTTPS; `http://localhost` is the explicit local-development exception. Do not derive configuration from untrusted Host headers or MCP tool arguments.

User verification means the authenticator verified its user using a supported local method. It can be Touch ID, Face ID, a PIN or another supported method. It does not prove a fingerprint was used or that the user understood the application's statement. The application review page, credential enrollment and account binding remain trusted.

No authenticator attachment restriction is imposed, allowing platform passkeys and a browser's native nearby-device path. Platform and physical-device compatibility must be tested separately from the unit suite.

## Historical evidence

`verifyEvidenceAssertion()` is read-only and accepts a **separately trusted credential registration**. It checks the assertion signature, challenge, RP, origin, user handle and required verification. It deliberately does not compare an old assertion against today's increasing counter, or claim to prove historical revocation state. It does not persist a counter or authorize an action.

`vendor/bin/verify-evidence.php`, shipped with this package, combines that check with the application's signed execution report, and says whether that report was signed as the action committed. A public key included in an untrusted evidence file is not a trust source. Supply the trusted application key and credential registration separately.

## Verification

The tests use synthetic ES256 authenticators with real OpenSSL signatures and CBOR registration data. They exercise the actual upstream parser and validator, including rejection cases and transaction rollback. They do not simulate a human fingerprint or establish physical-device compatibility. Test helpers live exclusively under the root `tests/` development autoload namespace.
