# Verify an approval outside the application

The evidence export (`verified-human-approval/evidence/v2`) contains the exact canonical proposal string, proposal-bound assertion material, durable execution record and an Ed25519 application receipt over their digests. The receipt also signs when it was signed: `commit`, inside the transaction that ran the action, or `export`, only when the evidence was downloaded. A receipt signed at export cannot show an edit made before then, and cannot be relabelled as one signed at commit.

An application that signs receipts at commit exports nothing for a completed action whose receipt is missing: deleting a receipt is how someone with database access would hide an edit, so the exporter refuses (`receipt_missing`) rather than sign the record afresh. Only an application that has never signed at commit opts in to signing at export (`EvidenceExporter(..., signAtExport: true)`).

The Symfony bundle hands this file to the person as their receipt (`receipt-<id>.json`, from the completed review page) and publishes the application's key at `/approval/receipt-key.json`, so anyone can check a receipt without trusting the application's records.

Trust files are supplied separately. Do not accept trust data supplied solely by the artifact being verified.

`trusted-application.json` (the bundle's `/approval/receipt-key.json` has these fields, plus `format`):

```json
{
  "keyId": "your-application-signing-key-id",
  "publicKey": "BASE64URL_ED25519_PUBLIC_KEY",
  "rpId": "app.example.org",
  "origin": "https://app.example.org"
}
```

`trusted-credential.json`, optional:

```json
{
  "id": "BASE64URL_CREDENTIAL_ID",
  "userId": "user-123",
  "tenantId": "organization-456",
  "sourceJson": "THE_SERIALIZED_TRUSTED_WEBAUTHN_CREDENTIAL_RECORD"
}
```

`sourceJson` is a JSON **string** containing the WebAuthn library’s serialized registration record. It contains a public key and registered owner/handle, not the passkey private key. Obtain it through a trusted registration/audit process. Application setup prints the example receipt public key; runtime settings retain it. Never distribute the secret signing key from those settings.

```sh
vendor/bin/verify-evidence.php evidence.json trusted-application.json [trusted-credential.json]
```

The command ships with `verified-human-approval/webauthn`. Without the credential file it checks the application's signed report only, and reports `credentialApprovedExactProposal: null` with a qualification saying the passkey signature was not checked; that is what a person holding only their receipt and the site's key can check. Successful output separately reports `credentialApprovedExactProposal` and `applicationSignedExecutionReport`, when that report was signed (`applicationReportSignedAt`: `commit` or `export`), plus the original statement. Altered statements, effects, assertions, nonces, execution reports and substituted keys fail verification. An administrator may later update the live policy; that does not rewrite the old signed statement.

Historical verification checks signature, origin, RP, ownership, required UV and proposal binding. It deliberately does not apply the credential’s **current** monotonic counter to an old assertion. A historical proof does not independently establish the historical revocation status. The application’s recorded decision remains an application claim.

The command currently uses this package’s default hashed user-handle scheme. A host reusing credentials with another handle scheme should use `WebAuthnVerifier::verifyEvidenceAssertion()` with its trusted `UserHandleProvider`. That is also the seam for future Drupal integration.

Actions executed while an administrator has disabled approval export `approval: not_required`, no human proof, and `credentialApprovedExactProposal: false`. Their application report is still verifiable; they must not be presented as having human passkey approval.

The command never receives or executes an action. Its only job is verification.
