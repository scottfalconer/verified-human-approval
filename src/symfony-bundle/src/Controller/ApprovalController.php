<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Controller;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Bundle\ApprovalPresenter;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Bundle\ReceiptKey;
use VerifiedHumanApproval\Bundle\RequesterLabel;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;

/**
 * The review page and its JSON actions. A stranger is sent to sign in; once someone is reviewing,
 * every refusal on a JSON route is a JSON answer the page's script can show.
 */
final readonly class ApprovalController
{
    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private ApprovalPresenter $presenter,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private RateLimiterFactoryInterface $limiter,
        private ?ApprovalHandoff $handoff = null,
        private ?UrlGeneratorInterface $urls = null,
        private ?RequesterLabel $requesters = null,
        private ?EvidenceExporter $receipts = null,
        private ?ReceiptKey $receiptKey = null,
        // What the pages call the agent's app when they send the person back to it.
        private string $agentName = 'Claude',
    ) {
    }

    public function review(Request $request, string $id): Response
    {
        try {
            $state = $this->approvals->get($this->reviewer($request, $id), $id);
        } catch (ApprovalException $error) {
            // The person's own record, damaged, or one too damaged to show whose it is: "not found" would read
            // as "nothing happened".
            if ('record_integrity_error' === $error->reason) {
                return new Response($this->twig->render('@VerifiedHumanApproval/integrity_error.html.twig', ['id' => $id]), 409);
            }
            throw new NotFoundHttpException('This request is unavailable for your account.');
        }

        return new Response($this->twig->render('@VerifiedHumanApproval/review.html.twig', [
            'state' => $state,
            'proposal' => $state->request->payload(),
            'prepared' => $state->request->prepared(),
            'csrf' => $this->csrf->getToken('verified_human_approval.'.$id)->getValue(),
            // Who asked: an agent's request should not look like the person's own.
            'requested_by' => $this->requesters?->describe($state->request->actor()),
            'scoped' => null !== $this->handoff?->personFor($request, $id),
            // Whether this site signs receipts, so a completed request offers the person their copy.
            'receipts' => null !== $this->receipts,
            // Where a request made on this site came from, so the finished page can lead back there.
            'return_path' => self::localPath($request->query->get('return')),
            'agent_name' => $this->agentName,
        ]));
    }

    /**
     * A path on this site, or null. The page that sends someone here names where they came from, and the review
     * leads back there once the request finishes; anything that could reach another site is ignored.
     */
    private static function localPath(mixed $path): ?string
    {
        return \is_string($path) && \strlen($path) <= 2048 && 1 === preg_match('#^/(?![/\\\\])[^\s\\\\\x00-\x1f\x7f]*$#D', $path) ? $path : null;
    }

    /**
     * The person's receipt for a completed request, to keep: the exact proposal, their passkey's signature on
     * it, what ran, and this site's receipt signed as it ran. It checks offline against the published key.
     * Only for the signed-in person: a phone code opens one review to approve, not the record behind it.
     */
    public function evidence(string $id): Response
    {
        if (null === $this->receipts) {
            throw new NotFoundHttpException('This site does not sign receipts.');
        }
        try {
            $state = $this->approvals->get($this->actors->interactive(), $id);
        } catch (ApprovalException $error) {
            if ('record_integrity_error' === $error->reason) {
                return self::refusal($error->reason, $error->getMessage(), 409);
            }
            throw new NotFoundHttpException('This request is unavailable for your account.');
        }
        try {
            $receipt = $this->receipts->export($state);
        } catch (ApprovalException $error) {
            // Not run yet: nothing is signed now to stand in for a receipt.
            return self::refusal($error->reason, $error->getMessage(), 409);
        }
        // A file for a person to keep and open, so readable JSON. Layout does not matter: checking recomputes the canonical form.
        $response = new JsonResponse($receipt);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'receipt-'.$id.'.json'));

        return $response;
    }

    /** The current key that checks this site's receipts, for anyone: the one-key file for vendor/bin/verify-evidence.php. */
    public function receiptKey(): JsonResponse
    {
        if (null === $this->receiptKey) {
            throw new NotFoundHttpException('This site does not sign receipts.');
        }

        return self::publicKeys($this->receiptKey->document());
    }

    /**
     * Every key that checks this site's receipts and its signed audit log, each with the window it signed in,
     * for anyone: the key-set file for vendor/bin/verify-evidence.php, which also checks receipts signed before
     * a key rotation.
     */
    public function receiptKeys(): JsonResponse
    {
        if (null === $this->receiptKey) {
            throw new NotFoundHttpException('This site does not sign receipts.');
        }

        return self::publicKeys($this->receiptKey->keySet());
    }

    /** @param array<string, mixed> $document */
    private static function publicKeys(array $document): JsonResponse
    {
        $response = new JsonResponse($document);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    public function status(Request $request, string $id): JsonResponse
    {
        return $this->respond(fn () => $this->presenter->data($this->approvals->get($this->reviewer($request, $id), $id)));
    }

    public function options(Request $request, string $id): JsonResponse
    {
        if (($refused = $this->refuseWithoutToken($request, $id)) !== null) {
            return $refused;
        }
        if (($limited = $this->limit($this->reviewer($request, $id))) !== null) {
            return $limited;
        }

        return $this->respond(function () use ($request, $id): array {
            $ceremony = $this->approvals->begin($this->reviewer($request, $id), $id);
            // The page/request pairing is checked independently of proof validation.
            $request->getSession()->set('verified_human_approval.ceremony.'.$id, $ceremony->id);

            return ['ceremony_id' => $ceremony->id, 'publicKey' => $ceremony->options, 'expires_at' => $ceremony->expiresAt];
        }, $this->reviewer($request, $id), $id);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        if (($refused = $this->refuseWithoutToken($request, $id)) !== null) {
            return $refused;
        }
        if (($limited = $this->limit($this->reviewer($request, $id))) !== null) {
            return $limited;
        }
        $body = $this->body($request);
        if (null === $body || [] !== array_diff(array_keys($body), ['ceremony_id', 'credential'])
            || !\is_string($body['ceremony_id'] ?? null) || !\is_array($body['credential'] ?? null)) {
            return self::refusal('invalid_request', 'Completion accepts only a ceremony identifier and WebAuthn response.', 400);
        }
        $expected = $request->getSession()->get('verified_human_approval.ceremony.'.$id);
        if (!\is_string($expected) || !hash_equals($expected, $body['ceremony_id'])) {
            return self::refusal('ceremony_mismatch', 'Start a fresh approval from this request’s review page.', 403);
        }

        return $this->respond(function () use ($request, $id, $body): array {
            $actor = $this->reviewer($request, $id);
            $this->approvals->get($actor, $id); // Ownership is checked before any work.
            $request->getSession()->remove('verified_human_approval.ceremony.'.$id);

            return $this->presenter->data($this->approvals->complete($actor, $body['ceremony_id'], $body['credential']));
        }, $this->reviewer($request, $id), $id);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        if (($refused = $this->refuseWithoutToken($request, $id)) !== null) {
            return $refused;
        }
        // Cancelling needs the signed-in person. A phone code opens one review to approve with a passkey;
        // whoever scans it must not be able to call the request off.
        try {
            $person = $this->actors->interactive();
        } catch (AccessDeniedException) {
            return self::refusal('sign_in_required', 'Cancel this request from your signed-in browser.', 403);
        }

        return $this->respond(fn () => $this->presenter->data($this->approvals->cancel($person, $id)), $person, $id);
    }

    /**
     * A one-time phone link, step one: a page with a Continue button. Opening the link does not use it,
     * so a link previewer or scanner fetching the URL cannot burn the code before the person does.
     */
    public function handoff(Request $request, string $token): Response
    {
        if (null === $this->handoff || !$this->handoff->peek($token)) {
            return $this->handoffExpired();
        }

        return new Response($this->twig->render('@VerifiedHumanApproval/handoff_continue.html.twig', [
            'csrf' => $this->csrf->getToken('verified_human_approval.handoff')->getValue(),
            'agent_name' => $this->agentName,
        ]));
    }

    /** Step two: redeems the code once, scopes this browser session to its request, then opens the review. */
    public function handoffContinue(Request $request, string $token): Response
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('verified_human_approval.handoff', (string) $request->request->get('_token', '')))) {
            return $this->handoffExpired();
        }
        $id = $this->handoff?->redeem($token, $request->getSession());
        if (null === $id) {
            return $this->handoffExpired();
        }

        $review = $this->urls?->generate('verified_human_approval_review', ['id' => $id]) ?? '/approval/'.rawurlencode($id);

        return new RedirectResponse($review.'#return=handoff', 303);
    }

    /** A phone code that was used, expired or never existed: the person is told to show a new one. */
    private function handoffExpired(): Response
    {
        return new Response($this->twig->render('@VerifiedHumanApproval/handoff_expired.html.twig', ['agent_name' => $this->agentName]), 410);
    }

    /** The person reviewing: a phone session scoped to this request, or the signed-in person. */
    private function reviewer(Request $request, string $id): Actor
    {
        return $this->handoff?->personFor($request, $id) ?? $this->actors->interactive();
    }

    /**
     * Someone must be reviewing, or they are sent to sign in, and the page's CSRF token must match.
     * A stale token is a JSON refusal, not an HTML error page the script could not read.
     */
    private function refuseWithoutToken(Request $request, string $id): ?JsonResponse
    {
        $this->reviewer($request, $id);
        if ($this->csrf->isTokenValid(new CsrfToken('verified_human_approval.'.$id, (string) $request->headers->get('X-CSRF-Token', '')))) {
            return null;
        }

        return self::refusal('csrf_invalid', 'This page expired. Reload it, then approve again.', 403);
    }

    /** @return array<string, mixed>|null the JSON object sent, or null when it is not one */
    private function body(Request $request): ?array
    {
        if (\strlen($request->getContent()) > 65_536) {
            return null;
        }
        try {
            $body = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($body) && !array_is_list($body) ? $body : null;
    }

    /**
     * A refusal about one request also says whether that request is still open (`request_status`), so the
     * page shows a finished or stopped request as it is instead of a button that can no longer work.
     */
    private function respond(callable $operation, ?Actor $reviewer = null, ?string $id = null): JsonResponse
    {
        try {
            return new JsonResponse($operation());
        } catch (ApprovalException $error) {
            $refusal = self::refused($error->reason, $error->getMessage());
            if (null !== $reviewer && null !== $id) {
                // A record that fails its integrity check has no status to report. Naming that as the request's
                // state makes the page reload, and the reloaded page says the outcome cannot be confirmed.
                $current = 'record_integrity_error' === $error->reason ? 'integrity_error' : $this->currentStatus($reviewer, $id);
                if (null !== $current) {
                    $refusal['request_status'] = $current;
                }
            }

            return new JsonResponse($refusal, 409);
        }
    }

    private function currentStatus(Actor $reviewer, string $id): ?string
    {
        try {
            return $this->approvals->get($reviewer, $id)->status;
        } catch (\Throwable) {
            return null; // Unknown here too: the page shows the refusal and leaves the next step to the person.
        }
    }

    private static function refusal(string $reason, string $message, int $status): JsonResponse
    {
        return new JsonResponse(self::refused($reason, $message), $status);
    }

    /**
     * What a JSON route answers when the application would not do something. A stored record that fails its
     * integrity check is not a refusal: corrupt state is not evidence that nothing happened, so it is reported
     * as `integrity_error` and never as `rejected`.
     *
     * @return array{status: string, reason: string, message: string}
     */
    private static function refused(string $reason, string $message): array
    {
        return ['status' => 'record_integrity_error' === $reason ? 'integrity_error' : 'rejected', 'reason' => $reason, 'message' => $message];
    }

    private function limit(Actor $actor): ?JsonResponse
    {
        // One account budget covers all proposal IDs, so changing the URL cannot reset it.
        $limit = $this->limiter->create(hash('sha256', $actor->tenantId."\0".$actor->userId))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return new JsonResponse([
            'status' => 'rate_limited', 'message' => 'Too many approval attempts. Wait a minute before starting a fresh review.',
        ], 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
    }
}
