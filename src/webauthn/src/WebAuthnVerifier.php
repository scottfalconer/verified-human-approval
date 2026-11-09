<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalVerifier;
use VerifiedHumanApproval\Core\VerifiedApproval;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Delegates WebAuthn parsing and cryptographic verification to web-auth/webauthn-lib.
 * No alternative proof, password or agent confirmation can satisfy this verifier.
 */
final class WebAuthnVerifier implements ApprovalVerifier
{
    private const MAX_RESPONSE_BYTES = 131072;

    private readonly SerializerInterface $serializer;
    private readonly AuthenticatorAssertionResponseValidator $assertionValidator;
    private readonly AuthenticatorAttestationResponseValidator $registrationValidator;
    private readonly UserHandleProvider $userHandles;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly CredentialRepository $credentials,
        ?UserHandleProvider $userHandles = null,
    ) {
        $this->userHandles = $userHandles ?? new HashedUserHandleProvider();
        $attestations = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
        $this->serializer = (new WebauthnSerializerFactory($attestations))->create();
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$configuration->origin], false);
        $factory->setAttestationStatementSupportManager($attestations);
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());
        $this->registrationValidator = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());
    }

    public function requestOptions(Actor $actor, string $challenge): array
    {
        $this->assertChallenge($challenge);
        $credentials = $this->credentials->forActor($actor);
        if ([] === $credentials) {
            // Passkeys the person enrolled, which nothing vouches for right now: enrolling another would not help.
            if ($this->credentials instanceof AnchoredCredentialRepository && [] !== $this->credentials->unvouched($actor)) {
                throw new ApprovalException('credential_unvouched', 'Your passkey cannot approve right now: the site cannot confirm its enrollment. Ask the site’s administrator.');
            }
            throw new ApprovalException('credential_required', 'Enroll an approval passkey through your trusted account settings first.');
        }
        $records = [];
        foreach ($credentials as $credential) {
            if (!$credential->belongsTo($actor) || $credential->revoked) {
                throw new ApprovalException('credential_unavailable', 'No acceptable approval credential is available.');
            }
            $records[] = $this->recordFor($actor, $credential);
        }

        return $this->jsonArray($this->options($challenge, $records));
    }

    /**
     * Called by ApprovalService inside the final business transaction.
     *
     * @param array<string, mixed> $assertion
     */
    public function verify(Actor $actor, string $challenge, array $assertion): VerifiedApproval
    {
        $this->assertChallenge($challenge);
        $response = $this->parseResponse($assertion);
        $id = Base64UrlSafe::encodeUnpadded($response->rawId);
        $credential = $this->credentials->find($id);
        if (null === $credential || !$credential->belongsTo($actor) || $credential->revoked) {
            throw new ApprovalException('credential_unavailable', 'This passkey is not an active approval credential for this account.');
        }
        $record = $this->recordFor($actor, $credential);
        $counterBefore = $record->counter;
        $verified = $this->validateAssertion($actor, $challenge, $response, $record);
        $updated = new Credential(
            $credential->id,
            $credential->userId,
            $credential->tenantId,
            $this->serializer->serialize($verified, 'json'),
            false,
            $credential->label,
        );
        // The repository compare-and-replace rejects intervening revocation or counter changes.
        $this->credentials->update($updated, $credential->sourceJson);

        return new VerifiedApproval($id, $this->evidence($actor, $assertion, $id, $counterBefore, $verified->counter));
    }

    /**
     * A read-only historical verification using a separately trusted credential registration.
     * This proves the signature and proposal binding, not the historical live-counter/revocation state.
     *
     * @param array<string, mixed> $assertion
     */
    public function verifyEvidenceAssertion(
        Actor $actor,
        string $challenge,
        array $assertion,
        Credential $trustedCredential,
    ): VerifiedApproval {
        $this->assertChallenge($challenge);
        if (!$trustedCredential->belongsTo($actor)) {
            throw new ApprovalException('untrusted_credential', 'The supplied trusted credential belongs to another account.');
        }
        $response = $this->parseResponse($assertion);
        $id = Base64UrlSafe::encodeUnpadded($response->rawId);
        if (!hash_equals($trustedCredential->id, $id)) {
            throw new ApprovalException('untrusted_credential', 'The assertion does not use the supplied trusted credential.');
        }
        $record = $this->recordFor($actor, $trustedCredential);
        // Historical signatures remain verifiable after later uses of this passkey.
        // Setting the starting counter to zero does not bypass any signature, owner, RP, origin or UV check.
        $record->counter = 0;
        $verified = $this->validateAssertion($actor, $challenge, $response, $record);

        return new VerifiedApproval($id, $this->evidence($actor, $assertion, $id, null, $verified->counter));
    }

    /**
     * The host must authenticate a human, retain these exact options server-side,
     * impose a short expiry and one-time use, and protect completion with CSRF.
     * An MCP bearer credential must never gain access to this enrollment flow.
     *
     * @return array<string, mixed> Browser PublicKeyCredentialCreationOptions JSON
     */
    public function registrationOptions(Actor $actor, string $challenge, string $displayName): array
    {
        $this->assertChallenge($challenge);
        if ('' === $displayName || \strlen($displayName) > 256) {
            throw new \InvalidArgumentException('The credential account display name must be between one and 256 bytes.');
        }
        $exclude = [];
        foreach ($this->credentials->forActor($actor) as $credential) {
            $exclude[] = $this->recordFor($actor, $credential)->getPublicKeyCredentialDescriptor();
        }
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: $this->configuration->rpId),
            user: PublicKeyCredentialUserEntity::create($displayName, $this->userHandle($actor), $displayName),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: $this->configuration->timeoutMs,
        );

        return $this->jsonArray($options);
    }

    /**
     * Validate and insert a new registration. No existing key is replaced or unrevoked.
     * $savedOptions must come from trusted server-side enrollment state, never the browser.
     *
     * @param array<string, mixed> $savedOptions
     * @param array<string, mixed> $response
     */
    public function register(Actor $actor, array $savedOptions, array $response, string $label = 'Passkey'): Credential
    {
        if ('' === $label || \strlen($label) > 200) {
            throw new \InvalidArgumentException('The passkey label must be between one and 200 bytes.');
        }
        try {
            $options = $this->serializer->deserialize($this->encoded($savedOptions), PublicKeyCredentialCreationOptions::class, 'json');
            if ($options->rp->id !== $this->configuration->rpId
                || !hash_equals($this->userHandle($actor), $options->user->id)
                || AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED !== $options->authenticatorSelection?->userVerification
                || (null !== $options->mediation && PublicKeyCredentialCreationOptions::MEDIATION_DEFAULT !== $options->mediation)
            ) {
                throw new \UnexpectedValueException('Enrollment options do not match the trusted account and RP.');
            }
            $this->assertChallenge($options->challenge);
            $loaded = $this->parseResponse($response);
            if (!$loaded->response instanceof AuthenticatorAttestationResponse) {
                throw new \UnexpectedValueException('Expected a credential registration response.');
            }
            $this->assertTopLevel($loaded);
            $record = $this->registrationValidator->check($loaded->response, $options, $this->configuration->host);
            if (!hash_equals($loaded->rawId, $record->publicKeyCredentialId)) {
                throw new \UnexpectedValueException('The outer and attested credential IDs differ.');
            }
            $credential = new Credential(
                Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
                $actor->userId,
                $actor->tenantId,
                $this->serializer->serialize($record, 'json'),
                false,
                $label,
            );
        } catch (\Throwable) {
            // Do not put assertion material or potentially sensitive decoder messages into application logs.
            throw new ApprovalException('invalid_registration', 'The passkey registration could not be verified.');
        }
        $this->credentials->add($credential);

        return $credential;
    }

    private function validateAssertion(Actor $actor, string $challenge, PublicKeyCredential $response, CredentialRecord $record): CredentialRecord
    {
        try {
            if (!$response->response instanceof AuthenticatorAssertionResponse
                || !hash_equals($response->rawId, $record->publicKeyCredentialId)
            ) {
                throw new \UnexpectedValueException('Expected an assertion for the enrolled credential.');
            }
            $this->assertTopLevel($response);

            return $this->assertionValidator->check(
                $record,
                $response->response,
                $this->options($challenge, [$record]),
                $this->configuration->host,
                $this->userHandle($actor),
            );
        } catch (\Throwable) {
            throw new ApprovalException('invalid_approval', 'The passkey assertion could not be verified.');
        }
    }

    /** This first integration deliberately supports top-level browser ceremonies only. */
    private function assertTopLevel(PublicKeyCredential $response): void
    {
        $data = $response->response->clientDataJSON;
        if ($data->crossOrigin || null !== $data->topOrigin) {
            throw new \UnexpectedValueException('Cross-origin and embedded approval ceremonies are not enabled.');
        }
    }

    /** @param list<CredentialRecord> $records */
    private function options(string $challenge, array $records): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $this->configuration->rpId,
            allowCredentials: array_map(static fn (CredentialRecord $record) => $record->getPublicKeyCredentialDescriptor(), $records),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: $this->configuration->timeoutMs,
        );
    }

    private function recordFor(Actor $actor, Credential $credential): CredentialRecord
    {
        try {
            $record = $this->serializer->deserialize($credential->sourceJson, CredentialRecord::class, 'json');
            if (!$credential->belongsTo($actor)
                || !hash_equals($credential->id, Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId))
                || !hash_equals($this->userHandle($actor), $record->userHandle)
            ) {
                throw new \UnexpectedValueException('The registration is not bound to this account.');
            }

            return $record;
        } catch (\Throwable) {
            throw new ApprovalException('credential_unavailable', 'No acceptable approval credential is available.');
        }
    }

    /** @param array<string, mixed> $response */
    private function parseResponse(array $response): PublicKeyCredential
    {
        try {
            return $this->serializer->deserialize($this->encoded($response), PublicKeyCredential::class, 'json');
        } catch (\Throwable) {
            throw new ApprovalException('invalid_approval', 'The passkey response could not be read.');
        }
    }

    private function userHandle(Actor $actor): string
    {
        $handle = $this->userHandles->forActor($actor);
        if ('' === $handle || \strlen($handle) > 64) {
            throw new \LogicException('The configured user-handle provider must return one to 64 bytes.');
        }

        return $handle;
    }

    private function assertChallenge(string $challenge): void
    {
        if (\strlen($challenge) < 32 || \strlen($challenge) > 1024) {
            throw new \InvalidArgumentException('Use a server-generated challenge containing at least 32 bytes.');
        }
    }

    /** @param array<string, mixed> $value */
    private function encoded(array $value): string
    {
        $json = json_encode($value, \JSON_THROW_ON_ERROR);
        if (\strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new \LengthException('The WebAuthn response exceeds the supported size.');
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function jsonArray(object $value): array
    {
        return json_decode($this->serializer->serialize($value, 'json'), true, 64, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $assertion
     *
     * @return array<string, mixed>
     */
    private function evidence(Actor $actor, array $assertion, string $id, ?int $before, int $after): array
    {
        return [
            'format' => 'verified-human-approval.webauthn.v1',
            'credential_id' => $id,
            'rp_id' => $this->configuration->rpId,
            'origin' => $this->configuration->origin,
            'user_handle' => Base64UrlSafe::encodeUnpadded($this->userHandle($actor)),
            'assertion' => $assertion,
            'user_verified' => true,
            'counter_before' => $before,
            'counter_after' => $after,
        ];
    }
}
