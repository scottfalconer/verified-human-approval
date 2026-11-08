<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Controller;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalPolicy;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Demo\Action\PublishAnnouncement;
use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Demo\Form\ApprovalPolicyType;
use ExactApproval\Symfony\ActorProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class DemoController
{
    public function __construct(
        private DemoDatabase $database,
        private ActorProvider $actors,
        private ApprovalService $approvals,
        private PublishAnnouncement $action,
        private CsrfTokenManagerInterface $csrf,
        private FormFactoryInterface $forms,
        private Security $security,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
    ) {}

    public function home(): Response
    {
        $actor = $this->actors->interactive();
        $query = $this->database->pdo->prepare('SELECT * FROM demo_announcements WHERE tenant_id = ? ORDER BY id');
        $query->execute([$actor->tenantId]);

        return new Response($this->twig->render('home.html.twig', ['announcements' => $query->fetchAll(), 'actor' => $actor]));
    }

    public function requestPublication(Request $request, string $id): Response
    {
        $actor = $this->actors->interactive();
        if (!$this->csrf->isTokenValid(new CsrfToken('publish.'.$id, (string) $request->request->get('_token')))) throw new AccessDeniedException('Invalid CSRF token.');
        try {
            $state = $this->approvals->request($actor, 'announcement.publish', ['announcement_id' => $id]);
        } catch (ApprovalException $error) {
            throw new AccessDeniedException($error->getMessage());
        }

        return new RedirectResponse($this->urls->generate('exact_approval_review', ['id' => $state->request->id]));
    }

    public function editDraft(Request $request, string $id): Response
    {
        $actor = $this->actors->interactive();
        if (!$this->security->isGranted('ROLE_PUBLISHER') || !$this->csrf->isTokenValid(new CsrfToken('draft.'.$id, (string) $request->request->get('_token')))) throw new AccessDeniedException();
        $title = $request->request->get('title');
        $body = $request->request->get('body');
        if (!\is_string($title) || trim($title) === '' || \strlen($title) > 200 || !\is_string($body) || \strlen($body) > 20_000) throw new BadRequestHttpException('Invalid draft content.');
        // Editing a draft cannot update any published field. Pending approvals will become stale.
        $query = $this->database->pdo->prepare('UPDATE demo_announcements SET title = ?, body = ?, revision = revision + 1 WHERE id = ? AND tenant_id = ?');
        $query->execute([$title, $body, $id, $actor->tenantId]);

        return new RedirectResponse('/');
    }

    public function policy(Request $request): Response
    {
        $actor = $this->actors->interactive();
        if (!$this->security->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Policy administration requires a human administrator session.');
        $row = $this->database->policy('announcement.publish');
        $form = $this->forms->create(ApprovalPolicyType::class, ['required' => (bool) $row['required'], 'statement' => $row['statement'], 'version' => (string) $row['version']]);
        $form->handleRequest($request);
        $preview = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $policy = new ApprovalPolicy('announcement.publish', (string) ((int) $data['version'] + 1), $data['statement'], 600, $data['required']);
                $preview = $policy->statement($this->action->prepare($actor, ['announcement_id' => 'summer-campaign']));
                $query = $this->database->pdo->prepare('UPDATE demo_policies SET version = version + 1, statement = ?, required = ? WHERE action_id = ? AND version = ?');
                $query->execute([$data['statement'], (int) $data['required'], 'announcement.publish', (int) $data['version']]);
                if ($query->rowCount() !== 1) $form->addError(new FormError('The policy changed in another session. Reload before saving.'));
                else return new RedirectResponse('/admin/approval-policy?saved=1');
            } catch (\InvalidArgumentException | ApprovalException $error) {
                $form->addError(new FormError($error->getMessage()));
            }
        }
        if ($preview === null) {
            $preview = (new ApprovalPolicy('announcement.publish', (string) $row['version'], $row['statement']))->statement($this->action->prepare($actor, ['announcement_id' => 'summer-campaign']));
        }

        return new Response($this->twig->render('policy.html.twig', ['form' => $form->createView(), 'preview' => $preview, 'saved' => $request->query->getBoolean('saved')]));
    }
}
