<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Handoff;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Approve on your phone": a one-time link, shown as a QR code, that opens one pending request's
 * review on another device for the same person. It is not a sign-in. The scoped session can only
 * review and approve that one request, so it cannot enroll a passkey or reach anything else, and
 * approving still takes the person's own passkey.
 */
final readonly class ApprovalHandoff
{
    private const LINK_SECONDS = 120;
    private const SESSION_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private UrlGeneratorInterface $urls,
        private string $publicOrigin,
        // Makes redemption single use even when two devices fetch the link at the same moment.
        private ?LockFactory $locks = null,
    ) {}

    /** A phone can only open the site at a public HTTPS address, and passkeys need HTTPS there too. */
    public function reachable(): bool
    {
        return str_starts_with($this->publicOrigin, 'https://');
    }

    /** @return array{url: string, expiresAt: int, expiresIn: int} */
    public function issue(Actor $person, ApprovalState $state): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = time() + self::LINK_SECONDS;
        $item = $this->cache->getItem(self::key($token));
        $item->set(['request' => $state->request->id, 'userId' => $person->userId, 'tenantId' => $person->tenantId, 'expires' => $expires]);
        $item->expiresAfter(self::LINK_SECONDS);
        $this->cache->save($item);

        return ['url' => rtrim($this->publicOrigin, '/').$this->urls->generate('verified_human_approval_handoff', ['token' => $token]), 'expiresAt' => $expires, 'expiresIn' => self::LINK_SECONDS];
    }

    /**
     * The link as QR modules, one string of 0 and 1 per row with the quiet zone included. The card
     * draws these itself, so it never has to trust markup from anywhere.
     *
     * @return list<string>
     */
    public static function modules(string $url): array
    {
        $matrix = (new QRCode(new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => true])))->addByteSegment($url)->getQRMatrix();

        return array_values(array_map(static fn (array $row): string => implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $row)), $matrix->getMatrix(true)));
    }

    /** Whether a link is still usable, without using it. */
    public function peek(string $token): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) return false;
        $item = $this->cache->getItem(self::key($token));
        $data = $item->isHit() ? $item->get() : null;

        return \is_array($data) && ($data['expires'] ?? 0) >= time();
    }

    /** Redeems a link once and scopes this browser session to its request. Returns that request's ID. */
    public function redeem(string $token, SessionInterface $session): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) return null;
        $lock = $this->locks?->createLock(self::key($token), 10);
        if ($lock !== null && !$lock->acquire()) return null;
        try {
            $item = $this->cache->getItem(self::key($token));
            if (!$item->isHit()) return null;
            $data = $item->get();
            $this->cache->deleteItem(self::key($token));
        } finally {
            $lock?->release();
        }
        if (!\is_array($data) || ($data['expires'] ?? 0) < time()) return null;
        $session->migrate(true);
        $session->set('verified_human_approval.handoff.'.$data['request'], ['userId' => $data['userId'], 'tenantId' => $data['tenantId'], 'expires' => time() + self::SESSION_SECONDS]);

        return $data['request'];
    }

    /** The person a scoped session may act as, for this one request; never delegated. */
    public function personFor(Request $request, string $requestId): ?Actor
    {
        // Never starts a session just to look: only a browser that already has one can hold a scoped review.
        if (!$request->hasSession() || (!$request->getSession()->isStarted() && !$request->hasPreviousSession())) return null;
        $data = $request->getSession()->get('verified_human_approval.handoff.'.$requestId);
        if (!\is_array($data) || ($data['expires'] ?? 0) < time()) return null;

        return new Actor((string) $data['userId'], (string) $data['tenantId']);
    }

    private static function key(string $token): string
    {
        return 'verified_human_approval_handoff_'.hash('sha256', $token);
    }
}
