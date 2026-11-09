<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Bundle\SecurityHeadersSubscriber;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Example\Publishing\Action\ArchiveArticles;
use VerifiedHumanApproval\Example\Publishing\Action\DeleteArticle;
use VerifiedHumanApproval\Example\Publishing\Action\EditLiveArticle;
use VerifiedHumanApproval\Example\Publishing\Action\PublishArticle;
use VerifiedHumanApproval\Example\Publishing\Editing\Drafts;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;
use VerifiedHumanApproval\Example\Publishing\Entity\User;

/**
 * The newsroom, in the person's own browser. Drafts save at once. The four changes to what visitors see are only
 * requested here: each is one ApprovalService::request() call, the same one the MCP tools make, followed by the
 * bundle's review page, where the person approves the frozen proposal with their passkey.
 */
#[Route('/editor', defaults: [SecurityHeadersSubscriber::ROUTE_DEFAULT => true])]
final class EditorController extends AbstractController
{
    private const FORM = 'newsroom';

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ActorProvider $actors,
        private readonly Drafts $drafts,
        private readonly EntityManagerInterface $entities,
    ) {
    }

    #[Route('', name: 'editor', methods: ['GET'])]
    public function index(): Response
    {
        $this->actors->interactive();

        return $this->render('editor/index.html.twig', ['articles' => $this->entities->getRepository(Article::class)->findBy([], ['draftTitle' => 'ASC'])]);
    }

    #[Route('/articles/{id}', name: 'editor_article', methods: ['GET'], requirements: ['id' => '[0-9a-f]{16}'])]
    public function article(string $id): Response
    {
        $this->actors->interactive();

        return $this->render('editor/article.html.twig', [
            'article' => $this->entities->find(Article::class, $id) ?? throw $this->createNotFoundException('No such article.'),
        ]);
    }

    #[Route('/articles', name: 'editor_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertForm($request);
        try {
            $article = $this->drafts->create($this->editor(), $request->getPayload()->get('title'), $request->getPayload()->get('body'));
        } catch (ApprovalException $error) {
            return $this->back($error->getMessage(), $this->generateUrl('editor'));
        }

        return $this->redirectToRoute('editor_article', ['id' => $article->id()]);
    }

    #[Route('/articles/{id}/draft', name: 'editor_save', methods: ['POST'], requirements: ['id' => '[0-9a-f]{16}'])]
    public function save(Request $request, string $id): Response
    {
        $this->assertForm($request);
        try {
            $this->drafts->save($this->editor(), $id, $request->getPayload()->get('title'), $request->getPayload()->get('body'));
        } catch (ApprovalException $error) {
            return $this->back($error->getMessage(), $this->generateUrl('editor_article', ['id' => $id]));
        }
        $this->addFlash('notice', 'Draft saved. Visitors do not see drafts.');

        return $this->redirectToRoute('editor_article', ['id' => $id]);
    }

    #[Route('/articles/{id}/publish', name: 'editor_publish', methods: ['POST'], requirements: ['id' => '[0-9a-f]{16}'])]
    public function publish(Request $request, string $id): Response
    {
        return $this->ask($request, PublishArticle::ID, ['article_id' => $id], $this->generateUrl('editor_article', ['id' => $id]));
    }

    #[Route('/articles/{id}/live', name: 'editor_edit_live', methods: ['POST'], requirements: ['id' => '[0-9a-f]{16}'])]
    public function editLive(Request $request, string $id): Response
    {
        $form = $request->getPayload();

        return $this->ask($request, EditLiveArticle::ID, ['article_id' => $id, 'title' => $form->get('title'), 'body' => $form->get('body')], $this->generateUrl('editor_article', ['id' => $id]));
    }

    #[Route('/archive', name: 'editor_archive', methods: ['POST'])]
    public function archive(Request $request): Response
    {
        return $this->ask($request, ArchiveArticles::ID, ['article_ids' => array_values($request->getPayload()->all('article_ids'))], $this->generateUrl('editor'));
    }

    #[Route('/articles/{id}/delete', name: 'editor_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f]{16}'])]
    public function delete(Request $request, string $id): Response
    {
        return $this->ask($request, DeleteArticle::ID, ['article_id' => $id], $this->generateUrl('editor'));
    }

    /**
     * Requests the change and opens its review, which leads back to $back once it finishes.
     *
     * @param array<string, mixed> $input
     */
    private function ask(Request $request, string $action, array $input, string $back): Response
    {
        $actor = $this->actors->interactive();
        $this->assertForm($request);
        try {
            $state = $this->approvals->request($actor, $action, $input);
        } catch (ApprovalException $error) {
            return $this->back($error->getMessage(), $back);
        }

        return $this->redirectToRoute('verified_human_approval_review', ['id' => $state->request->id, 'return' => $back]);
    }

    private function assertForm(Request $request): void
    {
        $this->actors->interactive();
        if (!$this->isCsrfTokenValid(self::FORM, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedException('This form expired. Reload the page and try again.');
        }
    }

    private function editor(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new AccessDeniedException('Sign in first.');
    }

    private function back(string $message, string $url): Response
    {
        $this->addFlash('error', $message);

        return $this->redirect($url);
    }
}
