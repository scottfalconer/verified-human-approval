# Fictional deletion evidence

This public sample was exported after the real Drupal HTTP acceptance test deleted a fictional node. The signing credential is a synthetic ES256 authenticator, not a person's device. No passkey private key, server signing secret, account password or biometric data is included.

- `evidence.json`: canonical proposal, WebAuthn assertion, recorded outcome and signed application receipt.
- `trusted-credential.json`: public credential registration and its fictional account/site binding.
- `trusted-application.json`: application public verification key, key ID, RP ID and origin.

After installing the root Composer dependencies, run from the repository root:

```sh
php bin/verify-evidence.php \
  examples/evidence/drupal-delete/evidence.json \
  examples/evidence/drupal-delete/trusted-application.json \
  examples/evidence/drupal-delete/trusted-credential.json
```

The expected output has both `credentialApprovedExactProposal` and `applicationSignedExecutionReport` set to `true`. The former verifies a credential signature bound to the proposal. The latter verifies the application's signed report. Neither independently witnesses a physical person, the displayed page, or a historical database write.

The adjacent trust files are supplied specifically for this example. For real deployments, obtain the trust anchors through independently trusted registration and application-key records. Do not trust an arbitrary evidence bundle merely because its sender included matching public keys.
