<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;

/**
 * A browser that answers from a script and keeps every request it sent, so a test reads exactly what the passkey
 * panel put on the wire. It is not a kernel browser, which is the point: the panel has to work with any BrowserKit
 * browser.
 *
 * @extends AbstractBrowser<Request, Response>
 */
final class ScriptedBrowser extends AbstractBrowser
{
    /** @var list<Request> */
    public array $requests = [];

    /**
     * @param array<string, Response|\Closure(Request): Response> $script the answer to each "METHOD address"
     * @param array<string, mixed>                                $server
     */
    public function __construct(private array $script, array $server = [])
    {
        parent::__construct($server);
    }

    /** @param Response|\Closure(Request): Response $answer */
    public function script(string $request, Response|\Closure $answer): void
    {
        $this->script[$request] = $answer;
    }

    /** @param Request $request */
    protected function doRequest(object $request): Response
    {
        $this->requests[] = $request;
        $answer = $this->script[$request->getMethod().' '.$request->getUri()] ?? new Response('<html><head><title>Not Found</title></head><body>No such page.</body></html>', 404);

        return $answer instanceof \Closure ? $answer($request) : $answer;
    }
}
