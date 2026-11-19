<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

final class WebAuthnVerifierTest extends TestCase
{
    private \PDO $pdo;
    private PdoCredentialRepository $credentials;
    private WebAuthnVerifier $verifier;
    private Actor $actor;
    private VirtualAuthenticator $authenticator;
    private Credential $registration;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->credentials = new PdoCredentialRepository($this->pdo);
        $this->credentials->initialize();
        $this->verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $this->credentials);
        $this->actor = new Actor('editor', 'acme', 'personal-delegation');
        $this->authenticator = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Campaign editor');
        $this->registration = $this->verifier->register($this->actor, $options, $this->authenticator->registration($options));
    }

    public function testRealEs256AssertionPassesRequiredUserVerificationAndUpdatesCounter(): void
    {
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $challenge);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(Base64UrlSafe::encodeUnpadded($challenge), $options['challenge']);
        self::assertSame($this->authenticator->id(), $options['allowCredentials'][0]['id']);
        $proof = $this->verifier->verify($this->actor, $challenge, $this->authenticator->assertion($options));
        self::assertSame($this->authenticator->id(), $proof->credentialId);
        self::assertTrue($proof->evidence['user_verified']);
        self::assertSame(0, $proof->evidence['counter_before']);
        self::assertSame(1, $proof->evidence['counter_after']);
        $stored = json_decode($this->credentials->find($this->authenticator->id())->sourceJson, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $stored['counter']);
    }

    #[DataProvider('invalidAssertionChanges')]
    public function testInvalidSignedAssertionsAreRejectedWithoutUpdatingTheCredential(array $changes): void
    {
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $challenge);
        try {
            $this->verifier->verify($this->actor, $challenge, $this->authenticator->assertion($options, $changes));
            self::fail('An invalid assertion was accepted.');
        } catch (ApprovalException $exception) {
            self::assertSame('invalid_approval', $exception->reason);
            self::assertSame($this->registration->sourceJson, $this->credentials->find($this->authenticator->id())->sourceJson);
        }
    }

    public static function invalidAssertionChanges(): iterable
    {
        yield 'missing user verification' => [['flags' => 0x01]];
        yield 'missing user presence' => [['flags' => 0x04]];
        yield 'wrong relying party' => [['rpId' => 'other.test']];
        yield 'wrong origin' => [['origin' => 'https://evil.test']];
        yield 'sibling origin' => [['origin' => 'https://sub.approval.test']];
        yield 'different port' => [['origin' => 'https://approval.test:9443']];
        yield 'different challenge' => [['challenge' => Base64UrlSafe::encodeUnpadded(str_repeat('x', 32))]];
        yield 'wrong ceremony' => [['type' => 'webauthn.create']];
        yield 'wrong user handle' => [['userHandle' => Base64UrlSafe::encodeUnpadded('another-person')]];
        yield 'cross origin without top origin' => [['crossOrigin' => true]];
        yield 'cross origin with top origin' => [['crossOrigin' => true, 'topOrigin' => 'https://evil.test']];
        yield 'invalid backup bits' => [['flags' => 0x15]];
        yield 'changed backup eligibility' => [['flags' => 0x0D]];
    }

    public function testKnownUserMayUseNonDiscoverableCredentialWithoutResponseUserHandle(): void
    {
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $challenge);
        $proof = $this->verifier->verify($this->actor, $challenge, $this->authenticator->assertion($options, ['userHandle' => null]));
        self::assertSame($this->authenticator->id(), $proof->credentialId);
    }

    public function testSamePersonInBrowserDoesNotNeedAgentDelegationToUseOwnPasskey(): void
    {
        $browserActor = new Actor('editor', 'acme');
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($browserActor, $challenge);
        self::assertSame($this->authenticator->id(), $this->verifier->verify($browserActor, $challenge, $this->authenticator->assertion($options))->credentialId);
    }

    public function testAnUnusedValidAssertionCannotAuthorizeAnotherChallenge(): void
    {
        $original = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $original);
        $unusedAssertion = $this->authenticator->assertion($options);
        try {
            $this->verifier->verify($this->actor, random_bytes(32), $unusedAssertion);
            self::fail('The proof was substituted.');
        } catch (ApprovalException $exception) {
            self::assertSame('invalid_approval', $exception->reason);
        }
        self::assertSame($this->authenticator->id(), $this->verifier->verify($this->actor, $original, $unusedAssertion)->credentialId);
    }

    public function testTamperedSignatureFails(): void
    {
        $challenge = random_bytes(32);
        $assertion = $this->authenticator->assertion($this->verifier->requestOptions($this->actor, $challenge));
        $assertion['response']['signature'] = Base64UrlSafe::encodeUnpadded(random_bytes(72));
        $this->expectException(ApprovalException::class);
        $this->verifier->verify($this->actor, $challenge, $assertion);
    }

    #[DataProvider('otherActors')]
    public function testCredentialCannotBeUsedForAnotherUserOrTenant(Actor $other): void
    {
        $challenge = random_bytes(32);
        $assertion = $this->authenticator->assertion($this->verifier->requestOptions($this->actor, $challenge));
        $this->expectException(ApprovalException::class);
        $this->verifier->verify($other, $challenge, $assertion);
    }

    public static function otherActors(): iterable
    {
        yield 'other user' => [new Actor('reviewer', 'acme')];
        yield 'other tenant' => [new Actor('editor', 'other-company')];
    }

    public function testRevocationBetweenChallengeAndCompletionIsRespected(): void
    {
        $challenge = random_bytes(32);
        $assertion = $this->authenticator->assertion($this->verifier->requestOptions($this->actor, $challenge));
        self::assertFalse($this->credentials->revoke(new Actor('mallory', $this->actor->tenantId), $this->authenticator->id()), 'Another account cannot revoke it.');
        self::assertTrue($this->credentials->revoke($this->actor, $this->authenticator->id()));
        $this->expectException(ApprovalException::class);
        $this->verifier->verify($this->actor, $challenge, $assertion);
    }

    public function testNoEnrolledKeyDoesNotProduceAnOpenDiscoverableCredentialRequest(): void
    {
        $this->expectException(ApprovalException::class);
        $this->verifier->requestOptions(new Actor('unconnected', 'acme'), random_bytes(32));
    }

    public function testEnrollingAnAlreadyRegisteredCredentialCannotChangeItsOwner(): void
    {
        $other = new Actor('another-user', 'acme');
        $options = $this->verifier->registrationOptions($other, random_bytes(32), 'Other user');
        try {
            $this->verifier->register($other, $options, $this->authenticator->registration($options));
            self::fail('A credential was reassigned.');
        } catch (ApprovalException $exception) {
            self::assertSame('credential_exists', $exception->reason);
        }
        self::assertSame('editor', $this->credentials->find($this->authenticator->id())->userId);
    }

    public function testEnrollmentRequiresUserVerification(): void
    {
        $key = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $this->expectException(ApprovalException::class);
        $this->verifier->register($this->actor, $options, $key->registration($options, ['flags' => 0x41]));
    }

    public function testEnrollmentOptionsCannotBeUsedForAnotherAccount(): void
    {
        $key = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $response = $key->registration($options);
        $this->expectException(ApprovalException::class);
        $this->verifier->register(new Actor('other', 'acme'), $options, $response);
    }

    public function testCounterUpdateRollsBackWithTheBusinessTransaction(): void
    {
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $challenge);
        $this->pdo->beginTransaction();
        $this->verifier->verify($this->actor, $challenge, $this->authenticator->assertion($options));
        $this->pdo->rollBack();
        self::assertSame($this->registration->sourceJson, $this->credentials->find($this->authenticator->id())->sourceJson);
    }

    public function testLiveCounterProtectsAgainstRepeatedCounterValues(): void
    {
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $challenge);
        $assertion = $this->authenticator->assertion($options, ['counter' => 7]);
        $this->verifier->verify($this->actor, $challenge, $assertion);
        $this->expectException(ApprovalException::class);
        $this->verifier->verify($this->actor, $challenge, $assertion);
    }

    public function testPasskeysWithoutCountersStillNeedTheApprovalCoreOneTimeCeremonyRule(): void
    {
        $challenge = random_bytes(32);
        $options = $this->verifier->requestOptions($this->actor, $challenge);
        $assertion = $this->authenticator->assertion($options, ['counter' => 0]);
        self::assertSame($this->authenticator->id(), $this->verifier->verify($this->actor, $challenge, $assertion)->credentialId);
        // Counterless authenticators are valid WebAuthn. ApprovalService provides one-time consumption.
        self::assertSame($this->authenticator->id(), $this->verifier->verify($this->actor, $challenge, $assertion)->credentialId);
    }

    public function testHistoricalProofUsesAnIndependentlyTrustedKeyAndDoesNotUpdateLiveCounter(): void
    {
        $challenge = random_bytes(32);
        $assertion = $this->authenticator->assertion($this->verifier->requestOptions($this->actor, $challenge));
        $this->verifier->verify($this->actor, $challenge, $assertion);
        $laterChallenge = random_bytes(32);
        $this->verifier->verify($this->actor, $laterChallenge, $this->authenticator->assertion($this->verifier->requestOptions($this->actor, $laterChallenge)));
        $current = $this->credentials->find($this->authenticator->id());
        self::assertSame($this->authenticator->id(), $this->verifier->verifyEvidenceAssertion($this->actor, $challenge, $assertion, $current)->credentialId);
        self::assertSame($current->sourceJson, $this->credentials->find($this->authenticator->id())->sourceJson);
    }

    public function testAnAttackerSuppliedEvidenceKeyCannotSubstituteTheTrustedKey(): void
    {
        $challenge = random_bytes(32);
        $assertion = $this->authenticator->assertion($this->verifier->requestOptions($this->actor, $challenge));
        $other = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Second key');
        $otherCredential = $this->verifier->register($this->actor, $options, $other->registration($options));
        $this->expectException(ApprovalException::class);
        $this->verifier->verifyEvidenceAssertion($this->actor, $challenge, $assertion, $otherCredential);
    }

    #[DataProvider('malformedAssertions')]
    public function testMalformedResponsesAreRejected(array $assertion): void
    {
        $this->expectException(ApprovalException::class);
        $this->verifier->verify($this->actor, random_bytes(32), $assertion);
    }

    public static function malformedAssertions(): iterable
    {
        yield 'empty response' => [[]];
        yield 'invalid base64' => [['id' => '!', 'rawId' => '!', 'type' => 'public-key', 'response' => []]];
        yield 'too large' => [['id' => str_repeat('x', 150000)]];
    }

    /** @return iterable<string, array{\Closure(self): mixed, string, class-string<\Throwable>}> */
    public static function failuresInsideTheLibrary(): iterable
    {
        $assertion = static function (self $test, \Closure $change): array {
            $challenge = random_bytes(32);
            $assertion = $test->authenticator->assertion($test->verifier->requestOptions($test->actor, $challenge));

            return [$challenge, $change($assertion)];
        };
        yield 'a user handle that is not a string, which the library meets with a TypeError' => [static function (self $test) use ($assertion): mixed {
            [$challenge, $sent] = $assertion($test, static fn (array $sent): array => array_replace_recursive($sent, ['response' => ['userHandle' => ['quoted-material']]]));

            return $test->verifier->verify($test->actor, $challenge, $sent);
        }, 'invalid_approval', \TypeError::class];
        yield 'an id that is not base64url' => [static function (self $test) use ($assertion): mixed {
            [$challenge, $sent] = $assertion($test, static fn (array $sent): array => ['id' => 'quoted-material!', 'rawId' => 'quoted-material!'] + $sent);

            return $test->verifier->verify($test->actor, $challenge, $sent);
        }, 'invalid_approval', \RangeException::class];
        yield 'a signature that is not an ECDSA signature' => [static function (self $test) use ($assertion): mixed {
            [$challenge, $sent] = $assertion($test, static fn (array $sent): array => array_replace_recursive($sent, ['response' => ['signature' => Base64UrlSafe::encodeUnpadded(random_bytes(72))]]));

            return $test->verifier->verify($test->actor, $challenge, $sent);
        }, 'invalid_approval', \InvalidArgumentException::class];
        yield 'an assertion made for another origin' => [static function (self $test): mixed {
            $challenge = random_bytes(32);
            $sent = $test->authenticator->assertion($test->verifier->requestOptions($test->actor, $challenge), ['origin' => 'https://quoted-material.test']);

            return $test->verifier->verify($test->actor, $challenge, $sent);
        }, 'invalid_approval', \Webauthn\Exception\AuthenticatorResponseVerificationException::class];
        yield 'a stored passkey that is not a passkey record' => [static function (self $test): mixed {
            $test->pdo->exec('UPDATE verified_human_approval_credentials SET source_json = \'{"quoted-material":true}\'');

            return $test->verifier->requestOptions($test->actor, random_bytes(32));
        }, 'credential_unavailable', \Webauthn\Exception\InvalidDataException::class];
        yield 'enrollment options for another relying party' => [static function (self $test): mixed {
            $options = (new WebAuthnVerifier(new Configuration('other.test', 'https://other.test'), $test->credentials))->registrationOptions($test->actor, random_bytes(32), 'quoted-material');

            return $test->verifier->register($test->actor, $options, (new VirtualAuthenticator())->registration($options));
        }, 'invalid_registration', \UnexpectedValueException::class];
    }

    /**
     * Whatever fails while the library reads or checks a response or a stored passkey, the result is a refusal,
     * errors included. The refusal says what failed and where, for whoever debugs it, but keeps neither the cause's
     * message nor the cause itself, which can quote or carry what was sent.
     *
     * @param \Closure(self): mixed    $operation
     * @param class-string<\Throwable> $cause
     */
    #[DataProvider('failuresInsideTheLibrary')]
    public function testARefusalNamesWhereItsCauseWasRaisedWithoutQuotingWhatWasSent(\Closure $operation, string $reason, string $cause): void
    {
        try {
            $operation($this);
            self::fail('The failure was not refused.');
        } catch (ApprovalException $refusal) {
            self::assertSame($reason, $refusal->reason);
            $kept = $refusal->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $kept);
            self::assertMatchesRegularExpression('/^'.preg_quote($cause, '/').' raised at \S+\.php:\d+\.$/D', $kept->getMessage());
            self::assertNull($kept->getPrevious(), 'The cause itself is not kept.');
            self::assertStringNotContainsString('quoted-material', $refusal->getMessage().$kept->getMessage());
        }
    }
}
