# Drupal verification

## Result

The Drupal integration is implemented and has passed the following local verification gates. These are executions of the delivered code, not a proposed test plan.

| Gate | Observed result |
| --- | --- |
| Installed Drupal acceptance suite | 19 scenarios, 138 assertions, zero failures |
| Real HTTP UI/session/WebAuthn suite | 7 scenarios, 86 assertions, zero failures |
| Official PHP MCP SDK client to Drupal MCP endpoint over HTTP | 7 checks passed |
| Existing shared core, WebAuthn, evidence and Symfony regression suite | 132 tests, 590 assertions, zero failures |
| Existing package PHPStan level 5 analysis | Passed; this existing configuration does not analyze the new Drupal module |
| New Drupal PHP and JavaScript syntax | Passed |
| Fresh source installation | Separate directory, Composer install without development dependencies, fresh minimal Drupal installation and example setup passed |

The repository includes a Drupal job in `.github/workflows/tests.yml` to repeat these gates. No GitHub Actions run or remote publication was performed here.

The installed environment was PHP 8.4.24, Drupal 11.4.12, Drupal MCP Server 2.0.0-beta7, official `mcp/sdk` 0.9.0 and WebAuthn Library 5.3.12. Composer lockfiles capture the exact dependencies. The Drupal example also pins dependency resolution to PHP 8.3 compatibility; execution here used PHP 8.4.

## Installed application tests

`packages/drupal/exact_approval/tests/verify.php` boots the real installed Drupal kernel and uses actual users, roles, node storage, field access and Content Moderation. It is restricted to a deliberately marked disposable test site. It uses a synthetic ES256 authenticator producing real signatures, which the production WebAuthn library validates.

The suite covers native permissions; missing keys; correct signed publication and deletion; wrong account, key, challenge, origin and missing user verification; stale content, revisions and policy; permission revocation and role-membership removal; and revoked credentials. It validates exact publication through Content Moderation, including the ability to save a subsequent unpublished moderation draft without changing the approved published revision.

Direct node saves/deletes, bulk deletion, UID 1, individual revision deletion, late hook publication and an entity reconstructed with an unprotected bundle are exercised as attempted bypasses. The rules are enforced at storage, not only by the approval tools. Field-view denial prevents hidden stored values from entering a proposal.

Real hooks deliberately write secondary database data and then fail, or alter content after save. The tests confirm rollback of the node, secondary write, credential counter and approval evidence. This tests local database atomicity; it does not make external hook effects transactional.

Two independent PHP processes boot Drupal, wait at a shared barrier and complete the same ceremony. Both receive the same committed outcome. The database contains one publication revision, one execution record and one evidence record for that request.

## HTTP tests

The UI runner submits Drupal's actual login and Form API pages, manages cookies and CSRF tokens, and sends synthetic WebAuthn responses through the public enrollment and approval endpoints. It verifies settings GET and POST, real native moderation permission options, key/audit pages, review ownership, CSRF and payload restrictions, Basic/bearer exclusion from human ceremonies, publication and replay, native published-edit validation, native Delete interception, and historical evidence downloads after deletion.

The MCP runner uses the official PHP SDK client and the Drupal module's actual HTTP SDK server. It discovers the three approval tools; confirms an ordinary fixture MCP tool cannot bypass the guarded native publish/delete path; requests owner-bound approval; checks idempotent request retry; checks wrong-owner status access; and confirms no protected mutation occurs from these agent requests alone. The ordinary tools are test fixtures, not a claim that every contrib MCP tool has been individually tested.

MCP test authentication uses each fictional user's own Basic credentials. A hidden fixture adds Basic authentication to the MCP route only on the explicitly marked isolated site. The distributable approval module does not change the production MCP route's authentication providers. No live model or hosted ChatGPT/Claude connection is used.

## Evidence verification

Completed records contain the original canonical proposal, passkey assertion, verification metadata, execution result and an application-signed receipt. The Drupal transaction also retains the public credential source and the application's public verification key used for that execution. Content deletion and later key revocation do not erase these records.

The acceptance suite verifies the application signature and the WebAuthn proof independently and rejects tampering. A fictional completed deletion is supplied in `examples/evidence/drupal-delete/` so the standalone verifier can be exercised after installing the root dependencies:

```sh
php bin/verify-evidence.php \
  examples/evidence/drupal-delete/evidence.json \
  examples/evidence/drupal-delete/trusted-application.json \
  examples/evidence/drupal-delete/trusted-credential.json
```

Those files contain public demonstration material only. Their trust inputs are supplied for the example. For real evidence, establish trust in the application's verification key and the user's credential registration independently; downloading three files from an untrusted party is not an independent trust establishment process.

## Reproduce

Follow `examples/drupal/README.md` for the isolated installation. Run the installed-site suite before the HTTP suites because it temporarily changes rules and then restores them. See `packages/drupal/exact_approval/tests/README.md` for commands. Do not run the tests on a production database.

The checked-in report files under `docs/drupal-results/` preserve the observed run. Runtime reports are regenerated under the ignored `examples/drupal/var/` directory.

## What these results do not establish

- A physical passkey, fingerprint, face-recognition or PIN prompt was not exercised. WebAuthn user verification does not specify which biometric or device-unlock method was used.
- Browser JavaScript received syntax checks and its HTTP contract was exercised, but no graphical browser/device automation completed in this environment.
- No hosted ChatGPT.com, Claude, Cowork or Claude Code connection was tested. Real OAuth grant/scope integration remains an explicit host integration; unknown delegation is rejected by default.
- Only SQLite was executed. Drupal's shared database transaction is used, but PostgreSQL/MySQL and multi-webhead contention need their own verification.
- Approval covers saved node fields, translations and the declared publication/deletion operation. Mutable referenced entities, rendered output, remote systems and arbitrary external hook effects are outside that snapshot.
- Existing saved nodes are supported. Active non-live Workspaces and standalone revision/translation deletion are deliberately refused; arbitrary content editing needs its own action adapter.
- Registration trusts the authenticated Drupal browser account. It is not an administrator-pinned enrollment ceremony, and it does not prove a legal identity or that a person understood the statement.
- Evidence owners retain their historical receipts, and the privileged audit permission grants cross-user evidence access. Executing a new action still requires current native authority.
- Trusted code, direct database access, module removal and administrators changing policy are outside the enforcement boundary. This is a tested prototype, not an external security audit.

