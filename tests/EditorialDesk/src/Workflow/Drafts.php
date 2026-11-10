<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Approval\CurrentAuthority;
use App\Entity\Page;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Doctrine\DbalTransaction;

/** Saves working text without changing any published column, including while the page remains live. */
final readonly class Drafts
{
    public function __construct(private ManagerRegistry $doctrine, private DbalTransaction $transaction, private CurrentAuthority $authority)
    {
    }

    public function create(Actor $actor, string $title, string $body): Page
    {
        $title = Text::title($title);
        $body = Text::body($body);

        return $this->transaction->run(function () use ($actor, $title, $body): Page {
            $this->authority->assertWriter($actor);
            $manager = $this->manager();
            $manager->clear();
            $this->onRollback();
            $page = new Page(bin2hex(random_bytes(16)), $title, $body);
            $manager->persist($page);
            $manager->flush();

            return $page;
        });
    }

    public function save(Actor $actor, string $id, string $title, string $body): Page
    {
        $title = Text::title($title);
        $body = Text::body($body);

        return $this->transaction->run(function () use ($actor, $id, $title, $body): Page {
            $this->authority->assertWriter($actor);
            $manager = $this->manager();
            $manager->clear();
            $this->onRollback();
            $page = $manager->find(Page::class, $id, LockMode::PESSIMISTIC_WRITE)
                ?? throw new ApprovalException('not_found', 'No such page.');
            $page->draftTitle = $title;
            $page->draftBody = $body;
            ++$page->draftRevision;
            $manager->flush();

            return $page;
        });
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->doctrine->resetManager();
        }
        if (!$manager instanceof EntityManagerInterface || $manager->getConnection() !== $this->transaction->connection()) {
            throw new \LogicException('Draft writes and approval storage must use the same Doctrine connection.');
        }

        return $manager;
    }

    private function onRollback(): void
    {
        $this->transaction->onRollback(function (): void {
            $this->doctrine->resetManager();
        });
    }
}
