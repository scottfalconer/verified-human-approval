<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class PreparedAction
{
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

    public function toArray(): array
    {
        return [
            'actionId' => $this->actionId, 'version' => $this->version,
            'target' => (object) $this->target, 'effect' => (object) $this->effect,
            'preconditions' => (object) $this->preconditions, 'display' => (object) $this->display,
            'placeholders' => (object) $this->placeholders,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['actionId'], $data['version'], CanonicalJson::map($data['target']), CanonicalJson::map($data['effect']), CanonicalJson::map($data['preconditions']), CanonicalJson::map($data['display']), CanonicalJson::map($data['placeholders']));
    }
}
