<?php

declare(strict_types=1);

namespace ExactApproval\Core;

final readonly class ApprovalPolicy
{
    public function __construct(
        public string $actionId,
        public string $version,
        public string $statementTemplate,
        public int $lifetimeSeconds = 600,
        public bool $required = true,
    ) {
        if ($actionId === '' || $version === '' || trim($statementTemplate) === '' || strlen($statementTemplate) > 8192) {
            throw new \InvalidArgumentException('An approval policy needs an action, version and non-empty statement.');
        }
        if ($lifetimeSeconds < 30 || $lifetimeSeconds > 3600) {
            throw new \InvalidArgumentException('Proposal lifetime must be 30–3600 seconds.');
        }
    }

    public function statement(PreparedAction $action): string
    {
        $resolved = preg_replace_callback('/\{\{\s*([a-zA-Z][a-zA-Z0-9_.]*)\s*\}\}/', static function (array $match) use ($action): string {
            return $action->placeholders[$match[1]] ?? throw new ApprovalException('invalid_policy', 'The approval statement contains an unknown placeholder.');
        }, $this->statementTemplate);
        if ($resolved === null || str_contains($resolved, '{{') || str_contains($resolved, '}}')) {
            throw new ApprovalException('invalid_policy', 'Only registered text placeholders are allowed in an approval statement.');
        }
        return $resolved;
    }

    public function toArray(): array
    {
        return ['actionId' => $this->actionId, 'version' => $this->version, 'statementTemplate' => $this->statementTemplate, 'lifetimeSeconds' => $this->lifetimeSeconds, 'required' => $this->required];
    }

    public function digest(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }
}
