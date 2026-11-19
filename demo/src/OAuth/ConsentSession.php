<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Where an authorization request waits for the person's answer: in their session, one request at a time. A newer
 * request takes the place of the one waiting, so a consent page left open never answers a request it did not show.
 * An answer is used once, by the request it answered, within LIFETIME_SECONDS of the question.
 */
final readonly class ConsentSession
{
    public const LIFETIME_SECONDS = 600;
    private const KEY = 'demo_oauth_consent';

    public function __construct(#[Autowire(service: 'verified_human_approval.clock')] private ClockInterface $clock)
    {
    }

    /** Puts this request to the person. The same request asking again keeps its page. */
    public function ask(SessionInterface $session, ConsentRequest $request): void
    {
        if ($this->waiting($session)?->binding !== $request->binding) {
            $session->set(self::KEY, ['request' => $request, 'until' => $this->now() + self::LIFETIME_SECONDS, 'allowed' => null]);
        }
    }

    /** The request waiting for the person's answer, if there is one. */
    public function waiting(SessionInterface $session): ?ConsentRequest
    {
        $entry = $this->entry($session);

        return null === $entry || null !== $entry['allowed'] ? null : $entry['request'];
    }

    /**
     * Records the person's Allow for the request their page showed. A second click answers the same way.
     *
     * @return string|null the authorization to resume, or null when that request no longer waits or was refused
     */
    public function allow(SessionInterface $session, string $id): ?string
    {
        return $this->answer($session, $id, true);
    }

    /**
     * Records the person's Don't allow for the request their page showed. It stands: it replaces an Allow the request
     * has not used yet, and no Allow replaces it.
     *
     * @return string|null the authorization to resume, which then goes back to the client refused, or null when that
     *                     request no longer waits
     */
    public function deny(SessionInterface $session, string $id): ?string
    {
        return $this->answer($session, $id, false);
    }

    /**
     * Uses up the person's answer to exactly this request.
     *
     * @return bool|null true for Allow, false for Don't allow, or null when they have not answered this request
     */
    public function take(SessionInterface $session, string $binding): ?bool
    {
        $entry = $this->entry($session);
        if (null === $entry || null === $entry['allowed'] || !hash_equals($entry['request']->binding, $binding)) {
            return null;
        }
        $session->remove(self::KEY);

        return $entry['allowed'];
    }

    private function answer(SessionInterface $session, string $id, bool $allowed): ?string
    {
        $entry = $this->entry($session);
        // A refusal stands: no Allow replaces it.
        if (null === $entry || !hash_equals($entry['request']->id, $id) || ($allowed && false === $entry['allowed'])) {
            return null;
        }
        $session->set(self::KEY, ['allowed' => $allowed] + $entry);

        return $entry['request']->resumeUri;
    }

    /** @return array{request: ConsentRequest, until: int, allowed: bool|null}|null */
    private function entry(SessionInterface $session): ?array
    {
        $entry = $session->get(self::KEY);
        if (!\is_array($entry) || !($entry['request'] ?? null) instanceof ConsentRequest || !\is_int($entry['until'] ?? null)
            || !\array_key_exists('allowed', $entry) || !\in_array($entry['allowed'], [null, true, false], true)) {
            return null;
        }

        return $entry['until'] > $this->now() ? ['request' => $entry['request'], 'until' => $entry['until'], 'allowed' => $entry['allowed']] : null;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
