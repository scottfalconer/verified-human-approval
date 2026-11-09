<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use VerifiedHumanApproval\Bundle\SecurityHeadersSubscriber;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;

/**
 * What visitors see: the live articles, each with "Approved with a passkey" when its live text is what a person
 * approved. The template asks the bundle for the badge, which checks the stored approval and its signed receipt
 * against the text the page actually shows: text changed by any other path loses it.
 */
final class PublicController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'], defaults: [SecurityHeadersSubscriber::ROUTE_DEFAULT => true])]
    public function index(EntityManagerInterface $entities): Response
    {
        return $this->render('public/index.html.twig', [
            'articles' => $entities->getRepository(Article::class)->findBy(['status' => Article::LIVE], ['liveTitle' => 'ASC']),
        ]);
    }
}
