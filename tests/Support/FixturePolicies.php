<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;

final class FixturePolicies implements PolicyProvider
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        $query = $this->pdo->prepare('SELECT * FROM test_policies WHERE action_id = ?');
        $query->execute([$actionId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new ApprovalException('unknown_policy', 'No configured policy.');
        }

        return new ApprovalPolicy($actionId, (string) $row['version'], (string) $row['statement'], 600, (bool) $row['required']);
    }
}
