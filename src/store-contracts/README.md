# Verified Human Approval: storage contracts (`verified-human-approval/store-contracts`)

The tests every Verified Human Approval storage adapter must pass, packaged so you can run them against your own: an `ApprovalStore`, an `Audit\AuditStore` or a `RevocableCredentialRepository` on another database, a framework's own storage, or a fork of the supplied ones. The repository runs these same tests against the SQLite reference stores and the Doctrine stores on SQLite, PostgreSQL, MySQL and MariaDB.

Require it for development only. The packages are not on Packagist yet: first configure the path repository described in the [Symfony installation guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/symfony-bundle/docs/index.md#1-install-from-a-checkout), including the version entry for `verified-human-approval/store-contracts`.

The package requires `verified-human-approval/core` and `verified-human-approval/webauthn` at its own version. Unless your `minimum-stability` is `dev`, Composer installs a package's `dev-main` version only when your root `composer.json` asks for it by name. If your application already requires both at `dev-main`, as the guide's step 1 does:

```bash
composer require --dev verified-human-approval/store-contracts:dev-main
```

Otherwise name the ones it lacks in the same command. With neither:

```bash
composer require --dev verified-human-approval/core:dev-main verified-human-approval/webauthn:dev-main verified-human-approval/store-contracts:dev-main
```

The contracts are PHPUnit test cases. The package requires `phpunit/phpunit ^11.5`, so the suite that runs them must use PHPUnit 11.

## What it checks

| Contract | Rules (see the PHPDoc of each interface) |
| --- | --- |
| `ApprovalStoreContract` | The rules in `ApprovalStore`'s PHPDoc, as tests: the change, proof, execution and receipt commit together; an unrelated open transaction is refused; writes outside the store's transaction are refused; every failure before commit rolls back every write; a lost commit answer is recovered, never repeated; terminal records keep their bytes; request keys and keyless retries reuse the original; racing completions, ceremonies, proposals and requests commit once; a lock timeout leaves nothing half done; an action that commits, rolls back or replaces the store's transaction itself, or rolls it back from inside a nested transaction of its own, gets no outcome recorded. Then: a change to the grant, permission, content, passkey or policy that another connection tries while an approval is between its authority check and its commit must wait, or commit first and stop it; a ceremony retired after a failed attempt is never used by a completion that waited; a proposal near the 256 KiB limit with U+2028 and 4-byte characters, and `{}` kept apart from `[]`, keep their bytes. A completed record edited in the database is `record_integrity_error` on every read by its owner and never runs again, whichever part was edited: one character of the proposal, the digest, the proposal and its digest together (also to a proposal of the wrong shape), the result, proof or execution record, the status, or the receipt signature. Someone else who names it is told `not_found`, as for a request that does not exist, unless its proposal no longer reads back. The store's error carries the request while the proposal reads back, and a retry without a key that finds a pending proposal that cannot be read gets the same error. A stored passkey ceremony whose options, nonce or expiry no longer read back is refused as `corrupt_ceremony`, and a fresh review still completes the request. With the `ApprovalHistoryContract` trait, for a store that keeps one: a resource's history, where an edited record keeps its place and a proposal that is not JSON fails no history. |
| `AuditStoreContract` | Rows kept exactly and returned unfiltered; a damaged head is still the head; every signed or bound field is checked; a joined append commits or rolls back with the host; other appenders are held off from before the head is read, for an empty log too; an append after the host read an older view never forks the chain; nothing is left half open after a failure; concurrent processes keep one ordered chain. |
| `CredentialRepositoryContract` | An identical zero-counter write-back succeeds; a replaced source is refused; an id is never overwritten, reassigned or reactivated; ids differing only in case or a trailing space are different passkeys; ownership is user and tenant together. |

The races are real: separate PHP processes, each with its own connection, start together at a barrier (`ProcessRace`). `FaultingStore` and `FaultingSigner` inject what a mock cannot: a failure just before commit, a commit whose answer is lost, a store that cannot be reached for recovery, a signer outage, and a pause at the authority check or just before commit.

## Run them against your adapter

Write a harness and one test class per harness. For an approval store, extend `StoreHarness`:

- `prepare()` creates an isolated store with its tables and the fixture application (`Fixture\FixtureSchema::statements('sqlite'|'postgresql'|'mysql')`), and returns where it is; `destroy()` removes it; `connect()` opens a new connection to it and runs no DDL, since race workers call it too.
- `close()` closes what `connect()` opened. The contract calls it when each test ends, before `destroy()`, on every harness that `connect()` gave the test. It does nothing unless you override it, so a harness that opens a connection closes it there. Leaving it to PHP is not enough: the `afterCheck` closure below keeps a harness alive until PHP next collects cycles, and MySQL and MariaDB keep a connection to a dropped database open, so a run can use up the server's connections.
- `service()` builds the real `ApprovalService` over your store wrapped in `FaultingStore`, with `Fixture\FixtureAction`, `FixtureAuthorizer`, `FixturePolicies` and the test-only `TestProofVerifier` on your store's connection (through a `Fixture\FixtureDatabase`; `PdoFixtureDatabase` wraps a PDO), a `FaultingSigner` over an Ed25519 signer that is the same for every connection to one location, and the harness's `$faults`. Set the authorizer's `afterCheck` to `fn () => $this->faults->reach('authority_checked')`.
- On a database that locks rows, give the fixture's database the lock clauses (`FOR UPDATE`, and `FOR SHARE` or `LOCK IN SHARE MODE`), so the fixture application locks what its decision reads, as yours must.
- The platform primitives: `attempt()` makes an ordinary writer's change with a bounded lock wait, `holdWriteLock()` holds what a completion needs first, `lockTimeout()`, `failInserts()` (a trigger, say), `withAmbientTransaction()`.
- `breakAction(?string $how)` makes the fixture action fail after its write (`'after_write'`) or break its postcondition (`'postcondition'`). Through `Fixture\FixtureAction::$afterPostconditions`, it also makes the action end the approval transaction itself once its postconditions hold, on the connection and past your adapter: commit it (`'commit'`), roll it back (`'roll_back'`), roll it back and begin another (`'begin_again'`), roll it back in SQL (`'sql_roll_back'`), or roll it back from inside a nested transaction of its own, a savepoint, and carry on (`'nested_roll_back'`). Null mends it.
- `editRecord()` rewrites one stored part of a request's record (`proposal`, `digest`, `status`, `result`, `proof`, `execution`, or the receipt's `signature`) from a connection of its own, as someone with write access to the database could. `editCeremony()` does the same to a stored passkey ceremony's `options`, `nonce` or `expires_at`.

Then:

```php
final class MyApprovalStoreContractTest extends \VerifiedHumanApproval\StoreContracts\ApprovalStoreContract
{
    protected static function harness(): string
    {
        return MyStoreHarness::class;
    }
}
```

If the store implements `ApprovalHistory`, add `use \VerifiedHumanApproval\StoreContracts\ApprovalHistoryContract;` to that class. A store without a history leaves it out, and no test is skipped.

`AuditStoreContract` with an `AuditHarness`, and `CredentialRepositoryContract` with a `CredentialHarness`, work the same way, `close()` included. The repository's [`tests/Contract`](https://github.com/scottfalconer/verified-human-approval/tree/main/tests/Contract) has a harness for each supplied store to start from. The races need `proc_open`. Each worker runs `bin/store-worker.php` or `bin/audit-worker.php` with the Composer autoloader of the test process that started it, and the PSR-4 namespaces that process registered at run time, so the package can be installed as a symlink (a Composer path repository) and harnesses can live in namespaces that a framework's test bootstrap adds at run time.

Passing these tests is necessary, not sufficient: they show your adapter keeps the rules for the fixture application. Your own authorizer and actions must lock what they read, as the fixture's do.

This package is developed in the [Verified Human Approval repository](https://github.com/scottfalconer/verified-human-approval). Open issues and pull requests there.
