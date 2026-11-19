# Verification status

What runs automatically on every push, which browser checks run by hand, what has been checked with real clients and devices, and what is not proven.

## Automated checks

GitHub Actions runs [the test workflow](../.github/workflows/tests.yml) on every push and pull request, in these jobs:

| Job | PHP | What it runs |
| --- | --- | --- |
| Locked dependencies | 8.3 | Composer validation of the root manifest and the seven package manifests, PHPUnit, PHPStan, the coding standard, the two Node checks ([`card_lifecycle.mjs`](../tests/card_lifecycle.mjs) and [`approval_recovery.mjs`](../tests/approval_recovery.mjs)), and the MCP and OAuth smoke tests over real HTTP |
| Locked dependencies | 8.4 and 8.5, one job each | Composer validation, PHPUnit, PHPStan and the two HTTP smoke tests |
| Lowest allowed dependencies | 8.3 | Composer validation, PHPUnit and the two HTTP smoke tests. The job raises the test-only `symfony/error-handler` floor, as the workflow explains |
| Symfony 8 | 8.4 | Composer validation, PHPUnit and the two HTTP smoke tests |
| Separate consumer | 8.3, 8.4 and 8.5, one job each | The packages installed into separate Composer projects and into a fresh Symfony application, then a check of what each package archive ships |
| Editorial Desk consumer | 8.3 | The current packages installed into the Symfony application in [`tests/EditorialDesk`](../tests/EditorialDesk), with its PHP, HTTP and browser checks (Chromium from Playwright 1.62.1, with a virtual authenticator) |
| Storage on PostgreSQL | 8.3 | PostgreSQL 14 and 17, one job each: the PostgreSQL classes of the `contract`, `doctrine` and `example` suites, with `--fail-on-skipped` |
| Storage on MySQL and MariaDB | 8.3 | MySQL 8.0, MySQL 8.4 and MariaDB 11.4, one job each: the MySQL classes of the same suites, with `--fail-on-skipped` |

A deprecation fails the test run, whichever package raises it: PHP's own, and the ones Symfony and Doctrine raise silenced. None is ignored for being raised inside a dependency, because that is where most of the ones an application can cause are raised: a configuration default it left unset, a value it handed to a serializer. This holds wherever the locked versions are installed, the storage jobs included. The lowest-versions and Symfony 8 jobs resolve their versions as they run, so there a deprecation is listed in the job's output and does not fail it: an old or a new release of someone else's package can raise one that no commit here caused.

A run checks the commit it ran on. The checks shown beside a commit or pull request on GitHub say whether that commit passed. A passing run is not a security audit.

<!-- Second documentation pass: record the baseline here, for the commit that is submitted: its short hash linked to the commit, the date, a link to its GitHub Actions run, and the PHPUnit totals of the locked PHP 8.3 job (tests, assertions, skipped). Then delete this comment. -->

## What the tests cover

| Area | What is checked, and where |
| --- | --- |
| Approval lifecycle | [`tests/Core`](../tests/Core): frozen content and rule, current permissions, the guarded action, cancellation, expiry, retries with and without a request key, and transaction rollback. |
| Storage contract and concurrency | [`ApprovalStoreContract`](../src/store-contracts/src/ApprovalStoreContract.php), run by [`tests/Contract`](../tests/Contract) on the SQLite PDO store (rollback journal and WAL) and the Doctrine store (SQLite in both journals, PostgreSQL and MySQL): every authoritative write in one transaction, the signed receipt included; a failure at each step; a commit whose answer is lost, recovered and not repeated; recovery that cannot reach the store (`execution_uncertain`, no replay); terminal records that do not change; request keys kept apart per connection; and separate processes racing one ceremony, two ceremonies, two proposals for one revision, keyed and keyless requests, an ordinary edit, a grant revocation and a lock timeout. An approval paused just after its authority check or just before its commit, while another connection revokes the grant or permission, edits the content, revokes the passkey or changes the rule, either holds that change off or does not commit after it. A ceremony retired after a failed attempt is never used by a completion that waited. A proposal near 256 KiB with U+2028 and 4-byte characters, and `{}` beside `[]`, keep their bytes. |
| Signed audit log and passkey storage | [`AuditStoreContract`](../src/store-contracts/src/AuditStoreContract.php) runs on the SQLite PDO store (both journals) and the Doctrine store (SQLite in both journals, PostgreSQL and MySQL): a stored `audit/v1` chain from a fixture still verifies, and new entries extend it while its rows keep their bytes; every signed or bound field is checked; a damaged head; the host's transaction; appenders held off from before the head is read; an append after an older view never forks the chain; processes appending at once. [`AuditStoreTest.php`](../tests/Core/AuditStoreTest.php) runs the log over a store with no PDO. [`CredentialRepositoryContract`](../src/store-contracts/src/CredentialRepositoryContract.php) checks the PDO and Doctrine passkey stores for an identical zero-counter write-back, exact ids and ownership. [`PortableCredentialStorageTest.php`](../tests/WebAuthn/PortableCredentialStorageTest.php) runs signed enrollment and revocation over storage with no PDO; [`PasskeyRevocationTest.php`](../tests/Bundle/PasskeyRevocationTest.php) shows a revocation rolls back when its entry cannot be signed or stored. |
| Doctrine storage | [`tests/Doctrine`](../tests/Doctrine) checks the approval transaction on each database (outer DBAL, PDO and ORM transactions refused; nested transactions as savepoints; a root ended, replaced or aborted caught before commit; the original error kept when rollback fails; `READ COMMITTED` on server databases), the schema (installed exactly as defined, so a migration diff is empty; JSON in text columns; in the ORM's schema), primary and replica reads, and ORM actions with the protected-entity check. [`DoctrinePersistenceTest.php`](../tests/Bundle/Doctrine/DoctrinePersistenceTest.php) boots DoctrineBundle with `persistence: { driver: doctrine }`. |
| Signing key rotation | Receipts, audit entries and passkey enrollments signed before a rotation keep verifying; a record dated outside its key's window, one signed with an unlisted key, and an entry the retired key signs after the recorded rotation, backdated or not, fail. [`ReceiptKeyRotationTest.php`](../tests/Evidence/ReceiptKeyRotationTest.php) also runs the offline command with both key files; [`KeyRotationTest.php`](../tests/Bundle/KeyRotationTest.php) rotates the demo's key and checks the published key set, the audit page and a checkpoint of the log's head. |
| Passkeys | Synthetic ES256 credentials whose real signatures and registration data pass through the upstream WebAuthn validator, in [`tests/WebAuthn`](../tests/WebAuthn). Refusals cover identity, origin, relying party, challenge, verification flags, counters and revocation. |
| Symfony and MCP | The real kernel, security, sessions, CSRF, the SDK's HTTP transport, scope checks and the browser endpoints, in [`tests/Bundle`](../tests/Bundle). [`HandshakeEraTest.php`](../tests/Bundle/HandshakeEraTest.php) connects a 2025-11-25 client that declares URL-mode elicitation under a listed client name: it gets the plain pending result, the card and the status, and never an elicitation. |
| HTTP scope challenges | [`ScopeAuthorizationTest.php`](../tests/Bundle/ScopeAuthorizationTest.php) calls the demo through its kernel. A token lacking one of a tool's scopes gets HTTP 403 with `insufficient_scope`, every scope the operation needs, the resource metadata and a JSON-RPC error naming them, and nothing of the tool runs; a batch needs the scopes of every call in it; a misleading `Mcp-Name` or `Mcp-Method` header never selects another tool's scopes; listing tools needs none; the refused request succeeds when retried with a broader token; ownership and permission refusals stay tool results; with the subscriber removed, the tools and the authorizer still refuse. [`McpScopeChallengeSubscriberTest.php`](../tests/Bundle/McpScopeChallengeSubscriberTest.php) covers the class alone: 401 only where a scope is needed and no token was validated, 413 for a body too large to inspect, implied and default scopes, header escaping, and other controllers and preflights left alone. |
| Receipts | Real passkey and receipt signatures, later edits to the record, missing receipts, wrong trusted keys and the separately installed verifier, in [`ApprovalEvidenceTest.php`](../tests/Evidence/ApprovalEvidenceTest.php). |
| Stored outcome consistency | [`StoredApprovalIntegrityTest.php`](../tests/Evidence/StoredApprovalIntegrityTest.php) edits a stored status and a stored result: the service will not report, export or retry that request, and the action still ran once. With a signer, a deleted or replaced receipt is refused even when the edited parts agree; without one, an application still reads its own completions. [`StoredApprovalAuditTest.php`](../tests/Bundle/StoredApprovalAuditTest.php) makes the same edits in the demo: the review page, its JSON routes and the three MCP tools answer `integrity_error`, the audit page keeps the request listed and marked untrusted while still checking its passkey signature, no route replaces the original receipt, and the alert changed once. |
| Separate consumer | [`check-consumer.php`](../tests/Package/check-consumer.php) installs core and WebAuthn, then those with MCP, then those with the Doctrine storage, then those with the testing helpers, into a separate Composer project from `src/` (copied, matching `dev-main`, stable defaults), and checks them with only that project's autoloader: no framework bundle installed, WebAuthn options built, the MCP card loaded, and the installed `verify-evidence.php` verifying the [synthetic commit receipt](../tests/fixtures/evidence/commit-v2). It also checks what each package archive ships. A path install is not an install from Packagist. |
| Fresh Symfony application | [`check-symfony-consumer.php`](../tests/Package/check-symfony-consumer.php) copies the public Doctrine example into an independent `App` namespace and installs all seven packages as separate copies, with its own autoloader, Security accounts and database. The installed helpers exercise enrollment, web and MCP publication, other-account refusal, stale content, revoked delegation, retries and independently verified receipts. Each job resolves dependencies for its own PHP version. The application is the publishing example under another namespace, so this shows that the packages work outside the monorepo; it is not a test by an independent developer, and its passkeys are software ones. |
| Approval card | [`card_lifecycle.mjs`](../tests/card_lifecycle.mjs) runs the card's real script in Node against a scripted host with a controlled clock. A reply that arrives after the site recorded an outcome, after another request replaced this one, or after the host stopped or tore down the card changes nothing; an unanswered request times out; a host that declares no `openLinks` or `serverTools` is sent neither request and the card says what it cannot do; status checks back off, pause while hidden, stop after a quarter of an hour and say so; an integrity error is never shown as "nothing changed". It is a state-machine test: layout and colors in a real host are outside it. |
| Review page script | [`approval_recovery.mjs`](../tests/approval_recovery.mjs) runs the review page's [`approval.js`](../src/symfony-bundle/public/approval.js) in Node against scripted responses: a session that expired before the passkey step, a lost, HTML or gateway answer after submission (left unconfirmed), a permission refusal kept apart from an expired session, a session lost during cancellation, and a refusal that reloads the recorded outcome. |
| HTTP, OAuth and Symfony AI | [`http_smoke.py`](../tests/http_smoke.py) starts the application and calls it over TCP with the real SDK, then runs Symfony AI's real `Agent` and `McpToolbox` with scripted model responses. [`oauth_http_smoke.py`](../tests/oauth_http_smoke.py) runs the demo's OAuth flow over real HTTP: it reads the metadata, signs in through a browser session and consents, is refused for a wrong S256 verifier and a reused code, calls the tools through the SDK with the issued token (publication stays pending), rotates the refresh token, and revokes the connection from the account page. Neither makes a protected change without a passkey. |

Core lifecycle tests use a test-only verifier where cryptography is not the subject. WebAuthn, evidence and Symfony integration tests use the production WebAuthn bridge. Synthetic authenticators test signatures and protocol behavior; they do not establish how a physical authenticator or a biometric behaves.

## Browser checks outside CI

Three Python scripts drive Chrome or Chromium through Playwright: two run the demo with virtual authenticators, and one runs the approval card on its own. CI does not run them; [run the checks yourself](#run-the-checks-yourself) gives the commands.

- [`browser_smoke.py`](../tests/browser_smoke.py): a first passkey needs the account's one-time link; an expired sign-in returns to the same request; the review page shows the final public page from the frozen proposal; the approval publishes once, and the request key's retry does not publish again; a draft changed after review stops its request; an answer lost after the passkey response, or replaced by a gateway's error, is reported as unconfirmed. It also runs Symfony AI's `Agent` and `McpToolbox` with a scripted model.
- [`card_smoke.py`](../tests/card_smoke.py): the real approval card inside a scripted MCP Apps host. A QR decoder reads back the phone code the card draws, and the card's text must be readable on the surface it paints, under dark, light and mismatched hosts and hosts that send no styles.
- [`phone_smoke.py`](../tests/phone_smoke.py): behind a stand-in for an HTTPS tunnel, a second browser that never signed in redeems the phone code, reaches nothing but that review, and approves with a copy of the passkey; the agent's `approval_status` then says completed, and the code is refused the second time.

The newest recorded run of all three passed on 10 November 2026, with Python Playwright 1.65.0 and an installed Google Chrome selected with `CHROMIUM_BIN`; the Chrome version was not recorded. The run is recorded in [`7b9c3cfe`](https://github.com/scottfalconer/verified-human-approval/commit/7b9c3cfe79a19f83406c69b7134b62ba4207a74b), which also holds the code it checked. That is earlier code. Two changes to the scripts came later, and neither has a recorded run: `browser_smoke.py` gained its expired sign-in check, and `card_smoke.py` its color checks. `phone_smoke.py` has not changed since.

Two more checks of earlier code drove Chromium by hand through Node Playwright, with scripts that are not in this repository: lost and stopped requests on the review page and in the card, on [`0c3bb115`](https://github.com/scottfalconer/verified-human-approval/commit/0c3bb115341e9ba36d876d3f76da38e947b15db4), and a receipt downloaded and verified offline, on [`4ea04eb1`](https://github.com/scottfalconer/verified-human-approval/commit/4ea04eb1487cc59a77687f8a4753dcc690957f48).

## Real clients and devices

These runs used real clients, with a physical device where the table says so. The last column names where each was first recorded. The three recorded approvals ran on the code of 8 November 2026, and none has been repeated on the current code. The runs of 18 November 2026 record what the card looked like in the Claude iOS app, and whether models send a request key.

| Date (UTC) | Client and connection | Passkey | Result | Recorded in |
| --- | --- | --- | --- | --- |
| 8 November 2026, 16:21 | A Claude Desktop chat, connected to a local demo through `mcp-remote` with the editor's personal token | A Mac's Touch ID, with an iCloud Keychain passkey | Claude asked to publish; the approval card polled `approval_status` every 3 seconds; the passkey approved the exact page 21 seconds later; the site published once, and the card stopped polling. This was the demo's earlier announcement version (tool `publish_announcement`), before it became the alerts site. | [`d63715cf`](https://github.com/scottfalconer/verified-human-approval/commit/d63715cf6277a1e0cf33c3e78f8d8f51bd3d02ac) |
| 8 November 2026, 17:46 | A claude.ai custom connector to the demo on a public HTTPS tunnel, over OAuth with Claude's client ID metadata document | A physical iPhone, from the phone code's scoped session, which never signed in; an iCloud Keychain passkey enrolled in Safari on the Mac | The card called the app-only `approval_handoff` tool through claude.ai, and the phone redeemed the code; the application recorded the approval and issued the alert in the same second, once. The demo then ran its own OAuth server, since replaced by League's bundle. claude.ai did not pass the server's instructions to the model in this run. | [`0dcba657`](https://github.com/scottfalconer/verified-human-approval/commit/0dcba6577aa91d0c928f1f61baa2c284d6fd49ba) |
| 8 November 2026 | Claude Code 2.1.317 with `claude-haiku-5-5`, in the stream-json host mode the desktop app uses, driven by [`claude_code_host.py`](../tests/claude_code_host.py) | Chrome's virtual authenticator: no physical device | The URL-mode elicitation reached the host; after the approval on the review page and an `accept`, the retried call returned `completed`, with one committed effect. | [`56f0bdeb`](https://github.com/scottfalconer/verified-human-approval/commit/56f0bdeb8f7fc3915f59da8c99f728628dbe7c0a) |
| 18 November 2026 | The Claude iOS app on an iPhone, connected to the demo | Not recorded | The approval card's text was unreadable: the light theme's dark text over the app's dark surface. That led to the card's color fix (see [MCP Apps](conformance-review.md#mcp-apps)), which has not been checked in the app. | The [changelog](../CHANGELOG.md), and [`card_smoke.py`](../tests/card_smoke.py), which reproduces those colors |
| 18 November 2026 | Claude Code 2.1.319 in print mode, with a plain system prompt, against the demo's HTTP endpoint, by hand and with [`claude_request_key.py`](../tests/claude_request_key.py) | None: nothing was approved | Asked to publish, Claude left the optional `request_key` out in all 38 new conversations (Opus 5.5, Fable 5.2, Sonnet 5.5 and Haiku 5.5); in a few more, Haiku showed the draft and asked before sending anything. With the earlier description, "Your own id for this request", it sent a key in 24 of 24 (Opus 5.5, Fable 5.2 and Haiku 5.5), often the same one again. Not checked in claude.ai, whose system prompt differs, or with the publishing example's tools. | This page |

## What is not proven

- **The current code on a physical device.** The newest recorded approvals with Touch ID and with an iPhone ran on 8 November 2026, on an earlier version of the demo with a different OAuth server.
- **The card's colors in the Claude iOS app.** No check in the app is recorded. `card_smoke.py` checks the fixed colors in Chromium under scripted hosts, and no run of those checks is recorded either.
- **Hosts other than Claude's.** No run with ChatGPT, VS Code, Cursor or another MCP Apps host is recorded.
- **Physical authenticators in the automated tests.** They use synthetic credentials and Chrome's virtual authenticator.
- **A live model in CI.** `http_smoke.py` gives Symfony AI scripted model responses. The [live-model example](../examples/README.md#live-model) needs a model API key, and the two checks with real Claude models above are not part of CI.
- **The Python browser checks on the current code.** CI runs the Editorial Desk's browser check, not these three. Their newest recorded pass, on 10 November 2026, is on earlier code, and the checks added since have no recorded run (see [browser checks outside CI](#browser-checks-outside-ci)).
- **An install from Packagist.** The packages are not on Packagist; the consumer jobs install them from this repository's `src/` directories.
- **Security.** This is a tested implementation, not an independent security audit.

## Run the checks yourself

From the repository root, after `composer install`:

```bash
composer test
composer analyse
composer cs
PHP_BIN=php python3 tests/http_smoke.py
PHP_BIN=php python3 tests/oauth_http_smoke.py
node --test tests/card_lifecycle.mjs tests/approval_recovery.mjs
```

PHPUnit reports the PostgreSQL and MySQL test classes as skipped unless `VERIFIED_HUMAN_APPROVAL_POSTGRES_URL` or `VERIFIED_HUMAN_APPROVAL_MYSQL_URL` names a database server; CI runs them. The [workflow](../.github/workflows/tests.yml) shows the form of each URL and the command, `vendor/bin/phpunit --testsuite contract,doctrine,example --filter Postgres` or `--filter Mysql`. Two SQLite cases are also skipped: in SQLite's rollback journal, the race they test cannot happen.

The Python HTTP checks use the standard library. The Node checks need Node.js 22 or later and install nothing. The optional browser checks need Python Playwright and Chrome or Chromium, and the card check also needs PHP's `gd` extension to decode its QR code. The newest recorded run used Playwright 1.65.0 with an installed Google Chrome, so these commands install that version in a virtual environment and name the browser with `CHROMIUM_BIN`:

```bash
python3 -m venv var/browser-checks
. var/browser-checks/bin/activate
python3 -m pip install playwright==1.65.0
export CHROMIUM_BIN=/path/to/chrome
PHP_BIN=php python3 tests/browser_smoke.py
PHP_BIN=php python3 tests/card_smoke.py
PHP_BIN=php python3 tests/phone_smoke.py
```

On macOS, with Google Chrome in Applications, that line is `export CHROMIUM_BIN='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'`. Without `CHROMIUM_BIN`, `browser_smoke.py` and `phone_smoke.py` use a `chromium` on the path if there is one, and otherwise, like `card_smoke.py`, Playwright's own Chromium, which `python3 -m playwright install chromium` downloads. No run with Playwright 1.65.0's own Chromium is recorded.

Two optional checks use a real model through the Claude Code CLI. [`claude_request_key.py`](../tests/claude_request_key.py) checks that an agent leaves the request key out, with three short model calls by default. [`claude_code_host.py`](../tests/claude_code_host.py) runs the URL-mode elicitation end to end, with one model call, and also needs Python Playwright, and `CHROMIUM_BIN` or a `chromium` on the path:

```bash
CLAUDE_BIN=claude PHP_BIN=php python3 tests/claude_request_key.py
CLAUDE_BIN=claude CHROMIUM_BIN=/path/to/chrome PHP_BIN=php python3 tests/claude_code_host.py
```

For a check on a physical device, follow the [demo](../demo/README.md) with the device and client you intend to support, and record the commit, origin, browser, client and device beside the outcome.

[Verify a receipt](evidence.md) is a smaller check: verify an original record, change a copy, and see verification fail.
