<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Evidence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\FixtureAction;
use VerifiedHumanApproval\Tests\Support\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** Stored projections must not contradict the receipt or make a completed action executable again. */
final class StoredApprovalIntegrityTest extends TestCase
{
    private CoreFixture $fixture;
    private WebAuthnVerifier $verifier;
    private VirtualAuthenticator $authenticator;
    private SodiumEvidenceSigner $signer;
    private ApprovalService $service;
    private FixtureAction $action;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
        $credentials = new PdoCredentialRepository($this->fixture->pdo);
        $credentials->initialize();
        $this->verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $credentials);
        $this->authenticator = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->fixture->reviewer, random_bytes(32), 'Alice');
        $this->verifier->register($this->fixture->reviewer, $options, $this->authenticator->registration($options));
        $this->signer = new SodiumEvidenceSigner('test-application-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->service = $this->service($this->signer);
    }

    #[DataProvider('projectionChanges')]
    public function testContradictoryStoredProjectionsCannotBeReportedExportedOrRetried(string $change): void
    {
        [$completed, $ceremony, $assertion] = $this->complete();
        $id = $completed->request->id;
        $original = (new EvidenceExporter())->export($this->service->get($this->fixture->actor, $id));
        if ('result' === $change) {
            $result = $completed->result;
            $result['published'] = false;
            $result['result_revision'] = 999;
            $this->fixture->pdo->prepare('UPDATE verified_human_approval_requests SET result = ? WHERE id = ?')->execute([CanonicalJson::encode((object) $result), $id]);
        } else {
            $this->fixture->pdo->prepare('UPDATE verified_human_approval_requests SET status = ?, reason = ? WHERE id = ?')->execute([$change, 'cancelled' === $change ? 'cancelled_by_user' : null, $id]);
        }
        // A false pending flag must not even be rewritten as expired before the discrepancy is found.
        $this->fixture->clock->modify('+1 day');
        $raw = $this->fixture->store->get($id);
        self::assertNotNull($raw, 'An auditor can still load the damaged record.');
        $this->assertIntegrityError($raw->toArray(...));
        $this->assertIntegrityError(static fn () => (new EvidenceExporter())->export($raw));
        $unavailableSigner = new class implements EvidenceSigner {
            public function keyId(): string
            {
                throw new \LogicException('An inconsistent record must never reach the signer.');
            }

            public function sign(string $manifest): string
            {
                throw new \LogicException('An inconsistent record must never reach the signer.');
            }

            public function publicKey(): string
            {
                throw new \LogicException('An inconsistent record must never reach the signer.');
            }
        };
        $this->assertIntegrityError(static fn () => (new EvidenceExporter($unavailableSigner, signAtExport: true))->export($raw));
        $this->assertIntegrityError(fn () => $this->service->get($this->fixture->actor, $id));
        $this->assertIntegrityError(fn () => $this->service->begin($this->fixture->reviewer, $id));
        $this->assertIntegrityError(fn () => $this->service->complete($this->fixture->reviewer, $ceremony->id, $assertion));
        $this->assertIntegrityError(fn () => $this->service->cancel($this->fixture->reviewer, $id));
        $this->assertIntegrityError(fn () => $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'saved-request'));
        if ('pending' === $change) {
            $this->assertIntegrityError(fn () => $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1]));
        }
        try {
            $this->service->get(new Actor('bob', 'tenant-a'), $id);
            self::fail('The damaged record was disclosed to someone else.');
        } catch (ApprovalException $error) {
            self::assertSame('not_found', $error->reason);
        }
        self::assertSame(1, $this->action->executeCalls);
        self::assertSame(1, $this->fixture->effectCount());
        self::assertEquals($raw, $this->fixture->store->get($id), 'Reading or retrying never rewrites the damaged evidence.');
        self::assertEquals($original['receipt'], (object) $raw->receipt);
        self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify($original, $this->signer->keyId(), $this->signer->publicKey())['receiptSignedAt'], 'The original retained receipt still verifies.');
    }

    public static function projectionChanges(): iterable
    {
        yield 'only the duplicate result was edited' => ['result'];
        yield 'completed was changed to pending' => ['pending'];
        yield 'completed was changed to cancelled' => ['cancelled'];
    }

    #[DataProvider('receiptChanges')]
    public function testAConfiguredSigningServiceRequiresTheOriginalReceiptEvenWhenAlteredProjectionsAgree(bool $deleteReceipt): void
    {
        [$completed, $ceremony, $assertion] = $this->complete();
        $id = $completed->request->id;
        $record = $this->fixture->store->get($id)->execution;
        $record['result']->result_revision = 999;
        $record['result']->published = false;
        $this->fixture->pdo->prepare('UPDATE verified_human_approval_executions SET record = ? WHERE request_id = ?')->execute([CanonicalJson::encode((object) $record), $id]);
        $this->fixture->pdo->prepare('UPDATE verified_human_approval_requests SET result = ? WHERE id = ?')->execute([CanonicalJson::encode($record['result']), $id]);
        if ($deleteReceipt) {
            $this->fixture->pdo->prepare('DELETE FROM verified_human_approval_receipts WHERE request_id = ?')->execute([$id]);
        }
        $raw = $this->fixture->store->get($id);
        $raw->assertConsistent(); // Structural agreement alone is not authentication.
        $this->assertIntegrityError(fn () => $this->service->get($this->fixture->actor, $id));
        $this->assertIntegrityError(fn () => $this->service->complete($this->fixture->reviewer, $ceremony->id, $assertion));
        $this->assertIntegrityError(fn () => $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'saved-request'));
        self::assertEquals($raw, $this->fixture->store->get($id));
        self::assertSame(1, $this->action->executeCalls);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public static function receiptChanges(): iterable
    {
        yield 'the original receipt no longer matches' => [false];
        yield 'the original receipt was deleted' => [true];
    }

    public function testUnsignedConfigurationsStillReadAndReplayTheirOwnCompletions(): void
    {
        $this->service = $this->service(null);
        [$completed, $ceremony, $assertion] = $this->complete();
        $state = $this->service->get($this->fixture->actor, $completed->request->id);
        self::assertSame('completed', $state->toArray()['status']);
        self::assertNull($state->receipt);
        self::assertEquals($state, $this->service->complete($this->fixture->reviewer, $ceremony->id, $assertion));
        self::assertEquals($state, $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'saved-request'));
        $export = (new EvidenceExporter($this->signer, signAtExport: true))->export($state);
        self::assertSame(Receipt::EXPORT, (new ReceiptVerifier())->verify($export, $this->signer->keyId(), $this->signer->publicKey())['receiptSignedAt']);
        self::assertSame(1, $this->action->executeCalls);
        self::assertSame(1, $this->fixture->effectCount());

        // Enabling signing later does not silently authenticate those older unsigned records.
        $withSigning = $this->service($this->signer);
        $this->assertIntegrityError(fn () => $withSigning->get($this->fixture->actor, $completed->request->id));
    }

    private function service(?EvidenceSigner $signer): ApprovalService
    {
        $guard = new ExecutionGuard();
        $this->action = new FixtureAction($this->fixture->pdo, $guard);

        return new ApprovalService(new ActionRegistry([$this->action]), $this->fixture->policies, $this->fixture->authorizer, $this->fixture->store, $this->verifier, $this->fixture->clock, $guard, $signer);
    }

    /** @return array{ApprovalState, Ceremony, array<string, mixed>} */
    private function complete(): array
    {
        $pending = $this->service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'saved-request');
        $ceremony = $this->service->begin($this->fixture->reviewer, $pending->request->id);
        $assertion = $this->authenticator->assertion($ceremony->options);

        return [$this->service->complete($this->fixture->reviewer, $ceremony->id, $assertion), $ceremony, $assertion];
    }

    private function assertIntegrityError(callable $operation): void
    {
        try {
            $operation();
            self::fail('An inconsistent or unauthenticated stored outcome was accepted.');
        } catch (ApprovalException $error) {
            self::assertSame('record_integrity_error', $error->reason);
        }
    }
}
