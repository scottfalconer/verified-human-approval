# Implemented extension contracts

Namespaces: `VerifiedHumanApproval\Core`, `VerifiedHumanApproval\WebAuthn`, `VerifiedHumanApproval\Bundle`, `VerifiedHumanApproval\Demo`.

Core public value types and interfaces (all in `VerifiedHumanApproval\Core` unless stated):

- `Actor(string $userId, string $tenantId, ?string $delegationId = null)`: authenticated subject. Reviewer and initiator must match user/tenant; stored initiator delegation is independently rechecked at execution.
- `PreparedAction(string $actionId, string $version, array $target, array $effect, array $preconditions, array $display, array $placeholders = [])`.
- `ApprovalPolicy(string $actionId, string $version, string $statementTemplate, int $lifetimeSeconds = 600, bool $required = true)`.
- `ActionAdapter`: `id(): string`, `version(): string`, `prepare(Actor $actor, array $input): PreparedAction`, `assertPreconditions(Actor $actor, PreparedAction $action): void`, `execute(Actor $actor, PreparedAction $action): array`, `assertPostconditions(Actor $actor, PreparedAction $action, array $result): void`.
- `PolicyProvider::get(string $actionId, ?Actor $actor = null): ApprovalPolicy`: the rule for this action, for this person when one is given, so it can depend on their role.
- `Authorizer::assertAllowed(Actor $actor, PreparedAction $action): void` must reload current user/delegation authority using the transaction's database, not trust stored roles.
- `ApprovalVerifier::requestOptions(Actor $actor, string $challenge): array`, `verify(Actor $actor, string $challenge, array $assertion): VerifiedApproval`. Challenge is raw bytes. Verification and credential-counter persistence occur within the final transaction.
- `VerifiedApproval(string $credentialId, array $evidence)`.
- `ApprovalException(string $reason, string $message)` for deliberate rejection.
- `ExecutionGuard::assertExecuting(Actor $actor, PreparedAction $action): void`: application mutation services call this; service opens a narrow execution context only after all checks. No MCP argument can create it.
- `ActionRegistry(iterable $actions)`, `get(string $id): ActionAdapter`.
- `ApprovalService(ActionRegistry $actions, PolicyProvider $policies, Authorizer $authorizer, ApprovalStore $store, ApprovalVerifier $verifier, ClockInterface $clock, ExecutionGuard $guard, ?EvidenceSigner $receipts = null)`: with `$receipts`, each execution's receipt is signed inside the completing transaction, and a signing failure means nothing executes.
- `ApprovalService::request(Actor $actor, string $actionId, array $input, ?string $idempotencyKey = null, string $locale = 'en'): ApprovalState`.
- `ApprovalService::get(Actor $actor, string $requestId): ApprovalState` (ownership first, including completed results).
- `ApprovalService::begin(Actor $reviewer, string $requestId): Ceremony` (checks ownership, state and current eligibility; produces passkey options).
- `ApprovalService::complete(Actor $reviewer, string $ceremonyId, array $assertion): ApprovalState` (stored action only; no replacement arguments).
- `ApprovalService::cancel(Actor $reviewer, string $requestId): ApprovalState`.
- `ApprovalState` has `request: ApprovalRequest`, `status: string`, `reason: ?string`, `result: ?array`, `execution: ?array`, `proof: ?array`, `receipt: ?array`; `toArray(): array` is a safe status result. Only a non-null `proof` means a person approved it with a passkey. Status values pending, completed, invalidated, expired, cancelled, denied.
- `ApprovalRequest` has `id`, `canonical`, `digest`; `actor(): Actor`, `prepared(): PreparedAction`, `payload(): array`, `statement(): string`, `expiresAt(): DateTimeImmutable`. Stored canonical payload is authoritative and always checked against its digest.
- `Ceremony` has `id`, `requestId`, `nonce` (base64url), `challenge` (base64url), `expiresAt` (timestamp string), `options: array`, `consumed: bool`; `toArray()` returns ceremonyId/options/expiry, not a general approval capability.
- `Storage\PdoApprovalStore(PDO $pdo)` with `initialize(): void` owns approval tables on caller's SQLite PDO; `transaction(callable $callback): mixed` uses BEGIN IMMEDIATE, rejects unrelated nested transactions. Application tables, authorization rows, credential rows and approvals use this exact connection. `connection(): PDO` exposes it for demo wiring.

- `Evidence\EvidenceExporter(EvidenceSigner $signer, bool $signAtExport = false)`, `export(ApprovalState $state): array`: the evidence bundle (format `verified-human-approval/evidence/v2`). Without `$signAtExport` it refuses a completed action whose receipt signed at commit is missing (`receipt_missing`), since a deleted receipt is how an edit would be hidden. The receipt (`verified-human-approval/receipt/v2`) signs `signedAt`, `commit` or `export`, with the digests; `Evidence\ReceiptVerifier::verify()` returns it as `receiptSignedAt`.

The WebAuthn package owns its credential types/repository and PDO implementation, and the `verify-evidence.php` command. It implements core ApprovalVerifier, plus trusted-host enrollment methods. No framework dependency is required by core or WebAuthn bridge. All production assertions use upstream verification; tests may use a distinctly test-only verifier.

Exactness uses stored canonical bytes, policy digest including resolved statement/version, prepared action behavior version, resource preconditions, current authority, fresh proposal-bound WebAuthn assertion, and postconditions. Completion consumes approval and writes business state and execution record in one transaction. Known execution rollback retires its ceremony; retry starts a fresh ceremony. A completed retry returns existing result, with ownership checked before disclosure.

Policy `required=false` is explicitly an unprotected request: it still checks authority/preconditions and records atomic outcome but has no passkey proof. A pending proposal from the old required policy cannot execute after any policy change. The browser and MCP adapters cannot edit policies; host administrator routes control configuration.

The Symfony bridge also requires `ActorProvider::assertCanReadApproval(ApprovalState): void` for current delegated result-read authorization. Core historical reads enforce ownership; the host must decide which delegated connections can read those results. The example checks `approval.read`, including idempotent request-result retrieval.

Canonicalization delegates ordering to `root23/php-json-canonicalization` 1.0.1 and re-emits the ordered graph with PHP's unescaped Unicode/line-terminator flags. This compensates for upstream 1.0.1 escaping U+2028/U+2029 while [RFC 8785 section 3.2.2.2](https://www.rfc-editor.org/rfc/rfc8785.html#section-3.2.2.2) requires them literally. The accepted number profile is exact integers only. Tests preserve literal backslash-u text separately from those Unicode characters.
