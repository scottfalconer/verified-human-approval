<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/** The immutable canonical bytes, rather than mutable PHP objects, are the source of truth. */
final readonly class ApprovalRequest
{
    private const FORMAT = 'verified-human-approval/proposal/v1';

    public function __construct(public string $id, public string $canonical, public string $digest)
    {
        try {
            $payload = CanonicalJson::map(CanonicalJson::decode($canonical));
            if (!hash_equals(hash('sha256', $canonical), $digest) || self::FORMAT !== ($payload['format'] ?? null)
                || ($payload['id'] ?? null) !== $id || CanonicalJson::encode((object) $payload) !== $canonical) {
                throw new \UnexpectedValueException('The proposal does not match its digest, format, id or canonical form.');
            }
            // Each part is read once here, as the methods below read it, so a proposal that loads can always be read.
            self::actorIn($payload);
            self::actionIn($payload);
            self::statementIn($payload);
            self::expiryIn($payload);
            if (!\is_string($payload['policyDigest'] ?? null)) {
                throw new \UnexpectedValueException('The proposal does not name the digest of its policy.');
            }
        } catch (\Throwable $error) {
            throw new ApprovalException('corrupt_proposal', 'Stored approval data failed its integrity check.', $error);
        }
    }

    public static function create(Actor $actor, PreparedAction $action, ApprovalPolicy $policy, \DateTimeImmutable $now, string $locale): self
    {
        $id = 'apr_'.bin2hex(random_bytes(16));
        $payload = [
            'format' => self::FORMAT, 'id' => $id,
            'actor' => (object) $actor->toArray(), 'action' => (object) $action->toArray(),
            'policy' => (object) $policy->toArray(), 'policyDigest' => $policy->digest(),
            'statement' => $policy->statement($action), 'locale' => $locale,
            'createdAt' => self::timestamp($now), 'expiresAt' => self::timestamp($now->modify('+'.$policy->lifetimeSeconds.' seconds')),
        ];
        $canonical = CanonicalJson::encode($payload);
        if (\strlen($canonical) > 262144) {
            throw new ApprovalException('proposal_too_large', 'The prepared action exceeds the 256 KiB approval limit.');
        }

        return new self($id, $canonical, hash('sha256', $canonical));
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return CanonicalJson::map(CanonicalJson::decode($this->canonical));
    }

    public function actor(): Actor
    {
        return self::actorIn($this->payload());
    }

    public function prepared(): PreparedAction
    {
        return self::actionIn($this->payload());
    }

    public function statement(): string
    {
        return self::statementIn($this->payload());
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return self::expiryIn($this->payload());
    }

    public static function timestamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @param array<string, mixed> $payload */
    private static function actorIn(array $payload): Actor
    {
        return Actor::fromArray(CanonicalJson::map($payload['actor'] ?? null));
    }

    /** @param array<string, mixed> $payload */
    private static function actionIn(array $payload): PreparedAction
    {
        return PreparedAction::fromArray(CanonicalJson::map($payload['action'] ?? null));
    }

    /** @param array<string, mixed> $payload */
    private static function statementIn(array $payload): string
    {
        return \is_string($payload['statement'] ?? null) ? $payload['statement'] : throw new \UnexpectedValueException('The proposal has no statement.');
    }

    /** @param array<string, mixed> $payload */
    private static function expiryIn(array $payload): \DateTimeImmutable
    {
        return \is_string($payload['expiresAt'] ?? null) ? new \DateTimeImmutable($payload['expiresAt']) : throw new \UnexpectedValueException('The proposal has no expiry.');
    }
}
