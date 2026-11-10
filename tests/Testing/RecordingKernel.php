<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * A kernel that answers from a closure and keeps what it was asked, so a test reads what the kernel client handed
 * it, and in which order.
 */
final class RecordingKernel implements HttpKernelInterface, TerminableInterface
{
    /** @var list<Request> */
    public array $handled = [];

    /** @var list<string> what happened to this kernel, in order */
    public array $log = [];

    /** @param \Closure(Request, self): Response $answer */
    public function __construct(private readonly \Closure $answer)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->handled[] = $request;
        $this->log[] = 'handle';

        return ($this->answer)($request, $this);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->log[] = 'terminate';
    }
}
