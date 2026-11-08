# Drupal exact-approval demo

This example installs a disposable Drupal site to demonstrate a simple policy: an authorized editor must approve an exact publication or deletion with a registered key. Drupal stores the signing evidence and applies its normal permissions and workflows.

The example uses the base Drupal module, the shared PHP core and WebAuthn bridge, and the optional MCP plugins. It does not run the Symfony example application or require Symfony FrameworkBundle.

## Requirements

- PHP 8.3+ and Composer 2, with the extensions required by Drupal, including PDO SQLite, OpenSSL, sodium, JSON, mbstring, DOM and XML.
- The **full repository layout**. The Composer path repositories reference `../../packages/core`, `../../packages/webauthn`, and `../../packages/drupal/exact_approval`; these unpublished packages are not fetched from a public package registry.
- Internet access for Composer's public Drupal and library dependencies during installation.
- Optional Python 3 with `requests` and `beautifulsoup4` for the HTTP UI test. Python is not a runtime dependency of the Drupal module.

The verified installation used Drupal 11.4.12, PHP 8.4.24, `drupal/mcp_server` 2.0.0-beta7, `mcp/sdk` 0.9.0, and `web-auth/webauthn-lib` 5.3.12, as pinned in the Composer locks.

## Install a fresh demo

From this repository's root:

```sh
composer install
cd examples/drupal
composer install
php setup.php
```

The first install supplies the root verification utilities; the second installs Drupal and its path-repository dependencies. `setup.php` creates a new SQLite database, generated local passwords, an application signing key, configured approval rules, and two fictional announcement drafts. It refuses to replace an existing `var/drupal.sqlite` database.

The site is created with these accounts:

| Account | Purpose |
| --- | --- |
| `admin` | Configure the site, native permissions, approval policy, and audit access |
| `editor` | Publish/delete announcement content subject to exact approval |

Read their generated passwords from **`var/demo.json`** on your own machine. Do not commit or share this file, the SQLite database, generated settings, or the signing secret. The root `.gitignore` excludes generated runtime state.

Start the server **from the `web` directory**:

```sh
cd web
php -S localhost:8090 .ht.router.php
```

Open `http://localhost:8090/user/login`. The working directory matters: this Drupal runtime's plugin discovery uses paths relative to the Drupal document root. The PHP development server is for this loopback demo only.

The default trusted passkey origin is `http://localhost:8090`, with RP ID `localhost`. Set `EXACT_APPROVAL_ORIGIN` before the first setup if you intentionally need a different origin. The automated HTTP UI runner uses the configured demo origin; the MCP runner uses a separate loopback port for its transport test. Production enrollment requires a correctly configured HTTPS origin, not this local setup.

## Demonstrate the administrator and editor flow

### 1. Inspect the policy

Sign in as `admin` and open:

**`/admin/config/people/exact-approval`**

The Announcement content type has protected Publish and Delete operations. Each rule requires both exact approval and the selected native permissions. In Drupal 11.4, ordinary publication checks node update access and status-field editing; the latter permits `administer node published status` or `administer nodes`. Moderated publication instead uses an allowed transition to a published default-revision state. The permission selector includes installed `use WORKFLOW_ID transition TRANSITION_ID` permissions.

You can change the exact plain-text statements. Supported placeholders are `title`, `nodeId`, `bundle`, `revisionId`, `operation`, `transition`, and `site`, written as `{{title}}`, etc. `site` is this site's UUID. A policy change invalidates incompatible pending requests.

### 2. Register the editor's key

Sign in as `editor`, open **`/user/exact-approval/keys`**, and choose **Register approval key**.

The browser asks your authenticator to create a passkey and verify its user. That verification may use a fingerprint, facial verification, PIN, or another supported method. Drupal stores the public registration associated with the editor's UID and this site's UUID, never the private key or biometric data.

Enrollment trusts the currently authenticated Drupal browser session. This example does not implement administrator approval of enrollment or pin a particular administrator-selected device. Possession and user verification of the registered credential are the demonstrated claims.

### 3. Publish a saved draft

Open `/admin/content`, choose a draft announcement, and use its exact-publication action, or visit:

```text
/node/NODE_ID/exact-approval/publish
```

Prepare the request. Review the saved target, content snapshot/effect, statement, and expiry. Then choose **Approve and execute** and use the registered passkey. The node changes only when that proof succeeds and current permissions, policy and stored content still match.

The current adapter publishes an **existing saved revision**; it does not accept fresh content edits during approval. Publish protection also covers saves that leave content published. The normal edit form therefore asks you to save an unpublished draft/revision first. A moderated site can retain its previous live revision while preparing a new draft. An unmoderated site does not gain a separate working-copy system from this module.

### 4. Delete through Drupal's ordinary confirmation

Open a different announcement's native Delete page. For a protected content type, **Prepare exact approval** replaces the direct deletion submit. The next page describes deletion of the complete content item, including its revisions and translations. Nothing has been deleted yet.

Approve with the editor's registered key. After the deletion, the original node is gone and its signed approval evidence remains. Translation-only and individual revision deletion are unsupported protected actions; the integration refuses them rather than changing their scope.

### 5. Inspect evidence

- Personal history: `/user/exact-approval/history`
- Registered public keys for administrators: `/admin/config/people/exact-approval/keys`
- Completed evidence: use the **Evidence** link in history.

Download the signed bundle, historical public credential record, and historical application public key. From the repository root:

```sh
php bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json
```

The two trust files must come from independently trusted registration/key records. The verifier establishes that the credential approved the proposal and the application signed the execution report. It does not independently witness the database, the rendered review page, a specific biometric, or human understanding.

An active owner retains access to their own stored approval history after losing current content permissions. Historical public registrations and signatures survive credential revocation or content deletion while the module's data is retained.

## Demonstrate MCP

Setup enables `mcp_server` and the included `exact_approval_mcp` submodule. The submodule adds three plugins to the existing `/mcp` server:

| Tool | Example arguments | Behavior |
| --- | --- | --- |
| `exact_approval_publish` | `{"node_id": 1, "idempotency_key": "demo-publication-1"}` | Return a pending exact proposal and owner-bound browser review URL |
| `exact_approval_delete` | `{"node_id": 2}` | Request approval to delete the complete node |
| `exact_approval_status` | `{"request_id": "apr_..."}` | Read the initiating user's stored result without executing again |

The agent requests; the same user signs in the browser; the agent checks the result. Publication and deletion are executed by the shared approval service, not by an agent returning an approval string.

The loopback acceptance fixture enables **per-user Basic authentication** for `/mcp` using a test-only route subscriber. That is intentionally part of this disposable example, not an OAuth implementation or a production recommendation. Human key enrollment, policy administration, and approval endpoints still require the Drupal browser session and CSRF token and reject Basic/bearer credentials.

The production submodule leaves the existing MCP server's route and authentication configuration intact. Bearer/unknown authentication providers require a trusted stored delegation integration and current-grant checks; otherwise this integration rejects them. Live ChatGPT/Claude authorization flows have not been implemented or tested here. See the [module README](../../packages/drupal/exact_approval/README.md#oauth-and-delegation-are-an-integration-point).

## Run the tests

Run these **sequentially**. The installed-Drupal acceptance suite temporarily changes approval policy and creates fixtures, so parallel HTTP ceremonies could be invalidated by its intentional policy changes.

From the repository root, after setup:

```sh
php packages/drupal/exact_approval/tests/verify.php
python3 packages/drupal/exact_approval/tests/http_mcp_smoke.py
```

If PHP is not named `php` on your PATH, the MCP runner accepts `PHP_BIN=/absolute/path/to/php`. It starts and stops its own server on port 8091 and uses the official PHP MCP SDK client against the real Drupal server. It temporarily enables direct-mutation test plugins to confirm ordinary tools cannot bypass the guard, then uninstalls those plugins.

Install the optional Python dependencies in a virtual environment for the UI test:

```sh
python3 -m venv .venv-drupal-http
.venv-drupal-http/bin/python -m pip install -r packages/drupal/exact_approval/tests/requirements-http.txt
.venv-drupal-http/bin/python packages/drupal/exact_approval/tests/http_ui_smoke.py --php /absolute/path/to/php --start-server
```

The UI runner starts and stops its server on the configured demo origin. Stop a manually started server on that port first. It reads the generated demo passwords without printing them, uses real Drupal login and Form API sessions, and keeps its synthetic authenticator's private key only in that test process's memory.

For execution environments that isolate each command's network namespace, `--start-server` keeps the HTTP server, client, and synthetic authenticator in the same invocation. A server started by an earlier unrelated command may not be reachable there.

### Latest observed results

| Suite | Result |
| --- | --- |
| Installed Drupal acceptance on SQLite | **19 scenarios / 138 assertions / 0 failures** |
| Real HTTP UI, native forms and WebAuthn endpoint flow | **7 scenarios / 86 assertions / 0 failures** |
| Official SDK over the actual Drupal MCP endpoint | **7 checks passed** |

The acceptance suite covers actual Drupal permissions/moderation, stale proposals, revoked roles and keys, hidden-field denial, rollback and post-save alteration, retained deletion evidence, and a simultaneous-completion race. The UI suite includes an unchanged policy settings POST, native transition choices, owner isolation, missing CSRF, strict completion input, replay rejection, agent-authentication denial, publication, native edit blocking, and native deletion.

The generated reports used for this gate are `var/verification.json`, `var/mcp-verification.json`, and `var/http-ui-result.json`. Each runner saves its report there; the acceptance CLI also prints JSON. Test users, nodes and audit entries remain available for inspection in this disposable site.

**These are real HTTP and cryptographic tests using a synthetic ES256 authenticator.** They do not execute browser JavaScript or interact with physical passkeys, fingerprint readers, or live AI clients. The manual browser steps above are still needed to validate your actual device and deployment origin.

## Scope of this example

The module's guards protect ordinary Drupal node entity-storage mutations. They are not protection against trusted PHP issuing direct SQL, a malicious module, or an administrator disabling policy. Custom node storage handlers fail closed pending explicit integration. Non-live Workspaces, individual revision deletion and translation-only deletion are not implemented.

The tested local database effects, approval record, credential update, and signed evidence share a transaction. External HTTP requests, email, files, or writes to a different database made by arbitrary Drupal hooks are not made atomic by this mechanism. Other database backends have not received this integration's SQLite acceptance and concurrency tests.

For deployment installation, key handling, retention details and integration interfaces, see the [module README](../../packages/drupal/exact_approval/README.md). Install only the production module and desired MCP submodule on a real site. Never enable `exact_approval_test`, `exact_approval_mcp_test`, or `exact_approval_acceptance_test` outside this isolated testbed.
