# Verify a receipt

An action approved with a passkey and signed at commit has two signatures with different jobs:

| Signature | What it establishes |
| --- | --- |
| The user's passkey assertion | The credential enrolled for this account approved the exact frozen proposal. |
| The application's receipt | The application signed the proposal, the approval proof and its execution report together. |

With a receipt signer configured, the application signs inside the transaction that commits the action. Changing a signed field afterwards makes verification fail. For example, someone cannot change a recorded result from `yes` to `no` and keep a valid receipt.

The application runs this check itself, every time `ApprovalService` reads a stored request: before it reports an outcome, returns one again for a request key, offers the request for approval or changes it. The status and the result are stored beside the execution record, so they must agree with it. With a receipt signer configured, a completed request must also still carry the receipt signed as it committed, and that receipt must verify against the application's [trusted keys](#rotate-the-signing-key). A request that fails is answered with `record_integrity_error`, never with its stored outcome: changing a status to `cancelled` cannot hide that the action ran, and an edited result is not reported as what happened. The bundle's review page says that the outcome cannot be confirmed, and its MCP tools answer `integrity_error`. The demo's audit page keeps such a request in its list, marked. That includes a request edited so far that it can no longer be read back, such as one character changed in its stored proposal: the service answers `record_integrity_error` for it too, and the audit page lists it as a record that cannot be read. Nothing repairs or re-signs it.

Verification checks the original approval and execution record. A later, legitimate edit to the live resource does not change that historical record. Verification does not compare the receipt with today's content, prove who wrote the content, or independently observe the database effect.

The Symfony bundle hands this record to the person as their receipt, from the completed review page, and publishes the application's public keys at `/approval/receipt-keys.json`: every key it has signed with, and when each one signed. The agent's result carries the same signed receipt. A person who keeps their copy can check it later without trusting the application's stored records, including after the application [changes its signing key](#rotate-the-signing-key).

## Try it with the demo

Complete the [demo walkthrough](../demo/README.md) as the **editor**, using a passkey to approve an alert. The manager can publish directly under the default rule; that path has no passkey proof and is not the example below.

Run these commands from the repository root after `composer install`.

### 1. Download your receipt

While signed in as the person who approved the request, open its review page and choose **Download your receipt**. The browser saves `receipt-REQUEST_ID.json`. The same file is at:

```text
http://localhost:8080/approval/REQUEST_ID/evidence
```

Use the actual request ID from the approval page URL or the tool result, and your own origin if the demo runs elsewhere. Only the signed-in owner can download it. A phone opened with a one-time code cannot: its page says to download the receipt from the signed-in browser. An administrator can also open **Audit log** and use a completed action's **Evidence** link, at `/admin/audit/REQUEST_ID/evidence`.

Create a working directory and save the download there as `receipt.json`:

```sh
mkdir -p var/evidence-check
```

The download must be a completed action's evidence, not an error response. Its `format` is `verified-human-approval/evidence/v2`. Nothing is signed when you download it: it carries the receipt signed as the action committed.

### 2. Save the site's public keys

The site publishes the keys that check its receipts, for anyone, without signing in:

```sh
curl -fsS http://localhost:8080/approval/receipt-keys.json -o var/evidence-check/receipt-keys.json
```

Its `format` is `verified-human-approval/receipt-keys/v2`. It names the key that signs now (`currentKeyId`), lists every key in `keys`, and gives the passkey relying party and origin (`rpId`, `origin`). Each key has a `keyId`, a base64url Ed25519 `publicKey`, and the window it signed in: `validFrom` and `validUntil`, RFC 3339 times, either of which may be `null` (no earlier limit, or still signing). Save it and keep it with your receipts: later checks use your saved copy, so a key the site added or changed afterwards would not match. For an independent review, obtain the keys through a source you trust, never from the receipt file itself.

The site also serves a one-key file at `/approval/receipt-key.json` (`verified-human-approval/receipt-key/v1`, with `keyId`, `publicKey`, `rpId` and `origin`). It names only the key signing now. The command below reads both files, and a saved copy of the one-key file keeps checking what its key signed.

### 3. Verify the original

In this repository checkout:

```sh
php src/webauthn/bin/verify-evidence.php \
  var/evidence-check/receipt.json \
  var/evidence-check/receipt-keys.json
```

In an application that installed `verified-human-approval/webauthn` with Composer, use its binary instead:

```sh
vendor/bin/verify-evidence.php receipt.json receipt-keys.json
```

Success exits with code `0`. The output has this shape; the request ID and statement come from your approval:

```json
{
  "requestId": "YOUR_REQUEST_ID",
  "credentialApprovedExactProposal": null,
  "applicationSignedExecutionReport": true,
  "applicationReportSignedAt": "commit",
  "applicationKeyId": "demo-application-1",
  "statement": "THE_EXACT_STATEMENT_THE_USER_APPROVED",
  "qualification": "Verifies the application report. The passkey signature was not checked: no trusted passkey record was given. It does not independently witness the database effect, display, historical revocation state or human understanding."
}
```

`applicationReportSignedAt: "commit"` matters: the receipt was signed inside the action's transaction. The verifier checks that claim as part of the signed receipt. `applicationKeyId` names the key that signed it; the receipt's time, its execution record's `recordedAt`, must fall inside that key's window. `credentialApprovedExactProposal` is `null` because only the site's keys were given; [step 5](#5-optional-check-the-passkey-signature-too) checks the passkey signature as well.

### 4. Change a copy and verify again

This changes the recorded execution time in a separate file. It preserves the original download and the keys.

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
  var/evidence-check/receipt-keys.json
```

Expected result: `Verification failed: ...` and exit code `1`. The original file still verifies. Changes to the proposal, assertion, nonce or execution result also invalidate the evidence. Changing a field directly in the stored execution record has the same effect: the site answers `record_integrity_error` the next time it reads that request, and the record no longer verifies against its receipt.

Deleting the original receipt does not reset this protection. The site answers `record_integrity_error` for a completed request whose receipt is gone, and the exporter, called directly, refuses it (`receipt_missing`). Neither creates a fresh signature over possibly altered data. A receipt the person already downloaded stays valid on its own.

### 5. Optional: check the passkey signature too

The passkey check needs the public credential record from the user's original enrollment, trusted separately from the evidence. A key included by someone who supplies an evidence file cannot vouch for that same file.

For a local demo you control, run the following while you trust the original runtime settings and enrollment records. It verifies the enrollment's signature before exporting the credential. The credential ID from the receipt is only used to find that signed enrollment.

The script writes **public verification data only**. It reads the local application's signing key to construct its audit service, and checks the enrollment against every key the demo has signed with, but does not copy the key or sign a new receipt. Keep `settings.json` on the server. If you configured `VERIFIED_HUMAN_APPROVAL_RUNTIME`, keep it set to the same directory used by the demo.

```sh
php <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
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
// The current key and any it replaced, each with its window, as the site publishes them.
$keys = TrustedKeys::forSigner(
    $signer, $settings['receipt_key_valid_from'] ?? null, $settings['retired_receipt_keys'] ?? [],
);
$audit = new AuditLog(new PdoAuditStore($pdo), $signer, $keys, new NativeClock());
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
  var/evidence-check/receipt-keys.json \
  var/evidence-check/trusted-credential.json
```

Now the output reports `"credentialApprovedExactProposal": true`, and its qualification begins "Verifies credential approval and the application report." The credential file contains account identifiers, so share it only with the intended verifier.

## Use verification in your application

Configure the bundle's `receipt_signer` so that receipts are signed at commit. See the [Symfony integration guide](../src/symfony-bundle/docs/index.md#stored-receipts-and-later-verification), which also covers the person's receipt download and the published keys. Preserve the original receipt with its proposal, proof and execution record, and keep the signing key outside the database. Exporting or verifying evidence does not execute the action again.

Configure the signer before the first action runs. Once `ApprovalService` has a signer, it reads a completed request only while its commit receipt verifies, so a request that completed earlier, without a receipt, is answered with `record_integrity_error` from then on. This is deliberate: if a missing receipt were accepted, deleting one would switch the check off. The stored rows are not changed, and a page that reads the store directly, as the demo's audit page does, can still list them.

The trust files use these fields:

| File | Required fields | Trusted source |
| --- | --- | --- |
| Application keys: the bundle's key set, `receipt-keys.json` | `format` (`verified-human-approval/receipt-keys/v2`), `keys`, `rpId`, `origin`; each key has `keyId`, `publicKey`, `validFrom` and `validUntil` | The application's published or independently supplied signing keys, the window each signed in, and its WebAuthn configuration. `publicKey` is a base64url Ed25519 public key; `validFrom` and `validUntil` are RFC 3339 times or `null`. `currentKeyId` is informational. |
| Or one application key, such as the bundle's `receipt-key.json` | `keyId`, `publicKey`, `rpId`, `origin` | A one-key file. Its key is trusted at any time, without a window. The bundle's file adds `format` (`verified-human-approval/receipt-key/v1`). |
| `trusted-credential.json`, optional | `id`, `userId`, `tenantId`, `sourceJson` | The original, trusted passkey enrollment for that account. `id` is the base64url credential ID. Without it, the passkey signature is not checked. |

A receipt names the key that signed it. The CLI refuses a key the file does not list, and a receipt whose time falls outside its key's window. A receipt signed at commit was signed when its execution record says the action was recorded (`recordedAt`, which the receipt signs). A receipt signed at export does not record when it was exported, only that this came after `recordedAt`, so its key must not have retired before then.

`sourceJson` is a JSON **string** containing the WebAuthn library's serialized registration record. Keep the string intact. It contains the credential's public key and user handle, not the passkey's private key. Do not substitute an unchecked row from a database you suspect was altered.

The CLI checks the receipt with `ReceiptVerifier`, then checks the assertion with `WebAuthnVerifier::verifyEvidenceAssertion()`. The latter checks the signature, challenge, configured origin, relying party, account association and required user verification. It uses the original registration record without applying today's signature counter to an old assertion.

The CLI uses the package's default hashed user handles. If your host supplies another `UserHandleProvider`, call `verifyEvidenceAssertion()` with that same trusted provider.

An action whose rule did not require approval has no passkey proof. Its execution record says `approval: "not_required"`, and successful verification reports `credentialApprovedExactProposal: false`. Its application report is still verifiable. When a passkey did approve and no credential file is given, the CLI reports `credentialApprovedExactProposal: null`: that signature was not checked.

### Limits

The evidence proves approval by the enrolled credential and preserves the application's report. It does not prove authorship, a particular biometric, human understanding, an independently observed execution time or the credential's historical revocation status. Trust still depends on how the account and credential were enrolled and how verification keys were obtained. An exported record also cannot prove that other records were never deleted; the audit log cannot detect removal of its newest entries without an [independent checkpoint](#keep-checkpoints-of-the-audit-log). A receipt the person downloaded is such an independent copy, but only of their own requests. A key's window limits what it vouches for, but the time a record claims is the application's own: someone holding a signing key can backdate a record into that key's window (see [if the signing key is compromised](#if-the-signing-key-is-compromised)). See the [security model](security-model.md).

### Optional signing at export

Serving original receipts needs no signing key: construct the exporter without a signer (`new EvidenceExporter()`), and it returns each receipt exactly as it was signed at commit. An application that has **never** signed receipts at commit can explicitly use `new EvidenceExporter($signer, signAtExport: true)`. Its receipt says `export`, and the CLI reports `applicationReportSignedAt: "export"`. That only protects the record from the time it was exported; it cannot detect edits made earlier. Do not enable this option to replace a missing commit receipt. Such an application gives `ApprovalService` no signer, so the service's own reads check only that the stored status and result agree with the execution record.

## Rotate the signing key

One Ed25519 key signs new receipts and new audit log entries. Rotating it replaces that key without breaking what the old one signed: the old public key stays trusted, but only for records whose own time falls inside the window it signed in.

- A receipt's time is its execution record's `recordedAt`, as described above.
- An audit entry's time is its `at`. Once the chain records the rotation, an entry signed with the old key after that point is refused, whatever time it claims.
- A record that names a key the trusted keys do not list verifies nothing.

An audit entry that breaks these rules stops every passkey enrolled before it: from the chain alone, it could be what is left of a rewritten revocation. One the old key signs after the rotation entry stays refused whatever window that key is given, so it stops them until their owners enroll new passkeys, which the [security model](security-model.md#protection-after-storage) describes. Any other stops them only until the host's trusted keys list its key with a window that covers its time. The order of the steps below keeps a rotation from writing such entries.

### With the Symfony bundle

1. **Make a new key with a new id.** This writes it to a new file that only you can read:

   ```sh
   php -r '
   umask(0077);
   [, $file, $id] = $argv;
   $pair = sodium_crypto_sign_keypair();
   $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), "+/", "-_"), "=");
   $handle = fopen($file, "x") ?: throw new RuntimeException("Refusing to replace ".$file);
   fwrite($handle, "APPROVAL_RECEIPT_KEY_ID=$id\nAPPROVAL_RECEIPT_SECRET_KEY=".$encode(sodium_crypto_sign_secretkey($pair))."\nAPPROVAL_RECEIPT_PUBLIC_KEY=".$encode(sodium_crypto_sign_publickey($pair))."\n");
   ' var/approval/key-2.env application-2
   ```

   Put the new secret in your deployment's secret store, where the current one is. Never put it in the database.

2. **Switch the signer and retire the old key in one deploy.** Point `receipt_signer` at the new key (the [first-action guide](../src/symfony-bundle/docs/first-action.md) reads it from `APPROVAL_RECEIPT_KEY_ID` and `APPROVAL_RECEIPT_SECRET_KEY`), and list the old one, by its public key only, under `receipt_keys`:

   ```yaml
   verified_human_approval:
       receipt_signer: VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner
       receipt_keys:
           valid_from: '2026-11-09T12:00:00Z'        # when application-2 starts signing
           retired:
               - key_id: application-1
                 public_key: 'THE PUBLIC KEY OF application-1'
                 valid_from: '2026-01-05T09:00:00Z'  # optional: when it started, if you know
                 valid_until: '2026-11-09T12:10:00Z' # a little after it stops signing
   ```

   A key's window must cover everything it legitimately signs: the new key's `valid_from` no later than its first signature, the old key's `valid_until` no earlier than its last. Set the old key's `valid_until` a little after the switch, so that requests already under way finish inside its window, and keep that overlap short: until the old key's window closes, a stolen copy of it can sign records that verify. The retired list holds public keys only, so it can live in your repository; an environment variable read with `%env(json:...)%` also works. Your `AuditLog` must check entries against the same keys, through the bundle's `VerifiedHumanApproval\Core\Evidence\TrustedKeys` service, as the first-action guide wires it.

   Check the chain first: `verified-human-approval:audit-head` must report that every entry verifies. Once the old key has a `valid_until`, an entry edited, removed or put out of order anywhere in the chain stops every passkey that key enrolled, not only those enrolled before it.

   Switch every host and worker in the same deploy, and restart long-running workers with it. A host that does not list the new key yet cannot check what it signs: until it is updated, it answers `record_integrity_error` for receipts the new key signed, and refuses every passkey enrolled before an entry the new key signed (`credential_unvouched`), because an entry it cannot check could be a revocation. A host or worker that still signs with the old key after its window closes writes audit entries the log refuses, and each one stops every passkey enrolled before it. For one recorded before the rotation entry (step 4), moving the old key's `valid_until` to cover it ends that, but also widens the window a stolen copy of the key can backdate records into; one recorded after the rotation entry stops them until their owners enroll again.

   The approval service reads stored receipts against these keys too. Leave the old key out of `retired`, or set a window that does not cover what it signed, and every request that key completed is answered with `record_integrity_error` until the list is corrected. The audit log reads its entries against them as well, and an entry they no longer accept stops passkeys the same way. Nothing is lost: the stored receipts and entries are untouched and verify again once the list is corrected.

3. **Deploy to every host.** `/approval/receipt-keys.json` now lists both keys. A passkey that a host refused during the deploy, because it could not check the new key's entries, approves again once that host runs the new configuration.

4. **Record the rotation** in the signed audit log once the old key's window has closed, every host and worker signs with the new key, and the requests begun before the switch have finished:

   ```sh
   php bin/console verified-human-approval:record-key-rotation application-1 --reason="scheduled rotation"
   ```

   The entry, `signing_key.rotated`, is signed with the new key and names both keys, so the chain shows where trust moved. From it on, the log refuses any entry the old key signs, whatever window that key is given, and each such entry stops every passkey enrolled before it until their owners enroll again. Record it as soon as the old key's window has closed: from then until the new key signs its first entry, nothing the old key signed counts, so passkeys enrolled under it cannot approve: a person who tries is told to ask the administrator (`credential_unvouched`), and `verified-human-approval:audit-head` names the key and this command (`AuditLog::unvouchedKeys()` lists such keys). It also names a closed key whose rotation is not recorded, from the moment its window closes (`AuditLog::unrecordedRotations()`). The new key's first entry vouches for the entries before it, back to the nearest earlier one the log refuses. The command refuses a rotation the configuration does not show yet: the old key needs a `valid_until`, the new one a `valid_from`. It prints the new head of the log.

5. **Keep a checkpoint** of that head ([below](#keep-checkpoints-of-the-audit-log)), then **destroy the old private key** everywhere it was kept, backups included. Ask anyone who checks your receipts to save `/approval/receipt-keys.json` again.

The commands use the application's `AuditLog` service: the bundle's `audit_log` setting, or the service named `VerifiedHumanApproval\Core\Audit\AuditLog`.

### In the demo

`php demo/bin/console demo:rotate-receipt-key` does all of this on one host. It makes `demo-application-2`, closes the window of `demo-application-1` at that moment and keeps only its public key in `settings.json`, records the rotation in the audit log and prints a checkpoint. Check first that the **Audit log** page says every entry is signed and in an unbroken chain, as in step 2 above. Run it while nobody is approving or changing passkeys: a request already under way finishes with the old key, after its window closed. An approval completed that way reads back as `record_integrity_error` until the old key's `valid_until` in `settings.json` is moved to cover the time it recorded. A passkey enrolled or revoked, a link issued or a connection revoked that way is an audit entry the log refuses, and it stops every passkey enrolled before it: until that `valid_until` is moved to cover it, if it was recorded before the rotation entry, or else until their owners enroll again, as the [security model](security-model.md#browser-and-account-controls) describes for the demo. The approval service refuses to sign only when its own settings rule its key out, so it cannot catch a request whose settings predate the rotation. Receipts downloaded earlier still verify against a freshly saved `receipt-keys.json`, and the **Audit log** page lists both keys and the rotation entry.

### Without the bundle

Build an `Evidence\TrustedKeys` from `Evidence\TrustedKey` values (key id, public key, and the window, as `DateTimeImmutable` or `null`). Pass it to `ApprovalService` as `$trustedKeys`, to `Audit\AuditLog` and to `Evidence\ReceiptVerifier::verify()`. Without it, the service trusts only its signer's current key and answers `record_integrity_error` for every request an earlier key completed. Then call `AuditLog::recordRotation($oldKeyId, $reason)` once every process signs with the new key and the old key's window has closed, and publish `TrustedKeys::toArray()` as the `keys` of a key-set file. The [integration contracts](integration-contract.md) list the methods.

## Keep checkpoints of the audit log

Signatures show that an entry changed. They cannot show that the newest entries were cut off, and they do not stop someone holding a signing key from writing a chain of their own. A checkpoint, the newest entry's sequence and hash kept where the application cannot write, shows both later. With the bundle:

```sh
php bin/console verified-human-approval:audit-head
```

```text
Newest entry: 5, hash 5fd3c2045ece090c267c7f41eaa8ec71f0dc41ad6f3845e72379510ba8c8d79e, signed with key "demo-application-2" at 2026-11-09T18:46:01.260268Z.
All 5 entries verify in an unbroken chain. Keep this checkpoint where the application cannot write, and check it later with --check:
5:5fd3c2045ece090c267c7f41eaa8ec71f0dc41ad6f3845e72379510ba8c8d79e
```

Send the last line, or the `--json` form, somewhere else on a schedule and after each rotation: another system's log, a ticket, a message to someone outside the team that runs the application. Later, check it:

```sh
php bin/console verified-human-approval:audit-head --check=5:5fd3c2045ece090c267c7f41eaa8ec71f0dc41ad6f3845e72379510ba8c8d79e
```

It passes only if the whole chain still verifies and entry 5 still has that hash, so that no entry up to it changed or disappeared. It fails if the log now ends before entry 5, or if that entry was rewritten. Both forms exit with `1` when the chain does not verify: do not keep a checkpoint of a chain that fails. Without the bundle, `AuditLog::head()` returns the same sequence and hash. The demo's **Audit log** page shows them too.

## If the signing key is compromised

Rotation limits what a stolen key can do from then on. It cannot take back what the key signed, and the time a receipt or an entry records is the application's own claim: a thief can sign a record backdated into the stolen key's window, and it verifies.

1. **Rotate at once** as above, recording `--reason="suspected compromise"`, and close the old key's window at the switch, with no overlap, since someone else may hold the key. Closing it earlier, at the exposure, would refuse everything the key signed since, genuine receipts and entries included: the audit log would report those entries as failing from then on, each would stop every passkey enrolled before it, and no checkpoint of it would verify again.
2. **Check the chain against your newest checkpoint from before the exposure** with `audit-head --check`. If it holds, every entry up to that checkpoint is as it was. Review the entries after it that the old key signed: enrollments, revocations, links issued. Treat a passkey enrolled in that period as suspect: revoke it and enroll it again through your trusted account process.
3. **Keep a new checkpoint** right after the rotation entry.
4. **Destroy the old private key**, publish the new key set, and say when the exposure started if you know.

After the rotation, the old key cannot add an entry to the chain: one it signs after the rotation entry is refused, whatever time it claims. Damaging the rotation entry, or signing it again with a retired key, does not change that, even while the old key's window is still open: what a key with an end signs after a damaged entry or a refused rotation counts for nothing. To slip in a backdated entry, someone would have to replace an entry from before the rotation, which breaks the chain at the next entry (the audit page and `audit-head` show it), or rewrite every entry from there on. A rewrite in which every entry still verifies, with the rotation gone, is shown only by a checkpoint kept after the rotation, and `audit-head` then names the old key and asks for the rotation away from it to be recorded. A receipt is not in the chain: only its key's window bounds it. A receipt signed with the stolen key and dated inside its window cannot be told apart from a genuine one by its signature. Check a disputed one against your records, and against the copy its holder downloaded.

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

The check the service runs on every read:

```sh
composer test -- --filter 'StoredApprovalIntegrityTest|StoredApprovalAuditTest'
```

[StoredApprovalIntegrityTest](../tests/Evidence/StoredApprovalIntegrityTest.php) edits a stored status and a stored result, and checks that the service will not report, export or retry that request and that the action still ran once. It also checks that a deleted or replaced receipt is refused even when the edited parts agree with each other, and that an application without a signer still reads its own completions. [StoredApprovalAuditTest](../tests/Bundle/StoredApprovalAuditTest.php) checks that the demo's audit page keeps such a request in its list, marked as untrusted.

Key rotation:

```sh
composer test -- --filter 'KeyRotationTest|TrustedKeysTest|ReceiptKeysConfigurationTest'
```

[ReceiptKeyRotationTest](../tests/Evidence/ReceiptKeyRotationTest.php) checks receipts signed before a rotation, receipts dated outside their key's window and unknown key ids, in code and with the command and both key files. It also checks that the approval service reads an outcome an earlier key signed only once it is given its trusted keys, and holds a stored receipt to its key's window. [AuditKeyRotationTest](../tests/Core/AuditKeyRotationTest.php) checks a chain across a rotation, a retired key's backdated entry after it, and the rotation's own rules; [AnchoredKeyRotationTest](../tests/WebAuthn/AnchoredKeyRotationTest.php) does the same for passkey enrollment. [KeyRotationTest](../tests/Bundle/KeyRotationTest.php) runs the demo through `demo:rotate-receipt-key`, the published key set, the audit page and the checkpoint command.
