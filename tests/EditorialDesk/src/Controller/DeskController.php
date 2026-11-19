<?php

declare(strict_types=1);

namespace App\Controller;

use App\Approval\CurrentAuthority;
use App\Entity\Page;
use App\Workflow\Drafts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\UnreadableApproval;

final class DeskController extends AbstractController
{
    public function __construct(private readonly ActorProvider $actors, private readonly CurrentAuthority $authority,
        private readonly Drafts $drafts, private readonly EntityManagerInterface $entities,
        private readonly ApprovalService $approvals, private readonly ApprovalHistory $history)
    {
    }

    #[Route('/desk', name: 'desk', methods: ['GET'])]
    public function index(): Response
    {
        $this->authority->assertReader($this->actors->current());

        return $this->render('desk/index.html.twig', ['pages' => $this->entities->getRepository(Page::class)->findBy([], ['draftTitle' => 'ASC'])]);
    }

    #[Route('/desk/pages', name: 'desk_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->csrf($request);
        try {
            $page = $this->drafts->create($this->actors->current(), (string) $request->request->get('title', ''), (string) $request->request->get('body', ''));
        } catch (ApprovalException $error) {
            return $this->problem($error);
        }

        return $this->redirectToRoute('desk_page', ['id' => $page->id]);
    }

    #[Route('/desk/pages/{id}', name: 'desk_page', methods: ['GET'])]
    public function page(string $id): Response
    {
        $this->authority->assertReader($this->actors->current());
        $page = $this->entities->find(Page::class, $id) ?? throw $this->createNotFoundException('Page not found.');

        return $this->render('desk/page.html.twig', ['page' => $page, 'request_key' => bin2hex(random_bytes(16))]);
    }

    #[Route('/desk/pages/{id}/draft', name: 'desk_draft', methods: ['POST'])]
    public function draft(Request $request, string $id): Response
    {
        $this->csrf($request);
        try {
            $this->drafts->save($this->actors->current(), $id, (string) $request->request->get('title', ''), (string) $request->request->get('body', ''));
        } catch (ApprovalException $error) {
            return $this->problem($error);
        }
        $this->addFlash('success', 'Working draft saved. The public version is unchanged.');

        return $this->redirectToRoute('desk_page', ['id' => $id]);
    }

    #[Route('/desk/pages/{id}/{operation}', name: 'desk_action', requirements: ['operation' => 'publish|edit-live|archive|delete'], methods: ['POST'])]
    public function action(Request $request, string $id, string $operation): Response
    {
        $this->csrf($request);
        $input = ['page_id' => $id];
        if ('edit-live' === $operation) {
            $input += ['title' => (string) $request->request->get('title', ''), 'body' => (string) $request->request->get('body', '')];
        }
        $key = $request->request->get('request_key');
        try {
            $state = $this->approvals->request($this->actors->current(), 'page.'.str_replace('-', '_', $operation), $input, \is_string($key) && '' !== $key ? $key : null);
        } catch (ApprovalException $error) {
            return $this->problem($error);
        }
        $return = 'delete' === $operation ? '/desk' : '/desk/pages/'.$id;
        if ('completed' === $state->status) {
            $this->addFlash('success', 'Change completed under your account’s approval policy.');

            return $this->redirect($return);
        }

        return $this->redirect('/approval/'.$state->request->id.'?return='.rawurlencode($return));
    }

    #[Route('/desk/history/{id}', name: 'desk_history', methods: ['GET'])]
    public function history(string $id): Response
    {
        $actor = $this->actors->current();
        $this->authority->assertReader($actor);
        $rows = [];
        foreach ($this->history->forResource($actor->tenantId, $id) as $state) {
            if ($state instanceof UnreadableApproval) {
                // Nothing in it is shown as what was asked or how it ended. While its proposal reads back, it still
                // shows whose it is; otherwise only its id is known (owned is null).
                $rows[] = ['unreadable' => true, 'id' => $state->requestId, 'owned' => $state->error->request?->actor()->samePerson($actor)];
                continue;
            }
            try {
                $state->assertConsistent();
                $integrity = true;
            } catch (ApprovalException) {
                $integrity = false;
            }
            $rows[] = ['unreadable' => false, 'state' => $state, 'integrity' => $integrity, 'owned' => $state->request->actor()->samePerson($actor)];
        }

        return $this->render('desk/history.html.twig', ['resource_id' => $id, 'rows' => $rows]);
    }

    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('desk', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Reload this form and try again.');
        }
    }

    private function problem(ApprovalException $error): Response
    {
        // A request that reuses a stored record failing its integrity check was not refused: nothing in that record
        // says whether the change ran, so the page is the one the review page shows for it.
        if ('record_integrity_error' === $error->reason) {
            return $this->render('@VerifiedHumanApproval/integrity_error.html.twig', ['id' => $error->request?->id], new Response('', 409));
        }
        $status = match ($error->reason) {
            'permission_denied','delegation_denied','scope_denied' => 403,
            'not_found','missing_resource' => 404,
            'stale_resource','idempotency_conflict' => 409,
            default => 400,
        };

        return $this->render('desk/error.html.twig', ['reason' => $error->reason, 'message' => $error->getMessage()], new Response('', $status));
    }
}
