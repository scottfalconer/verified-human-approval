<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Controller;

use VerifiedHumanApproval\Demo\DemoDatabase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** The council's public alerts page. It shows only an alert that an approved proposal made live. */
final readonly class PublicController
{
    public function __construct(private DemoDatabase $database, private Environment $twig) {}

    /** The alerts page: calm until an approved alert is live, then in alert mode. */
    public function alerts(): Response
    {
        $row = $this->database->liveAlert();
        if ($row === null) {
            return new Response($this->twig->render('public/not_published.html.twig'));
        }

        return new Response($this->twig->render('public/announcement.html.twig', ['title' => $row['published_title'], 'body' => $row['published_body']]));
    }

    public function styles(): Response
    {
        return new Response((string) file_get_contents(\dirname(__DIR__, 2).'/assets/riverside.css'), headers: ['Content-Type' => 'text/css; charset=utf-8']);
    }
}
