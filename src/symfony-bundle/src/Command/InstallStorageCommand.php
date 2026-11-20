<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;

/**
 * Creates the approval, audit and passkey tables that are missing on the approval connection, with their lock rows,
 * for an application without migrations. With migrations, doctrine:migrations:diff creates them instead. An
 * installation step: never run it on each request, and never inside a transaction.
 */
#[AsCommand(name: 'verified-human-approval:install', description: 'Create the approval, audit and passkey tables on the approval connection.')]
final class InstallStorageCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ApprovalSchema::install($this->connection);
        (new SymfonyStyle($input, $output))->success('Approval storage is ready.');

        return Command::SUCCESS;
    }
}
