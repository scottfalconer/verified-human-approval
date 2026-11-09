<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Demo\Account\AgentConnections;
use VerifiedHumanApproval\Demo\Account\PasskeyConfirmation;
use VerifiedHumanApproval\Demo\Account\PasskeyLinks;
use VerifiedHumanApproval\Demo\Account\PasskeyRevocation;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\Security\Authenticators;
use VerifiedHumanApproval\Demo\Security\DemoRequesterLabel;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * The administrator's view of how every action in their organization happened: who asked, through
 * which agent, under which rule, and whether a person verified it with which passkey. Each completed
 * action is re-checked on every visit: its receipt against the signature made at commit, and the
 * person's passkey signature against the public key recorded, signed, when that passkey was enrolled.
 */
#[AsController]
final readonly class AuditController
{
    public function __construct(
        private ActorProvider $actors,
        private Security $security,
        private PdoApprovalStore $store,
        private EvidenceExporter $exporter,
        private SodiumEvidenceSigner $signer,
        private WebAuthnVerifier $webauthn,
        private PdoCredentialRepository $credentials,
        private AnchoredCredentialRepository $anchored,
        private AuditLog $audit,
        private AgentConnections $connections,
        private PasskeyRevocation $revocation,
        private PasskeyLinks $links,
        private PasskeyConfirmation $confirmation,
        private DemoDatabase $database,
        private DemoRequesterLabel $requesters,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
    ) {
    }

    public function audit(Request $request): Response
    {
        $admin = $this->admin();
        $actions = [];
        foreach ($this->store->recent(200) as $state) {
            if ($state->request->actor()->tenantId === $admin->tenantId) {
                $actions[] = $this->describe($state);
            }
        }
        $passkeys = [];
        foreach ($this->credentials->forTenant($admin->tenantId) as $credential) {
            $record = $this->anchored->enrollmentRecord($credential->id);
            $passkeys[] = [
                'id' => $credential->id, 'user' => $credential->userId, 'label' => $credential->label, 'revoked' => $credential->revoked,
                'authenticator' => Authenticators::name($credential->sourceJson), 'anchored' => $this->anchored->anchored($credential),
                'enrolledAt' => $record['at'] ?? null, 'method' => $record['data']->method ?? null, 'authorizedBy' => $record['data']->authorizedBy ?? null,
            ];
        }
        $log = $this->audit->verify();
        $entries = array_values(array_filter(array_reverse($log['entries']), static fn (array $item): bool => null === $item['entry'] || $item['entry']['tenantId'] === $admin->tenantId));
        $newLink = $request->getSession()->get('demo.new_link');
        $request->getSession()->remove('demo.new_link');

        return new Response($this->twig->render('audit.html.twig', [
            'actions' => $actions, 'passkeys' => $passkeys, 'now' => time(),
            'connections' => array_map(static fn (array $grant): array => ['scopes' => json_decode((string) $grant['scopes'], true)] + $grant, $this->database->grants($admin->tenantId)),
            'links' => $this->database->passkeyLinks($admin->tenantId), 'users' => $this->database->users($admin->tenantId),
            'log' => $entries, 'log_intact' => $log['intact'], 'log_newest' => $log['newest'],
            'key_id' => $this->signer->keyId(), 'public_key' => $this->signer->publicKey(),
            'confirmed' => null !== $this->confirmation->recent($request, $admin), 'new_link' => $newLink,
            'csrf' => $this->csrf->getToken('verified_human_approval.enrollment')->getValue(),
            'form_csrf' => $this->csrf->getToken('demo.audit')->getValue(),
        ]));
    }

    /** Evidence for any action in the administrator's organization, with its receipt signed at commit. */
    public function evidence(string $id): JsonResponse
    {
        $admin = $this->admin();
        $state = $this->store->get($id);
        if (null === $state || $state->request->actor()->tenantId !== $admin->tenantId) {
            throw new NotFoundHttpException('No such action in your organization.');
        }
        try {
            return new JsonResponse($this->exporter->export($state), headers: ['Content-Disposition' => 'attachment; filename="approval-evidence.json"']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'unavailable', 'reason' => $error->reason], 409);
        }
    }

    public function revokePasskey(Request $request, string $id): Response
    {
        $admin = $this->admin();
        $this->assertCsrf($request);
        $this->revocation->byAdministrator($admin, $id);

        return new RedirectResponse('/admin/audit');
    }

    public function revokeConnection(Request $request, string $id): Response
    {
        $admin = $this->admin();
        $this->assertCsrf($request);
        $this->connections->revoke($id, $admin->tenantId, $admin->userId);

        return new RedirectResponse('/admin/audit');
    }

    /** A one-time link for someone's first passkey. It needs the administrator's own passkey, confirmed just now. */
    public function issueLink(Request $request): Response
    {
        $admin = $this->admin();
        $this->assertCsrf($request);
        // A recent confirmation, by a passkey that is still active now.
        $confirmed = $this->confirmation->recent($request, $admin);
        if (null === $confirmed) {
            throw new AccessDeniedException('Confirm with your passkey before issuing a passkey link.');
        }
        $userId = (string) $request->request->get('user', '');
        $user = $this->database->user($userId);
        if (null === $user || $user['tenant_id'] !== $admin->tenantId) {
            throw new NotFoundHttpException('No such person in your organization.');
        }
        $token = $this->links->issue($userId, $admin->tenantId, 'administrator '.$admin->userId, 86400, ['issuedBy' => $admin->userId, 'confirmedWith' => $confirmed['credential']]);
        $request->getSession()->set('demo.new_link', ['user' => $userId, 'url' => $request->getSchemeAndHttpHost().'/account/passkeys/link/'.$token]);

        return new RedirectResponse('/admin/audit');
    }

    /** @return array<string, mixed> */
    private function describe(ApprovalState $state): array
    {
        $payload = $state->request->payload();
        $prepared = $state->request->prepared();
        $requester = $state->request->actor();
        $user = $this->database->user($requester->userId);
        $row = [
            'id' => $state->request->id, 'title' => $prepared->display['title'] ?? $prepared->actionId, 'statement' => $payload['statement'],
            'status' => $state->status, 'reason' => $state->reason, 'requestedAt' => $payload['createdAt'],
            'person' => $user['display_name'] ?? $requester->userId, 'userId' => $requester->userId, 'role' => $user['role'] ?? null,
            'through' => $this->requesters->connection($requester),
            'rule' => str_starts_with((string) $payload['policy']->version, 'fixed') ? 'Built-in rule' : 'Version '.$payload['policy']->version,
            'verificationRequired' => $payload['policy']->required,
            'executedAt' => $state->execution['recordedAt'] ?? null, 'approval' => null, 'receipt' => null, 'passkey' => null,
            'integrity_problem' => null, 'stored_status' => $state->status,
        ];
        try {
            $state->assertConsistent();
        } catch (ApprovalException $error) {
            // Keep this row visible without presenting its forged outcome as an
            // ordinary cancelled/denied action or vouching for a different result.
            $row['status'] = 'integrity error';
            $row['reason'] = null;
            $row['integrity_problem'] = $error->getMessage();
        }
        if ('completed' !== $state->status && null === $state->execution && null === $state->receipt && null === $state->proof) {
            return $row;
        }
        // The receipt signed as the action committed must be there, and still match the record. A missing one
        // is never replaced: it may have been deleted to hide an edit.
        try {
            $verified = (new ReceiptVerifier())->verify($this->exporter->export($state), $this->signer->keyId(), $this->signer->publicKey());
            $row['receipt'] = Receipt::COMMIT === $verified['receiptSignedAt']
                ? ['valid' => true, 'problem' => null]
                : ['valid' => false, 'problem' => 'it was not signed as the action committed'];
        } catch (ApprovalException $error) {
            $row['receipt'] = ['valid' => false, 'problem' => match ($error->reason) {
                'receipt_missing' => 'there is no receipt signed as the action committed; the record may have been edited and its receipt deleted',
                'record_integrity_error' => 'the stored outcome or result contradicts its execution evidence',
                default => 'the record no longer matches its receipt signed as the action committed',
            }];
        } catch (\Throwable) {
            $row['receipt'] = ['valid' => false, 'problem' => 'the receipt could not be checked'];
        }
        if (!$row['receipt']['valid']) {
            $row['status'] = 'integrity error';
            $row['reason'] = null;
            $row['integrity_problem'] ??= 'The stored execution has no valid receipt signed as it committed. Its outcome cannot be trusted.';
        }
        if (null !== $state->proof) {
            $credentialId = (string) $state->proof['credentialId'];
            $enrolled = $this->anchored->enrolled($credentialId);
            $row['approval'] = [
                'credential' => $credentialId, 'verifiedAt' => $state->proof['verifiedAt'] ?? null,
                'label' => $enrolled?->label, 'authenticator' => Authenticators::name($enrolled?->sourceJson),
            ];
            $row['passkey'] = null === $enrolled ? ['valid' => false, 'problem' => 'no signed enrollment record vouches for this passkey'] : $this->passkeySigned($state, $requester, $enrolled);
        }

        return $row;
    }

    /**
     * Re-checks that the enrolled passkey signed this exact proposal. The challenge is recomputed from
     * the stored proposal, not read from the stored proof, so an edit to either shows.
     *
     * @return array{valid: bool, problem: ?string}
     */
    private function passkeySigned(ApprovalState $state, Actor $requester, \VerifiedHumanApproval\WebAuthn\Credential $enrolled): array
    {
        if (null === $state->proof) {
            return ['valid' => false, 'problem' => 'no passkey signature was recorded'];
        }
        try {
            $challenge = ChallengeBinding::challenge($state->request->digest, ChallengeBinding::decode((string) $state->proof['nonce']));
            $this->webauthn->verifyEvidenceAssertion($requester, $challenge, CanonicalJson::map($state->proof['assertion']), $enrolled);

            return ['valid' => true, 'problem' => null];
        } catch (\Throwable) {
            return ['valid' => false, 'problem' => 'the passkey signature does not match this proposal'];
        }
    }

    private function admin(): Actor
    {
        $actor = $this->actors->interactive();
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('The audit log is for administrators.');
        }

        return $actor;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('demo.audit', (string) $request->request->get('_token', '')))) {
            throw new AccessDeniedException('Invalid or missing CSRF token.');
        }
    }
}
