<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Audit;

/**
 * Persistence for the global signed audit chain, independent of its cryptography.
 *
 * @phpstan-type AuditHead array{seq: int, hash: string}
 * @phpstan-type AuditRecord array{seq: int, tenant_id: string, type: string, subject: string, entry: string, hash: string, key_id: string, signature: string}
 */
interface AuditStore
{
    /**
     * Serialize appenders before reading the actual head, including an empty chain.
     * Call the builder once, then insert and return its exact record. Do not skip a
     * damaged head or retry the builder after an uncertain commit.
     *
     * A standalone append owns and commits its transaction. Inside the host's
     * transaction it joins it, retaining serialization until the outer transaction
     * ends; it must not commit or roll back the host's transaction. Failures propagate.
     *
     * @param callable(?AuditHead): AuditRecord $buildRecord
     *
     * @return AuditRecord
     */
    public function append(callable $buildRecord): array;

    /** @return iterable<AuditRecord> Raw matching rows, newest sequence first, including damaged rows. */
    public function recordsFor(string $type, string $subject): iterable;

    /** @return iterable<AuditRecord> A consistent view of the entire chain, oldest sequence first. */
    public function records(): iterable;
}
