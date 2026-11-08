<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Command;

use ExactApproval\Core\Audit\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The demo operator's reset between recordings: takes the live alert down, so the public page shows
 * no active alerts again. Accounts, passkeys, connections and the record of past approvals stay.
 * It is not part of the approval flow, and an agent cannot reach it; each take-down is recorded,
 * signed, in the security log, so the administrator's audit view shows when it happened.
 */
#[AsCommand(name: 'demo:all-clear', description: 'Take the public alert down so the demo can be run again, keeping accounts, passkeys and connections.')]
final class AllClearCommand extends Command
{
    public function __construct(private readonly \PDO $pdo, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->pdo->beginTransaction();
        $live = $this->pdo->query('SELECT id, tenant_id, published_revision, published_title FROM demo_announcements WHERE published_revision IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC);
        $this->pdo->exec('UPDATE demo_announcements SET published_revision = NULL, published_title = NULL, published_body = NULL WHERE published_revision IS NOT NULL');
        foreach ($live as $alert) {
            $this->audit->append($alert['tenant_id'], 'alert.taken_down', $alert['id'], ['by' => 'operator console (demo:all-clear)', 'revision' => (int) $alert['published_revision'], 'title' => $alert['published_title']]);
        }
        $this->pdo->commit();
        $output->writeln($live !== [] ? 'All clear: the public alerts page shows no active alerts again. The take-down is in the audit log.' : 'Already clear: no alert is live.');

        return Command::SUCCESS;
    }
}
