# Verification results

Build verification completed on November 8, 2026, using PHP 8.4.24. These results describe the implemented Symfony/PHP slice, not a production certification or physical-device demonstration.

## Update: approval card, native prompt and final-page review (November 8, 2026)

Run on macOS with PHP 8.3.31. The sections after this one keep the first build's record.

| Check | Result |
| --- | --- |
| PHPUnit, `composer test` | 150 tests, 743 assertions, all passing. New tests cover keyless retry reuse, the URL prompt and its bound retry, the card, the final-page preview, and the layout binding |
| PHPStan, `composer analyse` | No errors, now including `packages/mcp` |
| `tests/http_smoke.py` | Passed |
| `tests/browser_smoke.py`, with real Chrome and a CDP virtual authenticator | Passed. It covers enrollment and approval through the real page scripts, a 404 before approval, a live page identical to the approved preview, and the stale-draft refusal |
| `tests/claude_code_host.py`, with Claude Code 2.1.317 and claude-haiku-5-5 | Passed. The URL-mode elicitation reached the host; after the passkey approval and "accept", the retried call returned "completed", with one committed effect |
| GitHub Actions: PHP 8.3, PHP 8.4 and the Drupal job | Passed |
| A Claude Desktop chat, through a probe server using the same card mechanism | The card rendered, opened the review page through `ui/open-link`, and switched to approved after approval on the site |

Not yet run: the demo's own card in Claude Desktop with a physical Touch ID approval, and any hosted or phone connection.

## Automated PHP checks

Command: `php vendor/bin/phpunit --log-junit var/test-results.xml`

**132 tests, 590 assertions; no failures, errors or skipped tests.** The final recorded run took 17.373 seconds in this environment.

| Suite | Tests | Assertions | What it exercises |
| --- | ---: | ---: | --- |
| Core | 60 | 275 | Immutable binding, source/policy/identity changes, authorization, guarded mutation, rollback, process races, canonicalization and a second action shape. |
| WebAuthn | 43 | 69 | Actual upstream registration/assertion validation using real synthetic ES256 signatures; wrong owner, tenant, origin, RP, challenge, signature, UV, counters and revocation. |
| Symfony | 15 | 172 | Actual Symfony kernel, firewalls, SDK HTTP transport, MCP Bundle, sessions, CSRF, review, enrollment, completion, current scope, policy form, limits and evidence route. |
| Evidence | 14 | 74 | Actual approval + SQLite + ES256 + Ed25519 receipts, tampering and separate verifier process. |

The core-only suite uses a clearly marked test verifier to isolate lifecycle tests. The WebAuthn, evidence and Symfony integration paths use the production WebAuthn bridge and upstream cryptographic validation. No production fake verifier is supplied.

### Separate-process contention

Fresh PHP processes with separate PDO connections share one SQLite file:

| Scenario | Verified result |
| --- | --- |
| Four concurrent completions of the same ceremony | One committed effect, one credential update and one execution record. Every caller receives the completed result. |
| Two approved proposals for the same changing source revision | One completes; the other is invalidated. |
| Concurrent requests sharing an idempotency key | One frozen proposal. |

### Meaningful refusal and recovery checks

The tests check database state, credential counters and execution rows as well as responses. They cover unused-proof substitution separately from replay; statement/content/revision/dependency changes; revoked users, scopes and credentials; expiry/cancellation; malformed assertions; wrong-account reads; unsupported mutation paths; post-write and postcondition failure; and execution-record insertion failure.

Unexpected nested object changes during preconditions, execution or postconditions are rejected. A separate `campaign.set_closing_date` fixture exercises a different action/effect shape through the same core.

Evidence tests also establish that an application cannot make an old user assertion approve changed wording simply by signing a new application receipt. A signer failure and subsequent export retry leave the action's effect count unchanged.

## Real HTTP and Symfony AI check

Command: `PHP_BIN=php python3 tests/http_smoke.py`

**Passed.** This launches the actual PHP HTTP application and uses the official SDK over TCP, not an HTTP transport mock. It discovers all three tools, reads content, requests publication, receives a review URL, reads pending status and verifies request-key reuse.

It also runs Symfony AI's real `Agent` and `McpToolbox` with **scripted model responses** against that same HTTP endpoint. The protected mutation count remains zero without a human proof. This is an integration check, not an LLM benchmark; live model calls: **zero**.

The Symfony PHPUnit integration tests separately exercise completion through the actual kernel, authenticated browser session, CSRF and WebAuthn bridge, then retrieve the final MCP result.

## Package and static checks

- Composer strict validation: passed.
- PHPStan level 5 across production packages, example services and verifier: passed with no errors.
- JavaScript syntax check for the browser asset: passed.
- Clean source-archive install with `composer install --no-dev`, followed by the real HTTP/Symfony AI smoke test in the extracted directory: passed. Runtime state, generated credentials, caches and vendor files were excluded from the source archive.
- Composer reported no known security advisories during this dependency installation. This is not a security audit.
- Locked primary dependencies: Symfony 7.4 components, `mcp/sdk` 0.9.0, `symfony/mcp-bundle` 1.0.0-BETA1, Symfony AI 1.0.0-BETA1 and `web-auth/webauthn-lib` 5.3.12.

The supplied CI workflow targets PHP 8.3 and 8.4. It has not been executed on GitHub; the observed local runtime is PHP 8.4.24.

## Browser, device and client matrix

| Surface | Actual status |
| --- | --- |
| PHP MCP SDK over real HTTP | Verified. |
| Symfony AI Agent/McpToolbox with a scripted platform | Verified. |
| Symfony browser-session routes, CSRF and synthetic passkey proof | Verified through real kernel integration tests. |
| Native browser JavaScript with Chromium virtual authenticator | Test script included; execution blocked before browser launch by the environment's local-socket/process restrictions. No UI acceptance pass claimed. |
| Mac Touch ID / iPhone Face ID | Not tested. |
| Claude Code / Claude web / Cowork / ChatGPT.com | Not connected or tested. |
| Real external model API | Not called. |
| Drupal integration | Implemented in the subsequent Drupal build; separate installed-site and HTTP results are in [drupal-verification.md](drupal-verification.md). The 132-test counts above remain the original shared/Symfony suite. |

`tests/browser_smoke.py` provides a browser acceptance path for a machine where Chromium can run. It creates a temporary fictional account/application, registers through the real native WebAuthn API using a CDP virtual authenticator, attempts publication and tests stale state. Even a successful virtual-authenticator run would not establish physical biometric behavior.

## Supported guarantee and remaining integration work

The implementation enforces one local atomic action under current authority and an exact stored approval. The supplied store supports SQLite. Application effects outside that transaction, alternate unguarded write paths, remote MCP calls, real OAuth deployment, credential recovery, other database adapters and physical client/device interoperability need their own integration and review.

The next useful validation is a real user enrolling a passkey on a stable application origin, requesting the action from a selected agent client and completing approval on their own device. That validates user experience and client interoperability beyond the server guarantees tested here.
