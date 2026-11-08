# Verified Human Approval for Symfony (`verified-human-approval/symfony-bundle`)

A thin Symfony integration for the shared PHP approval engine. An existing MCP tool requests a protected local action, returns a review URL, and later reads the result. The application validates a fresh passkey assertion and executes the frozen proposal in its own database transaction.

This package does not create an OAuth server, replace application permissions or secure arbitrary writes automatically. Register a precise action and put the mandatory execution guard at every supported mutation entry point for that action.

## Run the reference application

The repository’s [demo](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/README.md) is a complete Symfony application using this bundle: Riverside Council’s public alerts site. From the repository root, with PHP 8.3+ and Composer:

```bash
composer install
php demo/bin/setup.php
php -S localhost:8080 -t demo/public demo/public/index.php
```

Setup creates private local state under `var/demo` and displays random development passwords and personal MCP tokens once. No plaintext account password or personal token is stored. Keep those credentials privately. The example is a local development application, not a production OAuth implementation.

1. Open `http://localhost:8080/login` and sign in as `editor` using the generated password.
2. Open the editor's `passkey_link` from setup's output, then register a passkey through the browser's prompt. A password alone cannot add an approval passkey: the first one needs this one-time link, and any further one a confirmation with an existing passkey.
3. Optionally sign in as `manager`, the administrator, and open **Administrator settings** to choose which roles must verify and to edit the required statement.
4. Choose **Issue alert** on the page, or connect an MCP client to `http://localhost:8080/mcp` using the editor's personal bearer token.
5. Open the returned review URL, inspect the final alerts page and the statement, and choose **Approve with your passkey**.
6. Read `approval_status` using the returned `requestId`. The application has already committed the stored action; this status tool does not execute it.

The demo provides `publish_alert`, `read_alert` and `approval_status`, plus the card's app-only `approval_handoff`. Its alert ID is `riverside-evacuation`. Under the starting rule, `manager` issues alerts directly, `editor` must approve each exact alert with a passkey, and `reader` can read but not issue them. The `other` account belongs to another tenant and cannot read or approve the first tenant's requests.

`VERIFIED_HUMAN_APPROVAL_RUNTIME` selects another private runtime directory. `VERIFIED_HUMAN_APPROVAL_ORIGIN` sets the initial origin during setup; choose a stable HTTPS origin before enrolling real passkeys for a hosted demonstration. HTTP `localhost` is the development exception. Hostname, WebAuthn origin and RP ID must agree. The bundled PHP development server is for local use.

The packages are not published on Packagist yet. The repository’s root `composer.json` replaces them, so the demo and the tests run from a checkout; the README shows how another application installs them from a path repository.

## Add the bundle to an existing application

Enable the existing MCP Bundle and this bundle, along with normal FrameworkBundle, SecurityBundle and TwigBundle:

```php
return [
    Symfony\AI\McpBundle\McpBundle::class => ['all' => true],
    VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle::class => ['all' => true],
];
```

Configure the host service adapters explicitly. These are service IDs, not user-controlled request fields:

```yaml
# config/packages/verified_human_approval.yaml
verified_human_approval:
    public_origin: '%env(APP_ORIGIN)%'
    actor_provider: App\Approval\ApplicationActorProvider
    policy_provider: App\Approval\ApplicationPolicyProvider
    authorizer: App\Approval\CurrentAuthority
    store: VerifiedHumanApproval\Core\Storage\PdoApprovalStore
    verifier: VerifiedHumanApproval\WebAuthn\WebAuthnVerifier
    attempts_per_minute: 30
    # Optional but recommended: sign each execution's receipt inside the completing transaction.
    receipt_signer: VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner
```

With `receipt_signer` set, a later edit to an approval's record no longer matches its receipt, and if signing fails nothing executes. To show who made a request on the approval sheet, implement `VerifiedHumanApproval\Bundle\RequesterLabel` and set `requester_label` to its service id (a service aliased to the interface is used when the key is unset). For example, it can name the agent connection, such as "Claude (claude.ai), an agent you connected to your account". `clock` defaults to the framework's `clock` service, so a clock you swap in for tests reaches the approvals too.

Import the review routes:

```yaml
# config/routes/verified_human_approval.yaml
verified_human_approval:
    resource: '@VerifiedHumanApprovalBundle/config/routes.php'
```

Configure the normal MCP endpoint and select your tools:

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
                    - App\Mcp\PublishAlertTool
                    - VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool
                    - VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool   # the card's phone code; conforming hosts hide it from the model
                apps:
                    - VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp
            session:
                lock: { enabled: true }                 # the card polls approval_status while the model calls tools
            request_state:
                key: '%env(MCP_REQUEST_STATE_KEY)%'     # signs the URL prompt's retry; its own key, at least 32 bytes
                ttl: 900                                # longer than your approval lifetime, so a retry after a slow approval still reads the outcome
```

Keep your existing MCP route import (`resource: '.', type: mcp`). Handshake-era clients such as Claude Desktop and claude.ai keep a session, and the card polls it while the model calls tools, so turn the MCP bundle's session lock on. The request-state TTL should outlive your approval lifetime: an expired state makes the retry fail with a protocol error, which the model recovers from by calling `approval_status` with the request id named in the prompt. Register action adapters as autoconfigured services; the bundle collects implementations of `VerifiedHumanApproval\Core\ActionAdapter` into its registry (or tag them `verified_human_approval.action`).

Publish the review page's script and stylesheet from the bundle's `public/` directory. Symfony Flex runs this after every `composer install`; without Flex, run it yourself:

```bash
php bin/console assets:install
```

### Services

The bundle defines its services explicitly under `verified_human_approval.` ids, and aliases the classes you inject to them, so autowiring keeps working:

| Inject | Service id | What it is |
| --- | --- | --- |
| `VerifiedHumanApproval\Core\ApprovalService` | `verified_human_approval.approval_service` | Requests, reviews, completes and cancels approvals |
| `VerifiedHumanApproval\Mcp\ApprovalFlow` | `verified_human_approval.flow` | Presents a protected tool's result: the card, the link and the optional URL prompt |
| `VerifiedHumanApproval\Core\ExecutionGuard` | `verified_human_approval.execution_guard` | Call `assertExecuting()` in every code path that performs a protected mutation |
| `VerifiedHumanApproval\Bundle\ActorProvider` | `verified_human_approval.actor_provider` | Your actor provider, under its alias |
| `VerifiedHumanApproval\Bundle\ApprovalPresenter` | `verified_human_approval.presenter` | The result shape for MCP and the browser |
| — | `verified_human_approval.review_request_matcher` | Matches the review routes, for `access_control` |
| — | `verified_human_approval.status_tool`, `.handoff_tool`, `.card_app` | The MCP elements, tagged `mcp.tool` and `mcp.app` |

The review routes need `framework.csrf_protection` on, and they carry the route default `_verified_human_approval_headers`, which sets a strict content security policy, `frame-ancestors 'none'`, `no-store` and `no-referrer`. Set the same default on your own sensitive pages, such as passkey enrollment and administration:

```yaml
account_passkeys:
    path: /account/passkeys
    controller: App\Controller\PasskeyController::index
    defaults: { _verified_human_approval_headers: true }
```

The bundled review controller uses a trusted interactive account session and CSRF protection. Put its routes behind the application's normal session firewall. Keep a separate stateless firewall for personally authenticated MCP requests. An agent bearer token must not qualify as an interactive browser account or enroll approval credentials. The reference application's `DemoKernel` shows this separation using standard Symfony Security firewalls and a form login.

Configure Symfony trusted hosts, HTTPS/session cookie policy and the application's existing OAuth integration for its real environment. `public_origin` is configured explicitly; review links are never assembled from arbitrary forwarded host headers.

## Map the current account

Implement `VerifiedHumanApproval\Bundle\ActorProvider`:

```php
interface ActorProvider
{
    public function current(): VerifiedHumanApproval\Core\Actor;
    public function interactive(): VerifiedHumanApproval\Core\Actor;
    public function assertCanReadApproval(VerifiedHumanApproval\Core\ApprovalState $state): void;
}
```

`current()` derives the user, tenant and optional delegation reference from the authenticated Symfony principal. `interactive()` also requires your trusted human account flow. It must reject the ordinary agent credential. Neither method reads a claimed user ID from MCP arguments or request JSON.

`assertCanReadApproval()` checks the current read grant before exposing a stored result. Ownership by itself is insufficient when different personal connections have different scopes. The status tool calls this after the core verifies ownership. Custom tools that return prior outcomes through an idempotency lookup must check read authority too. The demo uses a separate `approval.read` scope and rechecks it for publication retries.

The core `Authorizer` is a different interface: it checks the initiating actor's **current execution authority** within the final transaction. Reload the account and delegated grant; do not reuse roles captured when the proposal was created. A user may still read a denial after losing publication permission.

## Wrap a normal MCP tool

The MCP integration is ordinary SDK tool code:

```php
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;
use VerifiedHumanApproval\Bundle\ActorProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;

final readonly class PublishAlertTool
{
    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private ApprovalFlow $flow,
    ) {}

    #[McpTool(name: 'publish_alert', meta: ApprovalCard::TOOL_META)]
    public function publish(RequestContext $context, string $alert_id, ?string $request_key = null): CallToolResult|InputRequiredResult
    {
        // Apply the host's delegated scope/read checks here, as in DemoTools.
        $actor = $this->actors->current();

        // The flow returns the card's result, or for an opted-in client a URL-mode elicitation.
        return $this->flow->respond(
            $context, $actor, 'publish_alert', ['alert_id' => $alert_id, 'request_key' => $request_key],
            fn (): ApprovalState => $this->approvals->request($actor, 'alert.publish', ['alert_id' => $alert_id], $request_key),
        );
    }
}
```

The presenter returns `requestId`, `status`, `reason` and `result`, plus the card's view under `approval`. A pending result also has `review_url` and a message explaining the browser handoff. Pending approval is a normal tool outcome, not an HTTP authentication failure. URL-mode elicitation is optional and opt-in per client (see below). It opens the same URL without changing the verification boundary.

The completion route accepts a ceremony identifier and a WebAuthn response only. It does not accept replacement content or an execution token. The original MCP request is not held open while the person reads the page.

## Register an action and protect its mutation

The reusable core's `ActionAdapter` separates preparation, preconditions, execution and postconditions. The demo adapter is `demo/src/Action/PublishAlert.php`.

It freezes:

- Application, environment, tenant and target alert.
- Source revision, title and body.
- Current published-revision precondition and intended published state.
- Action behavior version, policy version and exact resolved statement.

`AlertPublisher::publish()` calls `ExecutionGuard::assertExecuting()` before its conditional SQL update. The execution context exists only while the core is applying a validated stored action. Calling the same publisher directly without that context fails. Browser publication and MCP publication both enter through `ApprovalService`.

An action's execution and postconditions must use the **same transaction and PDO connection** as approval consumption, credential counter updates and the execution record. The supplied `PdoApprovalStore` is a SQLite reference adapter. It does not automatically enlist a different ORM connection, remote database, email send or downstream MCP call. Integrate another database by implementing the store's transaction contract and running its concurrency/rollback tests.

Publication is distinct from draft editing. Saving a new draft does not change published fields and invalidates any pending approval bound to the prior draft revision. Meaning-changing hooks must run before freezing, or be covered by postconditions before transaction commit.

## Passkeys, policies and review screens

The core depends on `ApprovalVerifier`; the separate WebAuthn package supplies `WebAuthnVerifier`, using the existing PHP WebAuthn library. It requires user verification and validates credential ownership, origin, RP ID, challenge and signature. User verification can be a biometric, PIN or other authenticator verification method; it does not prove fingerprint-only use.

Credential registration belongs to the application's trusted account process, and a password alone must not be enough for it. The demo handles a person's passkeys like this:
- **First passkey:** needs a one-time link, issued at setup or by an administrator confirming with their own passkey.
- **Further passkeys:** need a fresh confirmation with an existing passkey.
- **Every registration** is recorded with its public key in the core's signed `AuditLog`. The verifier uses `AnchoredCredentialRepository`, so only passkeys with a signed enrollment record can approve.

Its MCP token cannot reach enrollment, policy administration or the audit page.

The demo administrator form uses Symfony Form and Validator. For each role it sets whether that role issues alerts directly, must approve each exact alert with a passkey, or may not issue them, plus a plain-text statement template with known placeholders.

Saving does not change anything by itself. It proposes a `policy.change` action, which the administrator approves with their own passkey like any other, and its policy is fixed in code. Approving it increments the policy version. A role set to issue directly lets **new** authorized requests from that role execute without proof. It cannot make an older pending proposal eligible under its previous policy. Your own `PolicyProvider` receives the requesting `Actor`, so the rule can depend on whatever your application knows about the person.

The review templates live in the bundle's `templates/` directory, and its browser script, published by `assets:install`, uses `navigator.credentials.create()` / `navigator.credentials.get()`. Override them the usual way, in `templates/bundles/VerifiedHumanApprovalBundle/`, keeping the full frozen action and the required statement: `base.html.twig` has `header`, `site_name` and `footer` blocks for your layout, and `review.html.twig` has `preview`, to show the effect as its audience will see it, and `return`, the way back to the agent. The page reads two keys of the action's `display`: `title` for its heading and `consequence`, shown next to the button while the request waits, for what happens the moment it is approved. A refusal on the JSON routes, such as a stale CSRF token or a ceremony from another page, is a JSON answer with a `reason` the page shows; a refusal about a request also carries its `request_status`, and when that is no longer `pending` the page reloads to show the request as finished or stopped. When the connection drops after the passkey response is sent, the page says it cannot confirm the outcome and asks the person to reload; it never guesses.

The browser approval budget is an ordinary framework rate limiter named `verified_human_approval`: 30 attempts per person per minute across all requests, set by `attempts_per_minute`. It uses the framework's `cache.rate_limiter` pool and, when `framework.lock` is on, its lock. Override any of it in your own configuration, per environment too:

```yaml
framework:
    rate_limiter:
        verified_human_approval: { policy: sliding_window, limit: 10, interval: '1 minute' }
```

Or point `rate_limiter` at a `RateLimiterFactoryInterface` service of your own. The phone code's single redemption takes a lock from `lock_factory`: by default a file lock on this host; set it to `lock.factory` with a shared store when several hosts serve the application, and give them a shared `cache.app` for the codes.

## Evidence and validation

The demo provides an owner-checked evidence download at `/approval/{requestId}/evidence`. It signs the already committed execution record with a generated application key. Signing failure cannot execute the action again. The separate verifier requires independently trusted credential and application keys; keys embedded in a downloaded bundle are not automatically trusted.

Run the integration tests from the repository root:

```bash
composer test -- --testsuite symfony
```

They use the real Symfony kernel, session firewall, CSRF handling, MCP Bundle, SDK HTTP transport and genuine synthetic ES256 assertions validated by the production WebAuthn bridge. They check pending/complete/status, unchanged publication, stale content, current delegation, read scopes, administrator forms, account/tenant isolation, credential enrollment separation and the mutation guard.

Synthetic authenticators prove the protocol and server checks. Real Mac Touch ID, iPhone Face ID and external Claude/ChatGPT client runs are separate integration checks and are not claimed by these tests.

## Approval card and native prompt

Pending results carry a view for the shared MCP App approval card. To show it, add `VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp` to your MCP server's `registry.apps` list, `VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool` to `registry.tools`, and give each protected tool `meta: ApprovalCard::TOOL_META`. The app serves the card resource and enables the MCP Apps extension through the MCP bundle's `#[AsMcpApp]`; the phone-code tool is an ordinary `#[McpTool]` whose `_meta.ui` links it to the card with app-only visibility, so it carries annotations and an output schema like every other tool. Every tool of this bundle declares `title`, `outputSchema` and `openWorldHint: false`; declare them on your protected tools too, with `outputSchema: ApprovalFlow::OUTPUT_SCHEMA`.

A protected tool takes the SDK's `RequestContext` and returns `ApprovalFlow::respond()`. To also send a URL-mode elicitation to clients you have tested:

```php
'verified_human_approval' => [
    // ...
    'url_elicitation' => ['enabled' => true, 'clients' => ['claude-code']],
],
```

It is off by default, applies only under MCP 2026-07-28 or later, and its retry is bound to the same caller, tool and arguments. The demo enables it for Claude Code. See [MCP presentation](https://github.com/scottfalconer/verified-human-approval/blob/main/src/mcp/README.md).

### Approve on your phone

When `public_origin` is HTTPS, the card also offers **Approve on your phone**. It calls the app-only `approval_handoff` tool, which issues a one-time link to `/approval/handoff/{token}` (single use, two minutes) and returns it as QR modules that the card draws itself.
- **Opening the link** shows a Continue button. A link previewer fetching the URL cannot use the code up.
- **Continue** redeems the code once, under a lock, and starts a session scoped to that one request's review, for the same person.
- **That session** is not a sign-in. It reaches no other page, it cannot cancel the request (cancelling needs the signed-in person), and approving still takes the person's passkey.

The tool applies the same read check as `approval_status` and a per-person budget. "App only" visibility is a convention hosts follow, not a server boundary, so treat the tool as callable by any client holding the person's token.

Your firewall must let the phone link and the review routes through without a sign-in. The bundle's request matcher names exactly those routes, so this one line does it, and never widens to routes of your own:

```yaml
security:
    access_control:
        - { request_matcher: verified_human_approval.review_request_matcher, roles: PUBLIC_ACCESS }
        # ... your other rules
```

The controller then requires either the signed-in person or that scoped session. Codes are kept in `cache.app`, so several web servers need a shared cache pool.
