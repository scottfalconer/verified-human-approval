# Verified Human Approval

**Cryptographic evidence of human approval, bound to the exact action.**

Let an AI agent request an action in your Symfony application. When your application's rule requires approval, it freezes the proposal and asks the person to review it on your site. The person approves with a passkey. The application checks their current permissions, applies that stored proposal once, and signs a receipt as the action commits.

The demo is a council alerts site. An agent can request publication of an evacuation alert, but an editor must review the exact public page and approve it before it goes live. The same rule applies to the site's own **Issue alert** button.

**[Run the demo](demo/README.md)** · **[Integrate into Symfony](src/symfony-bundle/docs/index.md)** · **[Verify a receipt](docs/evidence.md)**

## Current scope

This is a development preview. The packages are not on Packagist yet; the integration guide shows how to install them from this repository.

- PHP 8.3+ and Symfony 7.4 or 8. Symfony 8 requires PHP 8.4+.
- The supplied store supports **SQLite**. Approval records, credentials, permission checks and the business change must use the same PDO connection and transaction.
- Your application supplies its accounts, current permissions, trusted passkey enrollment and protected action. The bundle supplies the approval flow and review pages.
- Configure `receipt_signer` to sign receipts when actions commit. The demo and integration example include this configuration.
- Passkeys use WebAuthn. The device can use Touch ID, Face ID, a PIN or another supported local method. The server receives a signature, not biometric data, and does not claim which local method was used.
- The demo's account screens, administrator audit page and OAuth server are reference code, not features installed by the bundle.

## Try it locally

You need PHP, Composer and a browser with passkey support. See the [demo prerequisites](demo/README.md) for PHP extensions and platform requirements.

```bash
git clone https://github.com/scottfalconer/verified-human-approval.git
cd verified-human-approval
composer install
php demo/bin/setup.php
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php
```

Keep the setup output private. It contains the demo accounts' passwords, personal agent tokens and first-passkey links.

With the server running, continue at [Add the editor's passkey](demo/README.md#2-add-the-editors-passkey). Use the **editor** account: its rule requires passkey approval. The walkthrough uses the included PHP MCP client, so no model API key or agent application is needed.

You should see four things:

1. `publish_alert` returns `pending` with a review link. The alert is still unpublished.
2. The review page shows the exact proposed public page and statement.
3. Approving with the editor's passkey publishes the alert and records a signed receipt.
4. Checking status or retrying with the same request key returns `completed` without publishing again.

The guide also covers [agent clients and phone approval](demo/README.md#use-an-agent-client). The [Symfony AI example](examples/README.md#symfony-ai-example) uses a real `Agent` and `McpToolbox`; run it with `--scripted` to use scripted model responses without a model API key.

## How it works

1. **Prepare.** A normal MCP tool asks the approval service to run an action. The application checks the caller and rule, then stores the exact content, target, source revision, statement and expiry.
2. **Review.** The client receives a review link. Compatible hosts also show an MCP App card or a URL prompt. The person reviews the frozen proposal on the application's own origin.
3. **Approve and execute.** WebAuthn verifies a challenge bound to the proposal. The application rechecks the account, delegation, rule and source state, then applies the stored effect inside one database transaction.
4. **Record.** The configured receipt signer signs the execution receipt within that transaction. A signing failure rolls back the change.
5. **Report.** The client reads the recorded outcome. It never receives a reusable approval token or submits replacement content to the completion endpoint.

A client clicking **Accept** is not human approval. If the application permits a role to act directly, the record explicitly says approval was `not_required`; it does not claim a passkey was used.

The application must route every supported write path through the guarded action service. The bundle cannot protect an unrelated raw SQL write. See the [architecture](docs/overview.md) and [security model](docs/security-model.md) for the boundary.

## What it refuses

| Attempt | Result |
| --- | --- |
| A staff member's agent asks to issue the alert | Pending until that person approves with their passkey. The agent can't skip it |
| A read-only colleague's agent asks | Refused: the rule doesn't let that role issue alerts |
| The administrator changes the rule while a request waits | That request can no longer be approved |
| The draft is edited after you reviewed it | Not issued: the proposal no longer matches |
| The alerts page layout changes after review | Not issued: the layout is part of what you approved |
| A signed approval is replayed for another request | Rejected: the signature covers one proposal's digest |
| A client answers "accept" without your passkey | Still pending: accept only means the page was opened |
| The same approval is repeated | The same receipt, and the alert issued once |
| The rule lets a role act directly | It runs, and every page says it ran without a passkey |
| A phone code is used twice, or after two minutes | Refused: each code works once |
| Someone with only the password tries to add a passkey | Refused: the first needs a one-time link, any other an existing passkey |
| A passkey row is added or changed directly in the database | It approves nothing: no signed enrollment record vouches for it |
| A record is edited and its receipt deleted to hide it | The audit page flags it, and no evidence is signed for that record |
| A passkey is revoked just after it confirmed adding another | The confirmation ends with it: no new passkey or passkey link |
| An approval's record is edited in the database afterwards | The audit page's checks fail: the receipt was signed at commit, and the passkey signed the original |
| The site's record changes after the person kept their receipt | Their copy no longer matches it, checked with only the site's published key |
| An administrator's password alone tries to switch verification off | Pending: the rule change waits for the administrator's passkey |
| Another account, or a read-only agent token | No access to the request, and no rights from a passkey |

Each row is a test in [tests](tests).

## Check the original record later

A completed approval has two checks:

| Evidence | What it establishes |
| --- | --- |
| The passkey assertion | The trusted credential approved the exact stored proposal. |
| The application's receipt | The application signed this proposal, proof and execution report as the action committed. |

The verifier uses public keys trusted separately from the downloaded record. Changing a signed field afterward makes verification fail. Deleting the original receipt does not cause the exporter to create a replacement.

The person keeps their own copy. Once the action runs, the review page offers **Download your receipt**: the exact request, their passkey's signature on it and what ran, signed by the site as it ran. The agent's result carries the same signed receipt, and the site publishes the key that checks it at `/approval/receipt-key.json`. If the site's record of that request were changed or deleted later, the person's copy would show it.

This checks the **historical approval and execution record**. It does not monitor later edits to the live content, prove what the person understood, or independently witness the database change. Follow the [receipt walkthrough](docs/evidence.md) to verify a real demo receipt and then see an edited copy fail.

## Add it to your Symfony application

The [integration guide](src/symfony-bundle/docs/index.md) is the canonical installation path. It includes a complete first action: shared storage, host identity and permission adapters, service configuration, a guarded mutation, an MCP tool and a review page.

| Package | Purpose |
| --- | --- |
| [`verified-human-approval/core`](src/core/README.md) | Proposals, rules, current authority, transaction coordination and signed receipts. |
| [`verified-human-approval/webauthn`](src/webauthn/README.md) | Passkey registration and assertion validation, trusted credential storage and the offline verifier. |
| [`verified-human-approval/mcp`](src/mcp/README.md) | Approval card, optional URL prompt and retries bound to the original caller and arguments. |
| [`verified-human-approval/symfony-bundle`](src/symfony-bundle/README.md) | Symfony services, review routes, CSRF, rate limits, status tool and scoped phone handoff. |

The engine is independent of Symfony. The supported Symfony example uses a single SQLite transaction; other databases or external effects need their own implementation and verification.

## Built with Symfony and MCP

The project uses the official PHP MCP SDK, Symfony MCP Bundle, Symfony AI and the WebAuthn Framework. Protected actions remain ordinary MCP tools. MCP Apps show the approval card, URL-mode elicitation provides an optional native prompt, and every client receives a plain review link. The demo includes OAuth for remote clients.

The [examples guide](examples/README.md) explains what each example runs. The [conformance review](docs/conformance-review.md) records protocol choices and interoperability limits.

## Verification

```bash
composer test
composer analyse
composer cs
PHP_BIN=php python3 tests/http_smoke.py
```

The tests cover real WebAuthn signatures, Symfony and MCP integration, concurrent completion, transaction rollback and receipt tampering. CI covers PHP 8.3 and 8.4, lowest supported dependencies and Symfony 8. Browser tests, physical devices and hosted clients are reported separately in [verification status](docs/verification.md).

## Further reading

- [Demo and developer examples](demo/README.md)
- [Architecture and request lifecycle](docs/overview.md)
- [Integration contracts](docs/integration-contract.md)
- [Security model](docs/security-model.md)
- [Reporting a vulnerability](SECURITY.md)
- [Receipt verification](docs/evidence.md)

MIT licensed. See [LICENSE](LICENSE).
