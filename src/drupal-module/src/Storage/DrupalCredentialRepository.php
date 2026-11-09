<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Statement\FetchAs;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/** Raw Drupal credential records; wire the verifier to the anchored wrapper. */
final readonly class DrupalCredentialRepository implements RevocableCredentialRepository
{
    public function __construct(private Connection $database, private DrupalTransaction $transactions)
    {
    }

    public function find(string $id): ?Credential
    {
        $row = $this->database->select('vha_credential', 'c')->fields('c')->condition('credential_id', $id)->execute()->fetchAssoc();

        return false === $row ? null : self::hydrate($row);
    }

    public function forActor(Actor $actor): array
    {
        $rows = $this->database->select('vha_credential', 'c')->fields('c')
            ->condition('user_id', $actor->userId)->condition('tenant_id', $actor->tenantId)->condition('revoked', 0)
            ->orderBy('credential_id')->execute()->fetchAll(FetchAs::Associative);

        return array_map(self::hydrate(...), $rows);
    }

    public function add(Credential $credential): void
    {
        $this->transactions->assertActive();
        try {
            $this->database->insert('vha_credential')->fields([
                'credential_id' => $credential->id, 'user_id' => $credential->userId,
                'tenant_id' => $credential->tenantId, 'source_json' => $credential->sourceJson,
                'revoked' => (int) $credential->revoked, 'label' => $credential->label,
            ])->execute();
        } catch (IntegrityConstraintViolationException) {
            throw new ApprovalException('credential_exists', 'This credential is already registered.');
        }
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        $this->transactions->assertActive();
        $current = $this->find($credential->id);
        if (null === $current || $current->revoked || $current->userId !== $credential->userId || $current->tenantId !== $credential->tenantId || $current->sourceJson !== $expectedSourceJson || $credential->revoked) {
            throw new ApprovalException('credential_changed', 'The approval credential changed or was revoked.');
        }
        // A zero-counter authenticator may produce the exact same source. We
        // matched the active row under the database writer reservation, so this
        // succeeds without depending on a driver's changed-row count.
        if ($credential->sourceJson === $expectedSourceJson) {
            return;
        }
        $matched = $this->database->update('vha_credential')->fields(['source_json' => $credential->sourceJson])
            ->condition('credential_id', $credential->id)->condition('user_id', $credential->userId)
            ->condition('tenant_id', $credential->tenantId)->condition('revoked', 0)->condition('source_json', $expectedSourceJson)
            ->execute();
        if (1 !== $matched) {
            throw new ApprovalException('credential_changed', 'The approval credential changed or was revoked.');
        }
    }

    public function revoke(Actor $owner, string $id): bool
    {
        $this->transactions->assertActive();

        return 1 === $this->database->update('vha_credential')->fields(['revoked' => 1])
            ->condition('credential_id', $id)->condition('user_id', $owner->userId)->condition('tenant_id', $owner->tenantId)->condition('revoked', 0)
            ->execute();
    }

    /** @return list<Credential> Including revoked rows, for an explicitly authorized audit page. */
    public function forTenant(string $tenantId): array
    {
        $rows = $this->database->select('vha_credential', 'c')->fields('c')->condition('tenant_id', $tenantId)
            ->orderBy('user_id')->orderBy('credential_id')->execute()->fetchAll(FetchAs::Associative);

        return array_map(self::hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Credential
    {
        return new Credential($row['credential_id'], $row['user_id'], $row['tenant_id'], $row['source_json'], (bool) $row['revoked'], $row['label']);
    }
}
