<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Storage;

use Drupal\Core\Database\Connection;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;

/**
 * Native Drupal storage; the same root transaction also contains the node save.
 *
 * Adapted from VerifiedHumanApproval\Core\Storage\PdoApprovalStore, which is MIT licensed (see the
 * repository's LICENSE).
 */
final readonly class DrupalApprovalStore implements ApprovalStore
{
    private const SELECT = 'SELECT r.*, e.record AS execution, c.key_id AS receipt_key_id, c.manifest AS receipt_manifest, c.signature AS receipt_signature
        FROM {vha_request} r LEFT JOIN {vha_execution} e ON e.request_id = r.id LEFT JOIN {vha_receipt} c ON c.request_id = r.id';

    public function __construct(private Connection $database, private DrupalTransaction $transactions)
    {
    }

    public function transaction(callable $callback): mixed
    {
        return $this->transactions->run($callback);
    }

    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void
    {
        $this->transactions->assertActive();
        $request = $state->request;
        $this->database->insert('vha_request')->fields([
            'id' => $request->id, 'actor_key' => $request->actor()->key(),
            'idempotency_key' => null === $idempotencyKey ? null : hash('sha256', $idempotencyKey),
            'fingerprint' => $fingerprint, 'proposal' => $request->canonical,
            'digest' => $request->digest, 'status' => $state->status,
        ])->execute();
    }

    public function get(string $id): ?ApprovalState
    {
        $row = $this->database->query(self::SELECT.' WHERE r.id = :id', [':id' => $id])->fetchAssoc();

        return false === $row ? null : self::state($row);
    }

    public function findByKey(Actor $actor, string $idempotencyKey): ?array
    {
        $row = $this->database->select('vha_request', 'r')->fields('r', ['id', 'fingerprint'])
            ->condition('actor_key', $actor->key())->condition('idempotency_key', hash('sha256', $idempotencyKey))
            ->execute()->fetchAssoc();
        if (false === $row || null === $state = $this->get($row['id'])) {
            return null;
        }

        return ['state' => $state, 'fingerprint' => $row['fingerprint']];
    }

    public function findPending(Actor $actor, string $fingerprint): ?ApprovalState
    {
        $id = $this->database->select('vha_request', 'r')->fields('r', ['id'])
            ->condition('actor_key', $actor->key())->condition('fingerprint', $fingerprint)->condition('status', 'pending')
            ->orderBy('sequence', 'DESC')->range(0, 1)->execute()->fetchField();

        return false === $id ? null : $this->get((string) $id);
    }

    public function save(ApprovalState $state): void
    {
        $this->transactions->assertActive();
        $current = $this->get($state->request->id) ?? throw new \LogicException('Cannot update a missing approval.');
        if ('pending' !== $current->status || $current->request->canonical !== $state->request->canonical || $current->request->digest !== $state->request->digest) {
            throw new ApprovalException('immutable_approval', 'A frozen proposal or terminal outcome cannot be overwritten.');
        }
        if ('completed' === $state->status) {
            if (null === $state->execution || null === $state->result) {
                throw new \LogicException('Completion requires a durable result and execution record.');
            }
            $this->database->insert('vha_execution')->fields([
                'request_id' => $state->request->id, 'record' => CanonicalJson::encode((object) $state->execution),
            ])->execute();
            if (null !== $state->receipt) {
                $this->database->insert('vha_receipt')->fields([
                    'request_id' => $state->request->id, 'key_id' => $state->receipt['keyId'],
                    'manifest' => $state->receipt['manifest'], 'signature' => $state->receipt['signature'],
                ])->execute();
            }
        }
        $this->database->update('vha_request')->fields([
            'status' => $state->status, 'reason' => $state->reason,
            'result' => null === $state->result ? null : CanonicalJson::encode((object) $state->result),
            'proof' => null === $state->proof ? null : CanonicalJson::encode((object) $state->proof),
        ])->condition('id', $state->request->id)->condition('status', 'pending')->execute();
    }

    public function putCeremony(Ceremony $ceremony): void
    {
        $this->transactions->assertActive();
        $this->database->insert('vha_ceremony')->fields([
            'id' => $ceremony->id, 'request_id' => $ceremony->requestId,
            'nonce' => $ceremony->nonce, 'challenge' => $ceremony->challenge,
            'expires_at' => $ceremony->expiresAt, 'options' => CanonicalJson::encode((object) $ceremony->options),
            'consumed' => (int) $ceremony->consumed,
        ])->execute();
    }

    public function getCeremony(string $id): ?Ceremony
    {
        $row = $this->database->select('vha_ceremony', 'c')->fields('c')->condition('id', $id)->execute()->fetchAssoc();

        return false === $row ? null : new Ceremony($row['id'], $row['request_id'], $row['nonce'], $row['challenge'], $row['expires_at'], CanonicalJson::map(CanonicalJson::decode($row['options'])), (bool) $row['consumed']);
    }

    public function retireCeremony(string $id): void
    {
        $this->transactions->assertActive();
        $this->database->update('vha_ceremony')->fields(['consumed' => 1])->condition('id', $id)->execute();
    }

    /** @param array<string, mixed> $row */
    private static function state(array $row): ApprovalState
    {
        $decode = static fn (?string $json): ?array => null === $json ? null : CanonicalJson::map(CanonicalJson::decode($json));
        $receipt = null === $row['receipt_manifest'] ? null : [
            'manifest' => $row['receipt_manifest'], 'keyId' => $row['receipt_key_id'], 'signature' => $row['receipt_signature'],
        ];

        return new ApprovalState(new ApprovalRequest($row['id'], $row['proposal'], $row['digest']), $row['status'], $row['reason'], $decode($row['result']), $decode($row['execution']), $decode($row['proof']), $receipt);
    }
}
