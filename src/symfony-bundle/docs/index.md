# Add Verified Human Approval to Symfony

The bundle lets an MCP tool request an action, send the person to a review page on your site, and read the result. The person approves the frozen proposal with a passkey. Your application checks current permissions, executes the stored action, and signs its receipt in the same database transaction.

**Two storages are supplied.** The PDO store supports SQLite, on one shared PDO connection. The [Doctrine storage](#storage-on-doctrine) supports SQLite, PostgreSQL, MySQL 8.0.17 or later and MariaDB, on your DoctrineBundle connection, with your entities in the same transaction. Either way, the business write, account and delegation checks, approval records, credential updates and receipts must use **one shared connection**. The approval service owns the outer transaction; do not call it inside an existing transaction. A second connection, an external API call or an email is not included. For other storage, implement the `ApprovalStore` rules and pass the [storage contracts](https://github.com/scottfalconer/verified-human-approval/blob/main/src/store-contracts/README.md) before relying on this guarantee.

For a runnable application, start with the [demo guide](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/README.md). To integrate your own application, follow this page and the [first protected action](first-action.md). That example includes the action, identity adapter, current permission checks, database setup, signing keys and service definitions.

## What you need to supply

| Part | Responsibility |
| --- | --- |
| Existing application | Browser login, authenticated users and tenants, personal MCP authentication, live permissions and delegation records |
| Your action adapter | Exact proposed change, stale-data checks, guarded mutation and postconditions |
| Your policy provider | Whether approval is required, the statement and its version |
| Your account process | Trusted passkey enrollment and recovery; an agent token cannot use it |
| This bundle | Review routes and templates, CSRF checks, rate limiting, action discovery, MCP status/card/handoff services, the person's receipt download, the public receipt keys and the audit-log console commands |
| Core and WebAuthn packages | Proposal binding, WebAuthn verification, transactional execution and signed receipts |
| Demo only | Its accounts, OAuth server, administrator forms and audit pages |

The bundle does not add an approval check to existing writes by itself. Every supported route, command and job that performs the protected mutation must use the same guarded application boundary.

## 1. Install from a checkout

The packages are not published on Packagist yet. The current installation path is a local Composer path repository. Do not install the monorepo root package into your application.

Use PHP 8.3 or later and a compatible Symfony 7.4 or 8 application; Composer enforces the selected Symfony version's PHP requirement. This guide uses `json`, `mbstring`, `openssl`, `pdo`, `pdo_sqlite` and `sodium`, plus FrameworkBundle, SecurityBundle and TwigBundle.

From your application's root:

```bash
git clone https://github.com/scottfalconer/verified-human-approval.git ../verified-human-approval
```

Choose and record the commit you intend to use. Add this entry to your application's existing `composer.json` `repositories` array:

```json
{
    "type": "path",
    "url": "../verified-human-approval/src/*",
    "options": {
        "symlink": false,
        "versions": {
            "verified-human-approval/core": "dev-main",
            "verified-human-approval/webauthn": "dev-main",
            "verified-human-approval/mcp": "dev-main",
            "verified-human-approval/symfony-bundle": "dev-main",
            "verified-human-approval/doctrine": "dev-main",
            "verified-human-approval/store-contracts": "dev-main"
        }
    }
}
```

Then install all four packages at the same development version. The explicit beta flag allows the MCP bundle used by this project without changing your application's global stability setting:

```bash
composer require --no-scripts \
  verified-human-approval/core:dev-main \
  verified-human-approval/webauthn:dev-main \
  verified-human-approval/mcp:dev-main \
  verified-human-approval/symfony-bundle:dev-main \
  'symfony/mcp-bundle:^1.0@beta'
```

On Doctrine, add `verified-human-approval/doctrine:dev-main` to that command, with `doctrine/doctrine-bundle` if your application does not have it yet; see [Storage on Doctrine](#storage-on-doctrine).

`--no-scripts` lets you add the required configuration before Symfony boots. Commit the resulting Composer files. Keep the checked-out source available when installing from that lock file. There is no published Flex recipe for this bundle yet, so the configuration below is yours to add.

## 2. Enable and configure the bundle

With Symfony Flex installed, as in a project made with `symfony new`, Composer has already added both bundles to `config/bundles.php`: Flex registers every Symfony bundle it installs, recipe or not. Check that these entries are there. Without Flex, add them, keeping the application's other bundles:

```php
<?php
// config/bundles.php
return [
    // Keep the application's other bundles here.
    Symfony\AI\McpBundle\McpBundle::class => ['all' => true],
    VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle::class => ['all' => true],
];
```

The [worked example](first-action.md) defines these application services and all constructor arguments. Use the same names while following it:

```yaml
# config/packages/verified_human_approval.yaml
verified_human_approval:
    public_origin: '%env(APP_ORIGIN)%'
    actor_provider: App\Approval\ApplicationActorProvider
    policy_provider: App\Approval\ApplicationPolicyProvider
    authorizer: App\Approval\CurrentAuthority
    store: VerifiedHumanApproval\Core\Storage\PdoApprovalStore
    verifier: VerifiedHumanApproval\WebAuthn\WebAuthnVerifier
    receipt_signer: VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner
    relying_party_id: '%env(APP_RP_ID)%'
    attempts_per_minute: 30
```

On Doctrine, replace `store` with `persistence: { driver: doctrine, connection: default }`, as [Storage on Doctrine](#storage-on-doctrine) describes; `store` and `persistence` are exclusive.

`receipt_signer` is required for the stored-record tamper evidence described in this guide. It signs the receipt before commit. A signing failure rolls the action back. Store the private signing key outside the database, and retain a trusted copy of its public key separately from exported evidence. With a signer configured, the completed review page offers the person their receipt, and the bundle publishes the public keys; see [Stored receipts](#stored-receipts-and-later-verification).

The optional `receipt_keys` describes the signer's key and the keys it replaced. `valid_from` is when the signer's key began signing, an RFC 3339 time such as `2026-11-09T12:00:00Z`: a record it signed that claims an earlier time does not verify. Unset or empty, there is no earlier limit. `retired` lists earlier keys, by public key only, each with `key_id`, `public_key`, `valid_until` and optionally `valid_from`; what each signed keeps verifying inside its window. The bundle serves these keys as `VerifiedHumanApproval\Core\Evidence\TrustedKeys`, which your `AuditLog` checks entries against, and publishes them. To change keys, follow [Rotate the signing key](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md#rotate-the-signing-key).

`public_origin` is a fixed, trusted setting such as `https://app.example.com`. Use it for the WebAuthn configuration too. `relying_party_id` is optional: it is published with the receipt keys so a receipt's passkey signature can be checked offline, and defaults to the public origin's host. Do not derive it from a request's Host header. Choose the origin and RP ID before enrolling passkeys. HTTP `localhost` is allowed for local development.

Import the routes:

```yaml
# config/routes/verified_human_approval.yaml
verified_human_approval:
    resource: '@VerifiedHumanApprovalBundle/config/routes.php'
```

Enable sessions, CSRF protection and assets in your existing framework configuration. After completing the example's service wiring and installation step, run:

```bash
php bin/console cache:clear
php bin/console assets:install
```

Action adapters are discovered when their services are autoconfigured. If you configure services manually, tag each adapter `verified_human_approval.action`. The bundle provides autowiring aliases for `ApprovalService`, `ExecutionGuard`, `ActorProvider`, `ApprovalPresenter` and `ApprovalFlow`, and, with a receipt signer, for `EvidenceExporter`, `ReceiptKey` and `TrustedKeys`. Its default clock is Symfony's `clock` service; `verified_human_approval.clock` points to that same clock.

Two console commands work with your signed audit log: `verified-human-approval:audit-head` prints its newest entry's sequence and hash, a checkpoint to keep where the application cannot write, and checks a kept one with `--check`; `verified-human-approval:record-key-rotation` records a key rotation. They use the `AuditLog` service your configuration names in `audit_log`, or, unset, the service named `VerifiedHumanApproval\Core\Audit\AuditLog`.

## Storage on Doctrine

With `verified-human-approval/doctrine`, the bundle wires the storage on a DoctrineBundle connection: the approval store, the signed audit log and the passkey repository share one transaction with your entity manager and your queries, on SQLite, PostgreSQL, MySQL 8.0.17 or later, or MariaDB. The [package guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/doctrine/README.md) has the details.

```yaml
# config/packages/verified_human_approval.yaml
verified_human_approval:
    # ...as above, without "store"
    receipt_signer: VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner
    persistence:
        driver: doctrine
        connection: default                   # the connection your protected writes use
        protected_entities:                   # optional; the #[RequiresApproval] attribute does the same
            App\Entity\Document: [publishedTitle, publishedBody]
```

This registers, with autowiring aliases, `DbalTransaction`, `DbalApprovalStore` (also `ApprovalHistory`), `DbalAuditStore` (also `AuditStore`), the signed `AuditLog` (checked against the trusted keys that `receipt_keys` describes, so it keeps working across a [key rotation](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md#rotate-the-signing-key)), `DbalCredentialRepository` (also `RevocableCredentialRepository`), `AnchoredCredentialRepository` (also `CredentialRepository`, so `WebAuthnVerifier: ~` reads passkeys through it) and, with the ORM, `ProtectedEntityListener`. It replaces the PDO, store, audit and credential services of the [worked example](first-action.md#2-configure-keys-and-services); keep its signer, `Configuration` and `WebAuthnVerifier`.

**Tables and migrations.** With Doctrine Migrations, `doctrine:migrations:diff` creates the approval tables, since a schema listener adds them to the ORM's schema for that connection, and it never proposes to drop them. Without migrations, run `php bin/console verified-human-approval:install` once. Both refuse to run inside a transaction. Canonical JSON is kept in text columns, **never `JSON` or `JSONB`**, which rewrite the signed bytes: do not change those columns' types. On MySQL and MariaDB the tables are `utf8mb4` with a binary no-pad collation and `LONGTEXT`; connect with `charset: utf8mb4` and keep strict SQL mode.

**No outer transaction.** The approval service begins the root transaction itself, through DBAL, so a flush inside your action nests as a savepoint. It refuses to start inside another one: never call `ApprovalService` inside `Connection::transactional()`, `EntityManager::wrapInTransaction()`, or a Messenger handler behind the `doctrine_transaction` middleware. Call it outside that transaction, or from a handler without that middleware.

**Your locking duty on PostgreSQL, MySQL and MariaDB.** SQLite has one writer at a time, and the approval holds that place. A server database runs writers side by side, so your reads must lock what the decision depends on, inside the approval's transaction: in your `Authorizer` and policy, the permission, grant and policy rows `FOR SHARE`; in your action's `prepare()`, `assertPreconditions()` and postconditions, the content it changes `FOR UPDATE`. Inject `DbalTransaction` and append its clause, which is empty on SQLite:

```php
$grant = $this->connection->fetchAssociative(
    'SELECT * FROM app_agent_grants WHERE id = ? AND user_id = ? AND tenant_id = ? '.$this->transaction->forShare(),
    [$actor->delegationId, $actor->userId, $actor->tenantId],
);
```

The approval transaction runs at `READ COMMITTED` on those databases, so a read after a lock sees what committed before it. Without the locks, a grant revoked while a person approves could be revoked first and the approval still commit after it; the storage contracts include that race.

**Primary and replica.** With a primary/replica connection, every read the stores make goes to the primary, and so does everything inside the approval transaction.

**Actions on entities.** Extend `VerifiedHumanApproval\Doctrine\Orm\EntityAction`: its final `execute()` clears the entity manager, loads the targets you name with pessimistic write locks, lets your `apply()` change them and flushes, so your postconditions read the written rows before the receipt is signed; after a rollback it resets the entity manager. Read current state with its `current()`, which locks and reloads the row.

**Protected entities.** Mark an entity, or its approved-state fields, `#[RequiresApproval]` (or list them under `protected_entities`). Any flush on that connection that changes them outside an approved execution permitted for that entity fails with `approval_required`, whatever form, admin screen or command made the change. DQL bulk updates and plain SQL do not pass through the unit of work: keep those away from protected rows.

## 3. Connect existing authentication

Keep the review routes on your application's normal session firewall. Keep `/mcp` on a separate stateless firewall that authenticates a personal bearer token on every request. Your MCP authenticator must pass its **validated** `Mcp\Server\Authorization\AccessToken` to the SDK as the request attribute named `AccessToken::class`.

The [example identity adapter](first-action.md#3-map-the-authenticated-person) specifies the principal fields and token claims it expects. It never accepts a user ID or tenant ID from tool arguments.

`ActorProvider` has three jobs:

- `current()` returns the authenticated account, tenant and optional delegation ID.
- `interactive()` also requires the trusted browser account flow. An agent credential must fail this check.
- `assertCanReadApproval()` checks current read authority before a stored result is exposed, including any delegated read scope.

Execution authority is separate. `Authorizer::assertAllowed()` reloads current account and delegation permissions inside the final transaction. A person may lose permission to publish while retaining permission to read the reason a request stopped.

Add this rule **before broader access-control rules**, keeping your existing rules and firewalls. The phone handoff needs it to open a review, and it makes the receipt keys public:

```yaml
# Merge into config/packages/security.yaml
security:
    access_control:
        - { request_matcher: verified_human_approval.review_request_matcher, roles: PUBLIC_ACCESS }
        # Keep the application's existing rules after this entry.
```

The matcher covers only the bundle's own routes: review, handoff, the person's receipt download and the public receipt keys. The controller still requires the signed-in owner or a session scoped to one request, and the receipt download requires the signed-in owner; approval still requires that person's passkey. It does not make your own enrollment or administration routes public.

## 4. Register the MCP tools and card

The worked example defines `App\Mcp\PublishDocumentTool`. Register it with the bundle's status tool and optional card/phone handoff:

```yaml
# config/packages/mcp.yaml
mcp:
    servers:
        application:
            http:
                path: /mcp
                allowed_hosts: ['app.example.com']
            registry:
                tools:
                    - App\Mcp\PublishDocumentTool
                    - VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool
                    - VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool
                apps:
                    - VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp
            session:
                lock: { enabled: true }
            request_state:
                key: '%env(MCP_REQUEST_STATE_KEY)%'
                ttl: 900
```

Set `allowed_hosts` to your application's host. Keep the MCP route import (`resource: '.', type: mcp`) supplied by your MCP integration. The session lock prevents the card's polling and a tool call from overwriting each other's session state. The request-state key must be at least 32 bytes and separate from the receipt key. Its TTL should exceed your approval lifetime; this example uses a 600-second approval lifetime.

The [tool example](first-action.md#6-expose-the-action-through-mcp) checks the validated token's `approval.read` scope **before** calling `ApprovalFlow::respond()`. This matters because a retry may return a stored result without executing the action again. The tool also declares `ApprovalFlow::OUTPUT_SCHEMA`, uses `ApprovalCard::TOOL_META` and returns structured errors.

A pending response is a normal tool outcome. It contains a `requestId`, `review_url`, status and instructions. The agent must not say the action is completed while it is pending. Reuse a `request_key` for a retry; call `approval_status` to read the outcome. Status reads never perform the business mutation.

URL-mode elicitation is optional and off by default. Enable it only for clients you have tested. The card and review link do not depend on it. See [MCP presentation](https://github.com/scottfalconer/verified-human-approval/blob/main/src/mcp/README.md) for client and protocol details.

## 5. Connect passkey enrollment and the review screen

WebAuthn already supports authenticator verification such as Touch ID, Face ID or a PIN. The application requires user verification; it does not choose or prove which local biometric method was used. No additional authentication mechanism is needed for those cases.

Enrollment belongs to your existing trusted account process. The [worked example](first-action.md#7-connect-trusted-passkey-enrollment) shows the registration and signed-enrollment hooks. The [WebAuthn guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/webauthn/README.md) describes credential and user-handle adapters for existing passkeys. An ordinary agent token must never enroll or recover an approval credential.

When a page on your site starts a request, redirect to the review page with `return`, a path on your site (for example the page the button was on): once the request finishes or stops, the review page offers a way back there. Any value that could lead to another site is ignored. A request an agent made instead tells the person to return to the agent, by the name in [`agent_name`](#wording-and-translations).

Override templates in `templates/bundles/VerifiedHumanApprovalBundle/`. `base.html.twig` offers `stylesheets`, `header`, `site_name` and `footer`; `review.html.twig` offers `preview`, `return` and `receipt`, the person's receipt download. Render the preview from the **frozen `prepared.effect`**, as the example does. Do not reload today's draft into an older review page. To use your own layout's scripts, styles and fonts on these pages, see [Your layout and language](#your-layout-and-language).

Give every action a clear `display.title`: what will change. Add `display.consequence` when the statement does not already say what happens as soon as approval completes; the sheet shows it next to the button. The sheet reads top to bottom: who asked, the exact statement, the `preview`, then the passkey button, so the person sees the effect before they can approve it, on a phone too. Keep the exact statement, preview, expiry, passkey button and truthful completion state visible. If a response is lost after submitting approval, ask the person to reload and check the recorded state; do not claim either success or failure without it. An optional `RequesterLabel` service can name the agent connection on the sheet; set its service ID in `requester_label`.

## Your layout and language

The review pages can sit in your own layout and use your own wording without loosening the policy that protects them.

### The content security policy

The bundle's routes, and any route of yours that sets `_verified_human_approval_headers`, get this policy, which replaces any set earlier for the response:

```text
default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self' 'nonce-…'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'
```

When your layout loads scripts, styles, images or fonts from another origin, or connects to one, add those origins:

```yaml
# config/packages/verified_human_approval.yaml
verified_human_approval:
    content_security_policy:
        script_src: ['https://static.example.com']
        style_src: ['https://fonts.googleapis.com']
        img_src: ['https://images.example.com']
        font_src: ['https://fonts.gstatic.com']
        connect_src: ['https://api.example.com']
```

Each list accepts `'self'` and `https://` origins, a host with an optional port and no path or wildcard; `img_src` and `font_src` also accept `data:`. Anything else, such as `'unsafe-inline'`, `'unsafe-eval'`, `*`, `http:` or `https:`, stops the container from building, so a weakened policy never deploys. Because the check runs then, write the origins out or use container parameters: an environment variable's value arrives too late to check, and is refused. The other directives cannot be changed. An origin in `script_src` can run any script it serves on these pages, so name one that serves only your own files, not a public CDN that serves anyone's.

### Inline scripts and styles

Every response has a new nonce, in `script-src` and `style-src`. An inline script or style in your layout runs when it carries that nonce. Read it with `verified_human_approval_csp_nonce()`:

```twig
{# templates/bundles/VerifiedHumanApprovalBundle/base.html.twig #}
{% extends '@!VerifiedHumanApproval/base.html.twig' %}

{% block stylesheets %}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">
    <style nonce="{{ verified_human_approval_csp_nonce() }}">body { font-family: Inter, sans-serif; }</style>
    {{ importmap('app', {nonce: verified_human_approval_csp_nonce()}) }}
{% endblock %}
```

The function returns the response's nonce wherever it is called during the request, sub-requests included, and the header carries the same one. On a page that does not get these headers, the nonce matches no policy and changes nothing. Event-handler attributes such as `onclick` and `style` attributes stay blocked: a nonce does not cover them. In PHP, `nonce($request)` on the autowirable `VerifiedHumanApproval\Bundle\ContentSecurityPolicy` service returns the same value.

### Wording and translations

The templates' wording is in the `VerifiedHumanApproval` translation domain. The bundle ships its English catalogue, `translations/VerifiedHumanApproval.en.xlf`, where each message's source is its English text, so without Symfony's translator the pages read exactly as that English.

With the translator enabled (`symfony/translation`), the catalogue loads automatically. Translate the pages with a catalogue of your own for each locale, using the same sources:

```yaml
# translations/VerifiedHumanApproval.fr.yaml
en: fr
'Approve with your passkey': 'Approuver avec votre clé d’accès'
'Requested by %requester%.': 'Demandé par %requester%.'
```

Keep placeholders such as `%agent%` as they are. The message `en` is the pages' language, used for their `lang` attribute: a translation gives its own language tag. Your application's catalogues take precedence over the bundle's, so `translations/VerifiedHumanApproval.en.yaml` rewords the English without overriding a template.

`approval.js` takes its wording from the page: the review template renders the translated messages as JSON in `data-messages` on the passkey panel and on the return panel. Any message missing there stays in the script's own English. A page of your own that uses the script, such as passkey enrollment, can hand over its wording the same way, with the keys of `ENGLISH` in `approval.js`.

Only a request an agent made mentions the agent. Set `agent_name` (default `Claude`) to the name your people know it by, as in "You can close this tab and return to Claude". The approval card in Claude adds `#return=` to the review link it opens: with `claude-desktop` or `claude-mobile`, the finished page also offers a button that opens the `claude://` app link; with `claude-web`, one that closes the tab.

## After an action runs

Two hooks let the rest of your application follow an approved change:

- **Link the change to its approval.** While your adapter's `execute()` runs, `ExecutionGuard::current()` returns the executing approval: its `requestId`, and whether a person approved it with a passkey (`userVerified()`) or a rule let it run. Write the request id onto the revision or record you create, in the same transaction, so a page can later show that this exact revision was approved, and link to the evidence.
- **Work that must wait for the commit.** After the transaction commits, the bundle dispatches `VerifiedHumanApproval\Bundle\Event\ApprovalCompleted` with the completed state. Use it to purge caches, reindex or notify. Delivery is at least once, so make a listener tolerate a repeat. A listener that throws is logged and does not change the result the person sees. Services implementing `VerifiedHumanApproval\Core\ApprovalObserver` are registered automatically too.

### Show that a change was approved

To show readers that what a page shows was approved with a passkey, store the request id with the change your adapter makes (from `ExecutionGuard::current()`), then render the badge where the content appears:

```twig
<link rel="stylesheet" href="{{ asset('bundles/verifiedhumanapproval/badge.css') }}">
{{ verified_human_approval_badge(article.approvalRequestId, article.id, {title: article.title, body: article.body}) }}
```

It renders "Approved with a passkey · date" only when all of these hold, and nothing otherwise:

- the request completed and a person approved it with their passkey (a rule letting it run does not count);
- it targets the resource you name;
- each value you pass equals the approved value of the same name in the frozen effect, so content changed later by any other path loses the badge;
- the receipt this site signed as the change committed still verifies against the site's published keys, so a record edited afterwards loses it too, while a change approved before a key rotation keeps it.

It needs `receipt_signer`. `verified_human_approval(...)` returns the same check's result, or null, if you draw your own badge. With `public_verification: true`, the badge links to `/approval/{id}/verified`, a page anyone with the link can open: what was approved, when, and the signed receipt (digests only). It names no account, tenant, agent connection or passkey, and an unknown request looks the same as one that doesn't verify.

For history views, `PdoApprovalStore` and `DbalApprovalStore` also implement `VerifiedHumanApproval\Core\ApprovalHistory`: `forResource($tenantId, $resource)` lists the requests made about one target `resource`, newest first, including ones whose resource was later deleted. Decide who may see which history yourself.

## Stored receipts and later verification

The signed receipt protects the historical proposal, proof and execution report against later alteration. It does not claim that today's live document still equals the document published then. A normal `approval_status` read does not perform full cryptographic verification.

With a `receipt_signer`, the bundle gives the person their own copy, so a later change to the stored record would show:

- **On the completed review page**, "Download your receipt" (route `verified_human_approval_evidence`, `GET /approval/{id}/evidence`) returns the evidence bundle (`verified-human-approval/evidence/v2`) as `receipt-<id>.json`: the frozen proposal, the person's passkey signature on it, the execution record and the original receipt. Only the signed-in owner gets it; a phone session opened by a one-time code is pointed back to the signed-in browser.
- **Nothing is signed at download.** A request that has not run is refused (`not_completed`), and so is one whose original receipt is missing (`receipt_missing`); the page says when no receipt is recorded. Do not create a fresh signature over a record at download time to claim it was unchanged since approval.
- **In the agent's result**, a completed request carries `receipt` (the signed manifest, `keyId` and `signature`), `receipt_keys_url` (the key set, which still checks it after a rotation) and `receipt_key_url` (the current key), so a copy also lands in the conversation.
- **The keys that check them** are public at `GET /approval/receipt-keys.json` (route `verified_human_approval_receipt_keys`): the key set (`verified-human-approval/receipt-keys/v2`), with `currentKeyId`, `keys`, each with its `keyId`, `publicKey`, `validFrom` and `validUntil`, then `rpId` and `origin`. It lists the keys a rotation retired, so a receipt signed before one still checks. The review page, the public verification page and the agent's `receipt_keys_url` link it. The earlier one-key file stays at `GET /approval/receipt-key.json` (route `verified_human_approval_receipt_key`, `verified-human-approval/receipt-key/v1`, the current key only), which the agent's `receipt_key_url` names. Both need the access-control rule above to be reachable without signing in. Anyone can save them and check later receipts against that copy.

An administrator's export of other people's records is your application's to build, with its own owner and read checks; the demo's audit page shows one. Follow the [evidence guide](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md) to check a receipt offline:

```bash
vendor/bin/verify-evidence.php receipt-REQUEST_ID.json receipt-keys.json
```

Add the approving passkey's trusted registration record as a third file to check the passkey signature as well; without it, the output says that signature was not checked.

## Before deployment

Keep the application database and signing keys persistent across deploys. Restore and back up them deliberately; generating a replacement key on every boot breaks trust in earlier records. Protect your signing key separately from database write access. To replace the key on purpose, [rotate it](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md#rotate-the-signing-key): the old public key stays trusted for what it signed. Keep a checkpoint of the audit log's head outside the application on a schedule, with `verified-human-approval:audit-head`.

The browser rate limiter defaults to 30 attempts per person per minute. Override the `verified_human_approval` framework limiter or point `rate_limiter` to your own `RateLimiterFactoryInterface` service. The phone handoff uses `cache.app` and a lock; multiple web servers need a shared cache and a shared lock store configured through `lock_factory`. Its default file lock is local to one host.

The phone link is single-use and lasts two minutes. Opening it shows a Continue button; Continue redeems it and creates a session for that request only. It grants no general login and cannot cancel a request. App-only tool visibility is a client convention; the server still checks ownership and read permission.

Verify the [worked example's acceptance checks](first-action.md#8-check-the-integration) with your actual account and storage adapters. The repository's Symfony suite can be run from its root with `composer test -- --testsuite symfony`. Protocol tests do not replace checks with the browsers, authenticators and MCP clients you plan to support.
