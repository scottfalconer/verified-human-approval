<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final class ApprovalException extends \RuntimeException
{
    /**
     * @param ApprovalRequest|null $request the request of a stored record that cannot be read back, while its proposal
     *                                      still does: it shows whose record failed, so only that person is told
     */
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null, public readonly ?ApprovalRequest $request = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
