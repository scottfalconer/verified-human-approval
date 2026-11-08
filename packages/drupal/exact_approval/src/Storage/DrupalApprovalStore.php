<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Storage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use ExactApproval\Core\{Actor, ApprovalException, ApprovalRequest, ApprovalState, ApprovalStore, CanonicalJson, Ceremony};
use ExactApproval\Core\Evidence\{EvidenceExporter, SodiumEvidenceSigner};

/** One Drupal transaction owns the content write, credential counter and durable evidence. */
final class DrupalApprovalStore implements ApprovalStore
{
    private bool $active = false;

    public function __construct(
        private readonly Connection $database,
        private readonly DrupalCredentialRepository $credentials,
        private readonly SodiumEvidenceSigner $signer,
        private readonly ConfigFactoryInterface $config,
        private readonly EntityTypeManagerInterface $entities,
    ) {}

    public function transaction(callable $callback): mixed
    {
        if ($this->active || $this->database->inTransaction()) {
            throw new \LogicException('Approval must own the outer Drupal database transaction.');
        }
        $transaction = $this->database->startTransaction('exact_approval');
        $this->active = true;
        try {
            // An actual write obtains SQLite's reservation before any reads; on other drivers
            // this row also serializes approval completions. Adapter target locks remain required.
            if ($this->database->update('exact_approval_mutex')->expression('generation', '1 - generation')->condition('id', 1)->execute() !== 1) {
                throw new \LogicException('The approval transaction lock row is missing.');
            }
            $result = $callback();
            $transaction->commitOrRelease();
            return $result;
        } catch (\Throwable $error) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
                // The core service resolves an ambiguous commit by reading the durable request.
            }
            $this->entities->getStorage('node')->resetCache();
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void
    {
        $this->assertTransaction();
        $request = $state->request;
        $actor = $request->actor();
        $this->database->insert('exact_approval_requests')->fields([
            'id' => $request->id, 'actor_key' => $actor->key(), 'actor_user' => $actor->userId, 'actor_tenant' => $actor->tenantId,
            'idempotency_key' => $idempotencyKey === null ? null : hash('sha256', $idempotencyKey),
            'fingerprint' => $fingerprint, 'proposal' => $request->canonical, 'digest' => $request->digest, 'status' => $state->status,
            'created' => time(),
        ])->execute();
    }

    public function get(string $id): ?ApprovalState
    {
        $query = $this->database->select('exact_approval_requests', 'r');
        $query->leftJoin('exact_approval_executions', 'e', 'e.request_id = r.id');
        $query->fields('r')->addField('e', 'record', 'execution');
        $row = $query->condition('r.id', $id)->execute()->fetchAssoc();
        if (!$row) {
            return null;
        }
        $decode = static fn (?string $json): ?array => $json === null ? null : CanonicalJson::map(CanonicalJson::decode($json));
        return new ApprovalState(new ApprovalRequest($row['id'], $row['proposal'], $row['digest']), $row['status'], $row['reason'], $decode($row['result']), $decode($row['execution']), $decode($row['proof']));
    }

    public function findByKey(Actor $actor, string $key): ?array
    {
        $row = $this->database->select('exact_approval_requests', 'r')->fields('r', ['id', 'fingerprint'])
            ->condition('actor_key', $actor->key())->condition('idempotency_key', hash('sha256', $key))->execute()->fetchAssoc();
        return $row ? ['state' => $this->get($row['id']), 'fingerprint' => $row['fingerprint']] : null;
    }

    public function save(ApprovalState $state): void
    {
        $this->assertTransaction();
        $current = $this->get($state->request->id) ?? throw new \LogicException('Cannot update a missing approval.');
        if ($current->request->canonical !== $state->request->canonical || $current->status !== 'pending') {
            throw new ApprovalException('immutable_approval', 'Frozen proposals and terminal outcomes cannot be overwritten.');
        }
        if ($state->status === 'completed') {
            if ($state->execution === null || $state->result === null || $state->proof === null) {
                throw new \LogicException('Drupal completion requires a result, proof and execution record.');
            }
            $this->database->insert('exact_approval_executions')->fields([
                'request_id' => $state->request->id, 'record' => CanonicalJson::encode((object) $state->execution),
            ])->execute();
            // This stored signature becomes visible only with the same commit as the mutation.
            // Export later reads these bytes; it never repeats the action or re-signs history.
            $bundle = (new EvidenceExporter($this->signer))->export($state);
            $credential = $this->credentials->find($state->proof['credentialId']) ?? throw new \LogicException('Verified public credential disappeared.');
            $settings = $this->config->get('exact_approval.settings');
            $this->database->insert('exact_approval_evidence')->fields([
                'request_id' => $state->request->id,
                'bundle' => CanonicalJson::encode((object) $bundle),
                'credential' => CanonicalJson::encode((object) [
                    'id' => $credential->id, 'userId' => $credential->userId, 'tenantId' => $credential->tenantId, 'sourceJson' => $credential->sourceJson,
                ]),
                'trust' => CanonicalJson::encode((object) [
                    'keyId' => $this->signer->keyId(), 'publicKey' => $this->signer->publicKey(),
                    'rpId' => (string) $settings->get('rp_id'), 'origin' => (string) $settings->get('origin'),
                ]),
            ])->execute();
        }
        $updated = $this->database->update('exact_approval_requests')->fields([
            'status' => $state->status, 'reason' => $state->reason,
            'result' => $state->result === null ? null : CanonicalJson::encode((object) $state->result),
            'proof' => $state->proof === null ? null : CanonicalJson::encode((object) $state->proof),
        ])->condition('id', $state->request->id)->condition('status', 'pending')->execute();
        if ($updated !== 1) {
            throw new ApprovalException('approval_changed', 'The proposal changed before completion.');
        }
    }

    public function putCeremony(Ceremony $ceremony): void
    {
        $this->assertTransaction();
        $this->database->update('exact_approval_ceremonies')->fields(['consumed' => 1])->condition('request_id', $ceremony->requestId)->execute();
        $this->database->insert('exact_approval_ceremonies')->fields([
            'id' => $ceremony->id, 'request_id' => $ceremony->requestId, 'nonce' => $ceremony->nonce, 'challenge' => $ceremony->challenge,
            'expires_at' => $ceremony->expiresAt, 'options' => CanonicalJson::encode((object) $ceremony->options), 'consumed' => (int) $ceremony->consumed,
        ])->execute();
    }

    public function getCeremony(string $id): ?Ceremony
    {
        $row = $this->database->select('exact_approval_ceremonies', 'c')->fields('c')->condition('id', $id)->execute()->fetchAssoc();
        return $row ? new Ceremony($row['id'], $row['request_id'], $row['nonce'], $row['challenge'], $row['expires_at'], CanonicalJson::map(CanonicalJson::decode($row['options'])), (bool) $row['consumed']) : null;
    }

    public function retireCeremony(string $id): void
    {
        $this->assertTransaction();
        $this->database->update('exact_approval_ceremonies')->fields(['consumed' => 1])->condition('id', $id)->execute();
    }

    /** Infrastructure method: caller must enforce audit permission before requesting all actors. */
    public function recentForActor(Actor $actor, bool $admin = false, int $limit = 50): array
    {
        $query = $this->database->select('exact_approval_requests', 'r')->fields('r', ['id'])->condition('actor_tenant', $actor->tenantId);
        if (!$admin) {
            $query->condition('actor_user', $actor->userId);
        }
        $ids = $query->orderBy('created', 'DESC')->orderBy('id', 'DESC')->range(0, min(200, max(1, $limit)))->execute()->fetchCol();
        return array_map(fn (string $id): ApprovalState => $this->get($id), $ids);
    }

    /** Infrastructure method. Use the authorized EvidenceService for user-facing exports. */
    public function evidence(string $requestId): array
    {
        $row = $this->database->select('exact_approval_evidence', 'e')->fields('e')->condition('request_id', $requestId)->execute()->fetchAssoc();
        if (!$row) {
            throw new ApprovalException('not_completed', 'There is no committed execution evidence.');
        }
        return [
            'bundle' => CanonicalJson::map(CanonicalJson::decode($row['bundle'])),
            'credential' => CanonicalJson::map(CanonicalJson::decode($row['credential'])),
            'trust' => CanonicalJson::map(CanonicalJson::decode($row['trust'])),
        ];
    }

    private function assertTransaction(): void
    {
        if (!$this->active) {
            throw new \LogicException('Approval writes require the outer Drupal application transaction.');
        }
    }
}

