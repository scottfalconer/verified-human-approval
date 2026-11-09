# Verification status

This page separates checks on the current code baseline from earlier device demonstrations. Older test counts and environment limitations are kept in the [verification history](verification-history.md).

## Current automated baseline

Checked on 2026-11-09 against commit [`4ea04eb1`](https://github.com/scottfalconer/verified-human-approval/commit/4ea04eb1487cc59a77687f8a4753dcc690957f48), which added the person's receipt. Later documentation-only commits do not change the code it describes.

The [GitHub Actions run for that commit](https://github.com/scottfalconer/verified-human-approval/actions/runs/42541296445) completed successfully, with all four jobs passing. The [workflow](../.github/workflows/tests.yml) runs these jobs:

| Dependency set | PHP | Checks |
| --- | --- | --- |
| Locked dependencies | 8.3 | Composer validation, PHPUnit, PHPStan, coding standard and real HTTP smoke test. |
| Locked dependencies | 8.4 | Composer validation, PHPUnit, PHPStan and real HTTP smoke test. |
| Lowest allowed dependencies | 8.3 | Composer validation, PHPUnit and real HTTP smoke test. The job raises the test-only `symfony/error-handler` floor as explained in the workflow. |
| Symfony 8 | 8.4 | Composer validation, PHPUnit and real HTTP smoke test. |

This is a dated baseline, not a claim that every later commit has passed. Use the checks on the commit or pull request you plan to install. A successful suite is also not a production security certification.

## What the automated tests exercise

| Area | Coverage and source |
| --- | --- |
| Approval lifecycle | Frozen content and policy, current permissions, guarded mutation, cancellation, expiry, retries and transaction rollback in [`tests/Core`](../tests/Core). |
| Concurrent execution | Separate processes and SQLite connections compete to complete proposals in [`ConcurrencyTest.php`](../tests/Core/ConcurrencyTest.php). Checks include one committed effect and competing source revisions. |
| Passkeys | Real synthetic ES256 signatures and registration data pass through the upstream WebAuthn validator in [`tests/WebAuthn`](../tests/WebAuthn). Rejection cases cover identity, origin, relying party, challenge, verification flags, counters and revocation. |
| Symfony and MCP | The actual kernel, security, sessions, CSRF, SDK transport, scope checks and browser endpoints in [`tests/Bundle`](../tests/Bundle). |
| Historical evidence | Real approval and receipt signatures, later record edits, missing receipts, wrong trust keys and the separately installed verifier in [`ApprovalEvidenceTest.php`](../tests/Evidence/ApprovalEvidenceTest.php). |
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
```

The Python HTTP check uses the standard library. The optional browser checks need Python Playwright and Chromium. The card check also needs PHP's `gd` extension to decode its QR code. Install the Python dependencies in a virtual environment:

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
