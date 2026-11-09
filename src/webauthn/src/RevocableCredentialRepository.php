<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;

/**
 * A host's raw passkey storage, as AnchoredCredentialRepository wraps it: the PDO adapter, or a host's own
 * (a Drupal entity or table, say). Besides the CredentialRepository rules, an adapter must:
 *
 * - never overwrite, reassign or reactivate an existing credential ID when adding one;
 * - treat ownership as user and tenant together;
 * - make update() a compare-and-replace on the unchanged, active source. A zero-counter passkey can write
 *   back an identical source, so match the expected row, not a count of rows the database changed;
 * - take part in the host's transaction, so a revocation and its signed audit entry commit together.
 *
 * Adding a credential records no enrollment: AnchoredCredentialRepository::anchor() does, in the same transaction.
 */
interface RevocableCredentialRepository extends CredentialRepository
{
    /**
     * Revokes an active credential this user and tenant own. Returns false, changing nothing, when the
     * credential is absent, already revoked, or another account's.
     */
    public function revoke(Actor $owner, string $id): bool;
}
