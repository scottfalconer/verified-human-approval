<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Audit;

/**
 * Where the signed security log is kept. AuditLog builds, signs and checks every entry; a store only keeps
 * rows exactly as given and returns them unfiltered, including rows whose signature no longer verifies, so
 * that damage is reported rather than hidden or repaired.
 *
 * @phpstan-type AuditHead array{seq: int, hash: string}
 * @phpstan-type AuditRecord array{seq: int, tenant_id: string, type: string, subject: string, entry: string, hash: string, key_id: string, signature: string}
 */
interface AuditStore
{
    /**
     * Appends one row to the single chain shared by every tenant.
     *
     * The store first serializes appenders, then reads the head, then calls $buildRecord once and inserts
     * exactly the row it returns. The head is the highest stored sequence and its hash, even when that row
     * is damaged, or null when the log is empty; serialization must hold for an empty log too.
     *
     * Called outside a transaction, the store owns one and returns only after it commits. Called inside the
     * host's transaction, it joins it: it keeps appenders serialized until that transaction ends and neither
     * commits nor rolls it back. Every failure propagates, so the host can roll back the whole operation the
     * entry belongs to. The builder never runs again for the same call, even after an uncertain commit.
     *
     * @param callable(AuditHead|null): AuditRecord $buildRecord builds and signs one row; it must not change anything else
     *
     * @return AuditRecord the row as inserted
     */
    public function append(callable $buildRecord): array;

    /**
     * Rows of one type and subject, newest sequence first. A lookup through the store's own index, which an edited
     * index or storage class can make miss a row: AuditLog finds entries with it, but reads the whole chain to show
     * that one is absent, such as a revocation.
     *
     * @return iterable<AuditRecord>
     */
    public function recordsFor(string $type, string $subject): iterable;

    /**
     * The whole chain, oldest sequence first, as one consistent view. A later append may follow it.
     *
     * @return iterable<AuditRecord>
     */
    public function records(): iterable;
}
