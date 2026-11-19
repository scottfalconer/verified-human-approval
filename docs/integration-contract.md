# Integration contracts

The public types of the packages and the rules an implementation must keep. From 1.0, these follow semantic versioning; [versions and releases](releasing.md) says what each version promises. The [architecture](overview.md) explains how the parts work together, and the [Symfony guide](../src/symfony-bundle/docs/index.md) wires them into an application.

| Package | Namespace |
| --- | --- |
| `verified-human-approval/core` | `VerifiedHumanApproval\Core` |
| `verified-human-approval/webauthn` | `VerifiedHumanApproval\WebAuthn` |
| `verified-human-approval/mcp` | `VerifiedHumanApproval\Mcp` |
| `verified-human-approval/symfony-bundle` | `VerifiedHumanApproval\Bundle` |
| `verified-human-approval/doctrine` | `VerifiedHumanApproval\Doctrine` |
| `verified-human-approval/store-contracts` | `VerifiedHumanApproval\StoreContracts` |
| `verified-human-approval/testing` | `VerifiedHumanApproval\Testing` |

The demo's own code is in `VerifiedHumanApproval\Demo`; it is not a package. Types below are in `VerifiedHumanApproval\Core` unless a namespace is given.

## The action and the people

- `Actor(string $userId, string $tenantId, ?string $delegationId = null)`: the authenticated subject. `$delegationId` names the agent connection acting for the person, or is null for the person themselves. The reviewer must be the same user in the same tenant as the person who asked (`samePerson()`), and the delegation stored with the request is rechecked when the action runs.
- `PreparedAction(string $actionId, string $version, array $target, array $effect, array $preconditions, array $display, array $placeholders = [])`: the exact effect. The bundle's review page reads `display.title` and, when present, `display.consequence` (what happens the moment it is approved, for a statement that does not already say so); both are frozen with the proposal.
- `ApprovalPolicy(string $actionId, string $version, string $statementTemplate, int $lifetimeSeconds = 600, bool $required = true)`: the rule for an action. `statement(PreparedAction $action)` resolves the statement the person approves, and `digest()` is what a proposal records to notice a change of rule.

## What the host implements

- `ActionAdapter`: `id(): string`, `version(): string`, `prepare(Actor $actor, array $input): PreparedAction`, `assertPreconditions(Actor $actor, PreparedAction $action): void`, `execute(Actor $actor, PreparedAction $action): array` and `assertPostconditions(Actor $actor, PreparedAction $action, array $result): void`.
- `PolicyProvider::get(string $actionId, ?Actor $actor = null): ApprovalPolicy`: the rule for this action, for this person when one is given, so it can depend on their role.
- `Authorizer::assertAllowed(Actor $actor, PreparedAction $action): void` must reload the current user and delegation authority through the transaction's database, not trust stored roles. The service calls it as the request is made, whenever a pending request is offered for approval, before the action runs, and again after it, before the commit.
- `ExecutedActionAuthorizer` (extends `Authorizer`): `assertStillAllowed(Actor $actor, PreparedAction $action, array $result): void` replaces that last call, with what the action returned. Implement it when the check reads the action's target and the action can remove it, as a deletion does: decide from the frozen proposal and the result. It fails closed, and a refusal rolls the action back.
- `ApprovalVerifier`: `requestOptions(Actor $actor, string $challenge): array` and `verify(Actor $actor, string $challenge, array $assertion): VerifiedApproval`. The challenge is raw bytes. Verification and the credential counter's update happen inside the final transaction.
- `VerifiedApproval(string $credentialId, array $evidence)`: what a verifier returns.
- `ApprovalObserver::completed(ApprovalState $state): void`: a synchronous, best-effort callback after an action's transaction commits. A process can stop after the commit and before delivery, and recovery from an ambiguous commit can report a completion again, so observers must tolerate repeats. It runs outside the transaction; an exception it throws is logged and cannot change the result. Reliable delivery needs the host to persist an outbox entry in the action's transaction and dispatch it separately; the observer has no durable queue or delivery guarantee.
- `ApprovalHistory::forResource(string $tenantId, string $resource, int $limit = 50): list<ApprovalState>`: optional, newest first, keyed by the prepared target's `resource`; records outlive the resource. `Storage\PdoApprovalStore` and `DbalApprovalStore` implement it (the Doctrine store indexes a `resource` that is a string or an integer). The host decides who may see which history.

## The approval service

- `ActionRegistry(iterable $actions)`, with `get(string $id): ActionAdapter`.
- `ApprovalService(ActionRegistry $actions, PolicyProvider $policies, Authorizer $authorizer, ApprovalStore $store, ApprovalVerifier $verifier, ClockInterface $clock, ExecutionGuard $guard, ?EvidenceSigner $receipts = null, iterable $observers = [], ?LoggerInterface $logger = null, ?TrustedKeys $trustedKeys = null)`. With `$receipts`, each execution's receipt is signed inside the completing transaction, and a signing failure means nothing executes. `$observers` are `ApprovalObserver`s, and `$logger` records an observer's failure. `$trustedKeys` are the keys stored receipts are read against; without them, only the signer's current key is trusted, so pass them once a key has been rotated.
- `request(Actor $actor, string $actionId, array $input, ?string $idempotencyKey = null, string $locale = 'en'): ApprovalState`. The request key is `$idempotencyKey`.
- `get(Actor $actor, string $requestId): ApprovalState` checks ownership first, completed results included.
- `begin(Actor $reviewer, string $requestId): Ceremony` checks ownership, state and current eligibility, and produces the passkey options.
- `complete(Actor $reviewer, string $ceremonyId, array $assertion): ApprovalState` runs the stored action only; it takes no replacement arguments.
- `cancel(Actor $reviewer, string $requestId): ApprovalState`.
- `ApprovalException(string $reason, string $message, ?\Throwable $previous = null)`: a deliberate refusal, or a record the service cannot vouch for. `$reason` is a short code such as `not_found` or `record_integrity_error`.
- `ExecutionGuard::assertExecuting(Actor $actor, PreparedAction $action): void`: the application's mutation services call it. The service opens a narrow execution context only after all its checks, and no MCP argument can create one.
- `ExecutionGuard::current(): ?ExecutingApproval`, during `execute()` only: the executing approval's `requestId`, `actionId` and `approval` (`user_verified` or `not_required`; `userVerified(): bool`). An adapter writes the request id onto the change it makes, in the same transaction, to link that change (a published revision, say) to its evidence.

### The check on every read

Every method of the service checks the stored record before it reports, returns again, offers or changes it, after the ownership check, so only the owner learns of a failure. It runs `ApprovalState::assertConsistent()`, and, with `$receipts`, checks that a completed record carries the receipt signed at commit and that the receipt verifies against the trusted keys. A failure is `ApprovalException('record_integrity_error')`: the stored outcome cannot be vouched for, which is neither a refusal nor a sign that nothing ran. With `$receipts`, a completed record without a receipt fails too, including one that completed before signing was configured.

### Exactness, retries and the rule

Exactness rests on the stored canonical bytes, the rule's digest (over its version, statement template, lifetime and whether approval is required), the resolved statement, the action's version, the resource preconditions, current authority, a fresh passkey assertion bound to the proposal, and the postconditions. Completion consumes the approval and writes the business state and the execution record in one transaction. If the action fails after the passkey check, the transaction rolls back and the service retires the ceremony, so a retry starts a fresh ceremony. A retry of a completed request returns the existing result, with ownership checked before anything is disclosed.

A rule with `required: false` makes an unprotected request: the service still checks authority and preconditions and records the outcome atomically, but there is no passkey proof. A pending proposal made under an earlier rule cannot execute after the rule changes. The browser and MCP adapters cannot edit rules; the host's administrator routes control them.

## Requests and their state

- `ApprovalState` has `request: ApprovalRequest`, `status: string`, `reason: ?string`, `result: ?array`, `execution: ?array`, `proof: ?array` and `receipt: ?array`. The status is one of `pending`, `completed`, `invalidated`, `expired`, `cancelled` and `denied`. `toArray(): array` is a safe status result. Only a non-null `proof` means a person approved it with a passkey.
- `ApprovalState::assertConsistent(): void` throws `record_integrity_error` when the stored parts contradict each other: a status other than completed with a result, execution, proof or receipt behind it, or a completed one whose result is not the one its execution record holds for this request and proposal. `toArray()` and `EvidenceExporter::export()` call it; call it yourself before presenting a state read from a store directly, as a history or audit page does. Construction refuses an unknown status, and otherwise accepts parts that contradict each other, so that such a page can still load the row.
- `ApprovalRequest` has `id`, `canonical` and `digest`, with `actor(): Actor`, `prepared(): PreparedAction`, `payload(): array`, `statement(): string` and `expiresAt(): DateTimeImmutable`. The stored canonical payload is authoritative. Construction checks the payload against its digest and its canonical form, and refuses a mismatch with `corrupt_proposal`.
- `Ceremony` has `id`, `requestId`, `nonce` (base64url), `challenge` (base64url), `expiresAt` (a timestamp string), `options: array` and `consumed: bool`. `toArray()` returns `ceremonyId`, `options` and `expiresAt`, which is not a general approval capability.

## Storage

### The approval store

`ApprovalStore` is the storage and transaction boundary: `transaction(callable $callback): mixed`, `insert()`, `get()`, `findByKey()`, `findPending()`, `save()`, `putCeremony()`, `getCeremony()` and `retireCeremony()`. Its PHPDoc states the nine rules an adapter must keep: own the root transaction, include every authoritative write, serialize the writers that matter, run the callback once, keep canonical bytes, enforce identity and immutability, handle every `Throwable`, recover from authoritative state, and keep schema changes out of execution. On a database that locks rows, the contract also requires the application's reads to lock what the decision reads: an approval must never commit after a change to its grant, permission, content, passkey or rule that committed first.

The package `verified-human-approval/store-contracts` (`VerifiedHumanApproval\StoreContracts`) runs those rules against an adapter through a harness:

- `ApprovalStoreContract`, with a `StoreHarness`, and the `ApprovalHistoryContract` trait for a store that implements `ApprovalHistory`.
- `AuditStoreContract`, with an `AuditHarness`, for an `Audit\AuditStore`.
- `CredentialRepositoryContract`, with a `CredentialHarness`, for a `RevocableCredentialRepository`.
- The fixture application (`Fixture\*`, on a `Fixture\FixtureDatabase`, with a fixture schema per platform), `FaultingStore`, `FaultingSigner`, `Faults` (pauses at `authority_checked` and `before_commit` among them) and `ProcessRace`.

This repository runs them on the PDO approval and audit stores on SQLite, in the rollback journal and in WAL, and on the PDO passkey repository in the rollback journal. It runs them on the Doctrine stores on SQLite in both journals, PostgreSQL and MySQL, and CI runs the MySQL classes on MariaDB too. The server runs are named by `VERIFIED_HUMAN_APPROVAL_POSTGRES_URL` and `VERIFIED_HUMAN_APPROVAL_MYSQL_URL`. Require the same before relying on another adapter.

### The PDO store

`Storage\PdoApprovalStore(PDO $pdo)` keeps the approval tables on the caller's SQLite connection, and implements `ApprovalStore` and `ApprovalHistory`. `initialize(): void` creates the tables. `transaction(callable $callback): mixed` takes the write reservation with `BEGIN IMMEDIATE` and refuses to start inside another transaction. `recent(int $limit = 200)` lists the newest requests, and `connection(): PDO` returns the connection. Application tables, authorization rows, credential rows and approvals must all use this one connection.

### The Doctrine store

The package `verified-human-approval/doctrine` needs DBAL 4.4 or later and supports SQLite, PostgreSQL, MySQL 8.0.17 or later, and MariaDB.

- `DbalTransaction(Connection $connection)` is the one root transaction the stores share. `run(callable $callback): mixed` refuses to start inside any other transaction (DBAL, ORM or driver; its message names `wrapInTransaction()` and Messenger's `doctrine_transaction` middleware) and outside auto-commit mode. It begins through DBAL, so an ORM flush nests as a savepoint; takes SQLite's write reservation before anything is read; runs PostgreSQL, MySQL and MariaDB at `READ COMMITTED`; runs the callback once; and rolls back on any `Throwable`, keeping the original error. Before the commit it releases a marker savepoint, so a root that was ended, replaced, committed implicitly or aborted by a swallowed failed statement fails instead of reading as a commit.
- `DbalTransaction` also has `isActive(): bool`, `assertActive(): void`, `onRollback(callable $listener): void` (inside the transaction only), `forUpdate(): string` and `forShare(): string` (the lock clauses for the connection's database, empty on SQLite), and `connection(): Connection`.
- `DbalApprovalStore(DbalTransaction $transaction)` implements `ApprovalStore` and `ApprovalHistory`, plus `recent(int $limit = 200)`. Inside the transaction, on databases that lock rows, it locks the request row, the ceremony row and the agent connection's request stripe.
- `DbalAuditStore(DbalTransaction $transaction)` locks its lock row before reading the head. It joins any open transaction on the connection, and otherwise owns a short one.
- `DbalCredentialRepository(DbalTransaction $transaction)` implements `RevocableCredentialRepository`, plus `forTenant(string $tenantId)`. It keeps ids exactly, keyed by their SHA-256 digest. `update()` needs the approval transaction, and `add()` and `revoke()` any transaction on the connection.
- Every read goes to the primary of a `PrimaryReadReplicaConnection`.
- `ApprovalSchema::tables(AbstractPlatform $platform)`, `addTo(Schema $schema, AbstractPlatform $platform, bool $inPlace = false, ?callable $assetFilter = null): Schema`, `install(Connection $connection)` and `seed(Connection $connection)` define the tables, add them to a schema (respecting the connection's schema assets filter), create them, and seed the lock rows. The tables keep canonical JSON in text columns, never JSON or JSONB, with `LONGTEXT`, `utf8mb4` and a binary no-pad collation on MySQL and MariaDB. `install()` refuses to run inside a transaction.
- With `doctrine/orm` 3.5 or later:
  - `Orm\ApprovalSchemaListener(Connection $connection)` adds the tables on `postGenerateSchema`.
  - `Orm\EntityAction` is an `ActionAdapter` whose final `execute()` clears the entity manager, loads `targets()` with pessimistic write locks, calls `apply()` and flushes. It offers `current()` (reload under a lock), `persist()`, `currentApproval()` (the executing approval) and `manager()`, and resets the entity manager after a rollback.
  - `Orm\ProtectedEntityListener(ExecutionGuard $guard, array $protected = [])`, for `onFlush` and `preUpdate`, refuses with `approval_required` any change to an `#[Orm\RequiresApproval]` entity or field, or one named in `$protected`, that an approved execution did not `permit(object ...$entities)`. A change inside an embedded object is not caught; see the [security model](security-model.md#what-the-host-must-trust).

## The signed audit log

- `Audit\AuditLog(AuditStore $store, EvidenceSigner $signer, TrustedKeys|string $trustedPublicKey, ClockInterface $clock)` is the signed, hash-chained security log, one chain for every tenant. It builds, signs and checks entries (format `verified-human-approval/audit/v1`); the store only keeps rows. `$trustedPublicKey` is the application's `TrustedKeys`; a single base64url public key is read as a one-key set for the signer's key id, without a window. A row's stored `key_id`, which is not itself signed, picks the key that must verify it: an unlisted key, or an `at` outside that key's window, fails. Its methods:
  - `append(string $tenantId, string $type, string $subject, array $data): array` refuses to sign unless the signer's key is in the set, with the same public key, and its window includes now.
  - `find(string $type, string $subject): ?array` returns the newest entry that verifies.
  - `verify(): array` reports every entry, gaps and breaks; each entry also gives its row's `keyId` and `hash`.
  - `head(): ?array` returns the newest `seq` and `hash`, its `keyId` and `at`, and whether the chain is `intact`: a checkpoint to keep outside the application. `checkpoint(int $seq, string $hash): ?string` is null while a kept checkpoint holds, and otherwise says what changed.
  - `unvouchedKeys(): array` lists keys whose window has closed and whose entries no later entry signed with a key in use vouches for yet; each entry of `verify()` marks this as `unvouched`. `unrecordedRotations(): array` lists retired keys whose window has closed while no entry records the rotation away from them, flagged before any passkey stops approving.
  - `recordRotation(string $fromKeyId, string $reason = ''): array` and `trustedKeys(): TrustedKeys`.
- Key rotation in the chain: `recordRotation()` appends `signing_key.rotated` (`AuditLog::KEY_ROTATED`) for every tenant (tenant `AuditLog::ALL_TENANTS`, the empty string), with the old key as its subject and `data` `{from, to, publicKey, reason?}`, signed with the new key, which `to` and `publicKey` must name. It refuses an old key without `validUntil`, a new key without `validFrom`, the same key, a second rotation away from one key, and a return to a key the chain rotated away from. After that entry, while it verifies, `find()` and `verify()` refuse an entry signed with the old key, whatever time it claims. Once a key's window has closed, `find()` counts an entry it signed only while a later entry signed with a key in use links back to it through the chain; until then the passkeys it enrolled cannot approve, so record the rotation as the old key's window closes.
- `Audit\AuditStore`: `append(callable $buildRecord): array` serializes appenders before reading the head (the highest stored row, even a damaged one), calls the builder once and inserts exactly its row. On its own it owns and commits a transaction; inside the host's it joins it and never ends it. `recordsFor(string $type, string $subject): iterable` (newest first) and `records(): iterable` (oldest first) return rows unfiltered.
- `Storage\PdoAuditStore(PDO $pdo)` is the SQLite adapter, with `initialize(): void`. A host transaction it joins must have been started with `PDO::beginTransaction()`.

## Evidence

- `Evidence\EvidenceSigner`: `keyId(): string`, `sign(string $manifest): string` and `publicKey(): string`, the base64url Ed25519 key that verifies its signatures. `Evidence\SodiumEvidenceSigner` implements it. One signer, the current key, signs new receipts and audit entries.
- `Evidence\TrustedKey(string $keyId, string $publicKey, ?DateTimeImmutable $validFrom = null, ?DateTimeImmutable $validUntil = null)`: one public key and the window it signed in, both ends inclusive; `null` means no earlier limit, or still signing. It has `covers(DateTimeImmutable $time): bool`, `coversClaim(mixed $claimed): bool` (any claim, for a key without a window), `bounded(): bool` (whether it has a window), `signed(string $manifest, string $signature): bool`, `toArray()` and `fromArray()`, and `TrustedKey::time(string $value)`, which reads an RFC 3339 time and refuses one without an offset.
- `Evidence\TrustedKeys(iterable $keys)`: the key set, by unique key id, in order. `single(string $keyId, string $publicKey)` is a one-key set without a window. `fromDocument(array $document)` reads a key-set file (`TrustedKeys::FORMAT`, `verified-human-approval/receipt-keys/v2`: `keys`, each `{keyId, publicKey, validFrom, validUntil}`) or a one-key file (`TrustedKeys::SINGLE_KEY_FORMAT`, `verified-human-approval/receipt-key/v1`, or no `format`: `keyId` and `publicKey`). It has `get(string $keyId): ?TrustedKey`, `all()` and `toArray()`. `forSigner(EvidenceSigner $signer, ?string $validFrom, mixed $retired = [])` builds an application's keys from its settings: the signer's key, open from `$validFrom`, then each retired key (`retired()`, a list of `{key_id, public_key, valid_from?, valid_until}`, the `RETIRED_FIELDS`).
- `Evidence\EvidenceExporter(?EvidenceSigner $signer = null, bool $signAtExport = false)`, `export(ApprovalState $state): array`: the evidence bundle (format `verified-human-approval/evidence/v2`). An original receipt comes back unchanged and no signer is touched, so serving evidence needs no private key; `$signAtExport` needs a signer. Without `$signAtExport` it refuses a completed action whose receipt signed at commit is missing (`receipt_missing`), since deleting a receipt is how an edit would be hidden. It exports nothing, and signs nothing, for a state whose stored parts contradict each other (`record_integrity_error`). The receipt (`verified-human-approval/receipt/v2`) signs `signedAt`, `commit` or `export`, with the digests; `ReceiptVerifier::verify()` returns it as `receiptSignedAt`.
- `Evidence\ReceiptVerifier::verify(array $bundle, TrustedKeys|string $trustedKeyId, ?string $trustedPublicKey = null): array`: pass the `TrustedKeys`, or one key id and its public key. The receipt's `keyId` picks the key, and an unlisted key fails. A commit receipt's time is its execution record's `recordedAt` (signed through the execution digest), which must fall inside that key's window; an export receipt only claims to come after `recordedAt`, so its key must not have retired before then. It returns `request`, `proof`, `execution`, `applicationSignatureValid`, `receiptSignedAt` and `keyId`; a failure is `ApprovalException('invalid_evidence')`, whose previous exception says what did not match.

### Canonical JSON

`CanonicalJson` delegates key ordering to `root23/php-json-canonicalization` 1.0.1 and re-emits the ordered graph with PHP's flags for unescaped Unicode and line terminators. This undoes the library's escaping of U+2028 and U+2029, which [RFC 8785 section 3.2.2.2](https://www.rfc-editor.org/rfc/rfc8785.html#section-3.2.2.2) requires literally. The only numbers accepted are exact integers. Tests keep a literal backslash-u sequence apart from those Unicode characters.

## WebAuthn

The WebAuthn package (`VerifiedHumanApproval\WebAuthn`) holds the passkey types, their storage, the verifier and the offline `verify-evidence.php` command. Neither core nor this package requires a framework.

- `Credential(string $id, string $userId, string $tenantId, string $sourceJson, bool $revoked = false, string $label = 'Passkey')`, with `belongsTo(Actor $actor): bool`. `Configuration(string $rpId, string $origin, int $timeoutMs = 60000)`. `UserHandleProvider::forActor(Actor $actor): string`, with `HashedUserHandleProvider` as the default.
- `CredentialRepository` (`find()`, `forActor()`, `add()`, `update(Credential $credential, string $expectedSourceJson)`), and `RevocableCredentialRepository`, which adds `revoke(Actor $owner, string $id): bool`. A raw adapter never overwrites, reassigns or reactivates an id, treats ownership as user and tenant together, compares and replaces on the active source (a zero-counter passkey writes back an identical source), and joins the host's transaction. `PdoCredentialRepository(PDO $pdo)` is the SQLite adapter, with `initialize()` and `forTenant()`.
- `AnchoredCredentialRepository(RevocableCredentialRepository $credentials, AuditLog $audit)` wraps a raw adapter and accepts a passkey only while a signed enrollment entry vouches for it and no signed revocation entry is found. Call `anchor(Credential $credential, array $context)` in the same transaction as the registration, and `revoke(Actor $owner, string $id, array $context)` in the same transaction as its signed entry. `enrolled()` and `enrollmentRecord()` read the signed enrollment, and `unvouched(Actor $actor): array` lists the owner's stored passkeys that no signed enrollment vouches for now. When those are all a person has, `WebAuthnVerifier` refuses with `credential_unvouched` rather than `credential_required`, because enrolling another passkey would not help.
- `WebAuthnVerifier(Configuration $configuration, CredentialRepository $credentials, ?UserHandleProvider $userHandles = null)` implements the core `ApprovalVerifier`. It also has the trusted host's enrollment methods, `registrationOptions(Actor $actor, string $challenge, string $displayName)` and `register(Actor $actor, array $savedOptions, array $response, string $label = 'Passkey')`, and `verifyEvidenceAssertion()` for the offline check. Every production assertion goes through `web-auth/webauthn-lib`; tests may use a verifier that is clearly test-only.
- `verify-evidence.php` reads either key file and reports `applicationKeyId`; the [receipt walkthrough](evidence.md) runs it.

## MCP presentation

The MCP package (`VerifiedHumanApproval\Mcp`) needs only the core and the PHP MCP SDK. Its [README](../src/mcp/README.md) describes the card, the URL prompt and the error results.

- `ApprovalFlow(ApprovalService $approvals, ResultPresenter $presenter, UrlPrompt $prompt)`. `respond(RequestContext $context, Actor $actor, string $tool, array $arguments, callable $request): CallToolResult|InputRequiredResult` presents a protected tool's result, and `ApprovalFlow::errorResult(ApprovalException $error, ?string $requestId = null): CallToolResult` its refusal. `ApprovalFlow::OUTPUT_SCHEMA` is the tools' output schema, and `ApprovalFlow::REQUEST_KEY_DESCRIPTION` the description of an optional request key.
- `ResultPresenter`: a platform's `reviewUrl(ApprovalState $state): string`, `toolResult(ApprovalState $state, ?string $message = null): CallToolResult` and `statusTool(): string`. The Symfony bundle's `ApprovalPresenter` implements it.
- `UrlPrompt(bool $enabled = false, array $clients = [])`: when to send the URL-mode elicitation, and how its retry is bound.
- `ApprovalCard`: the card's `ui://` resource (`ApprovalCard::URI`, `resource()`), the tool metadata that links a tool to it (`ApprovalCard::TOOL_META`), and `view()`, the fields the card reads.

The testing helpers (`VerifiedHumanApproval\Testing`) are described in their [README](../src/testing/README.md).

## The Symfony bundle

- `ActorProvider`: `current(): Actor` from the application's verified principal, never from MCP arguments; `interactive(): Actor`, which an agent token must not satisfy; and `assertCanReadApproval(ApprovalState $state): void`, the current read permission of a delegated caller.
- `RequesterLabel::describe(Actor $requester): ?string`, optional: names who made a request on the review page, so the person can tell an agent's request from their own.
- `persistence: { driver: doctrine, connection: <name>, protected_entities: {} }`, exclusive with `store` and needing `receipt_signer`, wires `DbalTransaction`, the three Doctrine stores, `AuditLog` (checked against the bundle's `TrustedKeys`), `AnchoredCredentialRepository` (aliased as `CredentialRepository`), the schema and protected-entity listeners, and the `verified-human-approval:install` command, on that DoctrineBundle connection.
- With a `receipt_signer`, the bundle builds the trusted keys from it and from `receipt_keys` (`valid_from` for the signer; `retired`, a list of `{key_id, public_key, valid_from?, valid_until}`). It registers them as the service `VerifiedHumanApproval\Core\Evidence\TrustedKeys` (id `verified_human_approval.trusted_keys`) for the host's `AuditLog`, passes them to `ApprovalService` for its read check, and publishes them at `GET /approval/receipt-keys.json` (route `verified_human_approval_receipt_keys`, format `verified-human-approval/receipt-keys/v2`, with `currentKeyId`, `rpId` and `origin`). `GET /approval/receipt-key.json` serves the current key alone (`verified-human-approval/receipt-key/v1`).
- The console commands `verified-human-approval:audit-head` (`--json`, `--check=SEQ:HASH`) and `verified-human-approval:record-key-rotation <from> [--reason]` use the `AuditLog` named by `audit_log`, or the service named after that class.

### Read permission

The core's reads check ownership by user and tenant: the browser, or another connection the person currently authorizes, need not be the connection that asked. The delegation stays bound where it exists: for a request key, a keyless pending request and a continuation. So the host checks its current read permission, through `ActorProvider::assertCanReadApproval()` or in the tool, before every path that can return a stored result: a new request or a retry (a completed result can come back before write authority is checked), a continuation, a status read and an evidence export. The demo's tools check `approval.read`, including when a request key returns a result. An administrator's access to other people's results and evidence needs its own explicit policy.
