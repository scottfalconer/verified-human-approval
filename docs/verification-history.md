# Verification history

This is an archive of run notes recorded on November 8, 2026. Counts, dependency versions, client names and statements such as "not tested" apply to the build described in each section. Later sections were added to the top, so older statements can be superseded by a newer entry. The original first-build commit was not recorded.

For the current baseline and commands, see [verification status](verification.md).

---

# Verification results

Build verification completed on November 8, 2026, using PHP 8.4.24. These results describe the implemented Symfony/PHP slice, not a production certification or physical-device demonstration.

## Update: maintainer review fixes (November 8, 2026, evening)

A maintainer-level review found two password-only ways around verification, receipts signed only at download, and no administrator view. All of it is fixed, with tests:

| Check | Result |
| --- | --- |
| PHPUnit, `composer test` | 181 tests, 1,015 assertions, all passing. New tests cover:<ul><li>passkey links and confirmation;</li><li>a database-inserted or swapped passkey being refused;</li><li>revocation;</li><li>rule changes needing the administrator's passkey;</li><li>receipts signed at commit catching later edits;</li><li>the audit page catching edited records;</li><li>the signed audit log's chain;</li><li>request keys refusing changed content;</li><li>the phone code's Continue step, scope check and budget;</li><li>the SSRF guard.</li></ul> |
| PHPStan | No errors |
| `tests/browser_smoke.py`, `tests/card_smoke.py`, `tests/phone_smoke.py`, `tests/http_smoke.py` | Passed, with the passkey links and the phone code's Continue step |

`tests/claude_code_host.py` was updated for passkey links but not re-run, since it makes a model call. Demos set up before this change need `setup.php --reset` (or `bin/public reset`).

## Update: claude.ai and a physical phone (November 8, 2026, 17:46 UTC)

Run by Scott through `demo/bin/public start` (a Cloudflare quick tunnel) and checked on the server.

| Check | Result |
| --- | --- |
| claude.ai custom connector, OAuth | Passed. claude.ai detected the server's OAuth and chose Claude's published identity: the server fetched `https://claude.ai/oauth/mcp-oauth-client-metadata`, the person signed in as `editor` and allowed access, and the code (redirect `https://claude.ai/api/mcp/auth_callback`) was exchanged for a grant |
| The card's phone code in claude.ai | Passed. The card called the app-only `approval_handoff` tool through claude.ai, and the code it drew was redeemed |
| Approval on a physical iPhone | Passed at 17:46:16 UTC. The approval came from the phone's scoped session, which never signed in, with the iCloud Keychain passkey registered in Safari on the Mac (user verification required). The application recorded the approval and issued the alert in the same second, once |

Found on the way: claude.ai did not pass the server's instructions to Claude, so it guessed the alert's ID and asked for it.

## Update: alert story, per-role rule, OAuth and the phone code (November 8, 2026, later)

Run on macOS with PHP 8.3.31. The demo became Riverside Council's public alerts site: `publish_alert`, `read_alert`, and an administrator's rule per role.

| Check | Result |
| --- | --- |
| PHPUnit, `composer test` | 156 tests, 870 assertions, all passing. New tests cover the per-role rule, MCP OAuth (the full flow, refusals and client ID metadata documents) and the phone code (`HandoffTest`: one request only, single use, owner only, the QR modules decode to the link, no code without HTTPS) |
| PHPStan, `composer analyse` | No errors |
| `tests/browser_smoke.py`, real Chrome | Passed, on the alert story |
| `tests/card_smoke.py`, real Chrome with a scripted MCP Apps host | Passed. The card drew the phone code, a QR decoder read it back as the one-time link, the code disappeared on approval and on expiry, and the option was hidden on a local address, without the server's offer, and inside Claude on a phone |
| `tests/phone_smoke.py`, real Chrome behind a stand-in HTTPS tunnel | Passed. A separate phone browser that never signed in opened the code's link, reached nothing but that review, approved with a synced copy of the passkey, and the agent's `approval_status` showed completed; the link was refused the second time |

At the time, claude.ai and a physical phone had not been run yet (see the update above). The Claude Desktop and Touch ID run below used the earlier announcement version of the demo.

## Update: approval card, native prompt and final-page review (November 8, 2026)

Run on macOS with PHP 8.3.31. The sections after this one keep the first build's record.

| Check | Result |
| --- | --- |
| PHPUnit, `composer test` | 150 tests, 743 assertions, all passing. New tests cover keyless retry reuse, the URL prompt and its bound retry, the card, the final-page preview, and the layout binding |
| PHPStan, `composer analyse` | No errors, now including `src/mcp` |
| `tests/http_smoke.py` | Passed |
| `tests/browser_smoke.py`, with real Chrome and a CDP virtual authenticator | Passed. It covers enrollment and approval through the real page scripts, a 404 before approval, a live page identical to the approved preview, and the stale-draft refusal |
| `tests/claude_code_host.py`, with Claude Code 2.1.317 and claude-haiku-5-5 | Passed. The URL-mode elicitation reached the host; after the passkey approval and "accept", the retried call returned "completed", with one committed effect |
| GitHub Actions: PHP 8.3, PHP 8.4 and the Drupal job (the Drupal port has since moved out of this repository) | Passed |
| A Claude Desktop chat, through a probe server using the same card mechanism | The card rendered, opened the review page through `ui/open-link`, and switched to approved after approval on the site |
| A Claude Desktop chat with this demo through `mcp-remote`, with physical Touch ID | Passed at 16:21 UTC. Claude called `publish_announcement` with the editor's personal token. The card polled `approval_status` every 3 seconds. An iCloud Keychain passkey, user verified, approved the exact page 21 seconds later. The site published once, and the card's polling stopped on the next poll |

Not yet run: any hosted or phone connection.

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
| Drupal integration | Was implemented in a subsequent build and verified on an installed site; that port now lives outside this repository. The 132-test counts above remain the original shared/Symfony suite. |

`tests/browser_smoke.py` provides a browser acceptance path for a machine where Chromium can run. It creates a temporary fictional account/application, registers through the real native WebAuthn API using a CDP virtual authenticator, attempts publication and tests stale state. Even a successful virtual-authenticator run would not establish physical biometric behavior.

## Supported guarantee and remaining integration work

The implementation enforces one local atomic action under current authority and an exact stored approval. The supplied store supports SQLite. Application effects outside that transaction, alternate unguarded write paths, remote MCP calls, real OAuth deployment, credential recovery, other database adapters and physical client/device interoperability need their own integration and review.

The next useful validation is a real user enrolling a passkey on a stable application origin, requesting the action from a selected agent client and completing approval on their own device. That validates user experience and client interoperability beyond the server guarantees tested here.
