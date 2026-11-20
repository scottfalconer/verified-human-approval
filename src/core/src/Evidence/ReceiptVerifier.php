<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;

/** Verifies the application's signed report. A WebAuthn verifier separately verifies human-credential approval. */
final class ReceiptVerifier
{
    /**
     * Trust keys must be supplied out-of-band. No public key contained in evidence is accepted.
     *
     * The receipt names its key: an id $keys does not list fails. A receipt signed at commit was signed when its
     * execution record says the action was recorded (`recordedAt`, which the receipt signs), and that time must
     * fall inside its key's window. A receipt signed at export does not sign when it was exported, only that it
     * came after the action was recorded, so its key must not have been retired before then. For one public key
     * on its own, pass TrustedKeys::single().
     *
     * @param array<string, mixed> $bundle
     *
     * @return array{request: ApprovalRequest, proof: array<string, mixed>|null, execution: array<string, mixed>, applicationSignatureValid: true, receiptSignedAt: Receipt::COMMIT|Receipt::EXPORT, keyId: string}
     */
    public function verify(array $bundle, TrustedKeys $keys): array
    {
        try {
            if (($bundle['format'] ?? null) !== EvidenceExporter::FORMAT || !\is_string($bundle['proposal'] ?? null)) {
                throw new \UnexpectedValueException('Unknown evidence format.');
            }
            // json_decode($json, true) turns the file's objects into arrays, and an empty object into an empty array,
            // so the bytes the digests cover could not be rebuilt from it.
            foreach (['receipt', 'execution', 'proof'] as $part) {
                if (\is_array($bundle[$part] ?? null)) {
                    throw new \UnexpectedValueException('Read the evidence with CanonicalJson::decode() and CanonicalJson::map(), not json_decode($json, true): its objects must stay objects, because their exact bytes are what the receipt signs.');
                }
            }
            $proposal = CanonicalJson::map(CanonicalJson::decode($bundle['proposal']));
            $digest = hash('sha256', $bundle['proposal']);
            $request = new ApprovalRequest($proposal['id'], $bundle['proposal'], $digest);
            $receipt = CanonicalJson::map($bundle['receipt']);
            // The digests cover what the proposal, proof and execution record hold; nothing covers a field beside them.
            if ([] !== array_diff(array_keys($bundle), ['format', 'proposal', 'proof', 'execution', 'receipt']) || [] !== array_diff(array_keys($receipt), ['manifest', 'keyId', 'signature'])) {
                throw new \UnexpectedValueException('The evidence holds fields that nothing in it signs.');
            }
            $proof = null === $bundle['proof'] ? null : CanonicalJson::map($bundle['proof']);
            $execution = CanonicalJson::map($bundle['execution']);
            $signed = \is_string($receipt['manifest'] ?? null) ? CanonicalJson::map(CanonicalJson::decode($receipt['manifest'])) : [];
            // When it was signed is part of what was signed: it cannot be changed without breaking the signature.
            $signedAt = $signed['signedAt'] ?? null;
            if (!\in_array($signedAt, [Receipt::COMMIT, Receipt::EXPORT], true)) {
                throw new \UnexpectedValueException('The receipt does not say when it was signed.');
            }
            // The receipt names its key; only a key the verifier was given out of band can vouch for it.
            $keyId = \is_string($receipt['keyId'] ?? null) ? $receipt['keyId'] : '';
            $key = $keys->get($keyId) ?? throw new \UnexpectedValueException(\sprintf('The receipt is signed with key "%s", which the trusted keys do not list.', $keyId));
            // Checked before the record is compared with the manifest: a manifest the key did not sign says nothing of the record.
            if (!\is_string($receipt['signature'] ?? null) || !$key->signed($receipt['manifest'], $receipt['signature'])) {
                throw new \UnexpectedValueException(\sprintf('The trusted key "%s" did not sign this receipt.', $key->keyId));
            }
            $expected = [
                'format' => Receipt::FORMAT, 'keyId' => $key->keyId, 'signedAt' => $signedAt, 'proposalDigest' => $digest,
                'proofDigest' => hash('sha256', CanonicalJson::encode($bundle['proof'])),
                'executionDigest' => hash('sha256', CanonicalJson::encode($bundle['execution'])),
            ];
            if (!hash_equals(CanonicalJson::encode($expected), $receipt['manifest'])) {
                throw new \UnexpectedValueException(self::notSigned($signed, $expected));
            }
            if ('verified-human-approval/execution/v1' !== $execution['format']
                || $execution['requestId'] !== $request->id || !hash_equals($digest, $execution['proposalDigest'])
                || CanonicalJson::encode($execution['actor']) !== CanonicalJson::encode((object) $request->actor()->toArray())
                || $execution['actionId'] !== $request->prepared()->actionId || $execution['actionVersion'] !== $request->prepared()->version
                || CanonicalJson::encode($execution['target']) !== CanonicalJson::encode((object) $request->prepared()->target)
            ) {
                throw new \UnexpectedValueException('Evidence components do not describe the same action.');
            }
            if (null === $proof) {
                if (false !== $proposal['policy']->required || 'not_required' !== $execution['approval']) {
                    throw new \UnexpectedValueException('Required approval proof is missing.');
                }
            } else {
                if ('verified-human-approval/proof/v1' !== $proof['format'] || $proof['proposalDigest'] !== $digest
                    || 'user_verified' !== $execution['approval']
                    || !hash_equals(ChallengeBinding::encode(ChallengeBinding::challenge($digest, ChallengeBinding::decode($proof['nonce']))), $proof['challenge'])
                ) {
                    throw new \UnexpectedValueException('The proof is not bound to the proposal.');
                }
            }
            // The time it claims is signed too, through the execution digest: it must fall inside its key's window.
            if (!self::withinWindow($key, $signedAt, $execution['recordedAt'] ?? null)) {
                throw new \UnexpectedValueException(\sprintf('The receipt claims a time outside the window of its key "%s".', $key->keyId));
            }

            return ['request' => $request, 'proof' => $proof, 'execution' => $execution, 'applicationSignatureValid' => true, 'receiptSignedAt' => $signedAt, 'keyId' => $key->keyId];
        } catch (\Throwable $error) {
            throw new ApprovalException('invalid_evidence', 'The evidence does not verify.', $error);
        }
    }

    /**
     * Why a manifest its key signed does not vouch for this record: the parts whose digests differ from the ones it
     * signed, else the other name it gives the key, else that the key signed no receipt of this record.
     *
     * @param array<string, mixed>  $signed the manifest the receipt carries
     * @param array<string, string> $record the manifest the receipt would carry for this record
     */
    private static function notSigned(array $signed, array $record): string
    {
        $parts = [];
        foreach (['proposalDigest' => 'proposal', 'proofDigest' => 'approval proof', 'executionDigest' => 'execution record'] as $digest => $part) {
            if (($signed[$digest] ?? null) !== $record[$digest]) {
                $parts[] = $part;
            }
        }
        if ([] === $parts) {
            $signedAs = $signed['keyId'] ?? null;
            if (\is_string($signedAs) && $signedAs !== $record['keyId']) {
                // The receipt was given another name for the key that signed it, as a key set may list one under two.
                return \sprintf('The receipt names key "%s", but it was signed as key "%s".', $record['keyId'], $signedAs);
            }

            // Its digests are this record's and it names no other key, so it differs in how it is written: another
            // format, a field more or less, or other bytes. Only what Receipt::manifest() writes is a receipt, so the
            // key signed no receipt of this record.
            return \sprintf('The trusted key "%s" did not sign this receipt.', $record['keyId']);
        }
        $last = array_pop($parts);

        return \sprintf('The record is not what the application signed: its receipt does not match its %s.', ([] === $parts ? '' : implode(', ', $parts).' and ').$last);
    }

    /** @param Receipt::COMMIT|Receipt::EXPORT $signedAt */
    private static function withinWindow(TrustedKey $key, string $signedAt, mixed $recordedAt): bool
    {
        if (Receipt::COMMIT === $signedAt) {
            // Signed inside the completing transaction, as the action was recorded.
            return $key->coversClaim($recordedAt);
        }
        if (null === $key->validUntil) {
            return true;
        }
        // Signed at some unrecorded time after the action was recorded: a key retired before then cannot have.
        try {
            return \is_string($recordedAt) && TrustedKey::time($recordedAt) <= $key->validUntil;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
