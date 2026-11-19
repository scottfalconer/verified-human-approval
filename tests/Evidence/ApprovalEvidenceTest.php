<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Evidence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** Runs the actual approval core, SQLite mutation, WebAuthn validator and receipt signatures together. */
final class ApprovalEvidenceTest extends TestCase
{
    private CoreFixture $fixture;
    private ApprovalService $service;
    private FixtureAction $action;
    private PdoCredentialRepository $credentials;
    private WebAuthnVerifier $verifier;
    private VirtualAuthenticator $authenticator;
    private Credential $trustedCredential;
    private SodiumEvidenceSigner $signer;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
        $this->credentials = new PdoCredentialRepository($this->fixture->pdo);
        $this->credentials->initialize();
        $this->verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $this->credentials);
        $this->authenticator = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->fixture->reviewer, random_bytes(32), 'Alice');
        $this->trustedCredential = $this->verifier->register($this->fixture->reviewer, $options, $this->authenticator->registration($options));
        $guard = new ExecutionGuard();
        $this->action = new FixtureAction($this->fixture->pdo, $guard);
        $this->service = new ApprovalService(
            new ActionRegistry([$this->action]),
            $this->fixture->policies,
            $this->fixture->authorizer,
            $this->fixture->store,
            $this->verifier,
            $this->fixture->clock,
            $guard,
        );
        $keypair = sodium_crypto_sign_keypair();
        $this->signer = new SodiumEvidenceSigner('test-application-key', sodium_crypto_sign_secretkey($keypair));
    }

    public function testRealApprovalCommitsOnceAndBothIndependentSignatureChecksPass(): void
    {
        $pending = $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        self::assertSame('pending', $pending->status);
        self::assertSame(0, $this->fixture->effectCount());
        $ceremony = $this->service->begin($this->fixture->reviewer, $pending->request->id);
        $assertion = $this->authenticator->assertion($ceremony->options);
        $completed = $this->service->complete($this->fixture->reviewer, $ceremony->id, $assertion);
        self::assertSame('completed', $completed->status);
        self::assertSame(1, $this->fixture->effectCount());
        self::assertSame(17, $completed->result['source_revision']);
        self::assertSame(18, $completed->result['result_revision']);
        $repeat = $this->service->complete($this->fixture->reviewer, $ceremony->id, $assertion);
        self::assertSame(CanonicalJson::encode((object) $completed->result), CanonicalJson::encode((object) $repeat->result));
        self::assertSame(1, $this->fixture->effectCount());
        // Re-read the persisted evidence to exercise nested JSON object preservation.
        $bundle = $this->exportPersisted($completed);
        $verified = (new ReceiptVerifier())->verify($bundle, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
        self::assertTrue($verified['applicationSignatureValid']);
        $this->verifyHuman($verified);
        [$exit, $stdout, $stderr] = $this->runVerifier($bundle);
        self::assertSame(0, $exit, $stderr);
        $result = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($result['credentialApprovedExactProposal']);
        self::assertTrue($result['applicationSignedExecutionReport']);
        self::assertSame(Receipt::EXPORT, $result['applicationReportSignedAt'], 'This service signs no receipt at commit, and the report says so.');
        self::assertSame($completed->request->statement(), $result['statement']);
        self::assertStringContainsString('does not independently witness', $result['qualification']);
    }

    #[DataProvider('tamperingCases')]
    public function testTamperedEvidenceCannotBeVerified(string $field): void
    {
        $bundle = $this->exportPersisted($this->complete());
        switch ($field) {
            case 'statement':
                $proposal = CanonicalJson::decode($bundle['proposal']);
                $proposal->statement .= '!';
                $bundle['proposal'] = CanonicalJson::encode($proposal);
                break;
            case 'effect':
                $proposal = CanonicalJson::decode($bundle['proposal']);
                $proposal->action->effect->body .= '!';
                $bundle['proposal'] = CanonicalJson::encode($proposal);
                break;
            case 'execution':
                $bundle['execution']->result->result_revision = 999;
                break;
            case 'assertion':
                $bundle['proof']->assertion->response->signature = ChallengeBinding::encode(random_bytes(72));
                break;
            case 'nonce':
                $bundle['proof']->nonce = ChallengeBinding::encode(random_bytes(32));
                break;
            case 'key-id':
                $bundle['receipt']->keyId = 'attacker';
                break;
            case 'signature':
                $bundle['receipt']->signature = ChallengeBinding::encode(random_bytes(64));
                break;
            case 'added-field':
                $bundle['approvedBy'] = 'the board';
                break;
            case 'added-receipt-field':
                $bundle['receipt']->verifiedBy = 'an auditor';
                break;
        }
        try {
            (new ReceiptVerifier())->verify($bundle, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
            self::fail('Altered evidence was accepted.');
        } catch (ApprovalException $exception) {
            self::assertSame('invalid_evidence', $exception->reason);
        }
        [$exit, $stdout, $stderr] = $this->runVerifier($bundle);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Verification failed', $stderr);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public static function tamperingCases(): iterable
    {
        foreach (['statement', 'effect', 'execution', 'assertion', 'nonce', 'key-id', 'signature', 'added-field', 'added-receipt-field'] as $case) {
            yield $case => [$case];
        }
    }

    public function testAnApplicationSignatureCannotSubstituteForTheHumanCredentialSignature(): void
    {
        $bundle = $this->exportPersisted($this->complete());
        $proposal = CanonicalJson::decode($bundle['proposal']);
        $proposal->statement .= ' This extra requirement was never reviewed.';
        $bundle['proposal'] = CanonicalJson::encode($proposal);
        $digest = hash('sha256', $bundle['proposal']);
        $bundle['execution']->proposalDigest = $digest;
        $bundle['proof']->proposalDigest = $digest;
        $bundle['proof']->challenge = ChallengeBinding::encode(ChallengeBinding::challenge($digest, ChallengeBinding::decode($bundle['proof']->nonce)));
        $manifest = CanonicalJson::encode([
            'format' => Receipt::FORMAT,
            'keyId' => $this->signer->keyId(),
            'signedAt' => Receipt::EXPORT,
            'proposalDigest' => $digest,
            'proofDigest' => hash('sha256', CanonicalJson::encode($bundle['proof'])),
            'executionDigest' => hash('sha256', CanonicalJson::encode($bundle['execution'])),
        ]);
        $bundle['receipt']->manifest = $manifest;
        $bundle['receipt']->signature = $this->signer->sign($manifest);
        // Even a newly valid application report cannot make the old user signature approve this altered statement.
        $application = (new ReceiptVerifier())->verify($bundle, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
        self::assertTrue($application['applicationSignatureValid']);
        try {
            $this->verifyHuman($application);
            self::fail('The application signature replaced the human-credential proof.');
        } catch (ApprovalException $exception) {
            self::assertSame('invalid_approval', $exception->reason);
        }
        [$exit, , $stderr] = $this->runVerifier($bundle);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Verification failed', $stderr);
    }

    public function testAnUntrustedApplicationKeyIsRejectedEvenIfIncludedInTheBundle(): void
    {
        $bundle = $this->exportPersisted($this->complete());
        $attacker = new SodiumEvidenceSigner('test-application-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $bundle['publicKey'] = $attacker->publicKey();
        $bundle['receipt']->signature = $attacker->sign($bundle['receipt']->manifest);
        $this->expectException(ApprovalException::class);
        (new ReceiptVerifier())->verify($bundle, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
    }

    public function testWrongTrustedCredentialFailsTheIndependentCli(): void
    {
        $bundle = $this->exportPersisted($this->complete());
        $other = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->fixture->reviewer, random_bytes(32), 'Other key');
        $otherCredential = $this->verifier->register($this->fixture->reviewer, $options, $other->registration($options));
        [$exit, $stdout, $stderr] = $this->runVerifier($bundle, $otherCredential);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('supplied trusted credential', $stderr);
    }

    public function testSignerOutageAndSigningRetryNeverReexecuteTheCommittedAction(): void
    {
        $completed = $this->complete();
        $unavailable = new class implements EvidenceSigner {
            public function keyId(): string
            {
                return 'temporarily-unavailable';
            }

            public function sign(string $manifest): string
            {
                throw new \RuntimeException('Injected signer outage.');
            }

            public function publicKey(): string
            {
                throw new \RuntimeException('Injected signer outage.');
            }
        };
        try {
            (new EvidenceExporter($unavailable, signAtExport: true))->export($completed);
            self::fail('The unavailable signer produced a receipt.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected signer outage.', $exception->getMessage());
        }
        $persisted = $this->service->get($this->fixture->actor, $completed->request->id);
        self::assertSame('completed', $persisted->status);
        self::assertSame(1, $this->fixture->effectCount());
        self::assertSame(1, $this->action->executeCalls);
        $retry = $this->exportPersisted($persisted);
        $this->verifyHuman((new ReceiptVerifier())->verify($retry, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey())));
        self::assertSame(1, $this->action->executeCalls);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testLivePolicyChangesDoNotRewriteHistoricalApprovalLanguage(): void
    {
        $completed = $this->complete();
        $statement = $completed->request->statement();
        $this->fixture->pdo->exec("UPDATE test_policies SET version = '999', statement = 'Different live policy' WHERE action_id = 'article.publish'");
        $bundle = $this->exportPersisted($completed);
        [$exit, $stdout, $stderr] = $this->runVerifier($bundle);
        self::assertSame(0, $exit, $stderr);
        self::assertSame($statement, json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR)['statement']);
    }

    public function testUnexpectedPostSaveMutationRollsBackRealCredentialCounterAndBusinessState(): void
    {
        $pending = $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $this->service->begin($this->fixture->reviewer, $pending->request->id);
        $this->action->alterAfterWrite = true;
        try {
            $this->service->complete($this->fixture->reviewer, $ceremony->id, $this->authenticator->assertion($ceremony->options));
            self::fail('Unexpected post-save content was committed.');
        } catch (ApprovalException $exception) {
            self::assertSame('execution_failed', $exception->reason);
        }
        self::assertSame(0, $this->fixture->effectCount());
        self::assertSame(0, (int) $this->fixture->pdo->query('SELECT COUNT(*) FROM verified_human_approval_executions')->fetchColumn());
        self::assertSame($this->trustedCredential->sourceJson, $this->credentials->find($this->trustedCredential->id)->sourceJson);
        self::assertTrue($this->fixture->store->getCeremony($ceremony->id)->consumed);
        $this->action->alterAfterWrite = false;
        $new = $this->service->begin($this->fixture->reviewer, $pending->request->id);
        self::assertSame('completed', $this->service->complete($this->fixture->reviewer, $new->id, $this->authenticator->assertion($new->options))->status);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testReceiptsSignedAtCommitExposeEditsMadeAfterwards(): void
    {
        [$service, $actor] = $this->signingService();
        $pending = $service->request($actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $id = $service->complete($this->fixture->reviewer, $ceremony->id, $this->authenticator->assertion($ceremony->options))->request->id;
        $export = fn (): array => (new EvidenceExporter($this->signer))->export($service->get($actor, $id));

        $verified = (new ReceiptVerifier())->verify($export(), TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
        self::assertSame(Receipt::COMMIT, $verified['receiptSignedAt']);
        $this->verifyHuman($verified);

        // Someone with database access rewrites when it happened. The receipt signed at commit no longer matches.
        $record = CanonicalJson::map(CanonicalJson::decode($this->fixture->pdo->query("SELECT record FROM verified_human_approval_executions WHERE request_id = '$id'")->fetchColumn()));
        $record['recordedAt'] = '2020-01-01T00:00:00.000000Z';
        $this->fixture->pdo->prepare('UPDATE verified_human_approval_executions SET record = ? WHERE request_id = ?')->execute([CanonicalJson::encode((object) $record), $id]);
        $this->expectException(ApprovalException::class);
        (new ReceiptVerifier())->verify($export(), TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
    }

    public function testADeletedCommitReceiptIsNeverReplacedByOneSignedAtExport(): void
    {
        [$service, $actor] = $this->signingService();
        $pending = $service->request($actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $id = $service->complete($this->fixture->reviewer, $ceremony->id, $this->authenticator->assertion($ceremony->options))->request->id;

        // Someone with database access edits what the action reported and deletes the receipt that would expose it.
        $record = CanonicalJson::map(CanonicalJson::decode((string) $this->fixture->pdo->query("SELECT record FROM verified_human_approval_executions WHERE request_id = '$id'")->fetchColumn()));
        $record['result']->result_revision = 999;
        $this->fixture->pdo->prepare('UPDATE verified_human_approval_executions SET record = ? WHERE request_id = ?')->execute([CanonicalJson::encode((object) $record), $id]);
        // The copy of the result stored with the request is kept in step, so that this case is about the
        // deleted signature alone and not about the separate check that the stored parts agree.
        $this->fixture->pdo->prepare('UPDATE verified_human_approval_requests SET result = ? WHERE id = ?')->execute([CanonicalJson::encode($record['result']), $id]);
        $this->fixture->pdo->prepare('DELETE FROM verified_human_approval_receipts WHERE request_id = ?')->execute([$id]);

        // The service refuses to read this record at all, so the exporter is given it straight from the store.
        try {
            (new EvidenceExporter($this->signer))->export($this->fixture->store->get($id));
            self::fail('A new signature vouched for the edited record.');
        } catch (ApprovalException $error) {
            self::assertSame('receipt_missing', $error->reason);
        }
    }

    public function testOriginalReceiptsExportWithoutAnySigningKey(): void
    {
        [$service, $actor] = $this->signingService();
        $pending = $service->request($actor, 'article.publish', ['id' => 1]);
        try {
            (new EvidenceExporter())->export($pending);
            self::fail('A request that has not run had evidence.');
        } catch (ApprovalException $error) {
            self::assertSame('not_completed', $error->reason);
        }
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $id = $service->complete($this->fixture->reviewer, $ceremony->id, $this->authenticator->assertion($ceremony->options))->request->id;
        $state = $service->get($actor, $id);

        $untouchable = new class implements EvidenceSigner {
            public function keyId(): string
            {
                throw new \LogicException('The exporter touched the key.');
            }

            public function sign(string $manifest): string
            {
                throw new \LogicException('The exporter touched the key.');
            }

            public function publicKey(): string
            {
                throw new \LogicException('The exporter touched the key.');
            }
        };
        $exporters = ['no signer' => new EvidenceExporter(), 'a signer it must not touch' => new EvidenceExporter($untouchable), 'export signing allowed' => new EvidenceExporter($untouchable, signAtExport: true)];
        foreach ($exporters as $how => $exporter) {
            $evidence = $exporter->export($state);
            self::assertEquals((object) $state->receipt, $evidence['receipt'], $how.': the original receipt, unchanged.');
            self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify($evidence, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()))['receiptSignedAt'], $how);
        }

        // A service that signs at commit refuses a completed record without its receipt, so from here the
        // exporter is given the record straight from the store, as an application that never signed would.
        $this->fixture->pdo->prepare('DELETE FROM verified_human_approval_receipts WHERE request_id = ?')->execute([$id]);
        try {
            (new EvidenceExporter())->export($this->fixture->store->get($id));
            self::fail('A missing receipt was replaced.');
        } catch (ApprovalException $error) {
            self::assertSame('receipt_missing', $error->reason);
        }
        $signedNow = (new EvidenceExporter($this->signer, signAtExport: true))->export($this->fixture->store->get($id));
        self::assertSame(Receipt::EXPORT, (new ReceiptVerifier())->verify($signedNow, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()))['receiptSignedAt'], 'Only the explicit export mode signs, and its receipt says so.');
    }

    public function testSigningAtExportNeedsASigner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EvidenceExporter(signAtExport: true);
    }

    public function testAReceiptChecksWithTheSitesKeyAloneAndSaysThePasskeyWasNotChecked(): void
    {
        // What a person holds: their receipt and the site's published key, but not their passkey's record.
        $bundle = $this->exportPersisted($this->complete());
        [$exit, $stdout, $stderr] = $this->runVerifier($bundle, withCredential: false);
        self::assertSame(0, $exit, $stderr);
        $result = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertNull($result['credentialApprovedExactProposal'], 'A passkey signed it, but nothing here checked that signature.');
        self::assertTrue($result['applicationSignedExecutionReport']);
        self::assertStringStartsWith('Verifies the application report. The passkey signature was not checked', $result['qualification'], 'It claims only what it checked.');

        // The site's key alone still catches a record edited after the receipt.
        $bundle['execution']->recordedAt = '2020-01-01T00:00:00.000000Z';
        [$exit, , $stderr] = $this->runVerifier($bundle, withCredential: false);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Verification failed', $stderr);
    }

    public function testWhenAReceiptWasSignedIsPartOfWhatIsSigned(): void
    {
        $bundle = $this->exportPersisted($this->complete());
        self::assertSame(Receipt::EXPORT, (new ReceiptVerifier())->verify($bundle, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()))['receiptSignedAt']);

        // Claiming an export-time receipt was signed at commit breaks its signature.
        $manifest = CanonicalJson::decode($bundle['receipt']->manifest);
        $manifest->signedAt = Receipt::COMMIT;
        $bundle['receipt']->manifest = CanonicalJson::encode($manifest);
        try {
            (new ReceiptVerifier())->verify($bundle, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()));
            self::fail('A receipt claimed a signing stage it was not signed with.');
        } catch (ApprovalException $error) {
            self::assertSame('invalid_evidence', $error->reason);
        }
        [$exit, , $stderr] = $this->runVerifier($bundle);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Verification failed', $stderr);
    }

    public function testTheVerifierRunsFromAnInstalledPackageAndThroughComposersBinProxy(): void
    {
        $bundle = $this->exportPersisted($this->complete());
        // An application's vendor directory, laid out as Composer installs it; its autoloader stands in for the real one.
        $app = sys_get_temp_dir().'/verified-human-approval-consumer-'.bin2hex(random_bytes(6));
        $installed = $app.'/vendor/verified-human-approval/webauthn/bin/verify-evidence.php';
        mkdir(\dirname($installed), 0700, true);
        mkdir($app.'/vendor/bin', 0700);
        copy(\dirname(__DIR__, 2).'/src/webauthn/bin/verify-evidence.php', $installed);
        file_put_contents($app.'/vendor/autoload.php', '<?php return require '.var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true).';');
        // Composer's proxy: it names the autoloader, then includes the package's script.
        file_put_contents($app.'/vendor/bin/verify-evidence.php', "<?php\n\$GLOBALS['_composer_autoload_path'] = __DIR__.'/../autoload.php';\ninclude __DIR__.'/../verified-human-approval/webauthn/bin/verify-evidence.php';\n");
        try {
            foreach ([$installed, $app.'/vendor/bin/verify-evidence.php'] as $script) {
                [$exit, $stdout, $stderr] = $this->runVerifier($bundle, script: $script);
                self::assertSame(0, $exit, $script.': '.$stderr);
                self::assertTrue(json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR)['credentialApprovedExactProposal']);
            }
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($app);
        }
    }

    public function testAReceiptSignerFailureMeansNothingExecutes(): void
    {
        $unavailable = new class implements EvidenceSigner {
            public function keyId(): string
            {
                return 'unavailable';
            }

            public function sign(string $manifest): string
            {
                throw new \RuntimeException('Injected signer outage.');
            }

            public function publicKey(): string
            {
                throw new \RuntimeException('Injected signer outage.');
            }
        };
        [$service, $actor] = $this->signingService($unavailable);
        $pending = $service->request($actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        try {
            $service->complete($this->fixture->reviewer, $ceremony->id, $this->authenticator->assertion($ceremony->options));
            self::fail('An action executed without its signed receipt.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
        }
        self::assertSame(0, $this->fixture->effectCount(), 'No unrecorded action: the transaction rolled back.');
    }

    /** @return array{ApprovalService, \VerifiedHumanApproval\Core\Actor} */
    private function signingService(?EvidenceSigner $signer = null): array
    {
        $guard = new ExecutionGuard();
        $service = new ApprovalService(new ActionRegistry([new FixtureAction($this->fixture->pdo, $guard)]), $this->fixture->policies, $this->fixture->authorizer, $this->fixture->store, $this->verifier, $this->fixture->clock, $guard, $signer ?? $this->signer);

        return [$service, $this->fixture->actor];
    }

    private function complete(): ApprovalState
    {
        $pending = $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $this->service->begin($this->fixture->reviewer, $pending->request->id);

        return $this->service->complete($this->fixture->reviewer, $ceremony->id, $this->authenticator->assertion($ceremony->options));
    }

    /** This service signs no receipt at commit, so its evidence is signed at export, and says so. */
    private function exportPersisted(ApprovalState $state): array
    {
        return (new EvidenceExporter($this->signer, signAtExport: true))->export($this->service->get($this->fixture->actor, $state->request->id));
    }

    private function verifyHuman(array $verified): void
    {
        $proof = $verified['proof'];
        $approval = $this->verifier->verifyEvidenceAssertion(
            $verified['request']->actor(),
            ChallengeBinding::decode($proof['challenge']),
            CanonicalJson::map($proof['assertion']),
            $this->trustedCredential,
        );
        self::assertSame($this->trustedCredential->id, $approval->credentialId);
    }

    /** @return array{int, string, string} */
    private function runVerifier(array $bundle, ?Credential $credential = null, ?string $script = null, bool $withCredential = true): array
    {
        $credential ??= $this->trustedCredential;
        $directory = sys_get_temp_dir().'/verified-human-approval-evidence-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $files = [
            'evidence.json' => $bundle,
            'application.json' => [
                'keyId' => $this->signer->keyId(),
                'publicKey' => $this->signer->publicKey(),
                'rpId' => 'approval.test',
                'origin' => 'https://approval.test',
            ],
            'credential.json' => [
                'id' => $credential->id,
                'userId' => $credential->userId,
                'tenantId' => $credential->tenantId,
                'sourceJson' => $credential->sourceJson,
            ],
        ];
        try {
            foreach ($files as $name => $contents) {
                file_put_contents($directory.'/'.$name, CanonicalJson::encode((object) $contents));
            }
            $process = proc_open([
                \PHP_BINARY,
                $script ?? \dirname(__DIR__, 2).'/src/webauthn/bin/verify-evidence.php',
                $directory.'/evidence.json',
                $directory.'/application.json',
                ...($withCredential ? [$directory.'/credential.json'] : []),
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                throw new \RuntimeException('Could not launch the independent verifier process.');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            return [$exit, $stdout, $stderr];
        } finally {
            foreach (array_keys($files) as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
        }
    }
}
