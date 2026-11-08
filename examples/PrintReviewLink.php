<?php

declare(strict_types=1);

use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

/** Shows a URL-mode prompt's review link and dismisses the prompt; the request stays open on the site. */
final class PrintReviewLink implements ElicitationCallbackInterface
{
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        fwrite(\STDERR, $request->message."\nReview and approve: ".($request->url ?? '(no link)')."\n");

        return new ElicitResult(ElicitAction::Cancel);
    }
}
