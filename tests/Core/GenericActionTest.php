<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class GenericActionTest extends TestCase
{
    public function testSameCoreCanApproveAnExactCampaignClosingDateChange(): void
    {
        $fixture = new CoreFixture();
        $fixture->pdo->exec("CREATE TABLE test_campaigns (id INTEGER PRIMARY KEY, tenant TEXT NOT NULL, closing_date TEXT NOT NULL, revision INTEGER NOT NULL)");
        $fixture->pdo->exec("INSERT INTO test_campaigns VALUES (1, 'tenant-a', '2026-06-10', 4)");
        $fixture->pdo->exec("INSERT INTO test_policies VALUES ('campaign.set_closing_date', '1', 'Change the closing date from {{ before }} to {{ after }}.', 1)");
        $guard = new ExecutionGuard();
        $adapter = new class($fixture->pdo, $guard) implements ActionAdapter {
            public function __construct(private readonly PDO $pdo, private readonly ExecutionGuard $guard) {}
            public function id(): string { return 'campaign.set_closing_date'; }
            public function version(): string { return '1'; }

            public function prepare(Actor $actor, array $input): PreparedAction
            {
                if (($input['campaign_id'] ?? null) !== 1 || !is_string($input['closing_date'] ?? null)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input['closing_date'])) {
                    throw new ApprovalException('invalid_input', 'A campaign and ISO closing date are required.');
                }
                $row = $this->row();

                return new PreparedAction(
                    $this->id(), $this->version(), ['tenant' => $row['tenant'], 'campaign' => 1],
                    ['closing_date' => $input['closing_date']],
                    ['revision' => (int) $row['revision'], 'closing_date' => $row['closing_date']],
                    ['title' => 'Campaign deadline', 'before' => $row['closing_date'], 'after' => $input['closing_date']],
                    ['before' => $row['closing_date'], 'after' => $input['closing_date']],
                );
            }

            public function assertPreconditions(Actor $actor, PreparedAction $action): void
            {
                $row = $this->row();
                if ($row['tenant'] !== $actor->tenantId || (int) $row['revision'] !== $action->preconditions['revision']
                    || $row['closing_date'] !== $action->preconditions['closing_date']) {
                    throw new ApprovalException('stale_campaign', 'The campaign deadline changed.');
                }
            }

            public function execute(Actor $actor, PreparedAction $action): array
            {
                $this->guard->assertExecuting($actor, $action);
                $update = $this->pdo->prepare('UPDATE test_campaigns SET closing_date = ?, revision = revision + 1 WHERE id = 1 AND tenant = ? AND revision = ?');
                $update->execute([$action->effect['closing_date'], $actor->tenantId, $action->preconditions['revision']]);
                if ($update->rowCount() !== 1) {
                    throw new ApprovalException('stale_campaign', 'The conditional deadline change did not match.');
                }

                return ['campaign_id' => 1, 'closing_date' => $action->effect['closing_date'], 'revision' => $action->preconditions['revision'] + 1];
            }

            public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
            {
                $row = $this->row();
                if ($row['closing_date'] !== $action->effect['closing_date'] || (int) $row['revision'] !== $result['revision']) {
                    throw new ApprovalException('postcondition_failed', 'The deadline change was not exact.');
                }
            }

            private function row(): array
            {
                return $this->pdo->query('SELECT * FROM test_campaigns WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            }
        };
        $service = new ApprovalService(new ActionRegistry([$adapter]), $fixture->policies, $fixture->authorizer, $fixture->store, $fixture->verifier, $fixture->clock, $guard);
        $request = $service->request($fixture->actor, 'campaign.set_closing_date', ['campaign_id' => 1, 'closing_date' => '2026-06-20']);
        self::assertSame('pending', $request->status);
        self::assertSame('Change the closing date from 2026-06-10 to 2026-06-20.', $request->request->statement());
        self::assertSame('2026-06-10', $fixture->pdo->query('SELECT closing_date FROM test_campaigns WHERE id = 1')->fetchColumn());
        $ceremony = $service->begin($fixture->reviewer, $request->request->id);
        $result = $service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));
        self::assertSame('completed', $result->status);
        self::assertSame('2026-06-20', $fixture->pdo->query('SELECT closing_date FROM test_campaigns WHERE id = 1')->fetchColumn());
        self::assertSame(5, $result->result['revision']);
        self::assertSame(0, $fixture->effectCount(), 'The independent publication action was not invoked.');
        self::assertSame(1, (int) $fixture->pdo->query('SELECT COUNT(*) FROM verified_human_approval_executions')->fetchColumn());
    }
}
