<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
                \sprintf('Newest entry: %d, hash %s, signed with key "%s"%s.', $head['seq'], $head['hash'], OutputFormatter::escape($head['keyId']), null === $head['at'] ? '' : ' at '.OutputFormatter::escape($head['at'])),
                $head['intact']
                    ? \sprintf('All %d entries verify in an unbroken chain. Keep this checkpoint where the application cannot write, and check it later with --check:', $head['seq'])
                    : '<error>The chain does not verify, so this is no checkpoint to keep.</error>',
                $head['seq'].':'.$head['hash'],
            ]);
            if (!$head['intact']) {
                $this->failures($this->audit, $output);
            }
            // The usual reason after a key change: the rotation away from the old key is not recorded yet.
            $unvouched = $this->audit->unvouchedKeys();
            foreach ($unvouched as $keyId) {
                $output->writeln(\sprintf('<error>Key "%1$s" has closed, and nothing signed with a key in use vouches for its entries yet, so passkeys enrolled under it cannot approve. Record the rotation away from it: %2$s %1$s</error>', OutputFormatter::escape($keyId), RecordKeyRotationCommand::NAME));
            }
            foreach (array_diff($this->audit->unrecordedRotations(), $unvouched) as $keyId) {
                $output->writeln(\sprintf('<comment>Key "%1$s" has closed, and the log does not record the rotation away from it. Record it: %2$s %1$s</comment>', OutputFormatter::escape($keyId), RecordKeyRotationCommand::NAME));
            }
        }

        return null === $head || $head['intact'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** Names the entries that fail, the first few of them. */
    private function failures(AuditLog $audit, OutputInterface $output): void
    {
        $failing = array_values(array_filter($audit->verify()['entries'], static fn (array $item): bool => !$item['valid']));
        foreach (\array_slice($failing, 0, self::LISTED) as $item) {
            $output->writeln(\sprintf('<error>Entry %d: %s.</error>', $item['seq'], OutputFormatter::escape($item['problem'] ?? 'it does not verify')));
        }
        if (\count($failing) > self::LISTED) {
            $output->writeln(\sprintf('<error>And %d more.</error>', \count($failing) - self::LISTED));
        }
    }

    private function check(AuditLog $audit, string $checkpoint, OutputInterface $output): int
    {
        if (1 !== preg_match('/^([1-9][0-9]{0,17}):([0-9a-f]{64})$/D', $checkpoint, $match)) {
            $output->writeln('<error>Give the checkpoint as SEQUENCE:HASH, as this command printed it.</error>');

            return Command::INVALID;
        }
        [, $seq, $hash] = $match;
        $seq = (int) $seq;
        $problem = $audit->checkpoint($seq, $hash);
        if (null !== $problem) {
            $output->writeln('<error>'.OutputFormatter::escape($problem).'</error>');
            $this->failures($audit, $output);

            return Command::FAILURE;
        }
        $output->writeln(\sprintf('The checkpoint holds: entry %d is unchanged, and all %d entries verify in an unbroken chain.', $seq, $audit->head()['seq'] ?? $seq));

        return Command::SUCCESS;
    }
}
