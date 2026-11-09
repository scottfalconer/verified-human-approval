<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * Optional, for history and audit views: the approval requests made about one resource, newest first. The
 * resource is the `resource` entry of the prepared action's target. The records outlive the resource itself, so
 * a deleted item's history stays readable. It is separate from ApprovalStore, so a store need not provide it.
 *
 * A host must still decide who may see which history: this returns every request for the resource in the tenant,
 * whoever made it.
 */
interface ApprovalHistory
{
    /** @return list<ApprovalState> */
    public function forResource(string $tenantId, string $resource, int $limit = 50): array;
}
