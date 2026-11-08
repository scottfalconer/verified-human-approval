<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/** The immutable canonical bytes, rather than mutable PHP objects, are the source of truth. */
final readonly class ApprovalRequest
{
    public function __construct(public string $id, public string $canonical, public string $digest)
    {
        $payload = CanonicalJson::map(CanonicalJson::decode($canonical));
        if (!hash_equals(hash('sha256', $canonical), $digest) || $payload['id'] !== $id || CanonicalJson::encode((object) $payload) !== $canonical) {
            throw new ApprovalException('corrupt_proposal', 'Stored approval data failed its integrity check.');
        }
    }

    public static function create(Actor $actor, PreparedAction $action, ApprovalPolicy $policy, \DateTimeImmutable $now, string $locale): self
    {
        $id = 'apr_'.bin2hex(random_bytes(16));
        $payload = [
            'format' => 'verified-human-approval/proposal/v1', 'id' => $id,
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

    public function payload(): array
    {
        return CanonicalJson::map(CanonicalJson::decode($this->canonical));
    }

    public function actor(): Actor
    {
        return Actor::fromArray(CanonicalJson::map($this->payload()['actor']));
    }

    public function prepared(): PreparedAction
    {
        return PreparedAction::fromArray(CanonicalJson::map($this->payload()['action']));
    }

    public function statement(): string
    {
        return $this->payload()['statement'];
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->payload()['expiresAt']);
    }

    public static function timestamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
