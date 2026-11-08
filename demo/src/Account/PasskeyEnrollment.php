<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Account;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\Security\Authenticators;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * Adds a passkey: the credential, its signed enrollment record and the one-time link it used up, all
 * together or none of them. Only a passkey with that signed record can approve anything.
 */
final readonly class PasskeyEnrollment
{
    public function __construct(private DemoDatabase $database, private WebAuthnVerifier $webauthn, private AnchoredCredentialRepository $anchored, private PasskeyConfirmation $confirmation)
    {
    }

    /**
     * @param array<string, mixed>                                                            $options       the registration options the browser was given
     * @param array<string, mixed>                                                            $response      the browser's registration response
     * @param array{method: string, authorizedBy: string, link?: string, credential?: string} $authorization what let this person add a passkey
     */
    public function complete(Actor $actor, array $options, array $response, array $authorization): Credential
    {
        return $this->database->transactional(function () use ($actor, $options, $response, $authorization): Credential {
            // The passkey that confirmed this must still be active now, not only when the confirmation was given.
            if ('existing passkey' === $authorization['method'] && !$this->confirmation->stillActive($actor, $authorization['credential'] ?? '')) {
                throw new ApprovalException('confirmation_revoked', 'The passkey that confirmed this has been revoked. Confirm again with an active passkey.');
            }
            $credential = $this->webauthn->register($actor, $options, $response, 'Personal passkey');
            if ('passkey link' === $authorization['method'] && !$this->database->usePasskeyLink($authorization['link'] ?? '', $credential->id)) {
                throw new ApprovalException('link_used', 'This passkey link has already been used or has expired.');
            }
            $this->anchored->anchor($credential, [
                'method' => $authorization['method'], 'authorizedBy' => $authorization['authorizedBy'],
                'authenticator' => Authenticators::name($credential->sourceJson),
            ]);

            return $credential;
        });
    }
}
