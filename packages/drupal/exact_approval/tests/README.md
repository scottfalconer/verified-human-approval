# Installed Drupal acceptance tests

These tests use the example's real Composer-installed Drupal site, real SQLite
entity tables, Drupal permissions and Content Moderation. They call the production
approval services and sign assertions with an ephemeral ES256 test authenticator.
They do not replace Drupal storage, authorization, or WebAuthn verification with
mocks.

From the repository root, install the disposable example and run:

```sh
composer install --working-dir=examples/drupal
php examples/drupal/setup.php
php packages/drupal/exact_approval/tests/verify.php
```

Setup refuses to overwrite an existing database. The runner requires
`$settings['exact_approval_acceptance_test'] === TRUE`. It creates fictional users,
unique content types and nodes, then restores the previous approval configuration.
Records remain in the disposable database for inspection. Do not run concurrent
interactive demos while the suite temporarily changes the site's approval policy.

The last complete run is written to
`examples/drupal/var/verification.json`. A failure returns a nonzero process exit
status and records its exception chain. The report identifies the actual Drupal
version, database driver, test count and assertion count.

## What is exercised

| Boundary | Evidence checked |
| --- | --- |
| Existing Drupal authority | Update, publication-status, delete and workflow-transition permissions remain mandatory. Removing a permission or the user's role membership after review is immediately authoritative. |
| Review confidentiality | A field-view denial prevents proposal creation, even when the account can update or delete the entity. No hidden-value proposal is stored. |
| Human key | A real ES256 registration and assertion succeeds. Wrong UID, another account's key, missing user verification, incorrect challenge/origin and revoked key fail. |
| Exactness | Changed content, replacement revision and changed administrator policy invalidate pending proposals. The published revision retains the approved content. |
| Storage enforcement | Direct save/delete, bulk deletion, UID 1, individual revision deletion, late hook publication and a reconstructed entity disguising its original bundle cannot evade required approval. |
| Normal editorial work | Ordinary unpublished drafts remain editable. After an approved moderated publication, a new draft can be saved without changing the approved live revision. |
| Atomic local completion | A real entity hook writes a second database row and then throws. Node mutation, hook row, credential counter, proof and signed receipt roll back together. A fresh ceremony can retry. |
| Late alteration | A post-save fixture changes persisted content after presave checks. Persisted postconditions reject it and the complete transaction rolls back. |
| Replay and concurrency | Replaying a ceremony creates no second revision. Two independent PHP processes are released through a barrier to complete the same ceremony concurrently; both receive the same result, with one publication revision, one execution row and one evidence row. |
| Durable evidence | Node deletion leaves the exact proposal, Drupal UID, public credential snapshot, human signature and application receipt intact. Both signatures verify; changing the recorded result fails verification; another account cannot export the receipt. |

`race-complete.php` is the worker for the actual two-process test. PHP CLI must
allow `proc_open()` for that check. The hidden `exact_approval_test` module supplies
the failure hooks and field-access denial. Its route subscriber allows Drupal
Basic authentication on the MCP route **only when the disposable-site marker is
present**, for the separate real HTTP MCP tests. Production authentication is not
configured by this fixture.

## Limits of these results

The database and concurrency results apply to the tested SQLite setup; MySQL and
PostgreSQL are separate integration targets. Local database rollback cannot undo
emails, remote writes or other external effects produced by application hooks.
The key material is synthetic: the suite proves cryptographic validation and
account binding, not a physical Touch ID/Face ID interaction. Browser-session,
CSRF and real MCP HTTP checks are covered by the separate example smoke tests.
The module guards its supported node-storage boundary; trusted PHP or direct SQL
with unrestricted database access is outside that boundary.
