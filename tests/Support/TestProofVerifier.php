<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalVerifier;
use VerifiedHumanApproval\Core\VerifiedApproval;

/** Test double only. Never register this verifier in an application container. */
final class TestProofVerifier implements ApprovalVerifier
{
    public int $verifyCalls = 0;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function requestOptions(Actor $actor, string $challenge): array
    {
        $query = $this->pdo->prepare('SELECT id FROM test_credentials WHERE user_id = ? AND tenant = ? AND active = 1');
        $query->execute([$actor->userId, $actor->tenantId]);
        $ids = $query->fetchAll(\PDO::FETCH_COLUMN);
        if ([] === $ids) {
            throw new ApprovalException('credential_unavailable', 'No accepted approval credential.');
        }

        return ['challenge' => self::encode($challenge), 'allowCredentials' => array_map(static fn (string $id): array => ['id' => $id, 'type' => 'public-key'], $ids), 'userVerification' => 'required'];
    }

    public function verify(Actor $actor, string $challenge, array $assertion): VerifiedApproval
    {
        ++$this->verifyCalls;
        if (($assertion['test_only'] ?? null) !== true
            || ($assertion['challenge'] ?? '') !== self::encode($challenge)
            || ($assertion['user'] ?? '') !== $actor->userId
            || ($assertion['tenant'] ?? '') !== $actor->tenantId) {
            throw new ApprovalException('invalid_assertion', 'Test proof does not bind this proposal and account.');
        }
        $query = $this->pdo->prepare('SELECT * FROM test_credentials WHERE id = ? AND user_id = ? AND tenant = ? AND active = 1');
        $query->execute([$assertion['credential'] ?? '', $actor->userId, $actor->tenantId]);
        $credential = $query->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($credential)) {
            throw new ApprovalException('credential_denied', 'The approval credential is no longer accepted for this person.');
        }
        $update = $this->pdo->prepare('UPDATE test_credentials SET verification_count = verification_count + 1 WHERE id = ?');
        $update->execute([$credential['id']]);

        return new VerifiedApproval((string) $credential['id'], ['test_only' => true, 'assertion' => $assertion]);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
