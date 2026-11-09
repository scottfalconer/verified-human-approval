# Verify a stored approval

An action approved with a passkey and signed at commit has two signatures with different jobs:

| Signature | What it establishes |
| --- | --- |
| The user's passkey assertion | The credential enrolled for this account approved the exact frozen proposal. |
| The application's receipt | The application signed the proposal, the approval proof and its execution report together. |

With a receipt signer configured, the application signs inside the transaction that commits the action. Changing a signed field afterwards makes verification fail. For example, someone cannot change a recorded result from `yes` to `no` and keep a valid receipt.

This checks the original approval and execution record. A later, legitimate edit to the live resource does not change that historical record. Verification does not compare the receipt with today's content, prove who wrote the content, or independently observe the database effect.

## Try it with the demo

Complete the [demo walkthrough](../demo/README.md) as the **editor**, using a passkey to approve an alert. The manager can publish directly under the default rule; that path has no passkey proof and is not the example below.

Run these commands from the repository root after `composer install`.

### 1. Download the original evidence

While signed in as the person who approved the request, open:

```text
http://localhost:8080/approval/REQUEST_ID/evidence
```

Use the actual request ID from the approval page URL or the tool result. If your demo uses another origin, use that origin. An administrator can also open **Audit**, select the completed action and download its evidence at `/admin/audit/REQUEST_ID/evidence`.

Create a working directory and save the download there as `approval-evidence.json`:

```sh
mkdir -p var/evidence-check
```

The download must be a completed action's evidence, not an error response. Its `format` is `verified-human-approval/evidence/v2`.

### 2. Export the public trust files

The verifier needs a trusted application public key and the public credential record from the user's original enrollment. These must come from a trusted source separate from the evidence. A key included by someone who supplies an evidence file cannot vouch for that same file.

For a local demo you control, run the following while you trust the original runtime settings and enrollment records. It verifies the enrollment's signature before exporting the credential. The credential ID from the evidence is only used to find that signed enrollment.

The script writes **public verification data only**. It reads the local application's signing key to construct its audit service, but does not copy the key or sign a new receipt. Keep `settings.json` on the server. If you configured `VERIFIED_HUMAN_APPROVAL_RUNTIME`, keep it set to the same directory used by the demo.

```sh
php <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

$directory = getcwd().'/var/evidence-check';
$runtime = getenv('VERIFIED_HUMAN_APPROVAL_RUNTIME') ?: getcwd().'/var/demo';
$read = static fn (string $path): array => json_decode(
    file_get_contents($path), true, 512, JSON_THROW_ON_ERROR,
);
$settings = $read($runtime.'/settings.json');
$evidence = $read($directory.'/approval-evidence.json');
$credentialId = $evidence['proof']['credentialId'] ?? null;
if (!is_string($credentialId) || $credentialId === '') {
    throw new RuntimeException('Use a completed request that required passkey approval.');
}

$pdo = new PDO('sqlite:'.$runtime.'/application.sqlite');
$signer = SodiumEvidenceSigner::fromEncoded(
    $settings['receipt_key_id'], $settings['receipt_secret_key'],
);
$audit = new AuditLog($pdo, $signer, $settings['receipt_public_key'], new NativeClock());
$credentials = new AnchoredCredentialRepository(new PdoCredentialRepository($pdo), $audit);
$credential = $credentials->enrolled($credentialId)
    ?? throw new RuntimeException('No valid signed enrollment for this credential.');

$files = [
    'trusted-application.json' => [
        'keyId' => $settings['receipt_key_id'],
        'publicKey' => $settings['receipt_public_key'],
        'rpId' => $settings['rp_id'],
        'origin' => $settings['origin'],
    ],
    'trusted-credential.json' => [
        'id' => $credential->id,
        'userId' => $credential->userId,
        'tenantId' => $credential->tenantId,
        'sourceJson' => $credential->sourceJson,
    ],
];
foreach ($files as $name => $contents) {
    file_put_contents($directory.'/'.$name, json_encode(
        $contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n");
}
echo "Wrote public application and credential trust files.\n";
PHP
```

Keep these trust files separate from the file you will modify in step 4. For an independent review, obtain them through a trusted administrator or registration process. They contain account identifiers, so share them only with the intended verifier.

### 3. Verify the original

In this repository checkout:

```sh
php src/webauthn/bin/verify-evidence.php \
  var/evidence-check/approval-evidence.json \
  var/evidence-check/trusted-application.json \
  var/evidence-check/trusted-credential.json
```

In an application that installed `verified-human-approval/webauthn` with Composer, use its binary instead:

```sh
vendor/bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json
```

Success exits with code `0`. The output has this shape; the request ID and statement come from your approval:

```json
{
  "requestId": "YOUR_REQUEST_ID",
  "credentialApprovedExactProposal": true,
  "applicationSignedExecutionReport": true,
  "applicationReportSignedAt": "commit",
  "statement": "THE_EXACT_STATEMENT_THE_USER_APPROVED",
  "qualification": "Verifies credential approval and the application report. It does not independently witness the database effect, display, historical revocation state or human understanding."
}
```

`applicationReportSignedAt: "commit"` matters: the receipt was signed inside the action's transaction. The verifier checks that claim as part of the signed receipt.

### 4. Change a copy and verify again

This changes the recorded execution time in a separate file. It preserves the original download and both trust files.

```sh
php <<'PHP'
<?php
$directory = getcwd().'/var/evidence-check';
$evidence = json_decode(
    file_get_contents($directory.'/approval-evidence.json'),
    false, 512, JSON_THROW_ON_ERROR,
);
$evidence->execution->recordedAt = '2000-01-01T00:00:00.000000Z';
file_put_contents($directory.'/altered-evidence.json', json_encode(
    $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n");
PHP

php src/webauthn/bin/verify-evidence.php \
  var/evidence-check/altered-evidence.json \
  var/evidence-check/trusted-application.json \
  var/evidence-check/trusted-credential.json
```

Expected result: `Verification failed: ...` and exit code `1`. The original file still verifies. Changes to the proposal, assertion, nonce or execution result also invalidate the evidence. Changing a field directly in the stored execution record has the same effect when that record is next exported and verified.

Deleting the original receipt does not reset this protection. The default exporter refuses a completed record with a missing receipt (`receipt_missing`); it does not create a fresh signature over possibly altered data.

## Use verification in your application

Configure the bundle's `receipt_signer` so that receipts are signed at commit. See the [Symfony integration guide](../src/symfony-bundle/docs/index.md). Preserve the original receipt with its proposal, proof and execution record, and keep the signing key outside the database. Exporting or verifying evidence does not execute the action again.

The two trust files use these fields:

| File | Required fields | Trusted source |
| --- | --- | --- |
| `trusted-application.json` | `keyId`, `publicKey`, `rpId`, `origin` | The application's published or independently supplied signing key and WebAuthn configuration. `publicKey` is a base64url Ed25519 public key. |
| `trusted-credential.json` | `id`, `userId`, `tenantId`, `sourceJson` | The original, trusted passkey enrollment for that account. `id` is the base64url credential ID. |

`sourceJson` is a JSON **string** containing the WebAuthn library's serialized registration record. Keep the string intact. It contains the credential's public key and user handle, not the passkey's private key. Do not substitute an unchecked row from a database you suspect was altered.

The CLI checks the receipt with `ReceiptVerifier`, then checks the assertion with `WebAuthnVerifier::verifyEvidenceAssertion()`. The latter checks the signature, challenge, configured origin, relying party, account association and required user verification. It uses the original registration record without applying today's signature counter to an old assertion.

The CLI uses the package's default hashed user handles. If your host supplies another `UserHandleProvider`, call `verifyEvidenceAssertion()` with that same trusted provider.

An action whose policy did not require approval has no passkey proof. Its execution record says `approval: "not_required"`, and successful verification reports `credentialApprovedExactProposal: false`. Its application report is still verifiable. The CLI currently requires both trust-file arguments even for that case; the credential is only checked when a proof is present.

### Limits

The evidence proves approval by the enrolled credential and preserves the application's report. It does not prove authorship, a particular biometric, human understanding, an independently observed execution time or the credential's historical revocation status. Trust still depends on how the account and credential were enrolled and how verification keys were obtained. An exported record also cannot prove that other records were never deleted; the audit log cannot detect removal of its newest entries without an independent checkpoint. See the [security model](security-model.md).

### Optional signing at export

An application that has **never** signed receipts at commit can explicitly use `EvidenceExporter(..., signAtExport: true)`. Its receipt says `export`, and the CLI reports `applicationReportSignedAt: "export"`. That only protects the record from the time it was exported; it cannot detect edits made earlier. Do not enable this option to replace a missing commit receipt.

## Regression checks

From the repository root:

```sh
composer test -- --testsuite evidence
```

Focused checks for stored-record integrity:

```sh
composer test -- --filter 'testReceiptsSignedAtCommitExposeEditsMadeAfterwards|testADeletedCommitReceiptIsNeverReplacedByOneSignedAtExport|testWhenAReceiptWasSignedIsPartOfWhatIsSigned'
```

These tests in [ApprovalEvidenceTest](../tests/Evidence/ApprovalEvidenceTest.php) cover a database edit after commit, deletion of the receipt, and an attempt to relabel an export-time signature as a commit-time signature. The evidence suite also checks modified proposals, substituted credentials and the separation between the application's signature and the user's signature.
