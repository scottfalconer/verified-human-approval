<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Evidence;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\FixtureAction;

/**
 * Receipts across a change of the application's signing key, checked in code and by the offline command:
 * what the old key signed keeps verifying, a receipt whose signed time falls outside its key's window does
 * not, and neither does one signed with a key the trusted file does not list.
 */
final class ReceiptKeyRotationTest extends TestCase
{
    private const ROTATED = '2026-11-09T12:00:00Z';

    private CoreFixture $fixture;
    private SodiumEvidenceSigner $old;
    private SodiumEvidenceSigner $new;
    private TrustedKeys $keys;
    private string $directory;

    protected function setUp(): void
    {
        // The fixture's clock starts on 2026-11-08, before the rotation.
        $this->fixture = new CoreFixture();
        $this->old = new SodiumEvidenceSigner('application-1', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->new = new SodiumEvidenceSigner('application-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->keys = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'), TrustedKey::time(self::ROTATED)),
        ]);
        $this->directory = sys_get_temp_dir().'/verified-human-approval-rotation-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testReceiptsSignedBeforeARotationStillVerifyAfterIt(): void
    {
        $before = self::evidence($this->commit($this->old, 1));
        $this->fixture->clock->modify('2026-11-09T13:00:00Z');
        $after = self::evidence($this->commit($this->new, 2));

        $verifier = new ReceiptVerifier();
        $verified = $verifier->verify($before, $this->keys);
        self::assertSame(['application-1', Receipt::COMMIT], [$verified['keyId'], $verified['receiptSignedAt']]);
        self::assertSame('application-2', $verifier->verify($after, $this->keys)['keyId']);

        // Offline, with the published key set: both verify, and the command says which key vouched.
        $keySet = $this->file('receipt-keys.json', ['format' => TrustedKeys::FORMAT, 'currentKeyId' => 'application-2', 'keys' => $this->keys->toArray()] + self::site());
        foreach (['application-1' => $before, 'application-2' => $after] as $keyId => $evidence) {
            [$exit, $stdout, $stderr] = $this->check($evidence, $keySet);
            self::assertSame(0, $exit, $stderr);
            $result = json_decode($stdout, true, 8, \JSON_THROW_ON_ERROR);
            self::assertSame([$keyId, 'commit', true], [$result['applicationKeyId'], $result['applicationReportSignedAt'], $result['applicationSignedExecutionReport']]);
        }

        // The earlier one-key file still checks what its key signed, and vouches for nothing else.
        $oneKey = $this->file('receipt-key.json', ['format' => TrustedKeys::SINGLE_KEY_FORMAT, 'keyId' => 'application-1', 'publicKey' => $this->old->publicKey()] + self::site());
        [$exit, , $stderr] = $this->check($before, $oneKey);
        self::assertSame(0, $exit, $stderr);
        [$exit, $stdout, $stderr] = $this->check($after, $oneKey);
        self::assertSame([1, ''], [$exit, $stdout]);
        self::assertStringContainsString('The receipt is signed with key "application-2", which the trusted keys do not list.', $stderr);
    }

    public function testAReceiptClaimingATimeOutsideItsKeysWindowFails(): void
    {
        // The retired key signs as an action commits a second after its window closed: a stolen copy, say.
        $this->fixture->clock->modify('2026-11-09T12:00:01Z');
        $late = self::evidence($this->commit($this->old, 1));
        // The new key signs a receipt whose recorded time comes before its window opened.
        $this->fixture->clock->modify('2026-11-09T11:59:59Z');
        $early = self::evidence($this->commit($this->new, 2));

        $keySet = $this->file('receipt-keys.json', ['format' => TrustedKeys::FORMAT, 'keys' => $this->keys->toArray()] + self::site());
        foreach (['application-1' => $late, 'application-2' => $early] as $keyId => $evidence) {
            $this->assertRefused($evidence, $this->keys, \sprintf('The receipt claims a time outside the window of its key "%s".', $keyId));
            [$exit, , $stderr] = $this->check($evidence, $keySet);
            self::assertSame(1, $exit);
            self::assertStringContainsString(\sprintf('outside the window of its key "%s"', $keyId), $stderr);
        }
        // The signatures themselves are genuine: checked against the lone key without a window, as before, each verifies.
        self::assertSame('application-1', (new ReceiptVerifier())->verify($late, 'application-1', $this->old->publicKey())['keyId']);
        self::assertSame('application-2', (new ReceiptVerifier())->verify($early, 'application-2', $this->new->publicKey())['keyId']);
    }

    public function testAReceiptSignedWithAnUnknownKeyIdFails(): void
    {
        $stranger = new SodiumEvidenceSigner('application-9', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $evidence = self::evidence($this->commit($stranger, 1));
        $this->assertRefused($evidence, $this->keys, 'The receipt is signed with key "application-9", which the trusted keys do not list.');

        // Renaming the key in the receipt to a trusted one breaks what it signed.
        $evidence['receipt']->keyId = 'application-1';
        $this->assertRefused($evidence, $this->keys, 'Evidence components do not describe the same action.');
    }

    public function testAReceiptSignedAtExportIsRefusedOnlyWhenItsKeyRetiredBeforeTheActionWasRecorded(): void
    {
        // An application that has never signed at commit signs receipts as it exports them.
        $before = $this->commit(null, 1);
        $this->fixture->clock->modify('2026-11-09T13:00:00Z');
        $after = $this->commit(null, 2);

        // Exported after the rotation, an action recorded before it is signed with the new key: that is consistent.
        $verified = (new ReceiptVerifier())->verify((new EvidenceExporter($this->new, signAtExport: true))->export($before), $this->keys);
        self::assertSame(['application-2', Receipt::EXPORT], [$verified['keyId'], $verified['receiptSignedAt']]);
        self::assertSame('application-1', (new ReceiptVerifier())->verify((new EvidenceExporter($this->old, signAtExport: true))->export($before), $this->keys)['keyId']);
        // The retired key cannot have signed anything about an action recorded after its window closed.
        $this->assertRefused((new EvidenceExporter($this->old, signAtExport: true))->export($after), $this->keys, 'The receipt claims a time outside the window of its key "application-1".');
    }

    /** One approved action, signed at commit when a signer is given; the clock says when. */
    private function commit(?EvidenceSigner $signer, int $article): ApprovalState
    {
        $guard = new ExecutionGuard();
        $service = new ApprovalService(new ActionRegistry([new FixtureAction($this->fixture->pdo, $guard)]), $this->fixture->policies, $this->fixture->authorizer, $this->fixture->store, $this->fixture->verifier, $this->fixture->clock, $guard, $signer);
        $pending = $service->request($this->fixture->actor, 'article.publish', ['id' => $article]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $completed = $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('completed', $completed->status);

        return $service->get($this->fixture->actor, $completed->request->id);
    }

    /** @return array<string, mixed> the evidence with its original receipt, as the person downloads it */
    private static function evidence(ApprovalState $state): array
    {
        return (new EvidenceExporter())->export($state);
    }

    /** @param array<string, mixed> $evidence */
    private function assertRefused(array $evidence, TrustedKeys $keys, string $reason): void
    {
        try {
            (new ReceiptVerifier())->verify($evidence, $keys);
            self::fail('The receipt verified.');
        } catch (ApprovalException $error) {
            self::assertSame('invalid_evidence', $error->reason);
            self::assertSame($reason, $error->getPrevious()?->getMessage());
        }
    }

    /** @return array{rpId: string, origin: string} */
    private static function site(): array
    {
        return ['rpId' => 'approval.test', 'origin' => 'https://approval.test'];
    }

    /** @param array<string, mixed> $contents */
    private function file(string $name, array $contents): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, json_encode($contents, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Runs the offline command, as a person would, on the evidence and a trusted key file.
     *
     * @param array<string, mixed> $evidence
     *
     * @return array{int, string, string}
     */
    private function check(array $evidence, string $keyFile): array
    {
        $receipt = $this->file('receipt.json', $evidence);
        $process = proc_open([\PHP_BINARY, \dirname(__DIR__, 2).'/src/webauthn/bin/verify-evidence.php', $receipt, $keyFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
