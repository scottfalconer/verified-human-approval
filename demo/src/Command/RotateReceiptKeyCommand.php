<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * The demo's key rotation: the procedure in docs/evidence.md in one step, for a site on one host. A new
 * receipt key signs from now. The old key's window closes now and only its public key is kept, so it still
 * checks what it signed but vouches for nothing newer. Then the signed audit log records the move, signed with
 * the new key. Run it while nobody is approving: a request already under way finishes with the old key, and
 * what it signs after the switch would not verify.
 */
#[AsCommand(name: 'demo:rotate-receipt-key', description: 'Replace the demo\'s receipt and audit signing key, keeping the old public key to check what it signed.')]
final class RotateReceiptKeyCommand extends Command
{
    public function __construct(
        #[Autowire('%env(DEMO_RUNTIME_DIRECTORY)%')] private readonly string $runtime,
        private readonly AuditStore $auditStore,
        #[Autowire(service: 'verified_human_approval.clock')] private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why the key changes, recorded with the audit entry.', 'scheduled rotation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read from disk, not from this kernel: the file is what every new request reads.
        $path = $this->runtime.'/settings.json';
        $settings = json_decode((string) file_get_contents($path), true, 32, \JSON_THROW_ON_ERROR);
        $old = (string) $settings['receipt_key_id'];
        $retired = $settings['retired_receipt_keys'] ?? [];
        $id = self::nextId($old, [$old, ...array_column($retired, 'key_id')]);
        $now = ApprovalRequest::timestamp($this->clock->now());
        $retired[] = ['key_id' => $old, 'public_key' => (string) $settings['receipt_public_key'], 'valid_from' => $settings['receipt_key_valid_from'] ?? null, 'valid_until' => $now];
        $keypair = sodium_crypto_sign_keypair();
        $signer = new SodiumEvidenceSigner($id, sodium_crypto_sign_secretkey($keypair));
        // Checked before anything changes: the new configuration must build.
        $audit = new AuditLog($this->auditStore, $signer, TrustedKeys::forSigner($signer, $now, $retired), $this->clock);

        // The old private key is not kept: from here on, only its public key and window are.
        $settings = [
            'receipt_key_id' => $id,
            'receipt_secret_key' => ChallengeBinding::encode(sodium_crypto_sign_secretkey($keypair)),
            'receipt_public_key' => $signer->publicKey(),
            'receipt_key_valid_from' => $now,
            'retired_receipt_keys' => $retired,
        ] + $settings;
        $temporary = tempnam($this->runtime, 'settings-');
        if (false === $temporary || false === file_put_contents($temporary, json_encode($settings, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n") || !chmod($temporary, 0600) || !rename($temporary, $path)) {
            throw new \RuntimeException('Could not write '.$path.'; the key was not rotated.');
        }

        try {
            $entry = $audit->recordRotation($old, (string) $input->getOption('reason'));
        } catch (\Throwable $error) {
            $output->writeln(\sprintf('<error>The site now signs with key "%s", but recording the rotation failed: %s Record it with: php demo/bin/console verified-human-approval:record-key-rotation %s</error>', $id, $error->getMessage(), $old));

            return Command::FAILURE;
        }
        $head = $audit->head();
        $output->writeln([
            \sprintf('The site signs with key "%s" from %s. Key "%s" is retired: it still checks what it signed until then, and its private key is gone.', $id, $now, $old),
            \sprintf('Audit entry %d records the rotation. /approval/receipt-keys.json now lists both keys; save it again.', $entry['seq']),
            'Keep this checkpoint of the log\'s head somewhere this site cannot write:',
            null === $head ? '' : $head['seq'].':'.$head['hash'],
        ]);

        return Command::SUCCESS;
    }

    /**
     * The next key id: demo-application-1 becomes demo-application-2, skipping any id already used.
     *
     * @param list<mixed> $used
     */
    private static function nextId(string $current, array $used): string
    {
        [$stem, $number] = 1 === preg_match('/^(.*?)(\d+)$/D', $current, $match) ? [$match[1], (int) $match[2]] : [$current.'-', 1];
        do {
            $id = $stem.++$number;
        } while (\in_array($id, $used, true));

        return $id;
    }
}
