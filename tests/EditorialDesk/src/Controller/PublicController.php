<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entities)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('public/index.html.twig', ['pages' => $this->entities->getRepository(Page::class)->findBy(['status' => Page::PUBLISHED], ['liveTitle' => 'ASC'])]);
    }

    #[Route('/pages/{id}', name: 'public_page', methods: ['GET'])]
    public function page(string $id): Response
    {
        $page = $this->entities->find(Page::class, $id);
        if (!$page instanceof Page || Page::PUBLISHED !== $page->status) {
            throw $this->createNotFoundException('This page is not published.');
        }

        return $this->render('public/page.html.twig', ['page' => $page]);
    }
}
