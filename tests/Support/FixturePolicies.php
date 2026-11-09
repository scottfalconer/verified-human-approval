<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;

final class FixturePolicies implements PolicyProvider
{
    private readonly FixtureDatabase $database;

    public function __construct(\PDO|FixtureDatabase $database)
    {
        $this->database = $database instanceof \PDO ? new PdoFixtureDatabase($database) : $database;
    }

    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        $row = $this->database->row('SELECT * FROM test_policies WHERE action_id = ? '.$this->database->forShare(), [$actionId]);
        if (null === $row) {
            throw new ApprovalException('unknown_policy', 'No configured policy.');
        }

        return new ApprovalPolicy($actionId, (string) $row['version'], (string) $row['statement'], 600, (bool) $row['required']);
    }
}
