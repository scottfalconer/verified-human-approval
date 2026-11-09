# Add Verified Human Approval to Symfony

The bundle lets an MCP tool request an action, send the person to a review page on your site, and read the result. The person approves the frozen proposal with a passkey. Your application checks current permissions, executes the stored action, and signs its receipt in the same database transaction.

**The supplied store supports SQLite only.** The business write, account and delegation checks, approval records, credential updates and receipts must use **one shared PDO connection**. The approval service owns the outer transaction; do not call it inside an existing transaction. A separate Doctrine connection, another database or an external API call is not included automatically. For another database, implement and test the `ApprovalStore` transaction contract before relying on this guarantee.

For a runnable application, start with the [demo guide](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/README.md). To integrate your own application, follow this page and the [first protected action](first-action.md). That example includes the action, identity adapter, current permission checks, database setup, signing keys and service definitions.

## What you need to supply

| Part | Responsibility |
| --- | --- |
| Existing application | Browser login, authenticated users and tenants, personal MCP authentication, live permissions and delegation records |
| Your action adapter | Exact proposed change, stale-data checks, guarded mutation and postconditions |
| Your policy provider | Whether approval is required, the statement and its version |
| Your account process | Trusted passkey enrollment and recovery; an agent token cannot use it |
| This bundle | Review routes and templates, CSRF checks, rate limiting, action discovery, MCP status/card/handoff services |
| Core and WebAuthn packages | Proposal binding, WebAuthn verification, transactional execution and signed receipts |
| Demo only | Its accounts, OAuth server, administrator forms, audit pages and evidence-download controller |

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
            "verified-human-approval/symfony-bundle": "dev-main"
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

`--no-scripts` lets you add the required configuration before Symfony boots. Commit the resulting Composer files. Keep the checked-out source available when installing from that lock file. There is no published Flex recipe for this bundle; register it and configure it explicitly.

## 2. Enable and configure the bundle

Add these entries to `config/bundles.php`, keeping the application's other bundles:

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
    attempts_per_minute: 30
```

`receipt_signer` is required for the stored-record tamper evidence described in this guide. It signs the receipt before commit. A signing failure rolls the action back. Store the private signing key outside the database, and retain a trusted copy of its public key separately from exported evidence.

`public_origin` is a fixed, trusted setting such as `https://app.example.com`. Use it for the WebAuthn configuration too. Do not derive it from a request's Host header. Choose the origin and RP ID before enrolling passkeys. HTTP `localhost` is allowed for local development.

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

Action adapters are discovered when their services are autoconfigured. If you configure services manually, tag each adapter `verified_human_approval.action`. The bundle provides autowiring aliases for `ApprovalService`, `ExecutionGuard`, `ActorProvider`, `ApprovalPresenter` and `ApprovalFlow`. Its default clock is Symfony's `clock` service; `verified_human_approval.clock` points to that same clock.

## 3. Connect existing authentication

Keep the review routes on your application's normal session firewall. Keep `/mcp` on a separate stateless firewall that authenticates a personal bearer token on every request. Your MCP authenticator must pass its **validated** `Mcp\Server\Authorization\AccessToken` to the SDK as the request attribute named `AccessToken::class`.

The [example identity adapter](first-action.md#3-map-the-authenticated-person) specifies the principal fields and token claims it expects. It never accepts a user ID or tenant ID from tool arguments.

`ActorProvider` has three jobs:

- `current()` returns the authenticated account, tenant and optional delegation ID.
- `interactive()` also requires the trusted browser account flow. An agent credential must fail this check.
- `assertCanReadApproval()` checks current read authority before a stored result is exposed, including any delegated read scope.

Execution authority is separate. `Authorizer::assertAllowed()` reloads current account and delegation permissions inside the final transaction. A person may lose permission to publish while retaining permission to read the reason a request stopped.

For the optional phone handoff, add this rule **before broader access-control rules**, keeping your existing rules and firewalls:

```yaml
# Merge into config/packages/security.yaml
security:
    access_control:
        - { request_matcher: verified_human_approval.review_request_matcher, roles: PUBLIC_ACCESS }
        # Keep the application's existing rules after this entry.
```

The matcher covers only bundle review and handoff routes. The controller still requires the signed-in owner or a session scoped to one request; approval still requires that person's passkey. It does not make your enrollment, administration or evidence-export routes public.

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

Override templates in `templates/bundles/VerifiedHumanApprovalBundle/`. `base.html.twig` offers `header`, `site_name` and `footer`; `review.html.twig` offers `preview` and `return`. Render the preview from the **frozen `prepared.effect`**, as the example does. Do not reload today's draft into an older review page.

Give every action a clear `display.title` and `display.consequence`: what will change, and what happens as soon as approval completes. Keep the exact statement, expiry, passkey button and truthful completion state visible. If a response is lost after submitting approval, ask the person to reload and check the recorded state; do not claim either success or failure without it. An optional `RequesterLabel` service can name the agent connection on the sheet; set its service ID in `requester_label`.

## Stored receipts and later verification

The signed receipt protects the historical proposal, proof and execution report against later alteration. It does not claim that today's live document still equals the document published then. A normal `approval_status` read does not perform full cryptographic verification.

Use the original receipt when exporting evidence. Do not create a fresh signature over a record at download time to claim it was unchanged since approval. The bundle does not supply an evidence-download controller; the demo has one, and your application must apply its own owner/read checks to any export. Follow the [evidence guide](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md) for export, independently trusted keys and offline verification.

## Before deployment

Keep the application database and signing keys persistent across deploys. Restore and back up them deliberately; generating a replacement key on every boot breaks trust in earlier records. Protect your signing key separately from database write access.

The browser rate limiter defaults to 30 attempts per person per minute. Override the `verified_human_approval` framework limiter or point `rate_limiter` to your own `RateLimiterFactoryInterface` service. The phone handoff uses `cache.app` and a lock; multiple web servers need a shared cache and a shared lock store configured through `lock_factory`. Its default file lock is local to one host.

The phone link is single-use and lasts two minutes. Opening it shows a Continue button; Continue redeems it and creates a session for that request only. It grants no general login and cannot cancel a request. App-only tool visibility is a client convention; the server still checks ownership and read permission.

Verify the [worked example's acceptance checks](first-action.md#8-check-the-integration) with your actual account and storage adapters. The repository's Symfony suite can be run from its root with `composer test -- --testsuite symfony`. Protocol tests do not replace checks with the browsers, authenticators and MCP clients you plan to support.
