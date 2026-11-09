<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use VerifiedHumanApproval\Core\Audit\AuditStore;

/**
 * Raw chain persistence. AuditLog retains all signing and verification logic.
 *
 * @phpstan-import-type AuditRecord from AuditStore
 */
final readonly class DrupalAuditStore implements AuditStore
{
    public function __construct(private Connection $database, private DrupalTransaction $transactions)
    {
    }

    public function append(callable $buildRecord): array
    {
        $append = function () use ($buildRecord): array {
            $this->transactions->assertActive();
            $last = $this->database->select('vha_audit', 'a')->fields('a', ['seq', 'hash'])
                ->orderBy('seq', 'DESC')->range(0, 1)->execute()->fetchAssoc();
            $record = $buildRecord(false === $last ? null : ['seq' => (int) $last['seq'], 'hash' => $last['hash']]);
            $this->database->insert('vha_audit')->fields($record)->execute();

            return $record;
        };

        return $this->transactions->isActive() ? $append() : $this->transactions->run($append);
    }

    public function recordsFor(string $type, string $subject): iterable
    {
        return $this->database->select('vha_audit', 'a')->fields('a')
            ->condition('type', $type)->condition('subject', $subject)->orderBy('seq', 'DESC')
            ->execute()->fetchAll(FetchAs::Associative);
    }

    public function records(): iterable
    {
        return $this->database->select('vha_audit', 'a')->fields('a')->orderBy('seq')
            ->execute()->fetchAll(FetchAs::Associative);
    }
}
