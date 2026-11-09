<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;

/**
 * The public page a "verified" badge links to: what was approved, when, and the receipt this site signed as the
 * change committed, checked against the published key. Off unless `public_verification` is enabled. An unknown
 * request and one that does not verify look the same, so the page reveals nothing about other requests.
 */
final readonly class VerificationController
{
    public function __construct(private PublicVerification $verification, private Environment $twig, private bool $enabled)
    {
    }

    public function verified(string $id): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException();
        }
        $approval = $this->verification->check($id);

        return new Response($this->twig->render('@VerifiedHumanApproval/verified.html.twig', ['approval' => $approval]), null === $approval ? 404 : 200);
    }
}
