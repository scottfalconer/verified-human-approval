<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/** A verified application subject; never populate this from tool arguments. */
final readonly class Actor
{
    public function __construct(public string $userId, public string $tenantId, public ?string $delegationId = null)
    {
        foreach ([$userId, $tenantId] as $value) {
            if ('' === $value || \strlen($value) > 255) {
                throw new \InvalidArgumentException('Actor identifiers must contain 1–255 bytes.');
            }
        }
        if (null !== $delegationId && ('' === $delegationId || \strlen($delegationId) > 255)) {
            throw new \InvalidArgumentException('Invalid delegation identifier.');
        }
    }

    public function samePerson(self $other): bool
    {
        return $this->userId === $other->userId && $this->tenantId === $other->tenantId;
    }

    public function toArray(): array
    {
        return ['userId' => $this->userId, 'tenantId' => $this->tenantId, 'delegationId' => $this->delegationId];
    }

    public function key(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }

    public static function fromArray(array $data): self
    {
        return new self($data['userId'], $data['tenantId'], $data['delegationId']);
    }
}
