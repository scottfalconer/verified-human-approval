<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

use Psr\Clock\ClockInterface;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\Receipt;

/** Request -> frozen review -> user verification -> one local atomic execution. */
final class ApprovalService
{
    private readonly object $executionOwner;

    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly PolicyProvider $policies,
        private readonly Authorizer $authorizer,
        private readonly ApprovalStore $store,
        private readonly ApprovalVerifier $verifier,
        private readonly ClockInterface $clock,
        private readonly ExecutionGuard $guard,
        // Signs each execution's receipt inside the completing transaction. Without it, receipts are
        // signed only when evidence is exported, which cannot show edits made in between.
        private readonly ?EvidenceSigner $receipts = null,
    ) {
        $this->executionOwner = new \stdClass();
        $guard->bind($this->executionOwner);
    }

    /** @param array<string, mixed> $input */
    public function request(Actor $actor, string $actionId, array $input, ?string $idempotencyKey = null, string $locale = 'en'): ApprovalState
    {
        if (null !== $idempotencyKey && ('' === $idempotencyKey || \strlen($idempotencyKey) > 128)) {
            throw new ApprovalException('invalid_idempotency_key', 'A request key must contain 1–128 bytes.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $locale)) {
            throw new ApprovalException('invalid_locale', 'Invalid approval locale.');
        }
        $inputJson = CanonicalJson::encode((object) $input);
        if (\strlen($inputJson) > 262144) {
            throw new ApprovalException('input_too_large', 'The action input exceeds 256 KiB.');
        }
        $fingerprint = hash('sha256', CanonicalJson::encode([$actor->toArray(), $actionId, (object) $input, $locale]));

        return $this->store->transaction(function () use ($actor, $actionId, $input, $idempotencyKey, $locale, $fingerprint): ApprovalState {
            if (null !== $idempotencyKey && ($existing = $this->store->findByKey($actor, $idempotencyKey)) !== null) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new ApprovalException('idempotency_conflict', 'This request key already belongs to different arguments. Use a new key for a new action.');
                }
                $this->assertOwner($actor, $existing['state']);
                $this->assertSameContent($existing['state'], $actor, $actionId, $input);

                return $this->expire($existing['state']);
            }
            // A retry without a key (a client re-sending the call, or MCP re-entering the handler
            // after an elicitation) gets the same pending proposal instead of a second review URL.
            if (null === $idempotencyKey && ($pending = $this->store->findPending($actor, $fingerprint)) !== null) {
                $this->assertOwner($actor, $pending);
                $pending = $this->eligible($pending);
                if ('pending' === $pending->status) {
                    return $pending;
                }
                // The earlier proposal expired or lost eligibility, so a fresh one is prepared below.
            }
            $adapter = $this->actions->get($actionId);
            $prepared = $adapter->prepare($actor, $input);
            if ($prepared->actionId !== $adapter->id() || $prepared->version !== $adapter->version()) {
                throw new \LogicException('The prepared action must match its registered adapter.');
            }
            $this->authorizer->assertAllowed($actor, $prepared);
            $adapter->assertPreconditions($actor, $prepared);
            $policy = $this->policies->get($actionId, $actor);
            if ($policy->actionId !== $actionId) {
                throw new \LogicException('The policy belongs to a different action.');
            }
            $request = ApprovalRequest::create($actor, $prepared, $policy, $this->clock->now(), $locale);
            $state = new ApprovalState($request);
            $this->store->insert($state, $idempotencyKey, $fingerprint);

            // Disabling protection is an administrator decision, not a client-supplied flag.
            // Older pending approvals never enter this path and are invalidated on policy change.
            return $policy->required ? $state : $this->execute($state, null);
        });
    }

    public function get(Actor $actor, string $requestId): ApprovalState
    {
        return $this->store->transaction(fn (): ApprovalState => $this->expire($this->owned($actor, $requestId)));
    }

    public function begin(Actor $reviewer, string $requestId): Ceremony
    {
        $result = $this->store->transaction(function () use ($reviewer, $requestId): Ceremony|ApprovalException {
            $state = $this->eligible($this->owned($reviewer, $requestId));
            if ('pending' !== $state->status) {
                return new ApprovalException($state->reason ?? $state->status, 'This proposal is no longer eligible for approval. Request a new action if needed.');
            }
            $nonce = random_bytes(32);
            $challenge = ChallengeBinding::challenge($state->request->digest, $nonce);
            $expires = min($state->request->expiresAt(), $this->clock->now()->modify('+5 minutes'));
            $ceremony = new Ceremony('cer_'.bin2hex(random_bytes(16)), $requestId, ChallengeBinding::encode($nonce), ChallengeBinding::encode($challenge), ApprovalRequest::timestamp($expires), $this->verifier->requestOptions($state->request->actor(), $challenge));
            $this->store->putCeremony($ceremony);

            return $ceremony;
        });
        if ($result instanceof ApprovalException) {
            throw $result;
        }

        return $result;
    }

    /** @param array<string, mixed> $assertion the browser's WebAuthn response */
    public function complete(Actor $reviewer, string $ceremonyId, array $assertion): ApprovalState
    {
        if (\strlen(CanonicalJson::encode((object) $assertion)) > 65536) {
            throw new ApprovalException('assertion_too_large', 'The passkey response exceeds the allowed size.');
        }
        $executionStarted = false;
        try {
            $result = $this->store->transaction(function () use ($reviewer, $ceremonyId, $assertion, &$executionStarted): ApprovalState|ApprovalException {
                $ceremony = $this->store->getCeremony($ceremonyId) ?? throw new ApprovalException('not_found', 'The approval request was not found.');
                $state = $this->owned($reviewer, $ceremony->requestId);
                // Ownership is checked before terminal/idempotent responses, including completed results.
                if ('pending' !== $state->status) {
                    return $state;
                }
                $state = $this->eligible($state);
                if ('pending' !== $state->status) {
                    $this->store->retireCeremony($ceremonyId);

                    return $state;
                }
                if ($ceremony->consumed) {
                    return new ApprovalException('ceremony_consumed', 'Start a fresh approval ceremony.');
                }
                if ($this->clock->now() >= new \DateTimeImmutable($ceremony->expiresAt)) {
                    $this->store->retireCeremony($ceremonyId);

                    return new ApprovalException('ceremony_expired', 'The passkey ceremony expired. Start a fresh review.');
                }
                $challenge = ChallengeBinding::challenge($state->request->digest, ChallengeBinding::decode($ceremony->nonce));
                if (!hash_equals($ceremony->challenge, ChallengeBinding::encode($challenge))) {
                    throw new ApprovalException('corrupt_ceremony', 'The stored approval binding is invalid.');
                }
                $verified = $this->verifier->verify($state->request->actor(), $challenge, $assertion);
                $proof = [
                    'format' => 'verified-human-approval/proof/v1', 'proposalDigest' => $state->request->digest,
                    'nonce' => $ceremony->nonce, 'challenge' => $ceremony->challenge,
                    'credentialId' => $verified->credentialId, 'verifiedAt' => ApprovalRequest::timestamp($this->clock->now()),
                    'assertion' => (object) $assertion, 'verification' => (object) $verified->evidence,
                ];
                $executionStarted = true;
                $completed = $this->execute($state, $proof);
                $this->store->retireCeremony($ceremonyId);

                return $completed;
            });
        } catch (\Throwable $error) {
            if (!$executionStarted) {
                throw $error;
            }
            // Read after rollback/ambiguous commit before ever deciding whether another attempt is safe.
            try {
                $recovered = $this->store->transaction(function () use ($reviewer, $ceremonyId): ?ApprovalState {
                    $ceremony = $this->store->getCeremony($ceremonyId);
                    if (null === $ceremony) {
                        return null;
                    }
                    $state = $this->owned($reviewer, $ceremony->requestId);
                    if ('completed' === $state->status) {
                        return $state;
                    }
                    $this->store->retireCeremony($ceremonyId);

                    return null;
                });
            } catch (\Throwable $recoveryError) {
                throw new ApprovalException('execution_uncertain', 'The execution outcome could not be confirmed. Read its status before taking further action.', $recoveryError);
            }
            if (null !== $recovered) {
                return $recovered;
            }
            throw new ApprovalException('execution_failed', 'The local action was not committed. Start a fresh approval ceremony before retrying.', $error);
        }
        if ($result instanceof ApprovalException) {
            throw $result;
        }

        return $result;
    }

    public function cancel(Actor $reviewer, string $requestId): ApprovalState
    {
        return $this->store->transaction(function () use ($reviewer, $requestId): ApprovalState {
            $state = $this->expire($this->owned($reviewer, $requestId));
            if ('pending' !== $state->status) {
                return $state;
            }
            $cancelled = $state->stopped('cancelled', 'cancelled_by_user');
            $this->store->save($cancelled);

            return $cancelled;
        });
    }

    /** @param array<string, mixed>|null $proof */
    private function execute(ApprovalState $state, ?array $proof): ApprovalState
    {
        $request = $state->request;
        $actor = $request->actor();
        $action = $request->prepared();
        $adapter = $this->actions->get($action->actionId);
        $frozenAction = CanonicalJson::encode((object) $action->toArray());
        $this->assertCurrent($request);
        $adapter->assertPreconditions($actor, $action);
        $this->assertUnchanged($action, $frozenAction);
        $result = $this->guard->within($this->executionOwner, $actor, $action, function () use ($actor, $action, $adapter, $request, $frozenAction): array {
            $result = $adapter->execute($actor, $action);
            $this->assertUnchanged($action, $frozenAction);
            // Postconditions compare against a fresh read of immutable stored bytes, not an object a hook touched.
            $baseline = $request->prepared();
            $adapter->assertPostconditions($actor, $baseline, $result);
            $this->assertUnchanged($baseline, $frozenAction);

            return $result;
        });
        $this->assertCurrent($request);
        $execution = [
            'format' => 'verified-human-approval/execution/v1', 'requestId' => $request->id, 'proposalDigest' => $request->digest,
            'actor' => (object) $actor->toArray(), 'actionId' => $action->actionId, 'actionVersion' => $action->version,
            'target' => (object) $action->target, 'result' => (object) $result,
            'recordedAt' => ApprovalRequest::timestamp($this->clock->now()),
            'approval' => null === $proof ? 'not_required' : 'user_verified',
        ];
        // Signed before commit: if signing fails, nothing executes, so no action goes unrecorded.
        $receipt = null === $this->receipts ? null : Receipt::sign($this->receipts, $request->digest, $proof, $execution);
        $completed = new ApprovalState($request, 'completed', null, $result, $execution, $proof, $receipt);
        $this->store->save($completed);

        return $completed;
    }

    /** Persist invalidation outside exception rollback, so status remains authoritative. */
    private function eligible(ApprovalState $state): ApprovalState
    {
        $state = $this->expire($state);
        if ('pending' !== $state->status) {
            return $state;
        }
        try {
            $this->assertCurrent($state->request, false);
            try {
                $this->authorizer->assertAllowed($state->request->actor(), $state->request->prepared());
            } catch (ApprovalException $error) {
                $denied = $state->stopped('denied', $error->reason);
                $this->store->save($denied);

                return $denied;
            }
            $action = $state->request->prepared();
            $this->actions->get($action->actionId)->assertPreconditions($state->request->actor(), $action);

            return $state;
        } catch (ApprovalException $error) {
            $stopped = $state->stopped('invalidated', $error->reason);
            $this->store->save($stopped);

            return $stopped;
        }
    }

    private function assertCurrent(ApprovalRequest $request, bool $checkAuthority = true): void
    {
        $action = $request->prepared();
        $policy = $this->policies->get($action->actionId, $request->actor());
        if (!hash_equals($request->payload()['policyDigest'], $policy->digest()) || $request->statement() !== $policy->statement($action)) {
            throw new ApprovalException('policy_changed', 'The approval policy or statement changed.');
        }
        if ($this->actions->get($action->actionId)->version() !== $action->version) {
            throw new ApprovalException('implementation_changed', 'The action implementation changed.');
        }
        if ($this->clock->now() >= $request->expiresAt()) {
            throw new ApprovalException('expired', 'The approval request expired.');
        }
        if ($checkAuthority) {
            $this->authorizer->assertAllowed($request->actor(), $action);
        }
    }

    private function expire(ApprovalState $state): ApprovalState
    {
        if ('pending' === $state->status && $this->clock->now() >= $state->request->expiresAt()) {
            $state = $state->stopped('expired', 'expired');
            $this->store->save($state);
        }

        return $state;
    }

    private function owned(Actor $actor, string $requestId): ApprovalState
    {
        $state = $this->store->get($requestId) ?? throw new ApprovalException('not_found', 'The approval request was not found.');
        $this->assertOwner($actor, $state);

        return $state;
    }

    private function assertOwner(Actor $actor, ApprovalState $state): void
    {
        if (!$state->request->actor()->samePerson($actor)) {
            throw new ApprovalException('not_found', 'The approval request was not found.');
        }
    }

    /**
     * A request key replays its recorded outcome only while the content it named is unchanged. If the
     * resource changed since (say, a new draft), replaying "completed" would suggest the new content
     * was approved, so the caller must use a new key.
     *
     * @param array<string, mixed> $input
     */
    private function assertSameContent(ApprovalState $state, Actor $actor, string $actionId, array $input): void
    {
        try {
            $current = $this->actions->get($actionId)->prepare($actor, $input);
        } catch (ApprovalException) {
            return; // Nothing current to compare with, such as a deleted resource: the recorded outcome stands.
        }
        $recorded = $state->request->prepared();
        if (CanonicalJson::encode((object) $current->target) !== CanonicalJson::encode((object) $recorded->target)
            || CanonicalJson::encode((object) $current->effect) !== CanonicalJson::encode((object) $recorded->effect)) {
            throw new ApprovalException('idempotency_conflict', 'This request key belongs to an earlier proposal, and the content has changed since. Use a new key to request the current content.');
        }
    }

    private function assertUnchanged(PreparedAction $action, string $frozenAction): void
    {
        if (!hash_equals($frozenAction, CanonicalJson::encode((object) $action->toArray()))) {
            throw new ApprovalException('prepared_action_changed', 'An application callback changed the frozen prepared action.');
        }
    }
}
