# Verification status

This page separates checks on the current code baseline from earlier device demonstrations. Older test counts and environment limitations are kept in the [verification history](verification-history.md).

## Current automated baseline

Checked on 2026-11-09 against commit [`09b3e75e`](https://github.com/scottfalconer/verified-human-approval/commit/09b3e75e60746e8742a438b1943c18fbe59d929a). The [GitHub Actions run for that commit](https://github.com/scottfalconer/verified-human-approval/actions/runs/42721674902) completed successfully, with all eleven of the jobs the workflow had then. The two jobs on PHP 8.5, and the rule that a deprecation fails the run, were added after it: the checks on the commit you install say whether they pass.

The [workflow](../.github/workflows/tests.yml) runs the whole suite in these jobs:

| Dependency set | PHP | Checks |
| --- | --- | --- |
| Locked dependencies | 8.3 | Composer validation, PHPUnit, PHPStan, coding standard, the card lifecycle check and real HTTP smoke test. |
| Locked dependencies | 8.4 | Composer validation, PHPUnit, PHPStan and real HTTP smoke test. |
| Locked dependencies | 8.5 | Composer validation, PHPUnit, PHPStan and real HTTP smoke test. |
| Lowest allowed dependencies | 8.3 | Composer validation, PHPUnit and real HTTP smoke test. The job raises the test-only `symfony/error-handler` floor as explained in the workflow. |
| Symfony 8 | 8.4 | Composer validation, PHPUnit and real HTTP smoke test. |

Further jobs run the storage suites on PostgreSQL 14 and 17, MySQL 8.0 and 8.4 and MariaDB 11.4, and install the packages into a separate project on PHP 8.3, 8.4 and 8.5. The table below describes both.

A deprecation fails the test run, whichever package raises it: PHP's own, and the ones Symfony and Doctrine raise silenced. None is ignored for being raised inside a dependency, because that is where most of the ones an application can cause are raised: a configuration default it left unset, a value it handed to a serializer. This holds wherever the locked versions are installed, the storage jobs included. The lowest-versions and Symfony 8 jobs resolve their versions as they run, so there a deprecation is listed in the job's output and does not fail it: an old release, or a new one, of someone else's package can raise one that no commit here caused.

This is a dated baseline, not a claim that every later commit has passed. Use the checks on the commit or pull request you plan to install. A successful suite is also not a production security certification.

## What the automated tests exercise

| Area | Coverage and source |
| --- | --- |
| Approval lifecycle | Frozen content and policy, current permissions, guarded mutation, cancellation, expiry, retries and transaction rollback in [`tests/Core`](../tests/Core). |
| Storage contract and concurrency | [`ApprovalStoreContract`](../src/store-contracts/src/ApprovalStoreContract.php), run by [`tests/Contract`](../tests/Contract), checks the `ApprovalStore` rules on the SQLite PDO adapter (rollback journal and WAL) and the Doctrine adapter (SQLite in both journals, PostgreSQL, MySQL): every authoritative write in one transaction (including the signed receipt row), failures at each step, a durable commit whose answer is lost (recovered, not repeated), recovery that cannot reach the store (`execution_uncertain`, no replay), immutable terminal records, request-key isolation, and independent processes racing one ceremony, two ceremonies, two proposals for one revision, keyed and keyless requests, an ordinary edit, a grant revocation and a lock timeout. An approval paused just after its authority check or just before its commit, while another connection revokes the grant or permission, edits the content, revokes the passkey or changes the policy, either holds the change off or never commits after it; a ceremony retired after a failed attempt is never used by a completion that waited; a near-256 KiB proposal with U+2028 and 4-byte characters, and `{}` apart from `[]`, keep their bytes. CI runs them on PostgreSQL 14 and 17, MySQL 8.0 and 8.4, and MariaDB 11.4; locally, set `VERIFIED_HUMAN_APPROVAL_POSTGRES_URL` or `VERIFIED_HUMAN_APPROVAL_MYSQL_URL`. |
| Signed audit log and passkey storage | [`AuditStoreContract`](../src/store-contracts/src/AuditStoreContract.php) checks every audit store (SQLite PDO in both journals; Doctrine on SQLite, PostgreSQL and MySQL) for a chain written before the store was extracted, every signed or bound field, a damaged head, the host's transaction, appenders held off from before the head is read, an append after an older view never forking the chain, and processes appending at once; [`AuditStoreTest.php`](../tests/Core/AuditStoreTest.php) runs the log over a store with no PDO. [`CredentialRepositoryContract`](../src/store-contracts/src/CredentialRepositoryContract.php) checks every passkey store for an identical zero-counter write-back, exact ids and ownership. [`PortableCredentialStorageTest.php`](../tests/WebAuthn/PortableCredentialStorageTest.php) runs signed enrollment and revocation over storage with no PDO; [`PasskeyRevocationTest.php`](../tests/Bundle/PasskeyRevocationTest.php) shows a revocation rolls back when its entry cannot be signed or stored. |
| Doctrine storage | [`tests/Doctrine`](../tests/Doctrine) checks the approval transaction on each database (outer DBAL, PDO and ORM transactions refused; nested transactions as savepoints; a root ended, replaced or aborted caught before commit; the original error kept when rollback fails; `READ COMMITTED` on server databases), the schema (installed exactly as defined, so a migration diff is empty; JSON in text columns; in the ORM's schema), primary/replica reads, and ORM actions with the protected-entity check. [`DoctrinePersistenceTest.php`](../tests/Bundle/Doctrine/DoctrinePersistenceTest.php) boots DoctrineBundle with `persistence: { driver: doctrine }`. |
| Signing key rotation | Receipts, audit entries and passkey enrollments signed before a rotation keep verifying; a record dated outside its key's window, one signed with an unlisted key, and an entry the retired key signs after the recorded rotation, backdated or not, fail. [`ReceiptKeyRotationTest.php`](../tests/Evidence/ReceiptKeyRotationTest.php) also runs the offline command with both key files; [`KeyRotationTest.php`](../tests/Bundle/KeyRotationTest.php) rotates the demo and checks the published key set, the audit page and a checkpoint of the log's head. |
| Passkeys | Real synthetic ES256 signatures and registration data pass through the upstream WebAuthn validator in [`tests/WebAuthn`](../tests/WebAuthn). Rejection cases cover identity, origin, relying party, challenge, verification flags, counters and revocation. |
| Symfony and MCP | The actual kernel, security, sessions, CSRF, SDK transport, scope checks and browser endpoints in [`tests/Bundle`](../tests/Bundle). |
| HTTP scope challenges | [`ScopeAuthorizationTest.php`](../tests/Bundle/ScopeAuthorizationTest.php) calls the demo through its kernel. A token lacking one of a tool's scopes gets HTTP 403 with `insufficient_scope`, every scope the operation needs, the resource metadata and a JSON-RPC error naming them, and nothing of the tool runs; a batch needs the scopes of every call in it; a misleading `Mcp-Name` or `Mcp-Method` header never selects another tool's scopes; listing tools needs none; the refused request succeeds when retried with a broader token; ownership and permission refusals stay tool results; with the subscriber removed, the tools and the authorizer still refuse. [`McpScopeChallengeSubscriberTest.php`](../tests/Bundle/McpScopeChallengeSubscriberTest.php) covers the class alone: 401 only where a scope is needed and no token was validated, 413 for a body too large to inspect, implied and default scopes, header escaping, and other controllers and preflights left alone. |
| Historical evidence | Real approval and receipt signatures, later record edits, missing receipts, wrong trust keys and the separately installed verifier in [`ApprovalEvidenceTest.php`](../tests/Evidence/ApprovalEvidenceTest.php). |
| Stored outcome consistency | [`StoredApprovalIntegrityTest.php`](../tests/Evidence/StoredApprovalIntegrityTest.php) edits a stored status and a stored result: the service will not report, export or retry that request, and the action still ran once. With a signer, a deleted or replaced receipt is refused even when the edited parts agree; without one, an application still reads its own completions. [`StoredApprovalAuditTest.php`](../tests/Bundle/StoredApprovalAuditTest.php) makes the same edits in the demo: the review page, its JSON routes and the three MCP tools answer `integrity_error`, the audit page keeps the request listed and marked untrusted while still checking its passkey signature, no route replaces the original receipt, and the alert changed once. |
| Separate consumer | [`check-consumer.php`](../tests/Package/check-consumer.php), a CI job on PHP 8.3, 8.4 and 8.5, installs core and WebAuthn, then those with MCP, then those with the Doctrine storage, into a separate Composer project from `src/` (copied, matching `dev-main`, stable defaults), and checks them with only that project's autoloader: no framework bundle installed, WebAuthn options built, the MCP card loaded, and the installed `verify-evidence.php` verifying the [synthetic commit receipt](../tests/fixtures/evidence/commit-v2). It also checks what each package archive ships. A path install is not a published-package installation. |
| MCP card lifecycle | [`card_lifecycle.mjs`](../tests/card_lifecycle.mjs) runs the card's real script in Node against a scripted host with a controlled clock. A reply that arrives after the site recorded an outcome, after another request replaced this one, or after the host stopped or tore down the card changes nothing; an unanswered request times out; a host that declares no `openLinks` or `serverTools` is sent neither request and the card says what it cannot do; status checks back off, pause while hidden, stop after a quarter of an hour and say so; an integrity error is never shown as "nothing changed". CI runs it in the locked PHP 8.3 job. It is a state-machine test: layout and a real hosted client are outside it. |
| HTTP and Symfony AI | [`http_smoke.py`](../tests/http_smoke.py) starts the application and calls it over TCP using the real SDK. It also runs Symfony AI's real `Agent` and `McpToolbox` with scripted model responses. |

Core lifecycle tests use a test-only verifier where cryptography is not the subject. WebAuthn, evidence and Symfony integration tests exercise the production WebAuthn bridge. Synthetic authenticators test signatures and protocol behavior; they do not establish physical biometric behavior.

## Browser, device and hosted-client checks

These results have different scopes and dates. They do not all describe the latest code.

| Surface | Recorded result | Scope |
| --- | --- | --- |
| Chromium: stopped requests and lost responses | Passed on `0c3bb115`, as its [commit message](https://github.com/scottfalconer/verified-human-approval/commit/0c3bb115341e9ba36d876d3f76da38e947b15db4) records. | Node Playwright: stopped request, dropped options call, lost approval response, lost cancellation response and stopped card call. |
| Chromium: the person's receipt | Passed on `4ea04eb1`, as its [commit message](https://github.com/scottfalconer/verified-human-approval/commit/4ea04eb1487cc59a77687f8a4753dcc690957f48) records. | Node Playwright: approve, download the receipt, fetch `/approval/receipt-key.json` while signed out, check the file offline with only that key, see an edited copy fail, and find the same signature in the agent's result. |
| Python browser checks | `tests/card_smoke.py`, `tests/browser_smoke.py` and `tests/phone_smoke.py` passed on `0c3bb115` and again on `4ea04eb1`. | Python Playwright 1.63 with Chrome for Testing (build 1234) and virtual authenticators, run locally. |
| Browser flow, card and phone handoff with virtual authenticators | Successful local runs are recorded on November 8, 2026. | Earlier code; see [history](verification-history.md). These are separate from the PHP CI matrix. |
| claude.ai, OAuth and physical iPhone | Successful approval recorded on November 8, 2026, at 17:46 UTC. | Earlier alert demo, run by Scott. The phone used the scoped review session and an enrolled passkey. |
| Claude Desktop and physical Touch ID | Successful approval recorded on November 8, 2026, at 16:21 UTC. | Earlier announcement version of the demo, before the alert naming and later enrollment changes. |
| Claude Code URL prompt | Successful run recorded on November 8, 2026. | Earlier code. The history records the client version and model used. |
| Symfony AI with a live model | Not established by the scripted integration check. | The [live example](../examples/README.md#live-model) requires a separate model call. |

No physical-device rerun against `4ea04eb1` is recorded here. Keep that distinction when describing tested clients or preparing a demonstration.

## Run the checks yourself

From the repository root, after `composer install`:

```bash
composer test
composer analyse
composer cs
PHP_BIN=php python3 tests/http_smoke.py
node --test tests/card_lifecycle.mjs
```

The Python HTTP check uses the standard library. The card lifecycle check needs Node.js 22 or later and installs nothing. The optional browser checks need Python Playwright and Chromium. The card check also needs PHP's `gd` extension to decode its QR code. Install the Python dependencies in a virtual environment:

```bash
python3 -m venv var/browser-checks
. var/browser-checks/bin/activate
python3 -m pip install playwright
python3 -m playwright install chromium
PHP_BIN=php python3 tests/browser_smoke.py
PHP_BIN=php python3 tests/card_smoke.py
PHP_BIN=php python3 tests/phone_smoke.py
```

The scripts use Playwright's Chromium unless `CHROMIUM_BIN` or a system `chromium` selects another executable. The browser scripts use virtual authenticators. For a physical-device check, follow the [demo](../demo/README.md) on the device and client you intend to support. Record the commit, origin, browser, client and device alongside the outcome.

The [receipt walkthrough](evidence.md) provides a smaller check: verify an original record, change a copy, and confirm that verification fails.

## Historical records

[verification-history.md](verification-history.md) preserves the original run notes, including the original 132-test result and later local runs. [verification-results.json](verification-results.json) is the machine-readable record of that first build. Its counts and untested-device flags are historical, not the current support matrix.
