<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use VerifiedHumanApproval\Bundle\ContentSecurityPolicy;

/**
 * verified_human_approval_csp_nonce(): the nonce the review pages' Content-Security-Policy allows on this response,
 * for an inline script or style in your layout, such as {{ importmap('app', {nonce: verified_human_approval_csp_nonce()}) }}.
 */
final class ContentSecurityPolicyExtension extends AbstractExtension
{
    public function __construct(private readonly ContentSecurityPolicy $policy, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('verified_human_approval_csp_nonce', $this->nonce(...))];
    }

    /**
     * The main request's nonce, so a fragment rendered in a sub-request gets the one in the page's header. Outside
     * a request, such as in a console command, there is no header to match: the nonce is new and allows nothing.
     */
    public function nonce(): string
    {
        $request = $this->requests->getMainRequest();

        return null === $request ? ContentSecurityPolicy::freshNonce() : $this->policy->nonce($request);
    }
}
