<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Evidence;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\exact_approval\Storage\DrupalApprovalStore;
use ExactApproval\Core\{Actor, ApprovalException, ApprovalService};
use ExactApproval\Core\Evidence\SodiumEvidenceSigner;

final readonly class EvidenceService
{
    public function __construct(private ApprovalService $approvals, private DrupalApprovalStore $store, private ActorProvider $actors, private SodiumEvidenceSigner $signer, private ConfigFactoryInterface $config) {}

    public function exportForActor(Actor $actor, string $id): array
    {
        $this->approvals->get($actor, $id);
        return $this->store->evidence($id)['bundle'];
    }

    public function credentialForActor(Actor $actor, string $id): array
    {
        $this->approvals->get($actor, $id);
        return $this->store->evidence($id)['credential'];
    }

    public function trustForActor(Actor $actor, string $id): array
    {
        $this->approvals->get($actor, $id);
        return $this->store->evidence($id)['trust'];
    }

    public function exportForAdministrator(Actor $actor, string $id): array
    {
        $this->assertAdministrator($actor, $id);
        return $this->store->evidence($id)['bundle'];
    }

    public function credentialForAdministrator(Actor $actor, string $id): array
    {
        $this->assertAdministrator($actor, $id);
        return $this->store->evidence($id)['credential'];
    }

    public function trustForAdministrator(Actor $actor, string $id): array
    {
        $this->assertAdministrator($actor, $id);
        return $this->store->evidence($id)['trust'];
    }

    /** Current public trust material; historical exports use the key retained with that execution. */
    public function trust(): array
    {
        $settings = $this->config->get('exact_approval.settings');
        return ['keyId' => $this->signer->keyId(), 'publicKey' => $this->signer->publicKey(), 'rpId' => $settings->get('rp_id'), 'origin' => $settings->get('origin')];
    }

    private function assertAdministrator(Actor $actor, string $id): void
    {
        if (!$this->actors->account($actor)->hasPermission('view exact approval audit') || $this->store->get($id)?->request->actor()->tenantId !== $actor->tenantId) {
            throw new ApprovalException('not_found', 'The evidence was not found.');
        }
    }
}

