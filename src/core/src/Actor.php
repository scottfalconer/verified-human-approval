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

    /** @return array{userId: string, tenantId: string, delegationId: ?string} */
    public function toArray(): array
    {
        return ['userId' => $this->userId, 'tenantId' => $this->tenantId, 'delegationId' => $this->delegationId];
    }

    public function key(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }

    /**
     * The actor as toArray() wrote it, all three keys present.
     *
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when the data is not an actor's
     */
    public static function fromArray(array $data): self
    {
        if (!\is_string($data['userId'] ?? null) || !\is_string($data['tenantId'] ?? null)
            || !\array_key_exists('delegationId', $data) || (null !== $data['delegationId'] && !\is_string($data['delegationId']))) {
            throw new \UnexpectedValueException('An actor needs a user id and a tenant id, as strings, and a delegation id that is a string or null.');
        }

        return new self($data['userId'], $data['tenantId'], $data['delegationId']);
    }
}
