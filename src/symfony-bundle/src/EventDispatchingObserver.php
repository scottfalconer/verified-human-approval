<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Psr\EventDispatcher\EventDispatcherInterface;
use VerifiedHumanApproval\Bundle\Event\ApprovalCompleted;
use VerifiedHumanApproval\Core\ApprovalObserver;
use VerifiedHumanApproval\Core\ApprovalState;

/** Turns the core's post-commit notice into a Symfony event, so an application only adds a listener. */
final readonly class EventDispatchingObserver implements ApprovalObserver
{
    public function __construct(private EventDispatcherInterface $events)
    {
    }

    public function completed(ApprovalState $state): void
    {
        $this->events->dispatch(new ApprovalCompleted($state));
    }
}
