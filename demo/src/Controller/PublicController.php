<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;
use VerifiedHumanApproval\Demo\DemoDatabase;

/** The council's public alerts page. It shows only an alert that an approved proposal made live. */
#[AsController]
final readonly class PublicController
{
    public function __construct(private DemoDatabase $database, private Environment $twig)
    {
    }

    /** The alerts page: calm until an approved alert is live, then in alert mode. */
    public function alerts(): Response
    {
        $row = $this->database->liveAlert();
        if (null === $row) {
            return new Response($this->twig->render('public/not_published.html.twig'));
        }

        return new Response($this->twig->render('public/alert.html.twig', ['title' => $row['published_title'], 'body' => $row['published_body']]));
    }

    /** The council site's look, shared by the alerts page and the approval preview. It is part of the alert action's version. */
    public function styles(): Response
    {
        return self::stylesheet('riverside.css');
    }

    /** The demo application's own pages around the approval: its header, alert list and rule page. Not part of any action's version. */
    public function demoStyles(): Response
    {
        return self::stylesheet('demo.css');
    }

    private static function stylesheet(string $file): Response
    {
        return new Response((string) file_get_contents(\dirname(__DIR__, 2).'/assets/'.$file), headers: ['Content-Type' => 'text/css; charset=utf-8']);
    }
}
