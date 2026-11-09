<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\verified_human_approval\Storage\DrupalApprovalStore;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalVerifier;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** Creates portable library objects using Drupal-owned storage and identity. */
final class LibraryFactory {

  public function __construct(
    private readonly ModuleSettings $settings,
    private readonly \Drupal\verified_human_approval\Storage\DrupalAuditStore $auditStore,
    private readonly EvidenceSigner $signer,
    private readonly Clock $clock,
  ) {}

  public function audit(): AuditLog {
    return new AuditLog($this->auditStore, $this->signer, $this->signer->publicKey(), $this->clock);
  }

  public function verifier(AnchoredCredentialRepository $credentials): WebAuthnVerifier {
    return new WebAuthnVerifier($this->settings->webAuthn(), $credentials);
  }

  public function approval(ActionRegistry $actions, \VerifiedHumanApproval\Core\PolicyProvider $policies, \VerifiedHumanApproval\Core\Authorizer $authorizer, DrupalApprovalStore $store, ApprovalVerifier $verifier, ExecutionGuard $guard): ApprovalService {
    return new ApprovalService($actions, $policies, $authorizer, $store, $verifier, $this->clock, $guard, $this->signer);
  }

}

