<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Policy;

use Drupal\Core\Config\ConfigFactoryInterface;
use ExactApproval\Core\CanonicalJson;

final readonly class PolicyRules
{
    public function __construct(private ConfigFactoryInterface $configFactory) {}

    public function settings(): array
    {
        // Read storage afresh: a cached permission or statement must not outlive a proposal.
        $this->configFactory->reset('exact_approval.settings');
        return $this->configFactory->get('exact_approval.settings')->getRawData();
    }

    public function protects(string $operation, string $bundle): bool
    {
        foreach ($this->settings()['rules'] ?? [] as $rule) {
            if ($rule['operation'] === $operation && $rule['bundle'] === $bundle) {
                return true;
            }
        }
        return false;
    }

    /** A binding is an additional OR check. Native entity/field/transition access still applies. */
    public function permissionBindings(string $operation, string $bundle): array
    {
        $permissions = [];
        foreach ($this->settings()['rules'] ?? [] as $rule) {
            if ($rule['operation'] === $operation && $rule['bundle'] === $bundle) {
                $permissions = array_merge($permissions, $rule['permissions'] ?? []);
            }
        }
        return array_values(array_unique($permissions));
    }

    public function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode((object) $this->settings()));
    }
}

