<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;
use VerifiedHumanApproval\Bundle\Verification\VerifiedApproval;

/**
 * What the functions of VerificationExtension call. Twig loads it the first time a template calls one, so a page
 * without a badge never builds the approval store it reads.
 *
 * @internal the bundle registers it; call its Twig functions
 */
final readonly class VerificationRuntime implements RuntimeExtensionInterface
{
    public function __construct(private PublicVerification $verification, private UrlGeneratorInterface $urls, private bool $publicPage)
    {
    }

    /** @param array<string, mixed> $shows */
    public function check(?string $requestId, ?string $resource = null, array $shows = []): ?VerifiedApproval
    {
        return $this->verification->check($requestId, $resource, $shows);
    }

    /**
     * Markup, so it stays safe when a template assigns it to a variable; an empty string, so `{% if %}` skips it.
     *
     * @param array<string, mixed> $shows
     */
    public function badge(Environment $twig, ?string $requestId, ?string $resource = null, array $shows = []): Markup|string
    {
        $approval = $this->verification->check($requestId, $resource, $shows);
        if (null === $approval) {
            return '';
        }

        return new Markup($twig->render('@VerifiedHumanApproval/badge.html.twig', [
            'approval' => $approval,
            'link' => $this->publicPage ? $this->urls->generate('verified_human_approval_verified', ['id' => $approval->requestId]) : null,
        ]), 'UTF-8');
    }
}
