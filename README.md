# Verified Human Approval

[![Tests](https://github.com/scottfalconer/verified-human-approval/actions/workflows/tests.yml/badge.svg)](https://github.com/scottfalconer/verified-human-approval/actions/workflows/tests.yml)

**A Symfony bundle that requires a human's passkey before a high-risk MCP tool runs.**

Allowing an AI agent to use an application is not the same as a person approving a specific change. With this bundle, an agent requests an action through an ordinary MCP tool. When your application's rule requires approval, the application freezes the exact proposal and asks the person to review it on your site. The person approves with a passkey. The application checks their current permissions, applies that stored proposal once, and signs a receipt as the action commits: cryptographic evidence of human approval, bound to the exact action.

![The demo's review page. Left: an agent's request to issue the public alert "Evacuation order: Riverside Road area", the statement the editor approves, and the buttons "Approve with your passkey" and "Cancel request". Right: the frozen preview of the public alerts page that goes live on approval, marked "Frozen preview, revision 1".](docs/images/review-page.png)

The demo is a council alerts site. An agent can request publication of an evacuation alert, but an editor must review the exact public page, as above, and approve it before it goes live. The same rule applies to the site's own **Issue alert** button.

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon/projects/verified-human-approval).

**[Run the demo](demo/README.md)** · **[Integrate into Symfony](src/symfony-bundle/docs/index.md)** · **[Test your integration](src/symfony-bundle/docs/testing.md)** · **[Verify a receipt](docs/evidence.md)**

## Try it locally

You need PHP, Composer and a browser with passkey support. See the [demo prerequisites](demo/README.md#1-start-the-application) for PHP extensions and platform requirements.

```bash
git clone https://github.com/scottfalconer/verified-human-approval.git
cd verified-human-approval
composer install
php demo/bin/setup.php
```

Setup shows each demo account's password, personal token and first-passkey link once. Save them privately before you start the server: its log scrolls them off the screen. If you lose them, stop the server, run `php demo/bin/setup.php --reset` for a fresh demo with new ones, and start the server again.

Then start the server. It keeps this terminal:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php
```

The demo answers only at the address it was set up for. If port 8080 is taken, PHP stops with `Failed to listen on localhost:8080 (reason: Address already in use)`. Then [set the demo up for another port](demo/README.md#if-port-8080-is-taken).

With the server running, continue at [Add the editor's passkey](demo/README.md#2-add-the-editors-passkey). Use the **editor** account: its rule requires passkey approval. The walkthrough runs the included PHP MCP client in a second terminal, so no model API key or agent application is needed.

You should see four things:

1. `publish_alert` returns `pending` with a review link. The alert is still unpublished.
2. The review page shows the exact proposed public page and statement.
3. Approving with the editor's passkey publishes the alert and records a signed receipt.
4. Checking status, or retrying with the same request key, returns `completed` without publishing again. The retry says it is the earlier request's recorded outcome and that nothing ran now.

The guide also covers [agent clients and phone approval](demo/README.md#use-an-agent-client). The [Symfony AI example](examples/README.md#symfony-ai-example) uses a real `Agent` and `McpToolbox`; run it with `--scripted` to use scripted model responses without a model API key.

## How it works

1. **Prepare.** A normal MCP tool asks the approval service to run an action. The application checks the caller and rule, then stores the exact content, target, source revision, statement and expiry.
2. **Review.** The client receives a review link. Compatible hosts also show an MCP App card or a URL prompt. The person reviews the frozen proposal on the application's own origin.
3. **Approve and execute.** WebAuthn verifies a challenge bound to the proposal. The application rechecks the account, delegation, rule and source state, then applies the stored effect inside one database transaction.
4. **Record.** The configured receipt signer signs the execution receipt within that transaction. A signing failure, or a signing key the application's trusted keys do not cover at that moment, rolls back the change.
5. **Report.** The client reads the recorded outcome. It never receives a reusable approval token or submits replacement content to the completion endpoint.

A client clicking **Accept** is not human approval. If the application permits a role to act directly, the record explicitly says approval was `not_required`; it does not claim a passkey was used.

The application must route every supported write path through the guarded action service. The bundle cannot protect an unrelated raw SQL write. See the [architecture](docs/overview.md) and [security model](docs/security-model.md) for the boundary.

## Built with Symfony AI and the PHP MCP SDK

The [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk) and the [MCP Bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html) from [Symfony AI](https://ai.symfony.com) let a Symfony application put a person's passkey in front of an MCP tool while the tool stays an ordinary tool. A client needs nothing written for this project: the approval travels in a standard tool result, an MCP App and a URL-mode elicitation, and no client holds anything that can approve.

- **An ordinary tool.** The demo's `publish_alert` ([`DemoTools`](demo/src/Mcp/DemoTools.php)) is a service method with the SDK's `#[McpTool]` attribute, which the MCP Bundle serves at `/mcp`. After a scope check it makes one call, `ApprovalFlow::respond()`, around `ApprovalService::request()`.
- **A structured result.** The tool declares `outputSchema: ApprovalFlow::OUTPUT_SCHEMA` and returns a `CallToolResult` whose `structuredContent` holds the status, the review link and, once the action has run, the signed receipt. Only `completed` means that the action ran.
- **The approval card.** `meta: ApprovalCard::TOOL_META` links the tool to an MCP App resource, which the bundle serves through the MCP Bundle's `#[AsMcpApp]` attribute (`ApprovalCardApp`). The card opens the review page and shows the outcome the application recorded. It never runs the passkey ceremony.
- **A prompt that does not hold the call open.** For a client on protocol revision 2026-07-28 or later that declares URL mode and that the configuration names (the demo names `claude-code`), `UrlPrompt` ends the call with the SDK's `InputRequiredResult`: a URL-mode `ElicitRequest` for the review page, and request state signed by `RequestContext::mintRequestState()`. When the client retries, the tool checks that state and reads only the stored outcome.
- **Authorization.** The host's Symfony Security firewall validates the bearer token and gives the SDK an `AccessToken`. The demo's tools read it from their `RequestContext` to check scopes; the bundle's `approval_status` tool, and its `approval_handoff` tool while the phone handoff is on, ask the host's `ActorProvider` instead, which in the demo reads the same token. The bundle's `McpScopeChallengeSubscriber`, which the demo registers, applies the SDK's `ScopePolicy`, so a token without a scope a tool needs gets HTTP 403 with `WWW-Authenticate: Bearer error="insufficient_scope"`.

Symfony carries the application side. The bundle is an `AbstractBundle` with validated configuration. It relies on the host's SecurityBundle firewall and login, renders the review pages with Twig, checks CSRF tokens, limits each person's approval attempts and agent requests with RateLimiter and, while the phone handoff is on, makes each phone code single-use with Lock. Its console commands print the audit log's head, record a key rotation and, on Doctrine, install the tables. The [Doctrine package](src/doctrine/README.md) keeps approvals on the application's DBAL connection, in the same transaction as its entities.

Passkey registration and verification use [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-lib), from the WebAuthn Framework. Receipts are Ed25519 signatures, made by the supplied `SodiumEvidenceSigner` and checked against the trusted keys, both with PHP's sodium extension. The demo's OAuth runs on `league/oauth2-server-bundle` and `omouren/mcp-oauth-bundle`.

Symfony AI's agent is used as a client. [`examples/agent.php`](examples/agent.php) runs Symfony AI's `Agent` with an `McpToolbox` connected to the demo through the SDK's client, and `--scripted` replaces the model with `MockPlatformFactory`, so no API key is needed. The packages and the demo server do not depend on Symfony AI's agent or platform packages; only the repository root requires them, for the examples.

The [examples guide](examples/README.md) explains what each example runs, and the [publishing example](examples/doctrine-publishing/README.md) is a complete application on Doctrine to copy from. The [MCP conformance](docs/conformance-review.md) page records protocol choices and interoperability limits.

## What it refuses

| Attempt | Result |
| --- | --- |
| A staff member's agent asks to issue the alert | Pending until that person approves with their passkey. The agent can't skip it |
| A read-only colleague's agent asks | Refused: a token without the publishing scope is stopped before the tool runs, and with every scope the rule still refuses that role |
| The administrator changes the rule while a request waits | That request can no longer be approved |
| The draft is edited after you reviewed it | Not issued: the proposal no longer matches |
| The alerts page layout changes after review | Not issued: the layout is part of what you approved |
| A signed approval is replayed for another request | Rejected: the signature covers one proposal's digest |
| A client answers "accept" without your passkey | Still pending: accepting only means the person agreed to open the page |
| The same approval is sent twice, or the agent repeats its request key | The alert is issued once. A key repeated after it ran gets the recorded outcome, which says it is the earlier request's and that nothing ran now. Once the alert's text changes, that key is refused with `idempotency_conflict` |
| The rule lets a role act directly | It runs, and the agent's result and the review page say it ran without a passkey |
| A phone code is used twice, or after two minutes | Refused: each code works once, for two minutes, and the phone session it opens lasts ten minutes |
| Someone with only the password tries to add a passkey | Refused: the first passkey takes a one-time link; another takes a fresh confirmation with an existing passkey, or a new link that an administrator confirms with their own passkey to issue |
| A passkey row is added, or its public key replaced, directly in the database | It approves nothing: no signed enrollment record vouches for it |
| A passkey is revoked just after it confirmed adding another | The confirmation ends with it: no new passkey or passkey link |
| A passkey's revocation is deleted or rewritten in the database | It stays revoked, unless the log is cut back to before it, or rewritten from an earlier key rotation onward with the retired key. Only a checkpoint of the log's head, kept elsewhere, shows either |
| A completed approval's stored proposal or outcome is edited, even by one character | Its owner's review page and the agent's tools say the outcome cannot be confirmed, and the audit page flags the record |
| An approval's proposal is rewritten in the database, with its digest recomputed to match | Its owner's review page and the agent's tools say the outcome cannot be confirmed, and the audit page's checks fail: the receipt was signed at commit, and the passkey signed the original |
| A record is edited and its receipt deleted to hide it | The audit page flags it, and no evidence is signed for that record |
| The site's record changes after the person kept their receipt | Their copy no longer matches it, checked with only the site's published key |
| An administrator's password alone tries to switch verification off | Pending: the rule change waits for the administrator's passkey |
| Another account, or the person's own personal token | Another account cannot open the request, and the token cannot approve it, add a passkey or change the rule |

The tests in [tests](tests) cover each row.

## Check the original record later

A completed approval has two checks:

| Evidence | What it establishes |
| --- | --- |
| The passkey assertion | The trusted credential approved the exact stored proposal. |
| The application's receipt | The application signed this proposal, proof and execution report as the action committed. |

The verifier uses public keys trusted separately from the downloaded record. Changing a signed field afterward, or adding a field nothing signs, makes verification fail. Deleting the original receipt does not cause the exporter to create a replacement.

The person keeps their own copy. Once the action runs, the review page offers **Download your receipt**: the exact request, their passkey's signature on it and what ran, signed by the site as it ran. The agent's result carries the same signed receipt, and the site publishes the keys that check it at `/approval/receipt-keys.json`, including any key it [rotated](docs/evidence.md#rotate-the-signing-key) away from. If the site's record of that request were changed or deleted later, the person's copy would show it.

This checks the **historical approval and execution record**. It does not monitor later edits to the live content, prove what the person understood, or independently witness the database change. Follow [Verify a receipt](docs/evidence.md) to check a real demo receipt and then see an edited copy fail.

## Current scope

This is a development preview. The packages are not on Packagist yet; the integration guide shows how to install them from this repository. [Versions and releases](docs/releasing.md) says what a version promises, and [the changelog](CHANGELOG.md) what is in the first one.

- PHP 8.3+ and Symfony 7.4 or 8. Symfony 8 requires PHP 8.4+.
- Storage: the PDO store supports **SQLite**; the Doctrine package supports **SQLite, PostgreSQL, MySQL 8.0.17+ and MariaDB** on your DBAL connection, with your entities in the same transaction. Either way, approval records, credentials, permission checks and the business change must use the same connection and transaction.
- Your application supplies its accounts, current permissions, trusted passkey enrollment and protected action. The bundle supplies the approval flow and review pages.
- Configure `receipt_signer` to sign receipts when actions commit. The demo and integration example include this configuration.
- Passkeys use WebAuthn. The device can use Touch ID, Face ID, a PIN or another supported local method. The server receives a signature, not biometric data, and does not claim which local method was used.
- The demo's account screens, administrator audit page and maintained-package OAuth integration are reference application code. The bundle uses the host's authentication and current permissions; it does not provide an OAuth issuer. See the [host contract](src/symfony-bundle/docs/authentication.md).
- The root `composer.json` installs `symfony/mcp-bundle` 1.0.0-BETA1 under the alias `0.13.99`, because the demo's OAuth bundle, `omouren/mcp-oauth-bundle` 1.2, still requires `^0.13` ([Authentication ownership](demo/authentication.md) explains it). The alias applies only to this repository's root project: the bundle requires `symfony/mcp-bundle ^1.0`, and an application that installs it does not need the alias unless it also installs that OAuth bundle.

## Add it to your Symfony application

The [integration guide](src/symfony-bundle/docs/index.md) is the canonical installation path. It includes a complete first action: shared storage, host identity and permission adapters, service configuration, a guarded mutation, an MCP tool and a review page.

| Package | Purpose |
| --- | --- |
| [`verified-human-approval/core`](src/core/README.md) | Proposals, rules, current authority, transaction coordination and signed receipts. |
| [`verified-human-approval/webauthn`](src/webauthn/README.md) | Passkey registration and assertion validation, trusted credential storage and the offline verifier. |
| [`verified-human-approval/mcp`](src/mcp/README.md) | Approval card, optional URL prompt and retries bound to the original caller and arguments. |
| [`verified-human-approval/symfony-bundle`](src/symfony-bundle/README.md) | Symfony services, review routes, CSRF, rate limits on approval attempts and agent requests, status tool and a scoped phone handoff that can be turned off. |
| [`verified-human-approval/doctrine`](src/doctrine/README.md) | The approval store, signed audit log and passkey storage on a Doctrine DBAL connection, the ORM schema listener, an entity action base and a flush check for protected entities. |
| [`verified-human-approval/store-contracts`](src/store-contracts/README.md) | The storage contract tests, with the fixture application and process races, to check an adapter of your own. |
| [`verified-human-approval/testing`](src/testing/README.md) | Development helpers for host integration tests: a software authenticator, a driver for the passkey panel a page renders, and a PSR-18 client that sends requests through your kernel in process. |

The engine is independent of Symfony. The worked Symfony example uses a single SQLite transaction; the Doctrine storage passes the same contract on SQLite, PostgreSQL, MySQL and MariaDB. External effects, such as an email or another service's API, are outside the transaction and need their own design.

On Doctrine ORM, start from the [publishing example](examples/doctrine-publishing/README.md): a runnable newsroom where publishing, editing live content, archiving (also a batch, under one approval) and deleting each wait for the person's passkey, from the web and from an agent alike, with a flush check that refuses any other change to what visitors see and a public page that marks approved live text. Its tests cover SQLite, PostgreSQL, MySQL and MariaDB. The flush check does not catch DQL bulk updates and deletes or raw SQL.

## Verification

```bash
composer test
composer analyse
composer cs
PHP_BIN=php python3 tests/http_smoke.py
```

`composer test` runs the PHPUnit suite, which takes about three minutes; the script turns off Composer's process timeout for it. `composer analyse` runs PHPStan at level 8 and `composer cs` checks the coding standard. `tests/http_smoke.py` starts the demo on a real HTTP server, drives it with the PHP MCP client and the scripted Symfony AI agent, and checks that nothing is published without approval.

Expect PHPUnit to report skipped tests. Without the server variables below, the PostgreSQL and MySQL variants of the storage contract, schema, transaction, ORM, passkey revocation and publishing-example tests skip. Two SQLite audit-log cases also skip, because on SQLite's rollback journal the host's read keeps the other writer from committing at all. `composer test -- --display-skipped` lists each one with its reason. CI runs the server variants with `--fail-on-skipped`, so there a skipped test fails the job. To run them yourself, give the tests a server account that can create and drop databases; each test creates its own throwaway database:

```bash
VERIFIED_HUMAN_APPROVAL_POSTGRES_URL='pdo-pgsql://postgres:postgres@127.0.0.1:5432/postgres' \
  vendor/bin/phpunit --testsuite contract,doctrine,example --filter Postgres --fail-on-skipped
VERIFIED_HUMAN_APPROVAL_MYSQL_URL='pdo-mysql://root:root@127.0.0.1:3306/mysql?charset=utf8mb4' \
  vendor/bin/phpunit --testsuite contract,doctrine,example --filter Mysql --fail-on-skipped
```

The tests cover real WebAuthn signatures, Symfony and MCP integration, concurrent completion, transaction rollback and receipt tampering. CI ([`tests.yml`](.github/workflows/tests.yml)) runs the suite on PHP 8.3, 8.4 and 8.5 with the locked dependencies, where any deprecation fails the run, and on the lowest allowed dependencies and on Symfony 8, where deprecations are reported without failing it. It runs the server variants on PostgreSQL 14 and 17, MySQL 8.0 and 8.4 and MariaDB 11.4, and installs the packages into separate Composer projects, among them [Editorial Desk](tests/EditorialDesk/README.md), an independent Symfony application. Browser tests, physical devices and hosted clients are reported separately in [verification status](docs/verification.md). Approvals with a passkey on a physical device are recorded in Claude Desktop with Touch ID and in claude.ai with an iPhone on 8 November 2026, and in the Claude iOS app with Face ID on 18 November 2026; none of them ran on the current code.

## Further reading

- [Demo and developer examples](demo/README.md)
- [Architecture and request lifecycle](docs/overview.md)
- [Integration contracts](docs/integration-contract.md)
- [Security model](docs/security-model.md)
- [Reporting a vulnerability](SECURITY.md)
- [Verify a receipt](docs/evidence.md)
- [Contributing](CONTRIBUTING.md)

MIT licensed: the packages, the demo, the examples and the test applications. See [LICENSE](LICENSE).
