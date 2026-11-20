<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

/** The approval service's limits on what it accepts, and the rules it keeps for records that have finished. */
final class ApprovalServiceGuardsTest extends TestCase
{
    private CoreFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
    }

    public function testARequestKeyHoldsOneTo128Bytes(): void
    {
        foreach (['empty' => '', 'over 128 bytes' => str_repeat('k', 129)] as $case => $key) {
            $this->assertRefused('invalid_idempotency_key', fn () => $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], $key), $case);
        }
        self::assertSame(0, $this->requests());
        self::assertSame('pending', $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], str_repeat('k', 128))->status);
    }

    public function testALocaleIsAShortCode(): void
    {
        foreach (['empty' => '', 'with a space' => 'en GB', 'over 32 characters' => str_repeat('x', 33)] as $case => $locale) {
            $this->assertRefused('invalid_locale', fn () => $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], null, $locale), $case);
        }
        self::assertSame(0, $this->requests());
        self::assertSame('en-GB', $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], null, 'en-GB')->request->payload()['locale']);
    }

    public function testActionInputOver256KiBIsRefusedBeforeTheActionPreparesIt(): void
    {
        // The fixture action refuses any key but "id" itself, so only the size check can name this reason.
        $this->assertRefused('input_too_large', fn () => $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1, 'padding' => str_repeat('x', 262144)]));
        self::assertSame(0, $this->requests());
    }

    public function testInputThatIsNotJsonDataIsRefusedAsInvalidInput(): void
    {
        foreach (['a decimal' => ['id' => 1, 'ratio' => 0.5], 'text that is not UTF-8' => ['id' => "\xff"], 'a key starting with NUL' => ["\0id" => 1]] as $case => $input) {
            $this->assertRefused('invalid_input', fn () => $this->fixture->service->request($this->fixture->actor, 'article.publish', $input), $case);
        }
        self::assertSame(0, $this->requests());
    }

    public function testAPasskeyResponseThatIsNotJsonDataIsRefusedAsUnreadable(): void
    {
        $pending = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $pending->request->id);

        $this->assertRefused('invalid_approval', fn () => $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony) + ['clientExtensionResults' => (object) ['ratio' => 0.5]]));
        self::assertSame(0, $this->fixture->verifier->verifyCalls);
        self::assertSame('completed', $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony))->status, 'The ceremony was not used up.');
    }

    public function testAProposalOver256KiBIsRefusedAndNothingIsStored(): void
    {
        $this->fixture->action->extraEffect = ['attachment' => str_repeat('x', 262144)];
        $this->assertRefused('proposal_too_large', fn () => $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1]));
        self::assertSame(0, $this->requests());
    }

    public function testAPasskeyResponseOver64KiBIsRefusedBeforeItIsVerified(): void
    {
        $pending = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $pending->request->id);
        $oversized = $this->fixture->proof($ceremony) + ['padding' => str_repeat('x', 65536)];

        $this->assertRefused('assertion_too_large', fn () => $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $oversized));
        self::assertSame(0, $this->fixture->verifier->verifyCalls);
        self::assertSame(0, $this->fixture->effectCount());
        self::assertSame('completed', $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony))->status, 'The ceremony was not used up.');
    }

    public function testCancellingAFinishedRequestLeavesItAsItIs(): void
    {
        $pending = $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $pending->request->id);
        $completed = $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));

        $after = $this->fixture->service->cancel($this->fixture->reviewer, $pending->request->id);
        self::assertSame(['completed', null], [$after->status, $after->reason]);
        self::assertEquals($completed->execution, $after->execution);
        self::assertSame('completed', $this->fixture->service->get($this->fixture->actor, $pending->request->id)->status);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testAReceiptSignedAtExportInPlaceOfTheOneSignedAtCommitIsAnIntegrityError(): void
    {
        $signer = new SodiumEvidenceSigner('application-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $guard = new ExecutionGuard();
        $service = new ApprovalService(new ActionRegistry([new FixtureAction($this->fixture->pdo, $guard)]), $this->fixture->policies, $this->fixture->authorizer, $this->fixture->store, $this->fixture->verifier, $this->fixture->clock, $guard, $signer);
        $pending = $service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        $stored = $service->get($this->fixture->actor, $pending->request->id);
        self::assertNotNull($stored->proof);
        self::assertNotNull($stored->execution);

        // A genuine signature by the application's key, over the record as it is now: it vouches for nothing at commit.
        $exported = Receipt::sign($signer, $stored->request->digest, $stored->proof, $stored->execution, Receipt::EXPORT);
        $this->fixture->pdo->prepare('UPDATE verified_human_approval_receipts SET manifest = ?, signature = ? WHERE request_id = ?')->execute([$exported['manifest'], $exported['signature'], $pending->request->id]);

        try {
            $service->get($this->fixture->actor, $pending->request->id);
            self::fail('A receipt signed at export was taken for the one signed at commit.');
        } catch (ApprovalException $error) {
            self::assertSame('record_integrity_error', $error->reason);
            self::assertSame('The stored receipt was not signed as the action committed.', $error->getPrevious()?->getMessage());
        }
    }

    private function requests(): int
    {
        return (int) $this->fixture->pdo->query('SELECT COUNT(*) FROM verified_human_approval_requests')->fetchColumn();
    }

    private function assertRefused(string $reason, callable $attempt, string $case = ''): void
    {
        try {
            $attempt();
            self::fail('The service accepted it'.('' === $case ? '.' : ': '.$case.'.'));
        } catch (ApprovalException $error) {
            self::assertSame($reason, $error->reason, $case);
        }
    }
}
