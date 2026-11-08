<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\exact_approval\Evidence\EvidenceService;
use Drupal\exact_approval\Security\ActorProvider;
use ExactApproval\Core\ApprovalException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Serve persisted evidence without rerunning or resigning an action. */
final class EvidenceController extends ControllerBase
{
    public function __construct(private readonly ActorProvider $actors, private readonly EvidenceService $evidence) {}

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('exact_approval.actor_provider'), $container->get('exact_approval.evidence'));
    }

    public function export(string $id): JsonResponse
    {
        return $this->download($id, 'evidence');
    }

    public function exportCredential(string $id): JsonResponse
    {
        return $this->download($id, 'credential');
    }

    public function exportTrust(string $id): JsonResponse
    {
        return $this->download($id, 'trust');
    }

    private function download(string $id, string $kind): JsonResponse
    {
        try {
            $actor = HumanRequestGuard::interactive($this->actors);
            $administrator = $this->actors->account($actor)->hasPermission('view exact approval audit');
            $data = match ($kind) {
                'credential' => $administrator ? $this->evidence->credentialForAdministrator($actor, $id) : $this->evidence->credentialForActor($actor, $id),
                'trust' => $administrator ? $this->evidence->trustForAdministrator($actor, $id) : $this->evidence->trustForActor($actor, $id),
                default => $administrator ? $this->evidence->exportForAdministrator($actor, $id) : $this->evidence->exportForActor($actor, $id),
            };
            $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) . '-' . $kind . '.json';
            return new JsonResponse($data, headers: [
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'unavailable', 'reason' => $error->reason, 'message' => $error->getMessage()], $error->reason === 'not_found' ? 404 : 409, ['Cache-Control' => 'private, no-store']);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['status' => 'unavailable', 'message' => $error->getMessage()], $error->getStatusCode(), ['Cache-Control' => 'private, no-store'] + $error->getHeaders());
        }
    }
}
