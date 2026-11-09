<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Setup;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'publishing:setup', description: 'Create the schema, an editor and a reader, their agent tokens and their one-time passkey links.')]
final class SetupCommand extends Command
{
    public function __construct(private readonly Installer $installer, #[Autowire('%env(APP_ORIGIN)%')] private readonly string $origin)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accounts = $this->installer->install($this->origin);
        $output->writeln('The newsroom is set up. Keep this private: the passwords, tokens and links are shown only once.');
        $output->writeln('');
        foreach ($accounts as $id => $account) {
            $output->writeln(\sprintf('<info>%s</info>', $id));
            $output->writeln('  password      '.$account['password']);
            $output->writeln('  agent token   '.$account['agent_token']);
            $output->writeln('  passkey link  '.$account['passkey_link'].'  (sign in first, then open it; it works once, for seven days)');
        }
        $output->writeln('');
        $output->writeln('Visitors: '.$this->origin.'/    Newsroom: '.$this->origin.'/editor    MCP: '.$this->origin.'/mcp');

        return Command::SUCCESS;
    }
}
