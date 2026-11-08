<?php

declare(strict_types=1);

namespace ExactApproval\Demo\OAuth;

/** An OAuth 2.1 error response: an RFC 6749 error code and a description. */
final class OAuthError extends \RuntimeException
{
    public function __construct(public readonly string $error, string $description)
    {
        parent::__construct($description);
    }
}
