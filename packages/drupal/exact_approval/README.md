# Exact approval for Drupal

Require the holder of a key registered to a Drupal account to approve an **exact saved-content operation** before Drupal publishes or deletes it. An administrator selects the content type, operation, native permission requirements, and approval statement. The same guarded action can be requested from Drupal's UI or from an MCP tool.

The human reviews the frozen proposal on Drupal, approves it with a passkey, and Drupal executes only that stored action. Changed content, revisions, policy, permissions, credentials, or relevant workflow state can prevent execution. A completed action keeps its approval signature, public credential registration, execution record, and separately signed application receipt.

This is an initial integration for stock Drupal node storage. The implemented actions are `node.publish` and `node.delete`; it is not a generic approval flag that grants permission to arbitrary subsequent writes.

## Packages and requirements

- Drupal **11.4 or later in the 11.x series**, PHP 8.3+, and the extensions required by Drupal.
- PHP OpenSSL, sodium, JSON, and mbstring, with Composer resolving the remaining library dependencies.
- `exact-approval/core` for immutable proposals, proof binding, and execution.
- `exact-approval/webauthn`, using `web-auth/webauthn-lib`, for actual WebAuthn verification.
- Optional `drupal/mcp_server` and the included `exact_approval_mcp` submodule to expose three tools through the site's existing MCP endpoint.

The base module uses Drupal's container, sessions, permissions, Form API, database connection, and node storage. It does **not** require Symfony FrameworkBundle, a separate Symfony application, or the repository's Symfony bundle. Symfony components used by Drupal and the WebAuthn library remain ordinary Composer dependencies.

These packages are supplied as source in this repository; the installation instructions below use local Composer path repositories. They are not a claim of published Packagist or Drupal.org releases.

## Try the complete local demo

See [the Drupal example README](../../../examples/drupal/README.md) for a disposable SQLite site with an administrator, an editor, two draft announcements, configured rules, and optional MCP tools. That example includes development-only authentication and test fixtures. Do not copy its `settings.php`, credentials, test modules, or acceptance-test marker into a production site.

## Install in an existing Drupal codebase

Keep the repository available at a stable path, or publish the three packages into your own Composer repository. From the Drupal project's Composer root, register the unpublished source packages, replacing the example absolute path:

```sh
composer config repositories.exact-approval-core path /absolute/path/exact-action-approval/packages/core
composer config repositories.exact-approval-webauthn path /absolute/path/exact-action-approval/packages/webauthn
composer config repositories.exact-approval-drupal path /absolute/path/exact-action-approval/packages/drupal/exact_approval
composer require 'drupal/exact_approval:^0.1'
```

An existing Drupal Composer project should already configure `composer/installers` and module installation paths. Path repositories may create symlinks during development; deployment must include the referenced source or configure those repositories with `"options": {"symlink": false}`. Requiring this module installs the core and WebAuthn packages transitively. The example project's Composer file provides a complete working repository configuration.

### Configure the application's receipt-signing key

This key belongs to the application and signs execution receipts. It is separate from each user's passkey. Generate it once into a protected secret location outside the public document root, readable only by the application and its operators:

```sh
php -r '$secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()); $encoded = rtrim(strtr(base64_encode($secret), "+/", "-_"), "="); if (file_put_contents($argv[1], $encoded) === false) { exit(1); } chmod($argv[1], 0600);' /secure/application-secrets/exact-approval.key
```

Create the containing directory with appropriate permissions first. Connect the secret through your existing settings/secrets deployment mechanism. A file-backed Drupal `settings.php` example is:

```php
$settings['exact_approval_signing_key_id'] = 'my-site-approval-key-1';
$settings['exact_approval_signing_secret'] = trim(
  file_get_contents('/secure/application-secrets/exact-approval.key')
);
```

The secret is not Drupal exportable configuration. Preserve its public verification key and key identifier in a trusted audit location. Completed evidence retains the public key used for that execution, including after a later application-key rotation.

Enable the module:

```sh
vendor/bin/drush en exact_approval -y
vendor/bin/drush cr
```

Visit **`/admin/config/people/exact-approval`** and configure:

1. The exact trusted HTTPS origin, such as `https://cms.example.org`.
2. The relying party ID, such as `cms.example.org`. It is a hostname, not a URL.
3. Publish/Delete rules for the desired content types and their native permission bindings.
4. The exact approval statements and proposal lifetime, from 30 to 3,600 seconds.

HTTP `localhost` is accepted for development. The origin is explicit configuration; the verifier does not infer trust from an incoming Host header. Changing the relying party ID may make existing passkeys unusable. Start with the correct production domain.

## Administrator rules and native permissions

An enabled rule protects the selected **operation and content type**. Its selected permissions are an additional OR requirement: the caller must hold at least one of them. They do not grant a permission, and selecting one does not exempt other users from the approval rule.

| Action | Drupal's underlying checks | Typical binding |
| --- | --- | --- |
| Delete a complete content item | The node's native delete access, including ownership and access grants | `delete own announcement content` and/or `delete any announcement content` |
| Publish without Content Moderation | Node update access and edit access to its `status` field | In the tested Drupal 11.4 code, `administer node published status` or `administer nodes` permits status-field editing; normal node update access is still required |
| Publish with Content Moderation | Node update access and an allowed transition to a published, default-revision state | The actual `use WORKFLOW_ID transition TRANSITION_ID` permission, selected from the installed permissions |

See [NodeAccess](src/Action/NodeAccess.php) for the integration's native checks. Publication is not modeled as an invented universal `publish content` permission. If several publishing transitions are permitted, the exact transition must be selected.

The administrative permissions provided by this module are:

- `administer exact approval`: manage rules and inspect registered public keys. It does not bypass an approval requirement.
- `view exact approval audit`: inspect and export all users' completed evidence, including stored snapshots of content that has since been deleted.

Statements are plain text with the supported placeholders `{{title}}`, `{{nodeId}}`, `{{bundle}}`, `{{revisionId}}`, `{{operation}}`, `{{transition}}`, and `{{site}}`. `site` resolves to this Drupal site's UUID. Statements are not executed as Twig. The resolved text is part of the frozen proposal. Policy changes invalidate incompatible pending requests.

### Protected published saves are deliberately strict

Publish protection also covers **edits that leave content published**, rather than protecting only the first unpublished-to-published transition. The current action accepts existing saved content identifiers, not replacement title/body fields or arbitrary edits.

Save an unpublished draft or revision first, then prepare approval for that saved revision. On a moderated site, this permits the normal draft-review-publish flow while the previous live revision remains live. On an unmoderated site, an editor may need to unpublish before saving changes; this prototype does not introduce a separate unpublished working-copy system for unmoderated content.

The native edit form explains this rule and blocks direct published-state saves with a validation message. The native complete-node Delete confirmation becomes **Prepare exact approval** and redirects to review without deleting anything. The storage guard also protects callers that bypass these forms, including ordinary MCP tools and bulk entity operations that use Drupal's entity storage API.

Unprotected native actions retain Drupal's native behavior. Explicit calls to this module's approval actions always create an approval request, even if that content type currently has no enabled native-action rule.

## Human enrollment and approval

1. Sign in to Drupal in a browser using the account that will initiate the action.
2. Open **`/user/exact-approval/keys`** and register a personal passkey.
3. Prepare a publish/delete request through Drupal or an authorized MCP tool.
4. Open the returned review URL in that same user's Drupal browser session.
5. Review the exact target, effect, preconditions, and statement; then choose **Approve and execute**.
6. Read the stored result, or let the MCP client retrieve the request's status.

Enrollment trusts the existing authenticated Drupal browser session to bind a new key to that Drupal UID and site UUID. This version does not require an administrator to pre-pin one particular key, require an administrator to approve enrollment, or establish an independently verified real-world identity. Account enrollment controls therefore remain part of the deployment's trust model.

WebAuthn requires user verification. The device may satisfy that with a biometric, a PIN, or another supported verification method. A valid assertion proves control of the registered credential under those checks; it does not establish that a particular fingerprint was used. Drupal receives neither biometric data nor the user's private key.

Human enrollment and approval endpoints require an actual Drupal browser session and CSRF protection. Basic/bearer credentials, a caller-supplied UID, or a tool response saying “yes” cannot approve. Each challenge is short-lived and single use, and completion accepts only the ceremony identifier and WebAuthn response. No replacement action arguments are accepted at completion.

## Optional MCP integration

Install the existing Drupal MCP server and enable the included submodule:

```sh
composer require 'drupal/mcp_server:^2.0@beta'
vendor/bin/drush en mcp_server exact_approval_mcp -y
vendor/bin/drush cr
```

This registers three tools and the approval card on the existing MCP server through its attribute-discovery hook (`McpServerFactory::addDiscovery()`), so each tool receives the SDK request context. It does not replace `/mcp`, install another kernel, replace the server's authentication flow, patch `mcp_server`, or expose a tool that signs on a human's behalf. Because they are discovered rather than plugins, these tools do not appear in the MCP server's per-tool settings; access still requires the caller's own authenticated Drupal account.

Publish and delete link the MCP App approval card (`ui://exact-approval/approval-card.html`), which opens the review page and shows only the recorded outcome. To also send a URL-mode elicitation to clients you have tested, set `exact_approval_mcp.settings:url_elicitation` to `{enabled: true, clients: ['claude-code']}`. It applies only under MCP 2026-07-28 or later, and its retry is bound to the same caller, tool and arguments. See [MCP presentation](../../mcp/README.md).

| Tool | Input | Result |
| --- | --- | --- |
| `exact_approval_publish` | `node_id`; optional saved `revision_id`, exact `transition_id`, and `idempotency_key` | Stored pending request and a browser review URL; content remains unchanged |
| `exact_approval_delete` | `node_id`; optional `idempotency_key` | Pending approval for deletion of the complete node, including revisions and translations |
| `exact_approval_status` | `request_id` | The initiating user's stored status and result; it does not execute again |

For example, the agent can request `exact_approval_publish` with `{"node_id": 42, "idempotency_key": "campaign-review-42"}`. The same user reviews and signs in Drupal. The agent then calls `exact_approval_status` with the returned request ID. The approval completion itself performs the stored operation; there is no separate unguarded “execute” token.

### OAuth and delegation are an integration point

The base module consumes Drupal's authenticated account; it is not an OAuth authorization server. Unknown/nonstandard authentication providers and bearer authentication are rejected unless a trusted authentication integration supplies a stored delegation reference. They must not silently gain the account's complete authority.

A delegated-auth integration must set the trusted request attribute `_exact_approval_delegation_id` and replace the `exact_approval.delegation_authorizer` service with an implementation of [DelegationAuthorizerInterface](src/Security/DelegationAuthorizerInterface.php). It must recheck the original grant's audience, scope, authorization and revocation at preparation and execution. The shipped implementation rejects delegated grants until that integration exists. Never populate this attribute from tool arguments or an unverified client header.

The disposable tests use personal HTTP Basic credentials through a **test-only** route subscriber on a loopback demo. The production submodule does not enable that subscriber or alter `/mcp` authentication. Compatibility with a live ChatGPT, Claude, or other remote client and its OAuth flow is not established by those tests.

## Evidence and verification

An active authenticated owner can inspect their history at **`/user/exact-approval/history`**. An account with `view exact approval audit` can inspect all users' evidence. Administrators can inspect registered credential IDs, UIDs, revocation status, and public-record digests at **`/admin/config/people/exact-approval/keys`**.

A completed action retains:

- The exact canonical proposal, its digest, and resolved statement.
- The initiating Drupal UID, site UUID, and any bound delegation reference.
- The credential identifier, original assertion/signature, challenge binding, and verification record.
- The historical **public** credential registration needed to verify that assertion.
- The execution outcome and application receipt signature, with the historical application public key.

This supports two separate conclusions:

1. The registered credential approved the exact proposal.
2. The application signed the reported execution outcome.

The second signature is an application attestation, not an independent witness to a database write, a browser display, or a human's understanding. The device's native approval sheet does not independently display and attest the business statement shown by Drupal.

Download the three files from a completed audit record. From the full source repository, with its root Composer dependencies installed:

```sh
php bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json
```

Use separately trusted registration and application-key records for the two trust inputs. Merely accepting whichever public keys accompany an untrusted evidence bundle would not establish the intended authority. The historical assertion verifier does not prove historical revocation freshness or replay the live authenticator-counter state.

Evidence and public registrations survive node deletion and key revocation while module data is retained. An active owner keeps their own approval history after losing current publish/delete permissions; history access is intentionally separate from current entity access. Audit data may contain content snapshots, so include these tables in the site's access, backup, and retention policy.

## Supported boundary and current limits

- Tested with **Drupal 11.4.12, PHP 8.4.24, and SQLite**. Other supported Drupal database backends have not received this integration's concurrency/rollback tests.
- The module uses a guarded subclass of stock `NodeStorage`. It fails closed if another module has already replaced that storage handler; custom storage needs explicit integration. Trusted PHP, direct SQL, disabled hooks, or a malicious module can bypass application-level controls and are outside this boundary.
- Native entity writes, approval consumption, credential-counter updates, and retained evidence share the tested local database transaction. Arbitrary hooks sending email, writing another database, or making external HTTP calls cannot be undone by that transaction. A retry after an ambiguous external effect needs application-specific handling.
- Only existing saved nodes/revisions are supported. Exact content editing, creation, bulk approval UX, alternate entity types, non-live Workspaces, individual revision deletion, and translation-only deletion need their own adapters. Protected revision/translation deletion fails closed; it is never broadened into complete-node deletion.
- The stored proposal is finite and can be rejected if its snapshot exceeds the core size limit. A large revision history may make whole-node deletion too large for this initial adapter.
- The administrator can change policy or disable the module. This is not a defense against a malicious site operator or against arbitrary trusted code running with database access.

## Validation

The latest local gate passed:

| Suite | Observed result |
| --- | --- |
| Real installed-Drupal acceptance | 19 scenarios, 138 assertions, 0 failures |
| Real HTTP UI, sessions, Form API, and WebAuthn ceremonies | 7 scenarios, 86 assertions, 0 failures |
| Official PHP MCP SDK client and raw 2026-07-28 calls against the Drupal HTTP MCP server | 11 checks passed |

The HTTP UI suite includes an actual unchanged-settings POST, native moderation permission choices, strict proof payloads, CSRF/ownership/replay checks, and refusal of Basic authentication for human administration and approval. The database suite includes stale state, revoked account roles, hook failures, post-save alteration, hidden-field protection, deletion evidence, and a simultaneous-completion race.

WebAuthn tests use genuine ES256 signatures from a synthetic authenticator and the production parser/verifier. Physical passkeys, biometric readers, browser JavaScript execution, and live LLM client integrations were **not** tested. See the example README for reproducible commands and the separate development-only Python dependencies.
