<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Verification;

use Psr\Log\LoggerInterface;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * Decides whether a page may say that what it shows was approved with a passkey. All of these must hold:
 *
 * - the request completed, and a person approved it with their passkey (a rule letting it run does not count);
 * - it targets the resource the page names;
 * - every value the page shows equals the approved value of the same name in the frozen effect;
 * - the receipt this site signed as the change committed still verifies against the site's trusted keys, inside
 *   the window of the key that signed it, so a change approved before a key rotation keeps its badge.
 *
 * The page passes what it actually renders, so content changed later by any other path loses the badge. Nothing it
 * returns identifies an account, tenant, agent connection or passkey. Without a receipt signer nothing verifies.
 */
final readonly class PublicVerification
{
    public function __construct(private ApprovalStore $store, private ?TrustedKeys $keys, private ?LoggerInterface $logger = null)
    {
    }

    /** @param array<string, mixed> $shows what the page renders, by the names in the approved effect, such as title and body */
    public function check(?string $requestId, ?string $resource = null, array $shows = []): ?VerifiedApproval
    {
        if (null === $requestId || null === $this->keys || 1 !== preg_match('/^apr_[0-9a-f]{32}$/D', $requestId)) {
            return null;
        }
        try {
            return $this->verify($requestId, $resource, $shows, $this->keys);
        } catch (\Throwable $error) {
            // Missing, malformed or unavailable evidence must remove the badge, not the page.
            $this->logger?->warning('Approval evidence could not be verified for a public badge.', ['requestId' => $requestId, 'exception' => $error]);

            return null;
        }
    }

    /** @param array<string, mixed> $shows */
    private function verify(string $requestId, ?string $resource, array $shows, TrustedKeys $keys): ?VerifiedApproval
    {
        $state = $this->store->get($requestId);
        if (null === $state || 'completed' !== $state->status || null === $state->proof || null === $state->execution
            || 'user_verified' !== ($state->execution['approval'] ?? null) || null === $state->receipt) {
            return null;
        }
        $action = $state->request->prepared();
        if (null !== $resource && (string) ($action->target['resource'] ?? '') !== $resource) {
            return null;
        }
        foreach ($shows as $name => $value) {
            if (!\array_key_exists($name, $action->effect) || CanonicalJson::encode([$action->effect[$name]]) !== CanonicalJson::encode([$value])) {
                return null;
            }
        }
        $verified = (new ReceiptVerifier())->verify((new EvidenceExporter())->export($state), $keys);
        if (Receipt::COMMIT !== $verified['receiptSignedAt'] || !\is_string($state->proof['verifiedAt'] ?? null) || !\is_string($state->execution['recordedAt'] ?? null)) {
            return null;
        }

        return new VerifiedApproval(
            $requestId,
            (string) ($action->target['application'] ?? ''),
            (string) ($action->display['title'] ?? ''),
            $state->request->statement(),
            new \DateTimeImmutable($state->proof['verifiedAt']),
            new \DateTimeImmutable($state->execution['recordedAt']),
            $state->receipt['keyId'],
            $state->receipt['manifest'],
            $state->receipt['signature'],
        );
    }
}
