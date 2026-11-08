<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Flood\FloodInterface;
use Drupal\exact_approval\Security\ActorProvider;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** Human-only JSON ceremony endpoints use Drupal's session and CSRF protection. */
final readonly class HumanRequestGuard
{
    public function __construct(
        private ActorProvider $actors,
        private CsrfTokenGenerator $csrf,
        private FloodInterface $flood,
    ) {}

    public function assert(Request $request, string $purpose, bool $limit = true): Actor
    {
        $actor = self::interactive($this->actors);
        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->headers->get('X-CSRF-Token', ''), $purpose)) {
            throw new AccessDeniedHttpException('Invalid or missing request CSRF token.');
        }
        if ($limit) {
            $identifier = hash('sha256', $actor->tenantId . "\0" . $actor->userId);
            if (!$this->flood->isAllowed('exact_approval.ceremony', 30, 60, $identifier)) {
                throw new TooManyRequestsHttpException(60, 'Too many approval attempts. Wait a minute and start a fresh review.');
            }
            $this->flood->register('exact_approval.ceremony', 60, $identifier);
        }

        return $actor;
    }

    public static function interactive(ActorProvider $actors): Actor
    {
        try {
            return $actors->interactive();
        } catch (ApprovalException $error) {
            throw new AccessDeniedHttpException($error->getMessage(), $error);
        }
    }

    /** The caller may return proof, never replacement content or targets. */
    public function proofBody(Request $request): array
    {
        if (strlen($request->getContent()) > 65_536) {
            throw new BadRequestHttpException('Request is too large.');
        }
        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON response.');
        }
        if (!is_array($body) || array_is_list($body)
            || array_diff(array_keys($body), ['ceremony_id', 'credential']) !== []
            || !is_string($body['ceremony_id'] ?? null) || !is_array($body['credential'] ?? null)
            || array_is_list($body['credential'])) {
            throw new BadRequestHttpException('Completion accepts only a ceremony identifier and a WebAuthn credential response.');
        }

        return $body;
    }
}
