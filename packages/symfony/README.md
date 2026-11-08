# Exact Approval for Symfony

A thin Symfony integration for the shared PHP approval engine. An existing MCP tool requests a protected local action, returns a review URL, and later reads the result. The application validates a fresh passkey assertion and executes the frozen proposal in its own database transaction.

This package does not create an OAuth server, replace application permissions or secure arbitrary writes automatically. Register a precise action and put the mandatory execution guard at every supported mutation entry point for that action.

## Run the reference application

From the repository root, with PHP 8.3+ and Composer:

```bash
composer install
php examples/symfony/bin/setup.php
php -S localhost:8080 -t examples/symfony/public examples/symfony/public/index.php
```

Setup creates private local state under `var/demo` and displays random development passwords and personal MCP tokens once. No plaintext account password or personal token is stored. Keep those credentials privately. The example is a local development application, not a production OAuth implementation.

1. Open `http://localhost:8080/login` and sign in as `editor` using the generated password.
2. Open **Your passkeys** and register a passkey through the native browser prompt.
3. Optionally open **Administrator settings** to edit the required statement.
4. Request publication from the page, or connect an MCP client to `http://localhost:8080/mcp` using the editor's personal bearer token.
5. Open the returned review URL, inspect the frozen content and statement, and choose **Approve and execute**.
6. Read `approval_status` using the returned `requestId`. The application has already committed the stored action; this status tool does not execute it.

The demo provides `publish_announcement`, `read_announcement` and `approval_status`. Its announcement ID is `summer-campaign`. The `reader` account has read access but cannot publish. The `other` account belongs to another tenant and cannot read or approve the first tenant's requests.

`EXACT_APPROVAL_RUNTIME` selects another private runtime directory. `EXACT_APPROVAL_ORIGIN` sets the initial origin during setup; choose a stable HTTPS origin before enrolling real passkeys for a hosted demonstration. HTTP `localhost` is the development exception. Hostname, WebAuthn origin and RP ID must agree. The bundled PHP development server is for local use.

The source packages are provided in this repository; they have not been published to Packagist. Its root Composer path repositories make them installable together.

## Add the bundle to an existing application

Enable the existing MCP Bundle and this bundle, along with normal FrameworkBundle, SecurityBundle and TwigBundle:

```php
return [
    Symfony\AI\McpBundle\McpBundle::class => ['all' => true],
    ExactApproval\Symfony\ExactApprovalBundle::class => ['all' => true],
];
```

Configure the host service adapters explicitly. These are service IDs, not user-controlled request fields:

```yaml
# config/packages/exact_approval.yaml
exact_approval:
    public_origin: '%env(APP_ORIGIN)%'
    actor_provider: App\Approval\ApplicationActorProvider
    policy_provider: App\Approval\ApplicationPolicyProvider
    authorizer: App\Approval\CurrentAuthority
    store: ExactApproval\Core\Storage\PdoApprovalStore
    verifier: ExactApproval\WebAuthn\WebAuthnVerifier
    attempts_per_minute: 30
```

Import the review routes. The directory depends on whether you install through Composer or a local path repository:

```yaml
# config/routes/exact_approval.yaml
exact_approval:
    resource: '@ExactApprovalBundle/config/routes.php'
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
                    - App\Mcp\PublishAnnouncementTool
                    - ExactApproval\Symfony\Mcp\ApprovalStatusTool
```

Keep your existing MCP route import (`resource: '.', type: mcp`). Register action adapters as autoconfigured services; the bundle collects implementations of `ExactApproval\Core\ActionAdapter` into its registry.

The bundled review controller uses a trusted interactive account session and CSRF protection. Put its routes behind the application's normal session firewall. Keep a separate stateless firewall for personally authenticated MCP requests. An agent bearer token must not qualify as an interactive browser account or enroll approval credentials. The reference application's `DemoKernel` shows this separation using standard Symfony Security firewalls and a form login.

Configure Symfony trusted hosts, HTTPS/session cookie policy and the application's existing OAuth integration for its real environment. `public_origin` is configured explicitly; review links are never assembled from arbitrary forwarded host headers.

## Map the current account

Implement `ExactApproval\Symfony\ActorProvider`:

```php
interface ActorProvider
{
    public function current(): ExactApproval\Core\Actor;
    public function interactive(): ExactApproval\Core\Actor;
    public function assertCanReadApproval(ExactApproval\Core\ApprovalState $state): void;
}
```

`current()` derives the user, tenant and optional delegation reference from the authenticated Symfony principal. `interactive()` also requires your trusted human account flow. It must reject the ordinary agent credential. Neither method reads a claimed user ID from MCP arguments or request JSON.

`assertCanReadApproval()` checks the current read grant before exposing a stored result. Ownership by itself is insufficient when different personal connections have different scopes. The status tool calls this after the core verifies ownership. Custom tools that return prior outcomes through an idempotency lookup must check read authority too. The demo uses a separate `approval.read` scope and rechecks it for publication retries.

The core `Authorizer` is a different interface: it checks the initiating actor's **current execution authority** within the final transaction. Reload the account and delegated grant; do not reuse roles captured when the proposal was created. A user may still read a denial after losing publication permission.

## Wrap a normal MCP tool

The MCP integration is ordinary SDK tool code:

```php
use ExactApproval\Core\ApprovalService;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\Symfony\ApprovalPresenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;

final readonly class PublishAnnouncementTool
{
    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private ApprovalPresenter $presenter,
    ) {}

    #[McpTool(name: 'publish_announcement')]
    public function publish(string $announcement_id, string $request_key): CallToolResult
    {
        // Apply the host's delegated scope/read checks here, as in DemoTools.
        $state = $this->approvals->request(
            $this->actors->current(),
            'announcement.publish',
            ['announcement_id' => $announcement_id],
            $request_key,
        );

        $this->actors->assertCanReadApproval($state);

        return $this->presenter->toolResult($state);
    }
}
```

The presenter returns `requestId`, `status`, `reason` and `result`. A pending result also has `review_url` and a message explaining the browser handoff. Pending approval is a normal tool outcome, not an HTTP authentication failure. Native URL elicitation is not required by this implementation; client-specific elicitation can later open the same URL without changing the verification boundary.

The completion route accepts a ceremony identifier and a WebAuthn response only. It does not accept replacement content or an execution token. The original MCP request is not held open while the person reads the page.

## Register an action and protect its mutation

The reusable core's `ActionAdapter` separates preparation, preconditions, execution and postconditions. The demo adapter is `examples/symfony/src/Action/PublishAnnouncement.php`.

It freezes:

- Application, environment, tenant and target announcement.
- Source revision, title and body.
- Current published-revision precondition and intended published state.
- Action behavior version, policy version and exact resolved statement.

`AnnouncementPublisher::publish()` calls `ExecutionGuard::assertExecuting()` before its conditional SQL update. The execution context exists only while the core is applying a validated stored action. Calling the same publisher directly without that context fails. Browser publication and MCP publication both enter through `ApprovalService`.

An action's execution and postconditions must use the **same transaction and PDO connection** as approval consumption, credential counter updates and the execution record. The supplied `PdoApprovalStore` is a SQLite reference adapter. It does not automatically enlist a different ORM connection, remote database, email send or downstream MCP call. Integrate another database by implementing the store's transaction contract and running its concurrency/rollback tests.

Publication is distinct from draft editing. Saving a new draft does not change published fields and invalidates any pending approval bound to the prior draft revision. Meaning-changing hooks must run before freezing, or be covered by postconditions before transaction commit.

## Passkeys, policies and review screens

The core depends on `ApprovalVerifier`; the separate WebAuthn package supplies `WebAuthnVerifier`, using the existing PHP WebAuthn library. It requires user verification and validates credential ownership, origin, RP ID, challenge and signature. User verification can be a biometric, PIN or other authenticator verification method; it does not prove fingerprint-only use.

Credential registration belongs to the application's trusted account process. The demo illustrates this with short-lived, single-use enrollment options held in the browser session, CSRF protection, separate human login and a registration rate limiter. Its MCP token cannot access that flow or policy administration.

The demo administrator form uses Symfony Form and Validator. It permits an approval switch and a plain-text statement template with known placeholders. Saving increments the policy version. A disabled policy allows **new** authorized requests to execute without proof; it cannot make an older pending proposal eligible under its previous policy.

Twig review templates live under `packages/symfony/templates`, and the shared browser asset uses `navigator.credentials.create()` / `navigator.credentials.get()`. The controller emits no-store, frame restrictions and a CSP. Applications can override the templates through normal Twig bundle overrides, while preserving the full frozen action and required statement.

The default browser approval budget is 30 attempts per user per minute across proposal IDs. It uses Symfony RateLimiter, the application cache and a Symfony Lock backed by `FlockStore`. Override `rate_limiter` with your existing `RateLimiterFactoryInterface` service when using shared infrastructure. Multi-host deployments need coordinated persistence and limiter storage/locks; the local reference application does not supply that deployment.

## Evidence and validation

The demo provides an owner-checked evidence download at `/approval/{requestId}/evidence`. It signs the already committed execution record with a generated application key. Signing failure cannot execute the action again. The separate verifier requires independently trusted credential and application keys; keys embedded in a downloaded bundle are not automatically trusted.

Run the integration tests from the repository root:

```bash
composer test -- --testsuite symfony
```

They use the real Symfony kernel, session firewall, CSRF handling, MCP Bundle, SDK HTTP transport and genuine synthetic ES256 assertions validated by the production WebAuthn bridge. They check pending/complete/status, unchanged publication, stale content, current delegation, read scopes, administrator forms, account/tenant isolation, credential enrollment separation and the mutation guard.

Synthetic authenticators prove the protocol and server checks. Real Mac Touch ID, iPhone Face ID and external Claude/ChatGPT client runs are separate integration checks and are not claimed by these tests.
