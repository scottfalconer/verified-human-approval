# Verified Human Approval: Doctrine (`verified-human-approval/doctrine`)

Storage for Verified Human Approval on an application's own Doctrine DBAL connection: SQLite, PostgreSQL, MySQL 8.0.17 or later, or MariaDB. The approval store, the signed audit log and the passkey repository share one transaction with your entities and queries, so the protected change, the passkey's counter, the proof, the execution record and the receipt (signed before commit) all commit together, or none of them does.

It keeps every rule the core `ApprovalStore` states, and passes the same contract tests as the SQLite reference store, on every supported database. It requires `doctrine/dbal` 4.4 or later; `doctrine/orm` 3.5 or later is optional and adds a schema listener for migrations, a base class for actions that change entities, and a check that protected entities change only through an approval.

## With Symfony

Install the package beside the bundle, then let the bundle wire the storage on a DoctrineBundle connection:

```yaml
# config/packages/verified_human_approval.yaml
verified_human_approval:
    # ...public_origin, actor_provider, policy_provider, authorizer and verifier, as before
    receipt_signer: VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner
    persistence:
        driver: doctrine
        connection: default            # the connection your protected writes and entity manager use
        # protected_entities: { App\Entity\Article: [publishedTitle, publishedBody] }
```

Leave `store` out: `persistence` wires it. The bundle registers, and aliases for autowiring:

| Service | What it is |
| --- | --- |
| `DbalTransaction` | the one approval transaction on the connection, with the lock clauses |
| `DbalApprovalStore` (also `ApprovalHistory`) | proposals, ceremonies, outcomes and receipts; the approval service's store |
| `DbalAuditStore` (also `AuditStore`) | the signed audit log's rows |
| `AuditLog` | the signed log, signing with `receipt_signer` |
| `DbalCredentialRepository` (also `RevocableCredentialRepository`) | the raw passkeys |
| `AnchoredCredentialRepository` (also `CredentialRepository`) | the passkeys a signed enrollment vouches for: wire `WebAuthnVerifier` to it |
| `ProtectedEntityListener` | the flush check, with the ORM |

`persistence` needs `receipt_signer`, since the audit log that vouches for every passkey is signed with it.

## Create the tables

With Doctrine Migrations, the schema listener adds the approval tables to the schema the ORM generates for the approval connection, so `doctrine:migrations:diff` (and `doctrine:schema:update`) create them and never propose to drop them. Review and run the migration as usual. The small lock table's rows are inserted when the first approval transaction starts, so a migration need not insert them.

Without migrations, run the installation once, or call `ApprovalSchema::install($connection)` from your own installer:

```bash
php bin/console verified-human-approval:install
```

Both refuse to run inside a transaction. `install()` creates only missing tables, and refuses a table that exists with another layout, such as the SQLite PDO store's: move those rows to the Doctrine layout first.

The tables keep canonical JSON in plain text columns, **never `JSON` or `JSONB`**: those types rewrite the bytes that a digest and a signature cover, and a record that no longer matches its digest is refused as corrupt. Do not change these columns' types in a migration. On MySQL and MariaDB a text column is `LONGTEXT`, so a 256 KiB proposal fits, and every table is `utf8mb4` with a binary collation that does not ignore trailing spaces (`utf8mb4_0900_bin`, or `utf8mb4_nopad_bin` on MariaDB), so passkey and account identifiers compare byte for byte. Connect with `charset: utf8mb4` and keep strict SQL mode.

## The approval transaction

`DbalTransaction::run()` is the root transaction the approval service runs every step in. It:

- **refuses to start inside any other transaction** on the connection: one begun through DBAL, the ORM, or the driver itself. Do not call `ApprovalService` inside `Connection::transactional()`, `EntityManager::wrapInTransaction()`, or a Messenger handler behind the `doctrine_transaction` middleware: call it outside that transaction, or from a handler without that middleware. `wrapInTransaction()` closes its entity manager on any failure, this refusal included; an `EntityAction` resets a closed manager before it runs;
- begins through DBAL, so an ORM flush inside nests as a savepoint instead of failing with "cannot start a transaction within a transaction";
- on SQLite, takes the database's write reservation before anything is read, as `BEGIN IMMEDIATE` does, so no other writer can change what the approval reads until it commits;
- on PostgreSQL, MySQL and MariaDB, runs at `READ COMMITTED` whatever the session's default, so each statement after a lock reads what committed before it;
- runs its callback once, never retries it, and rolls back on any `Throwable`, rethrowing the original error even if the rollback fails too;
- before commit, checks that the root is still the one it began: a callback that committed it, rolled it back and began another, left a nested transaction open, or swallowed a failed statement (PostgreSQL would then answer `COMMIT` with a silent rollback) fails instead of reporting a commit. On MySQL a schema change commits implicitly: never run DDL inside an approval.

Use the one connection for every protected write: your entity manager, your queries and the stores. A second connection, an external API call or an email is not part of the transaction.

## Your locking duty on PostgreSQL, MySQL and MariaDB

SQLite has one writer at a time, and the approval transaction holds that place, so on SQLite nothing more is needed. A server database lets writers run side by side. The stores lock their own rows; **your reads must lock what the decision depends on**, or a permission revoked while an approval is under way could be revoked first and still let the approval commit after it:

- in your `Authorizer` and policy reads, the permission, grant and policy rows `FOR SHARE`;
- in your action's `prepare()`, `assertPreconditions()` and postcondition reads, the content it will change `FOR UPDATE`.

`DbalTransaction` gives the clause for the connection's database, empty on SQLite:

```php
$grant = $connection->fetchAssociative(
    'SELECT active, scopes FROM app_agent_grants WHERE id = ? AND user_id = ? AND tenant_id = ? '.$transaction->forShare(),
    [$actor->delegationId, $actor->userId, $actor->tenantId],
);
$document = $connection->fetchAssociative(
    'SELECT * FROM app_documents WHERE id = ? AND tenant_id = ? '.$transaction->forUpdate(),
    [$id, $actor->tenantId],
);
```

With the ORM, load the entity with `LockMode::PESSIMISTIC_WRITE` (an `EntityAction` does, through `current()`), and use `forShare()` in the authority queries. Lock the rows in the same order everywhere (the request, then the content, then the passkey) to avoid deadlocks; the approval service already does.

## Primary and replica

With a `PrimaryReadReplicaConnection`, every read the stores make, inside or outside a transaction, goes to the primary: a replica that lags could miss a revocation or show an outcome as still pending. Your own authority reads inside an approval run on the primary too, since the transaction does; do not switch the connection to a replica inside an action.

## Actions on ORM entities

Use `currentApproval()->requestId` from `apply()` to associate the written revision with its approval in the same transaction. `currentApproval()` also reports `userVerified()` and refuses use outside execution; subclasses need not inject or store another `ExecutionGuard`.

Extend `Orm\EntityAction`. Its `execute()` is final: it clears the entity manager, so nothing loaded or changed earlier is read as current or flushed with the approved change; loads the targets you name, locked for writing; lets your `apply()` change them; and flushes inside the approval transaction, so your postconditions read the written rows and the receipt is signed after them. If the transaction rolls back, the entity manager is reset.

```php
use Doctrine\Persistence\ManagerRegistry;
use VerifiedHumanApproval\Doctrine\Orm\EntityAction;

final class PublishArticle extends EntityAction
{
    public function id(): string { return 'article.publish'; }
    public function version(): string { return '1'; }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $article = $this->current(Article::class, $input['id']) ?? throw new ApprovalException('not_found', 'No such article.');
        // ...freeze its title, body and revision into a PreparedAction
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $article = $this->current(Article::class, $action->target['resource']);
        // ...compare it with the frozen preconditions
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return ['article' => [Article::class, $action->target['resource']]];
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $entities['article']->publish($action->effect['title'], $action->effect['body']);

        return ['article' => $action->target['resource']];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $article = $this->current(Article::class, $action->target['resource']);
        // ...compare the published fields with the frozen effect
    }
}
```

Its constructor takes the `ManagerRegistry`, the `ExecutionGuard`, the `DbalTransaction`, the `ProtectedEntityListener` and, optionally, the entity manager's name; with autowiring they are all found.

## Protected entities

Mark the entities, or the fields, that only an approved action may change:

```php
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

#[ORM\Entity]
class Article
{
    #[ORM\Column] public string $title;           // the draft: free to edit
    #[RequiresApproval]
    #[ORM\Column(nullable: true)] public ?string $publishedTitle = null;
}
```

`ProtectedEntityListener` then refuses, on every flush of that connection's entity manager, a change to a marked entity or field, with `ApprovalException('approval_required')`, unless the flush happens while an approved action executes and that action permitted the entity. An `EntityAction` permits the targets it locks and the entities it creates with `persist()`; another adapter calls `permit()` itself. On a field, inserting or deleting the entity while the field holds a value other than null counts as a change too, so keep the approved state in nullable fields beside the draft, as above. The `protected_entities` setting does the same as the attribute, for entities you cannot annotate.

For an existing non-null `draft`/`published`/`archived` column, keep status protected and use a constrained, explicitly policy-exempt draft-creation action through `ApprovalService`. The [adoption cookbook](../symfony-bundle/docs/adoption.md#a-non-null-draftpublishedarchived-column) includes the executable adapter and regression tests; a draft value is not an automatic exemption from the guard.

The check covers the unit of work. DQL `UPDATE` and `DELETE`, and plain SQL, do not pass through it: keep those away from protected rows, as you keep every other write path behind the approval service.

## Without Symfony

```php
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;

$transaction = new DbalTransaction($connection);    // the application's own connection
$store = new DbalApprovalStore($transaction);
$audit = new AuditLog(new DbalAuditStore($transaction), $signer, $trustedPublicKey, $clock);
$credentials = new AnchoredCredentialRepository(new DbalCredentialRepository($transaction), $audit);
// $store and $credentials go to ApprovalService and WebAuthnVerifier; the transaction's lock clauses to your Authorizer.
```

Add or revoke a passkey inside a transaction on the connection (`$transaction->run()`, or your own begun through DBAL), so its signed audit entry commits with it.

## Checking an adapter

The same contract tests run on this package's stores and on the SQLite reference stores, on every database: `verified-human-approval/store-contracts` ships them, with the fixture application, fault injection and the process race, for checking your own adapter.
