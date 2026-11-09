<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\Audit\AuditStore;

/**
 * An audit store with no database at all, standing in for a host's own storage (a Drupal table, say), to
 * show that the audit log's signing and checking do not depend on PDO. Tests reach into $rows to damage it.
 *
 * @phpstan-import-type AuditRow from AuditStore
 */
final class InMemoryAuditStore implements AuditStore
{
    /** @var array<int, AuditRow> by sequence */
    public array $rows = [];

    public int $builds = 0;

    public function append(callable $buildRecord): array
    {
        $head = [] === $this->rows ? null : $this->rows[max(array_keys($this->rows))];
        ++$this->builds;
        $row = $buildRecord(null === $head ? null : ['seq' => $head['seq'], 'hash' => $head['hash']]);
        if (isset($this->rows[$row['seq']])) {
            throw new \RuntimeException('Sequence '.$row['seq'].' already exists.');
        }
        $this->rows[$row['seq']] = $row;

        return $row;
    }

    public function recordsFor(string $type, string $subject): iterable
    {
        $rows = array_filter($this->rows, static fn (array $row): bool => $row['type'] === $type && $row['subject'] === $subject);
        krsort($rows);

        return array_values($rows);
    }

    public function records(): iterable
    {
        $rows = $this->rows;
        ksort($rows);

        return array_values($rows);
    }
}
