<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use VerifiedHumanApproval\Demo\DemoDatabase;

/** Serializes OAuth validity checks and writes across the demo's SQLite connections. */
final class OAuthTransaction
{
    private bool $active = false;

    public function __construct(private readonly DemoDatabase $database)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        if ($this->active) {
            return $work();
        }
        if ($this->database->pdo->inTransaction()) {
            throw new \LogicException('OAuth must acquire its writer before reading credentials.');
        }

        return $this->database->transactional(function () use ($work): mixed {
            // PDO tracks this transaction. Even with no matching rows, a SQLite UPDATE reserves the writer.
            // Acquiring it before the first read prevents two requests from both seeing an unused credential.
            $this->database->pdo->exec('UPDATE demo_grants SET active = active WHERE 0');
            $this->active = true;
            try {
                return $work();
            } finally {
                $this->active = false;
            }
        });
    }

    public function assertActive(): void
    {
        if (!$this->active || !$this->database->pdo->inTransaction()) {
            throw new \LogicException('OAuth credential issuance needs its writer transaction.');
        }
    }
}
