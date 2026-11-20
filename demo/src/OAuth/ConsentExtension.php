<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\Security\DemoUser;

/**
 * What the consent page tells the person: what the client asks to do, and what their role's rule means for its
 * requests. Connecting never approves anything.
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

    /**
     * @param list<string> $scopes
     *
     * @return list<string> what each of these scopes lets the client do
     */
    public static function scopes(array $scopes): array
    {
        // A scope the page cannot describe is never left off it: the person would allow more than they were told.
        return array_map(static fn (string $scope): string => self::SCOPE_DESCRIPTIONS[$scope] ?? throw new \LogicException('The consent page has no description of the scope "'.$scope.'".'), $scopes);
    }

    /** The signed-in person's rule for issuing alerts: approval, direct or denied. */
    public function rule(): ?string
    {
        $user = $this->security->getUser();
        $row = $user instanceof DemoUser ? $this->database->user($user->id) : null;

        return null === $row ? null : $this->database->roleMode('alert.publish', (string) $row['role']);
    }
}
