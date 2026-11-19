<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use VerifiedHumanApproval\Core\Audit\AuditLog;

/**
 * Prints the signed audit log's head, its newest entry's sequence and hash, as a checkpoint to keep where the
 * application cannot write: a ticket, another system's log, a message to someone else. Signatures cannot show
 * that the newest entries were cut off, nor stop someone holding a signing key from rewriting the chain with
 * backdated entries; a kept checkpoint shows both. With --check, it compares a kept checkpoint with the chain
 * now: the same hash at that sequence, in a chain that verifies, means nothing up to it changed.
 *
 * Key ids and problems come from the stored log, so they are printed as text, never read as formatting.
 */
#[AsCommand(name: self::NAME, description: 'Print the signed audit log\'s newest entry (sequence and hash) to keep outside the application, or check a kept one.')]
final class AuditHeadCommand extends Command
{
    public const NAME = 'verified-human-approval:audit-head';

    /** How many failing entries a report names before it only counts the rest. */
    private const LISTED = 10;

    public function __construct(private readonly ?AuditLog $audit = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the head as one JSON line, for a script to store.')
            ->addOption('check', null, InputOption::VALUE_REQUIRED, 'A kept checkpoint, SEQUENCE:HASH as this command printed it, to compare with the chain now.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (null === $this->audit) {
            $io->error('No audit log to read: set verified_human_approval.audit_log to your AuditLog service.');

            return Command::FAILURE;
        }
        $check = $input->getOption('check');
        if (\is_string($check)) {
            return $this->check($this->audit, $check, $io);
        }
        $head = $this->audit->head();
        if ($input->getOption('json')) {
            // For a script to store, exactly as encoded.
            $output->writeln(json_encode($head, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        } elseif (null === $head) {
            $io->writeln('The audit log is empty: there is no entry to keep yet.');
        } else {
            $io->writeln(\sprintf('Newest entry: %d, hash %s, signed with key "%s"%s.', $head['seq'], $head['hash'], OutputFormatter::escape($head['keyId']), null === $head['at'] ? '' : ' at '.OutputFormatter::escape($head['at'])));
            if ($head['intact']) {
                $io->writeln([
                    \sprintf('All %d entries verify in an unbroken chain. Keep this checkpoint where the application cannot write, and check it later with --check:', $head['seq']),
                    $head['seq'].':'.$head['hash'],
                ]);
            } else {
                $io->error(['The chain does not verify, so this is no checkpoint to keep.', ...$this->failures($this->audit)]);
            }
            // The usual reason after a key change: the rotation away from the old key is not recorded yet.
            $unvouched = $this->audit->unvouchedKeys();
            foreach ($unvouched as $keyId) {
                $io->error(\sprintf('Key "%1$s" has closed, and nothing signed with a key in use vouches for its entries yet, so passkeys enrolled under it cannot approve. Record the rotation away from it: %2$s %1$s', $keyId, RecordKeyRotationCommand::NAME));
            }
            foreach (array_diff($this->audit->unrecordedRotations(), $unvouched) as $keyId) {
                $io->warning(\sprintf('Key "%1$s" has closed, and the log does not record the rotation away from it. Record it: %2$s %1$s', $keyId, RecordKeyRotationCommand::NAME));
            }
        }

        return null === $head || $head['intact'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The entries that fail and why, the first few of them.
     *
     * @return list<string>
     */
    private function failures(AuditLog $audit): array
    {
        $failing = array_values(array_filter($audit->verify()['entries'], static fn (array $item): bool => !$item['valid']));
        $lines = array_map(static fn (array $item): string => \sprintf('Entry %d: %s.', $item['seq'], $item['problem'] ?? 'it does not verify'), \array_slice($failing, 0, self::LISTED));
        if (\count($failing) > self::LISTED) {
            $lines[] = \sprintf('And %d more.', \count($failing) - self::LISTED);
        }

        return $lines;
    }

    private function check(AuditLog $audit, string $checkpoint, SymfonyStyle $io): int
    {
        if (1 !== preg_match('/^([1-9][0-9]{0,17}):([0-9a-f]{64})$/D', $checkpoint, $match)) {
            $io->error('Give the checkpoint as SEQUENCE:HASH, as this command printed it.');

            return Command::INVALID;
        }
        [, $seq, $hash] = $match;
        $seq = (int) $seq;
        $problem = $audit->checkpoint($seq, $hash);
        if (null !== $problem) {
            $io->error([$problem, ...$this->failures($audit)]);

            return Command::FAILURE;
        }
        $io->success(\sprintf('The checkpoint holds: entry %d is unchanged, and all %d entries verify in an unbroken chain.', $seq, $audit->head()['seq'] ?? $seq));

        return Command::SUCCESS;
    }
}
