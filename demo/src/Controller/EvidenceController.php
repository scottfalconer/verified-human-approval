<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;

final readonly class EvidenceController
{
    public function __construct(private ApprovalService $approvals, private ActorProvider $actors, private EvidenceExporter $exporter)
    {
    }

    public function export(string $id): JsonResponse
    {
        try {
            $state = $this->approvals->get($this->actors->interactive(), $id);
            // Signing happens after the persisted execution. A failure cannot rerun publication.
            $bundle = $this->exporter->export($state);

            return new JsonResponse($bundle, headers: ['Content-Disposition' => 'attachment; filename="approval-evidence.json"']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'unavailable', 'reason' => $error->reason], 409);
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'evidence_signing_pending', 'message' => 'Execution state is unchanged. Retry the evidence download later.'], 503);
        }
    }
}
