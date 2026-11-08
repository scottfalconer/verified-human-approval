<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class PreparedAction
{
    /**
     * @param array<string, mixed> $target        what the action acts on: application, environment, tenant, resource
     * @param array<string, mixed> $effect        the exact change
     * @param array<string, mixed> $preconditions what must still hold when it executes
     * @param array<string, mixed> $display       what the person reads on the review page
     * @param array<mixed>         $placeholders  statement placeholders: simple names to string values
     */
    public function __construct(
        public string $actionId,
        public string $version,
        public array $target,
        public array $effect,
        public array $preconditions,
        public array $display,
        public array $placeholders = [],
    ) {
        if ('' === $actionId || '' === $version || [] === $target || [] === $display) {
            throw new \InvalidArgumentException('An action needs an identity, version, target and human-readable display.');
        }
        foreach ($placeholders as $name => $value) {
            if (!\is_string($name) || !\is_string($value) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.]*$/D', $name)) {
                throw new \InvalidArgumentException('Statement placeholders must have simple names and string values.');
            }
        }
        CanonicalJson::encode($this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actionId' => $this->actionId, 'version' => $this->version,
            'target' => (object) $this->target, 'effect' => (object) $this->effect,
            'preconditions' => (object) $this->preconditions, 'display' => (object) $this->display,
            'placeholders' => (object) $this->placeholders,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['actionId'], $data['version'], CanonicalJson::map($data['target']), CanonicalJson::map($data['effect']), CanonicalJson::map($data['preconditions']), CanonicalJson::map($data['display']), CanonicalJson::map($data['placeholders']));
    }
}
