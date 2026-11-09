<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/**
 * What the next approval runs into. Planned transaction faults apply to the next calls of transaction(), in order.
 * A pause runs work once, inside the approval's transaction, at a named point: 'authority_checked', just after the
 * authorizer first allows the action, or 'before_commit', after everything but the commit.
 */
final class Faults
{
    /** @var list<'none'|'before_commit'|'after_commit'|'unavailable'> */
    public array $transactions = [];

    public bool $signer = false;

    /** @var array<'authority_checked'|'before_commit', \Closure(): void> */
    public array $pauses = [];

    /** @return 'none'|'before_commit'|'after_commit'|'unavailable' */
    public function nextTransaction(): string
    {
        return array_shift($this->transactions) ?? 'none';
    }

    /** @param 'authority_checked'|'before_commit' $point */
    public function reach(string $point): void
    {
        $work = $this->pauses[$point] ?? null;
        unset($this->pauses[$point]);
        if (null !== $work) {
            $work();
        }
    }
}
