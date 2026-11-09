<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VerifiedHumanApproval\Core\Audit\AuditLog;

/**
 * Prints the signed audit log's head, its newest entry's sequence and hash, as a checkpoint to keep where the
 * application cannot write: a ticket, another system's log, a message to someone else. Signatures cannot show
 * that the newest entries were cut off, nor stop someone holding a signing key from rewriting the chain with
 * backdated entries; a kept checkpoint shows both. With --check, it compares a kept checkpoint with the chain
 * now: the same hash at that sequence, in a chain that verifies, means nothing up to it changed.
 */
final class AuditHeadCommand extends Command
{
    public const NAME = 'verified-human-approval:audit-head';

    public function __construct(private readonly ?AuditLog $audit = null)
    {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this->setDescription('Print the signed audit log\'s newest entry (sequence and hash) to keep outside the application, or check a kept one.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the head as one JSON line, for a script to store.')
            ->addOption('check', null, InputOption::VALUE_REQUIRED, 'A kept checkpoint, SEQUENCE:HASH as this command printed it, to compare with the chain now.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->audit) {
            $output->writeln('<error>No audit log to read: set verified_human_approval.audit_log to your AuditLog service.</error>');

            return Command::FAILURE;
        }
        $check = $input->getOption('check');
        if (\is_string($check)) {
            return $this->check($this->audit, $check, $output);
        }
        $head = $this->audit->head();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($head, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } elseif (null === $head) {
            $output->writeln('The audit log is empty: there is no entry to keep yet.');
        } else {
            $output->writeln([
                \sprintf('Newest entry: %d, hash %s, signed with key "%s"%s.', $head['seq'], $head['hash'], $head['keyId'], null === $head['at'] ? '' : ' at '.$head['at']),
                $head['intact']
                    ? \sprintf('All %d entries verify in an unbroken chain. Keep this checkpoint where the application cannot write, and check it later with --check:', $head['seq'])
                    : '<error>The chain does not verify, so this is no checkpoint to keep. Open the audit log to see which entries fail.</error>',
                $head['seq'].':'.$head['hash'],
            ]);
        }

        return null === $head || $head['intact'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function check(AuditLog $audit, string $checkpoint, OutputInterface $output): int
    {
        if (1 !== preg_match('/^([1-9][0-9]{0,17}):([0-9a-f]{64})$/D', $checkpoint, $match)) {
            $output->writeln('<error>Give the checkpoint as SEQUENCE:HASH, as this command printed it.</error>');

            return Command::INVALID;
        }
        [, $seq, $hash] = $match;
        $seq = (int) $seq;
        $report = $audit->verify();
        $kept = array_values(array_filter($report['entries'], static fn (array $item): bool => $item['seq'] === $seq))[0] ?? null;
        $problem = match (true) {
            $report['newest'] < $seq => \sprintf('The log now ends at entry %d, before the kept entry %d: entries were cut off.', $report['newest'], $seq),
            null === $kept => \sprintf('Entry %d is missing from the log.', $seq),
            !hash_equals($hash, $kept['hash']) => \sprintf('Entry %d is not the entry kept: the chain was rewritten from there or before.', $seq),
            !$report['intact'] => \sprintf('Entry %d is unchanged, but the chain does not verify. Open the audit log to see which entries fail.', $seq),
            default => null,
        };
        if (null !== $problem) {
            $output->writeln('<error>'.$problem.'</error>');

            return Command::FAILURE;
        }
        $output->writeln(\sprintf('The checkpoint holds: entry %d is unchanged, and all %d entries verify in an unbroken chain.', $seq, $report['newest']));

        return Command::SUCCESS;
    }
}
