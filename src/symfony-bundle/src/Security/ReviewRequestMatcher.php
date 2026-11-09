<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Matches this bundle's review routes, for `access_control`. They must open without a sign-in,
 * because a one-time phone code opens a review on a device that never signed in. The controller
 * then requires the signed-in person, or a browser session scoped to that one request, and its
 * actions require a CSRF token and the person's passkey. The receipt key, and the verification page
 * when enabled, are public for anyone.
 *
 *     access_control:
 *         - { request_matcher: verified_human_approval.review_request_matcher, roles: PUBLIC_ACCESS }
 *
 * It matches by route name, which the router sets before the firewall runs, so it follows the
 * bundle's routes wherever you mount them and never widens to routes of your own.
 */
final class ReviewRequestMatcher implements RequestMatcherInterface
{
    public const ROUTES = [
        'verified_human_approval_review',
        'verified_human_approval_status',
        'verified_human_approval_options',
        'verified_human_approval_complete',
        'verified_human_approval_cancel',
        'verified_human_approval_evidence',
        'verified_human_approval_verified',
        'verified_human_approval_receipt_key',
        'verified_human_approval_handoff',
        'verified_human_approval_handoff_continue',
    ];

    public function matches(Request $request): bool
    {
        return \in_array($request->attributes->get('_route'), self::ROUTES, true);
    }
}
