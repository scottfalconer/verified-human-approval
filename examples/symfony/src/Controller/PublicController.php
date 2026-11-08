<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Controller;

use ExactApproval\Demo\DemoDatabase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** The council's public site. It shows only content that an approved proposal published. */
final readonly class PublicController
{
    public function __construct(private DemoDatabase $database, private Environment $twig) {}

    public function news(string $id): Response
    {
        $row = $this->database->announcement($id);
        if ($row === null || $row['published_revision'] === null) {
            return new Response($this->twig->render('public/not_published.html.twig'), 404);
        }

        return new Response($this->twig->render('public/announcement.html.twig', ['title' => $row['published_title'], 'body' => $row['published_body']]));
    }

    public function styles(): Response
    {
        return new Response((string) file_get_contents(\dirname(__DIR__, 2).'/assets/riverside.css'), headers: ['Content-Type' => 'text/css; charset=utf-8']);
    }
}
