<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Policy;

use ExactApproval\Core\{Actor, ApprovalException, ApprovalPolicy, PolicyProvider};

final readonly class DrupalPolicyProvider implements PolicyProvider
{
    public function __construct(private PolicyRules $rules) {}

    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        $operation = match ($actionId) {
            'node.publish' => 'publish',
            'node.delete' => 'delete',
            default => throw new ApprovalException('unsupported_action', 'This Drupal operation is not registered.'),
        };
        $settings = $this->rules->settings();
        // Using an approval action always requests approval. Unprotected native actions remain native.
        return new ApprovalPolicy($actionId, $this->rules->fingerprint(), (string) $settings['statements'][$operation], (int) $settings['lifetime'], true);
    }
}

