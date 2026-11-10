<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * The software passkey as a host's test uses it. What the verifier refuses, change by change, is in
 * tests/WebAuthn/WebAuthnVerifierTest.php, which drives this same class.
 */
final class VirtualAuthenticatorTest extends TestCase
{
    private const ORIGIN = 'https://app.test';
    private WebAuthnVerifier $verifier;
    private Actor $person;

    protected function setUp(): void
    {
        $credentials = new PdoCredentialRepository(new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $credentials->initialize();
        $this->verifier = new WebAuthnVerifier(new Configuration('app.test', self::ORIGIN), $credentials);
        $this->person = new Actor('alice', 'tenant-a');
    }

    public function testEachAuthenticatorIsAPasskeyOfItsOwn(): void
    {
        $one = new VirtualAuthenticator(self::ORIGIN);
        $two = new VirtualAuthenticator(self::ORIGIN);

        self::assertSame($one->id(), $one->id());
        self::assertNotSame($one->id(), $two->id());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $one->id(), 'Base64url without padding, as a browser gives a credential id.');
        self::assertSame(32, \strlen(Base64UrlSafe::decodeNoPadding($one->id())));
    }

    public function testTheShippedVerifierAcceptsItsRegistrationAndEachOfItsSignatures(): void
    {
        $passkey = new VirtualAuthenticator(self::ORIGIN);

        $options = $this->verifier->registrationOptions($this->person, random_bytes(32), 'Alice');
        $credential = $this->verifier->register($this->person, $options, $passkey->registration($options));
        self::assertSame($passkey->id(), $credential->id);
        self::assertTrue($credential->belongsTo($this->person));

        foreach ([1, 2] as $use) {
            $challenge = random_bytes(32);
            $proof = $this->verifier->verify($this->person, $challenge, $passkey->assertion($this->verifier->requestOptions($this->person, $challenge)));
            self::assertSame($passkey->id(), $proof->credentialId);
            self::assertTrue($proof->evidence['user_verified'], 'It says the person was verified, which is all a test can have it say.');
            self::assertSame($use - 1, $proof->evidence['counter_before']);
            self::assertSame($use, $proof->evidence['counter_after'], 'Its signature counter moves on with each use.');
        }
    }

    public function testAPasskeyMadeForAnotherOriginIsRefusedAtRegistration(): void
    {
        $elsewhere = new VirtualAuthenticator('https://elsewhere.test');
        $options = $this->verifier->registrationOptions($this->person, random_bytes(32), 'Alice');

        try {
            $this->verifier->register($this->person, $options, $elsewhere->registration($options));
            self::fail('A passkey that names another origin was registered.');
        } catch (ApprovalException $refused) {
            self::assertSame('invalid_registration', $refused->reason);
        }
    }

    public function testAnotherAuthenticatorCannotSignForARegisteredPasskey(): void
    {
        $passkey = new VirtualAuthenticator(self::ORIGIN);
        $options = $this->verifier->registrationOptions($this->person, random_bytes(32), 'Alice');
        $this->verifier->register($this->person, $options, $passkey->registration($options));
        $challenge = random_bytes(32);
        $request = $this->verifier->requestOptions($this->person, $challenge);

        try {
            $this->verifier->verify($this->person, $challenge, (new VirtualAuthenticator(self::ORIGIN))->assertion($request));
            self::fail('A passkey that was never registered approved.');
        } catch (ApprovalException $refused) {
            self::assertSame('credential_unavailable', $refused->reason);
        }
    }

    public function testTheDefaultOriginIsTheOneTheLibrarysOwnTestsUse(): void
    {
        $credentials = new PdoCredentialRepository(new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $credentials->initialize();
        $verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $credentials);
        $passkey = new VirtualAuthenticator();

        $options = $verifier->registrationOptions($this->person, random_bytes(32), 'Alice');

        self::assertSame($passkey->id(), $verifier->register($this->person, $options, $passkey->registration($options))->id);
    }
}
