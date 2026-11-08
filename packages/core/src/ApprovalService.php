<?php

declare(strict_types=1);

namespace ExactApproval\Core;

use Psr\Clock\ClockInterface;

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
    ) {
        $this->executionOwner = new \stdClass();
        $guard->bind($this->executionOwner);
    }

    public function request(Actor $actor, string $actionId, array $input, ?string $idempotencyKey = null, string $locale = 'en'): ApprovalState
    {
        if ($idempotencyKey !== null && (strlen($idempotencyKey) < 1 || strlen($idempotencyKey) > 128)) {
            throw new ApprovalException('invalid_idempotency_key', 'A request key must contain 1–128 bytes.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $locale)) {
            throw new ApprovalException('invalid_locale', 'Invalid approval locale.');
        }
        $inputJson = CanonicalJson::encode((object) $input);
        if (strlen($inputJson) > 262144) {
            throw new ApprovalException('input_too_large', 'The action input exceeds 256 KiB.');
        }
        $fingerprint = hash('sha256', CanonicalJson::encode([$actor->toArray(), $actionId, (object) $input, $locale]));

        return $this->store->transaction(function () use ($actor, $actionId, $input, $idempotencyKey, $locale, $fingerprint): ApprovalState {
            if ($idempotencyKey !== null && ($existing = $this->store->findByKey($actor, $idempotencyKey)) !== null) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new ApprovalException('idempotency_conflict', 'This request key already belongs to different arguments. Use a new key for a new action.');
                }
                $this->assertOwner($actor, $existing['state']);
                return $this->expire($existing['state']);
            }
            $adapter = $this->actions->get($actionId);
            $prepared = $adapter->prepare($actor, $input);
            if ($prepared->actionId !== $adapter->id() || $prepared->version !== $adapter->version()) {
                throw new \LogicException('The prepared action must match its registered adapter.');
            }
            $this->authorizer->assertAllowed($actor, $prepared);
            $adapter->assertPreconditions($actor, $prepared);
            $policy = $this->policies->get($actionId);
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
            if ($state->status !== 'pending') {
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

    public function complete(Actor $reviewer, string $ceremonyId, array $assertion): ApprovalState
    {
        if (strlen(CanonicalJson::encode((object) $assertion)) > 65536) {
            throw new ApprovalException('assertion_too_large', 'The passkey response exceeds the allowed size.');
        }
        $executionStarted = false;
        try {
            $result = $this->store->transaction(function () use ($reviewer, $ceremonyId, $assertion, &$executionStarted): ApprovalState|ApprovalException {
                $ceremony = $this->store->getCeremony($ceremonyId) ?? throw new ApprovalException('not_found', 'The approval request was not found.');
                $state = $this->owned($reviewer, $ceremony->requestId);
                // Ownership is checked before terminal/idempotent responses, including completed results.
                if ($state->status !== 'pending') {
                    return $state;
                }
                $state = $this->eligible($state);
                if ($state->status !== 'pending') {
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
                    'format' => 'exact-approval/proof/v1', 'proposalDigest' => $state->request->digest,
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
                    if ($ceremony === null) {
                        return null;
                    }
                    $state = $this->owned($reviewer, $ceremony->requestId);
                    if ($state->status === 'completed') {
                        return $state;
                    }
                    $this->store->retireCeremony($ceremonyId);
                    return null;
                });
            } catch (\Throwable $recoveryError) {
                throw new ApprovalException('execution_uncertain', 'The execution outcome could not be confirmed. Read its status before taking further action.', $recoveryError);
            }
            if ($recovered !== null) {
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
            if ($state->status !== 'pending') {
                return $state;
            }
            $cancelled = $state->stopped('cancelled', 'cancelled_by_user');
            $this->store->save($cancelled);
            return $cancelled;
        });
    }

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
            'format' => 'exact-approval/execution/v1', 'requestId' => $request->id, 'proposalDigest' => $request->digest,
            'actor' => (object) $actor->toArray(), 'actionId' => $action->actionId, 'actionVersion' => $action->version,
            'target' => (object) $action->target, 'result' => (object) $result,
            'recordedAt' => ApprovalRequest::timestamp($this->clock->now()),
            'approval' => $proof === null ? 'not_required' : 'user_verified',
        ];
        $completed = new ApprovalState($request, 'completed', null, $result, $execution, $proof);
        $this->store->save($completed);
        return $completed;
    }

    /** Persist invalidation outside exception rollback, so status remains authoritative. */
    private function eligible(ApprovalState $state): ApprovalState
    {
        $state = $this->expire($state);
        if ($state->status !== 'pending') {
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
        $policy = $this->policies->get($action->actionId);
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
        if ($state->status === 'pending' && $this->clock->now() >= $state->request->expiresAt()) {
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

    private function assertUnchanged(PreparedAction $action, string $frozenAction): void
    {
        if (!hash_equals($frozenAction, CanonicalJson::encode((object) $action->toArray()))) {
            throw new ApprovalException('prepared_action_changed', 'An application callback changed the frozen prepared action.');
        }
    }
}
