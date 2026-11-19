<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;

/**
 * The last step of a key rotation: records in the signed audit log that trust moved from the old key to the
 * current receipt signer, in an entry the new key signs. Run it once every host signs with the new key and
 * receipt_keys lists the old one, closed. From that entry on, an entry the old key signs is refused, whatever
 * time it claims. The rotation procedure is in docs/evidence.md.
 */
#[AsCommand(name: self::NAME, description: 'Record in the signed audit log that the signing key moved from an old key to the current receipt signer.')]
final class RecordKeyRotationCommand extends Command
{
    public const NAME = 'verified-human-approval:record-key-rotation';

    public function __construct(private readonly ?AuditLog $audit = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('from', InputArgument::REQUIRED, 'The id of the key the application signed with before, now listed under receipt_keys.retired.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why the key changed, recorded with the entry: "scheduled", or "suspected compromise".', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->audit) {
            $output->writeln('<error>No audit log to record in: set verified_human_approval.audit_log to your AuditLog service.</error>');

            return Command::FAILURE;
        }
        $from = (string) $input->getArgument('from');
        try {
            $entry = $this->audit->recordRotation($from, (string) $input->getOption('reason'));
        } catch (\InvalidArgumentException|\LogicException $error) {
            // The message names the key as the operator typed it: it is shown, never read as formatting.
            $output->writeln('<error>'.OutputFormatter::escape($error->getMessage()).'</error>');

            return Command::FAILURE;
        }
        $output->writeln(\sprintf('Entry %d records that trust moved from key "%s" to key "%s" at %s.', $entry['seq'], OutputFormatter::escape($entry['data']->from), OutputFormatter::escape($entry['data']->to), $entry['at']));
        $old = $this->audit->trustedKeys()->get($from);
        if (null !== $old?->validUntil && $old->validUntil > TrustedKey::time($entry['at'])) {
            $output->writeln(\sprintf('<comment>Key "%s" stays trusted for records claiming times up to %s. Once no host signs with it, shorten its valid_until to limit what a stolen copy could still sign.</comment>', OutputFormatter::escape($from), $old->toArray()['validUntil']));
        }
        $head = $this->audit->head();
        if (null !== $head) {
            $output->writeln(['Keep the new head where the application cannot write (verified-human-approval:audit-head checks it later):', $head['seq'].':'.$head['hash']]);
        }

        return Command::SUCCESS;
    }
}
