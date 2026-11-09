<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;
use VerifiedHumanApproval\Bundle\Verification\VerifiedApproval;

/**
 * `verified_human_approval_badge(requestId, resource, shows)` renders "Approved with a passkey" only when
 * PublicVerification says so, and nothing otherwise. `verified_human_approval(...)` returns the same check's
 * result, or null, for a page that draws its own badge.
 */
final class VerificationExtension extends AbstractExtension
{
    public function __construct(private readonly PublicVerification $verification, private readonly UrlGeneratorInterface $urls, private readonly bool $publicPage)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('verified_human_approval', $this->check(...)),
            new TwigFunction('verified_human_approval_badge', $this->badge(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
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
