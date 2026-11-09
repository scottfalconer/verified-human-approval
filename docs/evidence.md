# Verify a stored approval

An action approved with a passkey and signed at commit has two signatures with different jobs:

| Signature | What it establishes |
| --- | --- |
| The user's passkey assertion | The credential enrolled for this account approved the exact frozen proposal. |
| The application's receipt | The application signed the proposal, the approval proof and its execution report together. |

With a receipt signer configured, the application signs inside the transaction that commits the action. Changing a signed field afterwards makes verification fail. For example, someone cannot change a recorded result from `yes` to `no` and keep a valid receipt.

The service also checks the unsigned status and result fields against the execution record before returning a stored outcome or retrying an action. When a receipt signer is configured, completed reads verify the original commit receipt with its trusted public key. Inconsistent fields, a missing receipt or a failed signature check return `record_integrity_error`, so changing a status to `cancelled` cannot hide evidence that the action ran. The administrator's audit page keeps such rows visible for inspection. These checks do not repair or re-sign damaged records.

This checks the original approval and execution record. A later, legitimate edit to the live resource does not change that historical record. Verification does not compare the receipt with today's content, prove who wrote the content, or independently observe the database effect.

The Symfony bundle hands this record to the person as their receipt, from the completed review page, and publishes the application's public key at `/approval/receipt-key.json`. The agent's result carries the same signed receipt. A person who keeps their copy can check it later without trusting the application's stored records.

## Try it with the demo

Complete the [demo walkthrough](../demo/README.md) as the **editor**, using a passkey to approve an alert. The manager can publish directly under the default rule; that path has no passkey proof and is not the example below.

Run these commands from the repository root after `composer install`.

### 1. Download your receipt

While signed in as the person who approved the request, open its review page and choose **Download your receipt**. The browser saves `receipt-REQUEST_ID.json`. The same file is at:

```text
http://localhost:8080/approval/REQUEST_ID/evidence
```

Use the actual request ID from the approval page URL or the tool result, and your own origin if the demo runs elsewhere. Only the signed-in owner can download it; a phone opened with a one-time code is sent back to the signed-in browser. An administrator can also open **Audit log** and use a completed action's **Evidence** link, at `/admin/audit/REQUEST_ID/evidence`.

Create a working directory and save the download there as `receipt.json`:

```sh
mkdir -p var/evidence-check
```

The download must be a completed action's evidence, not an error response. Its `format` is `verified-human-approval/evidence/v2`. Nothing is signed when you download it: it carries the receipt signed as the action committed.

### 2. Save the site's public key

The site publishes the key that checks its receipts, for anyone, without signing in:

```sh
curl -fsS http://localhost:8080/approval/receipt-key.json -o var/evidence-check/receipt-key.json
```

It holds `keyId`, `publicKey`, `rpId` and `origin`. Save it once and keep it with your receipts: later checks use your saved copy, so a key the site changed afterwards would not match. For an independent review, obtain the key through a source you trust, never from the receipt file itself.

### 3. Verify the original

In this repository checkout:

```sh
php src/webauthn/bin/verify-evidence.php \
  var/evidence-check/receipt.json \
  var/evidence-check/receipt-key.json
```

In an application that installed `verified-human-approval/webauthn` with Composer, use its binary instead:

```sh
vendor/bin/verify-evidence.php receipt.json receipt-key.json
```

Success exits with code `0`. The output has this shape; the request ID and statement come from your approval:

```json
{
  "requestId": "YOUR_REQUEST_ID",
  "credentialApprovedExactProposal": null,
  "applicationSignedExecutionReport": true,
  "applicationReportSignedAt": "commit",
  "statement": "THE_EXACT_STATEMENT_THE_USER_APPROVED",
  "qualification": "Verifies the application report. The passkey signature was not checked: no trusted passkey record was given. It does not independently witness the database effect, display, historical revocation state or human understanding."
}
```

`applicationReportSignedAt: "commit"` matters: the receipt was signed inside the action's transaction. The verifier checks that claim as part of the signed receipt. `credentialApprovedExactProposal` is `null` because only the site's key was given; [step 5](#5-optional-check-the-passkey-signature-too) checks the passkey signature as well.

### 4. Change a copy and verify again

This changes the recorded execution time in a separate file. It preserves the original download and the key.

```sh
php <<'PHP'
<?php
$directory = getcwd().'/var/evidence-check';
$evidence = json_decode(
    file_get_contents($directory.'/receipt.json'),
    false, 512, JSON_THROW_ON_ERROR,
);
$evidence->execution->recordedAt = '2000-01-01T00:00:00.000000Z';
file_put_contents($directory.'/altered-receipt.json', json_encode(
    $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n");
PHP

php src/webauthn/bin/verify-evidence.php \
  var/evidence-check/altered-receipt.json \
  var/evidence-check/receipt-key.json
```

Expected result: `Verification failed: ...` and exit code `1`. The original file still verifies. Changes to the proposal, assertion, nonce or execution result also invalidate the evidence. Changing a field directly in the stored execution record has the same effect when that record is next exported and verified.

Deleting the original receipt does not reset this protection. The default exporter refuses a completed record with a missing receipt (`receipt_missing`); it does not create a fresh signature over possibly altered data. A receipt the person already downloaded stays valid on its own.

### 5. Optional: check the passkey signature too

The passkey check needs the public credential record from the user's original enrollment, trusted separately from the evidence. A key included by someone who supplies an evidence file cannot vouch for that same file.

For a local demo you control, run the following while you trust the original runtime settings and enrollment records. It verifies the enrollment's signature before exporting the credential. The credential ID from the receipt is only used to find that signed enrollment.

The script writes **public verification data only**. It reads the local application's signing key to construct its audit service, but does not copy the key or sign a new receipt. Keep `settings.json` on the server. If you configured `VERIFIED_HUMAN_APPROVAL_RUNTIME`, keep it set to the same directory used by the demo.

```sh
php <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

$directory = getcwd().'/var/evidence-check';
$runtime = getenv('VERIFIED_HUMAN_APPROVAL_RUNTIME') ?: getcwd().'/var/demo';
$read = static fn (string $path): array => json_decode(
    file_get_contents($path), true, 512, JSON_THROW_ON_ERROR,
);
$settings = $read($runtime.'/settings.json');
$evidence = $read($directory.'/receipt.json');
$credentialId = $evidence['proof']['credentialId'] ?? null;
if (!is_string($credentialId) || $credentialId === '') {
    throw new RuntimeException('Use a completed request that required passkey approval.');
}

$pdo = new PDO('sqlite:'.$runtime.'/application.sqlite');
$signer = SodiumEvidenceSigner::fromEncoded(
    $settings['receipt_key_id'], $settings['receipt_secret_key'],
);
$audit = new AuditLog(new PdoAuditStore($pdo), $signer, $settings['receipt_public_key'], new NativeClock());
$credentials = new AnchoredCredentialRepository(new PdoCredentialRepository($pdo), $audit);
$credential = $credentials->enrolled($credentialId)
    ?? throw new RuntimeException('No valid signed enrollment for this credential.');

file_put_contents($directory.'/trusted-credential.json', json_encode([
    'id' => $credential->id,
    'userId' => $credential->userId,
    'tenantId' => $credential->tenantId,
    'sourceJson' => $credential->sourceJson,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo "Wrote the public credential trust file.\n";
PHP

php src/webauthn/bin/verify-evidence.php \
  var/evidence-check/receipt.json \
  var/evidence-check/receipt-key.json \
  var/evidence-check/trusted-credential.json
```

Now the output reports `"credentialApprovedExactProposal": true`, and its qualification begins "Verifies credential approval and the application report." The credential file contains account identifiers, so share it only with the intended verifier.

## Use verification in your application

Configure the bundle's `receipt_signer` so that receipts are signed at commit. See the [Symfony integration guide](../src/symfony-bundle/docs/index.md#stored-receipts-and-later-verification), which also covers the person's receipt download and the published key. Preserve the original receipt with its proposal, proof and execution record, and keep the signing key outside the database. Exporting or verifying evidence does not execute the action again.

The trust files use these fields:

| File | Required fields | Trusted source |
| --- | --- | --- |
| Application key, such as the bundle's `receipt-key.json` | `keyId`, `publicKey`, `rpId`, `origin` | The application's published or independently supplied signing key and WebAuthn configuration. `publicKey` is a base64url Ed25519 public key. The bundle's file adds `format`. |
| `trusted-credential.json`, optional | `id`, `userId`, `tenantId`, `sourceJson` | The original, trusted passkey enrollment for that account. `id` is the base64url credential ID. Without it, the passkey signature is not checked. |

`sourceJson` is a JSON **string** containing the WebAuthn library's serialized registration record. Keep the string intact. It contains the credential's public key and user handle, not the passkey's private key. Do not substitute an unchecked row from a database you suspect was altered.

The CLI checks the receipt with `ReceiptVerifier`, then checks the assertion with `WebAuthnVerifier::verifyEvidenceAssertion()`. The latter checks the signature, challenge, configured origin, relying party, account association and required user verification. It uses the original registration record without applying today's signature counter to an old assertion.

The CLI uses the package's default hashed user handles. If your host supplies another `UserHandleProvider`, call `verifyEvidenceAssertion()` with that same trusted provider.

An action whose policy did not require approval has no passkey proof. Its execution record says `approval: "not_required"`, and successful verification reports `credentialApprovedExactProposal: false`. Its application report is still verifiable. When a passkey did approve and no credential file is given, the CLI reports `credentialApprovedExactProposal: null`: that signature was not checked.

### Limits

The evidence proves approval by the enrolled credential and preserves the application's report. It does not prove authorship, a particular biometric, human understanding, an independently observed execution time or the credential's historical revocation status. Trust still depends on how the account and credential were enrolled and how verification keys were obtained. An exported record also cannot prove that other records were never deleted; the audit log cannot detect removal of its newest entries without an independent checkpoint. A receipt the person downloaded is such an independent copy, but only of their own requests. See the [security model](security-model.md).

### Optional signing at export

Serving original receipts needs no signing key: construct the exporter without a signer (`new EvidenceExporter()`), and it returns each receipt exactly as it was signed at commit. An application that has **never** signed receipts at commit can explicitly use `new EvidenceExporter($signer, signAtExport: true)`. Its receipt says `export`, and the CLI reports `applicationReportSignedAt: "export"`. That only protects the record from the time it was exported; it cannot detect edits made earlier. Do not enable this option to replace a missing commit receipt.

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
