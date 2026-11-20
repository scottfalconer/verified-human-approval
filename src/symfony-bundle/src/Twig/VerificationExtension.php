<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `verified_human_approval_badge(requestId, resource, shows)` renders "Approved with a passkey" only when
 * PublicVerification says so, and nothing otherwise. `verified_human_approval(...)` returns the same check's
 * result, or null, for a page that draws its own badge. Both run in VerificationRuntime.
 *
 * @internal the bundle registers it; call its Twig functions
 */
final class VerificationExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('verified_human_approval', [VerificationRuntime::class, 'check']),
            new TwigFunction('verified_human_approval_badge', [VerificationRuntime::class, 'badge'], ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }
}
