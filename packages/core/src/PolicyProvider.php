<?php

declare(strict_types=1);

namespace ExactApproval\Core;

interface PolicyProvider
{
    public function get(string $actionId): ApprovalPolicy;
}
