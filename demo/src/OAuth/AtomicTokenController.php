<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use League\Bundle\OAuth2ServerBundle\Controller\TokenController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** League handles the protocol; this boundary makes consuming and replacing credentials one database commit. */
final readonly class AtomicTokenController
{
    public function __construct(private TokenController $inner, private OAuthTransaction $transaction, private TokenExchangeContext $exchange)
    {
    }

    public function indexAction(Request $request): Response
    {
        $this->exchange->begin();
        try {
            return $this->transaction->run(function () use ($request): Response {
                $response = $this->inner->indexAction($request);
                $this->exchange->finish($response->isSuccessful());

                return $response;
            });
        } finally {
            $this->exchange->reset();
        }
    }
}
