<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Security;

use ExactApproval\Core\Actor;
use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Symfony\RequesterLabel;

/** Names the agent connection behind a request, so an agent's request never looks like the person's own. */
final readonly class DemoRequesterLabel implements RequesterLabel
{
    public function __construct(private DemoDatabase $database) {}

    /** For the person themselves, on the approval sheet. */
    public function describe(Actor $requester): ?string
    {
        if ($requester->delegationId === null) {
            return null;
        }
        $agent = $this->agent($requester->delegationId);

        return $agent === null ? 'an agent connection that no longer exists' : ($agent === 'personal token' ? 'an agent using your personal token' : $agent.', an agent you connected to your account');
    }

    /** For an administrator: how the request reached the application. */
    public function connection(Actor $requester): string
    {
        if ($requester->delegationId === null) {
            return 'the site itself, signed in';
        }
        $agent = $this->agent($requester->delegationId);

        return $agent === null ? 'an agent connection that no longer exists' : ($agent === 'personal token' ? 'an agent with their personal token' : $agent.', an agent they connected');
    }

    private function agent(string $grantId): ?string
    {
        $grant = $this->database->grant($grantId);
        if ($grant === null) {
            return null;
        }
        if ($grant['client_id'] === null) {
            return 'personal token';
        }
        $name = \is_string($grant['client_name']) && $grant['client_name'] !== '' ? $grant['client_name'] : 'an MCP client';
        $host = str_starts_with((string) $grant['client_id'], 'https://') ? parse_url((string) $grant['client_id'], PHP_URL_HOST) : null;

        return $name.(\is_string($host) ? ' ('.$host.')' : '');
    }
}
