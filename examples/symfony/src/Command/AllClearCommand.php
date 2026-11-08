<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The demo operator's reset between recordings: takes the live alert down, so the public page shows
 * no active alerts again. Accounts, passkeys, connections and the record of past approvals stay.
 * It is not part of the approval flow, and an agent cannot reach it.
 */
#[AsCommand(name: 'demo:all-clear', description: 'Take the public alert down so the demo can be run again, keeping accounts, passkeys and connections.')]
final class AllClearCommand extends Command
{
    public function __construct(private readonly \PDO $pdo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cleared = $this->pdo->exec('UPDATE demo_announcements SET published_revision = NULL, published_title = NULL, published_body = NULL WHERE published_revision IS NOT NULL');
        $output->writeln($cleared > 0 ? 'All clear: the public alerts page shows no active alerts again.' : 'Already clear: no alert is live.');

        return Command::SUCCESS;
    }
}
