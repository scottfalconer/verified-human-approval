<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Storage;

use Drupal\Core\Database\Connection;
use ExactApproval\Core\{Actor, ApprovalException};
use ExactApproval\WebAuthn\{Credential, CredentialRepository};

final readonly class DrupalCredentialRepository implements CredentialRepository
{
    public function __construct(private Connection $database) {}

    public function find(string $id): ?Credential
    {
        $row = $this->database->select('exact_approval_credentials', 'c')->fields('c')->condition('credential_hash', hash('sha256', $id))->execute()->fetchAssoc();
        if (!$row || !hash_equals($row['credential_id'], $id)) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function forActor(Actor $actor): array
    {
        return array_values(array_filter($this->allForActor($actor), static fn (Credential $c): bool => !$c->revoked));
    }

    public function allForActor(Actor $actor): array
    {
        return $this->rows($this->database->select('exact_approval_credentials', 'c')->fields('c')->condition('user_id', $actor->userId)->condition('tenant_id', $actor->tenantId)->orderBy('created', 'DESC')->execute());
    }

    public function recent(int $limit = 100): array
    {
        return $this->rows($this->database->select('exact_approval_credentials', 'c')->fields('c')->orderBy('created', 'DESC')->range(0, min(500, max(1, $limit)))->execute());
    }

    public function add(Credential $credential): void
    {
        if ($this->find($credential->id) !== null) {
            throw new ApprovalException('credential_exists', 'This key is already registered.');
        }
        $this->database->insert('exact_approval_credentials')->fields([
            'credential_hash' => hash('sha256', $credential->id), 'credential_id' => $credential->id,
            'user_id' => $credential->userId, 'tenant_id' => $credential->tenantId,
            'source_json' => $credential->sourceJson, 'source_digest' => hash('sha256', $credential->sourceJson),
            'revoked' => (int) $credential->revoked, 'label' => mb_substr($credential->label, 0, 128), 'created' => time(),
        ])->execute();
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        // Versioned update shares the approval transaction, including on devices with counter zero.
        $updated = $this->database->update('exact_approval_credentials')->fields([
            'source_json' => $credential->sourceJson, 'source_digest' => hash('sha256', $credential->sourceJson),
        ])->condition('credential_hash', hash('sha256', $credential->id))
            ->condition('user_id', $credential->userId)->condition('tenant_id', $credential->tenantId)
            ->condition('revoked', 0)->condition('source_digest', hash('sha256', $expectedSourceJson))->execute();
        if ($updated !== 1) {
            throw new ApprovalException('credential_changed', 'The approval key changed or was revoked.');
        }
    }

    public function revokeForActor(Actor $actor, string $id): void
    {
        $updated = $this->database->update('exact_approval_credentials')->fields(['revoked' => 1])
            ->condition('credential_hash', hash('sha256', $id))->condition('user_id', $actor->userId)->condition('tenant_id', $actor->tenantId)->execute();
        if ($updated !== 1) {
            throw new ApprovalException('not_found', 'The key was not found.');
        }
    }

    private function rows(object $statement): array
    {
        $result = [];
        while ($row = $statement->fetchAssoc()) {
            $result[] = $this->hydrate($row);
        }
        return $result;
    }

    private function hydrate(array $row): Credential
    {
        return new Credential($row['credential_id'], $row['user_id'], $row['tenant_id'], $row['source_json'], (bool) $row['revoked'], $row['label']);
    }
}

