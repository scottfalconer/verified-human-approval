<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** What the next approval runs into. Planned transaction faults apply to the next calls of transaction(), in order. */
final class Faults
{
    /** @var list<'none'|'before_commit'|'after_commit'|'unavailable'> */
    public array $transactions = [];

    public bool $signer = false;

    /** @return 'none'|'before_commit'|'after_commit'|'unavailable' */
    public function nextTransaction(): string
    {
        return array_shift($this->transactions) ?? 'none';
    }
}
