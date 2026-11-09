<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts\Fixture;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalVerifier;
use VerifiedHumanApproval\Core\VerifiedApproval;

/** Test double only. Never register this verifier in an application container. */
final class TestProofVerifier implements ApprovalVerifier
{
    public int $verifyCalls = 0;

    private readonly FixtureDatabase $database;

    public function __construct(\PDO|FixtureDatabase $database)
    {
        $this->database = $database instanceof \PDO ? new PdoFixtureDatabase($database) : $database;
    }

    public function requestOptions(Actor $actor, string $challenge): array
    {
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $this->database->rows('SELECT id FROM test_credentials WHERE user_id = ? AND tenant = ? AND active = 1 ORDER BY id', [$actor->userId, $actor->tenantId]));
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
        // Locked for writing, as the counter below is: a revocation either commits first and is seen, or waits.
        $credential = $this->database->row('SELECT * FROM test_credentials WHERE id = ? AND user_id = ? AND tenant = ? AND active = 1 '.$this->database->forUpdate(), [$assertion['credential'] ?? '', $actor->userId, $actor->tenantId]);
        if (null === $credential) {
            throw new ApprovalException('credential_denied', 'The approval credential is no longer accepted for this person.');
        }
        $this->database->execute('UPDATE test_credentials SET verification_count = verification_count + 1 WHERE id = ?', [$credential['id']]);

        return new VerifiedApproval((string) $credential['id'], ['test_only' => true, 'assertion' => $assertion]);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
