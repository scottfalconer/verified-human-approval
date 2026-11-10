# Connect your trusted enrollment flow

The bundle handles review and approval for enrolled passkeys. Your host application decides who may add or revoke a credential and owns account recovery. Browser authentication alone is not an enrollment authorization decision. Require your established trusted process, such as a separately delivered one-time enrollment link or an existing verified passkey. An MCP bearer cannot enroll a credential.

There is a complete Doctrine reference in the [publishing application's account controller](../../../examples/doctrine-publishing/src/Controller/AccountController.php), [enrollment service](../../../examples/doctrine-publishing/src/Account/PasskeyEnrollment.php) and [template](../../../examples/doctrine-publishing/templates/account/passkey.html.twig). They use normal Symfony Security, session and CSRF services, the existing bundle JavaScript, and the WebAuthn library. Adapt the account-specific enrollment authorization rather than copying a new login system.

## Render the existing browser panel

On your authenticated account page, render this only after your trusted enrollment process authorizes the account to register. The base template includes the bundle's `approval.js`; in another layout, load that asset once.

```twig
{% extends '@VerifiedHumanApproval/base.html.twig' %}
{% block body %}
<div data-passkey-panel data-mode="registration"
     data-csrf="{{ csrf_token('account.passkey') }}"
     data-options-url="{{ path('account_passkey_options') }}"
     data-complete-url="{{ path('account_passkey_complete') }}">
    <button type="button" data-approve>Register a passkey</button>
    <p data-message aria-live="polite"></p>
</div>
{% endblock %}
```

Use the `SecurityHeadersSubscriber::ROUTE_DEFAULT` route default on your host enrollment routes to apply the same security headers as the review page. Keep your enrollment routes behind your own browser firewall. The bundle's public route matcher does not include them.

## Options endpoint

The panel sends `POST` with `{}`, `Content-Type: application/json` and `X-CSRF-Token`. Your endpoint must:

1. Resolve `ActorProvider::interactive()` and recheck trusted enrollment authority.
2. Validate the CSRF token for the same account page.
3. Generate options with `WebAuthnVerifier::registrationOptions($actor, random_bytes(32), $displayName)`.
4. Save the full options, account and tenant, a random ceremony ID, enrollment grant identifier and a short expiry in the server-side session. The reference uses 120 seconds.
5. Return the ceremony ID and options:

```json
{"ceremony_id":"SERVER_GENERATED_ID","publicKey":{"challenge":"...","rp":{"id":"app.example.com","name":"Your application"},"user":{"id":"...","name":"...","displayName":"..."},"pubKeyCredParams":[]}}
```

This JSON illustrates the envelope. Send the complete unmodified options returned by `registrationOptions()`, including their algorithm and verification settings; do not build the WebAuthn options yourself. The panel converts binary fields and invokes `navigator.credentials.create()`.

## Completion endpoint

The panel posts this envelope to the completion URL with the same JSON and CSRF headers:

```json
{"ceremony_id":"SERVER_GENERATED_ID","credential":{"id":"...","rawId":"...","type":"public-key","response":{"clientDataJSON":"...","attestationObject":"...","transports":[]},"clientExtensionResults":{}}}
```

Binary fields are base64url encoded by the bundle script. Your endpoint must resolve the interactive account again, check CSRF, bound the request size and consume the saved ceremony once. Check its ID, expiry, user and tenant, plus the enrollment grant's current validity. Never accept replacement registration options, account IDs or enrollment authority from the request body.

Inside one transaction on the application's shared connection:

```php
$credential = $webauthn->register($actor, $savedOptions, $browserCredential, 'Passkey');
// Atomically consume your one-time enrollment grant here, checking owner and expiry.
$passkeys->anchor($credential, [
    'method' => 'trusted one-time enrollment link',
    'authorizedBy' => $enrollmentAuditReference,
]);
```

`$passkeys` is `AnchoredCredentialRepository`. `register()` validates and inserts the credential; `anchor()` records its signed enrollment. The credential and anchor must commit together. A missing or failed anchor must leave no usable credential. The Doctrine reference uses `DbalTransaction::run()` and consumes its link with a conditional update in that transaction. For PDO use the same shared connection and transaction boundary as your application.

Return `{"status":"registered","message":"Your passkey is registered."}` only after commit. For a definite rejection, return an appropriate error status and `message`. A response lost after submission leaves the browser uncertain; reloading the account's credential list determines whether enrollment committed.

## Revocation and recovery

Resolve the trusted human account and enforce your revocation policy, then call the anchored repository's `revoke()` inside the shared transaction. Its signed audit entry and the credential change must commit together; signing failure rolls back revocation. The [reference revocation service](../../../demo/src/Account/PasskeyRevocation.php) demonstrates this boundary.

Recovery is host account functionality. Keep an agent token, a guessed user ID, or a stolen password from replacing the credential that authorizes protected actions. Approval still rechecks the credential and current business authority when executing the frozen proposal.

For automated tests, use the [testing package](testing.md) against your actual enrollment page and endpoints. A software authenticator tests protocol behavior; a supported browser and physical authenticator still need their own acceptance check.
