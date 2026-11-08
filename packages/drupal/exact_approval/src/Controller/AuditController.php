<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\exact_approval\Evidence\EvidenceService;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\exact_approval\Storage\DrupalApprovalStore;
use Drupal\exact_approval\Storage\DrupalCredentialRepository;
use ExactApproval\Core\ApprovalException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Audit ownership is checked independently from entity existence or edit access. */
final class AuditController extends ControllerBase
{
    public function __construct(
        private readonly ActorProvider $actors,
        private readonly DrupalApprovalStore $store,
        private readonly DrupalCredentialRepository $credentials,
        private readonly EvidenceService $evidence,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('exact_approval.actor_provider'), $container->get('exact_approval.store'), $container->get('exact_approval.credentials'), $container->get('exact_approval.evidence'));
    }

    public function listing(): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        $admin = $this->actors->account($actor)->hasPermission('view exact approval audit');
        $rows = [];
        foreach ($this->store->recentForActor($actor, $admin, 50) as $state) {
            $request = $state->request;
            $action = $request->prepared();
            $links = [];
            if ($request->actor()->samePerson($actor)) {
                $links['review'] = ['title' => $this->t('Review'), 'url' => Url::fromRoute('exact_approval.review', ['id' => $request->id])];
            }
            if ($state->status === 'completed') {
                $links['detail'] = ['title' => $this->t('Evidence'), 'url' => Url::fromRoute('exact_approval.audit_detail', ['id' => $request->id])];
            }
            $rows[] = [
                ['data' => ['#plain_text' => (string) $request->payload()['createdAt']]],
                ['data' => ['#plain_text' => $request->actor()->userId]],
                ['data' => ['#plain_text' => $action->actionId . ': ' . ($action->display['title'] ?? $request->id)]],
                ['data' => ['#plain_text' => $state->status . ($state->reason === null ? '' : ' — ' . $state->reason)]],
                ['data' => ['#plain_text' => $state->proof === null ? (string) $this->t('No approval signature') : (string) $this->t('User-verified key')]],
                ['data' => ['#type' => 'operations', '#links' => $links]],
            ];
        }

        return [
            '#title' => $this->t('Exact approval audit'),
            'description' => ['#type' => 'item', '#markup' => $admin
                ? $this->t('The 50 most recent approval requests on this site. Stored evidence remains available after content deletion or later key revocation.')
                : $this->t('Your 50 most recent approval requests. Stored evidence remains available after content deletion or later key revocation.')],
            'table' => [
                '#type' => 'table',
                '#header' => [$this->t('Requested'), $this->t('Drupal UID'), $this->t('Action'), $this->t('Status'), $this->t('Approval'), $this->t('Operations')],
                '#rows' => $rows, '#empty' => $this->t('No approval requests have been recorded.'),
            ],
            '#cache' => ['max-age' => 0],
        ];
    }

    public function detail(string $id): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        $admin = $this->actors->account($actor)->hasPermission('view exact approval audit');
        try {
            $bundle = $admin ? $this->evidence->exportForAdministrator($actor, $id) : $this->evidence->exportForActor($actor, $id);
            $credential = $admin ? $this->evidence->credentialForAdministrator($actor, $id) : $this->evidence->credentialForActor($actor, $id);
            $trust = $admin ? $this->evidence->trustForAdministrator($actor, $id) : $this->evidence->trustForActor($actor, $id);
        } catch (ApprovalException) {
            throw new NotFoundHttpException('Completed approval evidence is unavailable for this account.');
        }
        $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $proposal = json_decode($bundle['proposal'], false, 64, JSON_THROW_ON_ERROR);
        $build = [
            '#title' => $this->t('Signed approval evidence'),
            '#attributes' => ['class' => ['exact-approval']],
            'explanation' => ['#type' => 'item', '#markup' => $this->t('The registered public key verifies approval of the exact proposal. The application’s separate signature attests to the recorded execution. Trust the key registrations through your own records before using these files as independent verification inputs.')],
            'downloads' => ['#theme' => 'item_list', '#items' => [
                Link::fromTextAndUrl($this->t('Signed evidence bundle'), Url::fromRoute('exact_approval.evidence', ['id' => $id]))->toRenderable(),
                Link::fromTextAndUrl($this->t('Historical public credential registration'), Url::fromRoute('exact_approval.evidence_credential', ['id' => $id]))->toRenderable(),
                Link::fromTextAndUrl($this->t('Historical application verification key'), Url::fromRoute('exact_approval.evidence_trust', ['id' => $id]))->toRenderable(),
            ]],
            '#attached' => ['library' => ['exact_approval/review']],
            '#cache' => ['max-age' => 0],
        ];
        foreach ([
            'statement' => [$this->t('Exact approval statement'), $proposal->statement],
            'proposal' => [$this->t('Bound proposal'), $json($proposal)],
            'proof' => [$this->t('Passkey signature and verification record'), $json($bundle['proof'])],
            'execution' => [$this->t('Recorded execution'), $json($bundle['execution'])],
            'receipt' => [$this->t('Application signature'), $json($bundle['receipt'])],
            'credential' => [$this->t('Historical public credential'), $json($credential)],
            'trust' => [$this->t('Historical application public key'), $json($trust)],
        ] as $key => [$title, $value]) {
            $build[$key] = ['#type' => 'details', '#title' => $title, '#open' => in_array($key, ['statement', 'execution'], true), 'value' => ['#type' => 'html_tag', '#tag' => 'pre', '#value' => \Drupal\Component\Utility\Html::escape($value)]];
        }

        return $build;
    }

    public function registeredKeys(): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        if (!$this->actors->account($actor)->hasPermission('administer exact approval')) {
            throw new AccessDeniedHttpException('Only an approval administrator may inspect site key registrations.');
        }
        $rows = [];
        foreach ($this->credentials->recent(100) as $credential) {
            if ($credential->tenantId !== $actor->tenantId) continue;
            $rows[] = [
                ['data' => ['#plain_text' => $credential->userId]],
                ['data' => ['#plain_text' => $credential->label]],
                $credential->revoked ? $this->t('Revoked') : $this->t('Active'),
                ['data' => ['#plain_text' => $credential->id]],
                ['data' => ['#plain_text' => hash('sha256', $credential->sourceJson)]],
            ];
        }

        return [
            '#title' => $this->t('Registered approval keys'),
            'description' => ['#type' => 'item', '#markup' => $this->t('The 100 most recent public credential registrations, bound to Drupal user IDs. No private key is stored. The registration-record digest changes when authenticator counter metadata advances; completed evidence retains its historical record.')],
            'table' => ['#type' => 'table', '#header' => [$this->t('Drupal UID'), $this->t('Label'), $this->t('Status'), $this->t('Credential ID'), $this->t('Registration record SHA-256')], '#rows' => $rows, '#empty' => $this->t('No approval keys have been registered.')],
            '#attached' => ['library' => ['exact_approval/review']], '#cache' => ['max-age' => 0],
        ];
    }
}
