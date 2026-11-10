<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\Security\DemoUser;

/**
 * What the consent page (omouren/mcp-oauth-bundle's, with the demo's template) tells the person: what the
 * client asks to do, and what their role's rule means for its requests. Connecting never approves anything.
 */
final class ConsentExtension extends AbstractExtension
{
    public const SCOPE_DESCRIPTIONS = [
        'alert.read' => 'read public alerts',
        'alert.publish' => 'ask to issue public alerts as you',
        'approval.read' => 'read the outcome of your approvals',
    ];

    public function __construct(private readonly DemoDatabase $database, private readonly Security $security)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('consent_scopes', self::scopes(...)),
            new TwigFunction('consent_rule', $this->rule(...)),
        ];
    }

    /** @return list<string> what each requested scope lets the client do; all of them when none is named */
    public static function scopes(?string $requested): array
    {
        $names = null === $requested || '' === trim($requested) ? array_keys(self::SCOPE_DESCRIPTIONS) : (preg_split('/\s+/', trim($requested)) ?: []);

        return array_values(array_intersect_key(self::SCOPE_DESCRIPTIONS, array_flip($names)));
    }

    /** The signed-in person's rule for issuing alerts: approval, direct or denied. */
    public function rule(): ?string
    {
        $user = $this->security->getUser();
        $row = $user instanceof DemoUser ? $this->database->user($user->id) : null;

        return null === $row ? null : $this->database->roleMode('alert.publish', (string) $row['role']);
    }
}
