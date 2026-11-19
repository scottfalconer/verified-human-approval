# Your first protected action

This example publishes a document's current draft. A request freezes its title, body and revision. Approval copies those exact values into separate published fields, records the result and signs the receipt in one SQLite transaction.

It assumes you already have Symfony login and a trusted personal-token authenticator for MCP. It does not create accounts, issue bearer tokens or replace your enrollment process. The identity contract below makes those connections explicit. For a complete standalone application, use the [demo](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/README.md).

Start with the [installation and route configuration](index.md). Add the files below to your application, then run the commands in [Check the integration](#8-check-the-integration). The example uses the service names from that guide.

## 1. Use one database connection

These are the small application tables the example expects. In an existing application, map the queries below to your real account, grant and document tables on the same PDO connection. Keep permission changes in those authoritative records; do not maintain an independent permission copy that can become stale.

For a new application using this example schema, save it as `migrations/approval-example.sql` and apply it through your normal migration process:

```sql
CREATE TABLE app_accounts (
    id TEXT NOT NULL,
    tenant_id TEXT NOT NULL,
    enabled INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    can_publish INTEGER NOT NULL CHECK (can_publish IN (0, 1)),
    PRIMARY KEY (id, tenant_id)
);

CREATE TABLE app_agent_grants (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    tenant_id TEXT NOT NULL,
    active INTEGER NOT NULL CHECK (active IN (0, 1)),
    expires_at INTEGER,
    scopes TEXT NOT NULL,
    FOREIGN KEY (user_id, tenant_id) REFERENCES app_accounts (id, tenant_id)
);

CREATE TABLE app_documents (
    id TEXT NOT NULL,
    tenant_id TEXT NOT NULL,
    revision INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    published_revision INTEGER,
    published_title TEXT,
    published_body TEXT,
    mutation_count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (id, tenant_id)
);
```

Your account and connection management code creates real account/grant rows. `scopes` is a JSON list such as `["document.publish","approval.read"]`; `expires_at` is a Unix timestamp or `NULL`. Token validation must enforce the stored grant and expiry. Draft editing increments `revision` and leaves the published fields alone. The public document page reads the published fields.

```php
<?php
// src/Approval/Database.php

declare(strict_types=1);

namespace App\Approval;

final class Database
{
    public static function connect(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }
}
```

The package's PDO stores and credential repository use this connection as it is and change none of its settings. They refuse one that does not throw on errors, and an approval waits for another writer for as long as the busy timeout set here allows: ten seconds.

Do not create another PDO in the action or credential repository. Do not wrap `ApprovalService` calls in a transaction: `PdoApprovalStore` begins the outer transaction itself, with `PDO::beginTransaction()`, and refuses to begin inside another one. Its first statement is a write that changes nothing, which takes SQLite's write reservation before anything is read, as `BEGIN IMMEDIATE` would, and holds it until the transaction ends. Your action must not end that transaction either: once code on the connection commits or rolls it back, the store refuses every later write, so the approval records no outcome and the request stays pending, even when the action's own commit has already made its change.

This example uses the SQLite PDO store. On Doctrine (SQLite, PostgreSQL, MySQL or MariaDB), use your DoctrineBundle connection instead of this PDO, let the bundle wire the storage with `persistence: { driver: doctrine }` as [Storage on Doctrine](index.md#storage-on-doctrine) describes, and lock what your authorizer and action read with `DbalTransaction::forShare()` and `forUpdate()`.

## 2. Configure keys and services

Use a persistent application signing key. This one-time helper writes URL-safe base64 without padding, the format accepted by `SodiumEvidenceSigner::fromEncoded()`. Its 64-byte Ed25519 secret key is not a password or an arbitrary random string.

```php
<?php
// bin/create-approval-key.php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use VerifiedHumanApproval\Core\ChallengeBinding;

$path = $argv[1] ?? throw new InvalidArgumentException('Pass an output filename.');
umask(0077);
$file = fopen($path, 'x'); // Refuse to replace an existing key file.
if (false === $file) {
    throw new RuntimeException('Could not create the key file.');
}
$keyPair = sodium_crypto_sign_keypair();
$settings = implode("\n", [
    'APPROVAL_RECEIPT_KEY_ID=application-1',
    'APPROVAL_RECEIPT_SECRET_KEY='.ChallengeBinding::encode(sodium_crypto_sign_secretkey($keyPair)),
    'APPROVAL_RECEIPT_PUBLIC_KEY='.ChallengeBinding::encode(sodium_crypto_sign_publickey($keyPair)),
    'MCP_REQUEST_STATE_KEY='.bin2hex(random_bytes(32)),
])."\n";
if (strlen($settings) !== fwrite($file, $settings)) {
    throw new RuntimeException('Could not write the key file.');
}
fclose($file);
```

For local development, create the file once and add its values to Symfony's ignored `.env.local` file:

```bash
mkdir -p var/approval
chmod 700 var/approval
php bin/create-approval-key.php var/approval/key.env
cat var/approval/key.env >> .env.local
chmod 600 .env.local
```

Use your deployment's secret store for production. Keep the private key outside the database and web root, back it up, and keep its public key and ID in a separately trusted record for later verification: you need them again to retire the key. The bundle also publishes that public key, with any keys it later replaces, at `/approval/receipt-keys.json`, so people can check the receipts they download. Do not generate keys during application boot or normal deployments. To change the key later, [rotate it](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md#rotate-the-signing-key), so what the old key signed keeps verifying.

Add these application settings, choosing the actual absolute database path:

```dotenv
# .env.local: local example only
APP_ORIGIN=http://localhost:8080
APP_RP_ID=localhost
APP_SQLITE_PATH=/absolute/path/to/your-app/var/application.sqlite
```

Use the same host in the MCP `allowed_hosts` configuration. For a hosted application, choose a stable HTTPS origin and corresponding RP ID before enrolling credentials.

Merge these service definitions into `config/services.yaml`. If your existing `App\` resource already registers these classes with autowiring and autoconfiguration, keep that resource instead of adding overlapping ones.

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Approval\:
        resource: '../src/Approval/'

    App\Mcp\:
        resource: '../src/Mcp/'

    PDO:
        class: PDO
        factory: ['App\Approval\Database', 'connect']
        arguments: ['%env(APP_SQLITE_PATH)%']

    VerifiedHumanApproval\Core\Storage\PdoApprovalStore: ~
    VerifiedHumanApproval\Core\Storage\PdoAuditStore: ~
    VerifiedHumanApproval\WebAuthn\PdoCredentialRepository: ~
    VerifiedHumanApproval\Core\Audit\AuditStore:
        alias: VerifiedHumanApproval\Core\Storage\PdoAuditStore

    VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner:
        factory: ['VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner', 'fromEncoded']
        arguments:
            $id: '%env(APPROVAL_RECEIPT_KEY_ID)%'
            $encodedSecret: '%env(APPROVAL_RECEIPT_SECRET_KEY)%'

    # Entries are checked against the bundle's trusted keys: the signer's key, and any it replaced.
    VerifiedHumanApproval\Core\Audit\AuditLog:
        arguments:
            $store: '@VerifiedHumanApproval\Core\Storage\PdoAuditStore'
            $signer: '@VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner'
            $keys: '@VerifiedHumanApproval\Core\Evidence\TrustedKeys'
            $clock: '@verified_human_approval.clock'

    # Verification reads passkeys through the anchored wrapper, and the wrapper reads the raw rows.
    # Name the raw repository explicitly, so the wrapper never resolves to itself.
    VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository:
        arguments:
            $credentials: '@VerifiedHumanApproval\WebAuthn\PdoCredentialRepository'
    VerifiedHumanApproval\WebAuthn\CredentialRepository:
        alias: VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository

    VerifiedHumanApproval\WebAuthn\Configuration:
        arguments:
            $rpId: '%env(APP_RP_ID)%'
            $origin: '%env(APP_ORIGIN)%'

    VerifiedHumanApproval\WebAuthn\WebAuthnVerifier: ~

    App\Approval\CurrentAuthority:
        arguments:
            $clock: '@verified_human_approval.clock'

    App\Approval\PublishDocument:
        arguments:
            $environment: '%kernel.environment%'
```

The bundle supplies `ExecutionGuard` and `ApprovalService`; do not instantiate a second guard. All services above receive the same shared `PDO`. `WebAuthnVerifier` uses the default hashed user handle for new credentials. For an existing passkey system, supply the matching `UserHandleProvider` and credential storage described in the [WebAuthn guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/webauthn/README.md).

Create package tables as an installation or migration step. This command does not create accounts, grants or application data:

```php
<?php
// src/Approval/InitializeApprovalStorageCommand.php

declare(strict_types=1);

namespace App\Approval;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

#[AsCommand(name: 'app:approval:install', description: 'Create the approval, credential and audit tables.')]
final class InitializeApprovalStorageCommand extends Command
{
    public function __construct(
        private readonly PdoApprovalStore $store,
        private readonly PdoCredentialRepository $credentials,
        private readonly PdoAuditStore $audit,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->store->initialize();
        $this->credentials->initialize();
        $this->audit->initialize();
        $output->writeln('Approval storage is ready.');

        return Command::SUCCESS;
    }
}
```

`initialize()` creates the supplied schema. It is not a general migration/versioning system; review schema changes when upgrading. Do not call it on each HTTP request.

## 3. Map the authenticated person

Adapt your existing Symfony user class to this example interface, or change `ApplicationActorProvider` to read equivalent fields from your own class:

```php
<?php
// src/Approval/ApprovalPrincipal.php

declare(strict_types=1);

namespace App\Approval;

use Symfony\Component\Security\Core\User\UserInterface;

interface ApprovalPrincipal extends UserInterface
{
    public function approvalUserId(): string;
    public function approvalTenantId(): string;
    public function approvalDelegationId(): ?string;
    public function hasInteractiveSession(): bool;
}
```

Your existing authentication layer supplies these values from validated server-side records:

| Authentication | Principal values |
| --- | --- |
| Trusted browser login | Stable user and tenant IDs; `approvalDelegationId()` returns `null`; `hasInteractiveSession()` returns `true` |
| Personal MCP bearer | Same account/tenant; the stored grant ID; `hasInteractiveSession()` returns `false` |

`hasInteractiveSession()` describes how this request was authenticated. It is not a role the client can choose. The MCP authenticator validates the bearer, current account, grant and expiry on each request, then sets a validated `AccessToken` at the request attribute `AccessToken::class`. This example expects its `sub` claim to equal the principal's user ID and `grant_id` to equal its delegation ID: `$request->attributes->set(AccessToken::class, new AccessToken($scopes, ['sub' => $userId, 'grant_id' => $grantId]))`, where `$scopes` are the grant's stored scopes. The SDK exposes that same token through `RequestContext::getAccessToken()`.

Use the application's existing authentication code for that step. The demo's [resource-server authenticator](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/src/Security/AgentTokenAuthenticator.php) illustrates the SDK handoff; its token issuance and account model are demo-specific.

The authorizer below reads current authority from the same database as the write. Its separate read check allows a person to read a denied result after publication permission is removed.

```php
<?php
// src/Approval/CurrentAuthority.php

declare(strict_types=1);

namespace App\Approval;

use Mcp\Server\Authorization\AccessToken;
use Psr\Clock\ClockInterface;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;

final readonly class CurrentAuthority implements Authorizer
{
    public function __construct(private \PDO $pdo, private ClockInterface $clock) {}

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $account = $this->account($actor);
        if (!(bool) $account['can_publish'] || 'document.publish' !== $action->actionId
            || ($action->target['tenant_id'] ?? null) !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'This account cannot publish this document.');
        }
        if (null !== $actor->delegationId) {
            $this->assertGrant($actor, ['document.publish', 'approval.read']);
        }
    }

    public function assertCanRead(Actor $actor, ?AccessToken $token): void
    {
        $this->account($actor);
        if (null === $actor->delegationId) {
            if (null !== $token) {
                throw new ApprovalException('result_read_denied', 'An agent connection must identify its grant.');
            }

            return;
        }
        if (null === $token || !$token->hasScope('approval.read')
            || $token->getClaim('sub') !== $actor->userId
            || $token->getClaim('grant_id') !== $actor->delegationId) {
            throw new ApprovalException('result_read_denied', 'This connection cannot read approval results.');
        }
        $this->assertGrant($actor, ['approval.read']);
    }

    /** @return array<string, mixed> */
    private function account(Actor $actor): array
    {
        $query = $this->pdo->prepare('SELECT * FROM app_accounts WHERE id = ? AND tenant_id = ?');
        $query->execute([$actor->userId, $actor->tenantId]);
        $row = $query->fetch();
        if (false === $row || !(bool) $row['enabled']) {
            throw new ApprovalException('permission_denied', 'This account is not active in this tenant.');
        }

        return $row;
    }

    /** @param list<string> $requiredScopes */
    private function assertGrant(Actor $actor, array $requiredScopes): void
    {
        $query = $this->pdo->prepare('SELECT * FROM app_agent_grants WHERE id = ? AND user_id = ? AND tenant_id = ?');
        $query->execute([$actor->delegationId, $actor->userId, $actor->tenantId]);
        $row = $query->fetch();
        if (false === $row || !(bool) $row['active']
            || (null !== $row['expires_at'] && (int) $row['expires_at'] <= $this->clock->now()->getTimestamp())) {
            throw new ApprovalException('delegation_denied', 'The initiating connection is no longer authorized.');
        }
        $scopes = json_decode($row['scopes'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($requiredScopes as $scope) {
            if (!is_array($scopes) || !in_array($scope, $scopes, true)) {
                throw new ApprovalException('delegation_denied', 'The connection no longer has the required scope.');
            }
        }
    }
}
```

The service calls `assertAllowed()` as the request is made, before the action runs, and once more after it runs, before the commit. This check reads the account and the grant, which an action leaves in place. If yours reads the target itself, as a voter on the entity does, and an action can delete that target, implement `VerifiedHumanApproval\Core\ExecutedActionAuthorizer` as well: its `assertStillAllowed($actor, $action, $result)` replaces the last call, and decides from the frozen proposal and the action's result instead of loading what is gone.

```php
<?php
// src/Approval/ApplicationActorProvider.php

declare(strict_types=1);

namespace App\Approval;

use Mcp\Server\Authorization\AccessToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;

final readonly class ApplicationActorProvider implements ActorProvider
{
    public function __construct(
        private Security $security,
        private RequestStack $requests,
        private CurrentAuthority $authority,
    ) {}

    public function current(): Actor
    {
        $user = $this->principal();

        return new Actor($user->approvalUserId(), $user->approvalTenantId(), $user->approvalDelegationId());
    }

    public function interactive(): Actor
    {
        $user = $this->principal();
        if (!$user->hasInteractiveSession() || null !== $user->approvalDelegationId()
            || null !== $this->requests->getCurrentRequest()?->attributes->get(AccessToken::class)) {
            throw new AccessDeniedException('Use your trusted browser account session.');
        }

        return $this->current();
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
        $actor = $this->current();
        if (!$actor->samePerson($state->request->actor())) {
            throw new AccessDeniedException('This approval belongs to another account.');
        }
        $token = $this->requests->getCurrentRequest()?->attributes->get(AccessToken::class);
        $this->authority->assertCanRead($actor, $token instanceof AccessToken ? $token : null);
    }

    private function principal(): ApprovalPrincipal
    {
        $user = $this->security->getUser();
        if (!$user instanceof ApprovalPrincipal) {
            throw new AccessDeniedException('An authenticated application account is required.');
        }

        return $user;
    }
}
```

## 4. Require approval for this action

This first policy always requires a passkey and expires after ten minutes. The statement contains the frozen title and revision. A role-based policy can come later; it still belongs to your application.

```php
<?php
// src/Approval/ApplicationPolicyProvider.php

declare(strict_types=1);

namespace App\Approval;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;

final class ApplicationPolicyProvider implements PolicyProvider
{
    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        if ('document.publish' !== $actionId) {
            throw new ApprovalException('unknown_action', 'No approval policy exists for this action.');
        }

        return new ApprovalPolicy(
            $actionId,
            '1',
            'I approve publishing "{{ title }}", revision {{ revision }}, in {{ environment }}.',
            lifetimeSeconds: 600,
            required: true,
        );
    }
}
```

Change the policy version when its meaning changes. A proposal made under an old policy must be reviewed again. A passkey does not add permission: `CurrentAuthority` must still allow the action.

## 5. Freeze, guard and apply the change

This adapter contains the example's only publication write. `execute()` calls `ExecutionGuard` before touching published fields, so calling it directly fails. If your application already has a publication service, put the guard there and have the adapter call it. Route every other publication entry point through `ApprovalService` too.

```php
<?php
// src/Approval/PublishDocument.php

declare(strict_types=1);

namespace App\Approval;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;

final readonly class PublishDocument implements ActionAdapter
{
    public function __construct(
        private \PDO $pdo,
        private ExecutionGuard $guard,
        private string $environment,
    ) {}

    public function id(): string { return 'document.publish'; }
    public function version(): string { return '1'; }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        if (array_keys($input) !== ['document_id'] || !is_string($input['document_id'])
            || '' === $input['document_id'] || strlen($input['document_id']) > 128) {
            throw new ApprovalException('invalid_input', 'Provide one document_id.');
        }
        $row = $this->document($actor, $input['document_id']);

        return new PreparedAction(
            $this->id(),
            $this->version(),
            ['application' => 'Document site', 'environment' => $this->environment,
                'tenant_id' => $actor->tenantId, 'resource' => $row['id']],
            ['title' => $row['title'], 'body' => $row['body'], 'state' => 'published'],
            ['revision' => (int) $row['revision'],
                'published_revision' => null === $row['published_revision'] ? null : (int) $row['published_revision']],
            ['title' => 'Publish "'.$row['title'].'"',
                'consequence' => 'This exact title and body will become visible on the public document page.'],
            ['title' => $row['title'], 'revision' => (string) $row['revision'], 'environment' => $this->environment],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $row = $this->document($actor, $action->target['resource']);
        $published = null === $row['published_revision'] ? null : (int) $row['published_revision'];
        if ((int) $row['revision'] !== $action->preconditions['revision']
            || $row['title'] !== $action->effect['title'] || $row['body'] !== $action->effect['body']
            || $published !== $action->preconditions['published_revision']) {
            throw new ApprovalException('stale_resource', 'The document changed. Request a new review.');
        }
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        $this->guard->assertExecuting($actor, $action);
        $query = $this->pdo->prepare(<<<'SQL'
UPDATE app_documents
SET published_revision = revision, published_title = ?, published_body = ?, mutation_count = mutation_count + 1
WHERE id = ? AND tenant_id = ? AND revision = ? AND title = ? AND body = ? AND published_revision IS ?
SQL);
        $query->execute([
            $action->effect['title'], $action->effect['body'], $action->target['resource'], $actor->tenantId,
            $action->preconditions['revision'], $action->effect['title'], $action->effect['body'],
            $action->preconditions['published_revision'],
        ]);
        if (1 !== $query->rowCount()) {
            throw new ApprovalException('stale_resource', 'The document changed. Nothing was published.');
        }

        return ['document_id' => $action->target['resource'],
            'published_revision' => $action->preconditions['revision']];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $row = $this->document($actor, $action->target['resource']);
        if ($row['published_title'] !== $action->effect['title'] || $row['published_body'] !== $action->effect['body']
            || (int) $row['published_revision'] !== $action->preconditions['revision']
            || $result !== ['document_id' => $action->target['resource'], 'published_revision' => $action->preconditions['revision']]) {
            throw new ApprovalException('unexpected_effect', 'Publication did not preserve the approved change.');
        }
    }

    /** @return array<string, mixed> */
    private function document(Actor $actor, string $id): array
    {
        $query = $this->pdo->prepare('SELECT * FROM app_documents WHERE id = ? AND tenant_id = ?');
        $query->execute([$id, $actor->tenantId]);
        $row = $query->fetch();
        if (false === $row) {
            throw new ApprovalException('not_found', 'This document is unavailable for this account.');
        }

        return $row;
    }
}
```

Increment the action version when execution or review meaning changes. If templates or formatting determine the approved meaning, include their version or digest too; the demo's `PublishAlert::version()` shows that pattern. Run transformations that change content before freezing it, or verify their exact effect in the postconditions. The transaction cannot roll back an email already sent or an external API call.

The default review template already shows `effect.title` and `effect.body`. To use your own layout, render only the frozen values:

```twig
{# templates/bundles/VerifiedHumanApprovalBundle/review.html.twig #}
{% extends '@!VerifiedHumanApproval/review.html.twig' %}

{% block preview %}
    <article>
        <h3>{{ prepared.effect.title }}</h3>
        <div class="content-preview">{{ prepared.effect.body }}</div>
    </article>
{% endblock %}
```

Keep Twig escaping enabled. Do not query the latest draft from this block. For a browser publish button, call `ApprovalService::request()` with `ActorProvider::interactive()` after your usual CSRF check; do not call the adapter's `execute()` method. Then redirect to the review page, naming where the person came from, so the finished page leads back there: `$urls->generate('verified_human_approval_review', ['id' => $state->request->id, 'return' => '/documents/'.$id])`, where `return` is the page the button was on. The review page accepts only a path on your site for `return` and ignores anything else.

## 6. Expose the action through MCP

This is an ordinary SDK tool. The token read check happens before `ApprovalFlow`: both a new request and a retry can return sensitive stored results. `CurrentAuthority` also checks token/account binding and the current stored read grant.

```php
<?php
// src/Mcp/PublishDocumentTool.php

declare(strict_types=1);

namespace App\Mcp;

use App\Approval\CurrentAuthority;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

final readonly class PublishDocumentTool
{
    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private CurrentAuthority $authority,
        private ApprovalFlow $flow,
    ) {}

    #[McpTool(
        name: 'publish_document',
        title: 'Publish a document',
        description: 'Request publication of a draft. When pending, show the review URL and wait for the person to approve. Read approval_status afterwards; pending does not mean published. To retry, call again with the same arguments.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function publish(
        RequestContext $context,
        #[Schema(description: 'The existing document ID in the authenticated tenant.')] string $document_id,
        #[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        // A model that fills in every argument may send an empty key: that is no key.
        $request_key = '' === $request_key ? null : $request_key;
        try {
            $actor = $this->actors->current();
            $token = $context->getAccessToken()
                ?? throw new ApprovalException('unauthenticated', 'Use a personal MCP connection.');
            if (!$token->hasScope('approval.read')) {
                throw new ApprovalException('result_read_denied', 'This connection cannot read approval results.');
            }
            if (!$token->hasScope('document.publish')) {
                throw new ApprovalException('permission_denied', 'This token cannot request publication.');
            }
            $this->authority->assertCanRead($actor, $token);

            return $this->flow->respond(
                $context,
                $actor,
                'publish_document',
                ['document_id' => $document_id, 'request_key' => $request_key],
                fn (): ApprovalState => $this->approvals->request(
                    $actor, 'document.publish', ['document_id' => $document_id], $request_key,
                ),
            );
        } catch (ApprovalException $error) {
            return ApprovalFlow::errorResult($error);
        } catch (AccessDeniedException) {
            return ApprovalFlow::errorResult(
                new ApprovalException('unauthenticated', 'Use an authenticated application account.'),
            );
        }
    }
}
```

`ApprovalFlow::errorResult()` builds the error result a protected tool returns: `status`, `reason` and `message`, as text and as structured content that fits `ApprovalFlow::OUTPUT_SCHEMA`. A refusal is `rejected`. A stored request that fails the [integrity check](index.md#stored-receipts-and-later-verification) is `integrity_error`: the site cannot vouch for its outcome, so the agent must not report it as refused or as not having happened.

Describe the request key argument with `ApprovalFlow::REQUEST_KEY_DESCRIPTION`. A request key suits a client that generates a unique id for each new request. Asked for one, Claude models built it from the arguments and the date, and often built the same key again in a later conversation. The application then returns the earlier request and its outcome, and the person is not asked again. With the description, those models left the argument out. Without a key, a repeated call returns the same pending request, and a new request once the earlier one has finished.

Register this class with the MCP configuration in the [integration guide](index.md#4-register-the-mcp-tools-and-card). Ask the tool to publish a real document belonging to the signed-in tenant. The caller needs an active account with publication permission and a personal grant containing `document.publish` and `approval.read`.

The scope checks in this tool answer inside the tool's result. If `/mcp` is an OAuth protected resource, also register the [scope challenge](index.md#scope-challenges-over-http): a client whose token lacks `document.publish` or `approval.read` then gets HTTP 403 naming both and can ask the person for them. Keep the checks here either way.

## 7. Connect trusted passkey enrollment

Use your existing trusted account flow to authorize enrollment. It must establish the human account independently of an ordinary agent bearer or a password-only takeover. The demo uses a one-time trusted link for the first passkey and an existing passkey for another registration; your account recovery process remains your responsibility.

The [enrollment walkthrough](enrollment.md) gives the registration panel, JSON protocol and an existing Doctrine reference implementation. The low-level hooks are:

1. Call `ActorProvider::interactive()` and your enrollment authorization check. Apply CSRF protection and a short expiry.
2. Call `WebAuthnVerifier::registrationOptions($actor, random_bytes(32), $displayName)`. Save the complete options in the authenticated user's server-side session and send them to the browser's `navigator.credentials.create()` flow.
3. On completion, consume the saved options once. Check the same account, CSRF token and expiry. Never accept replacement registration options from request JSON.
4. In one transaction on the shared application connection, call `WebAuthnVerifier::register($actor, $savedOptions, $browserResponse, $label)`. This validates and inserts the credential. Then call `AnchoredCredentialRepository::anchor($credential, $context)` before committing. The context records how enrollment was authorized. Roll back both operations on failure.

`AnchoredCredentialRepository::add()` does not create the signed enrollment record by itself. Without `anchor()`, the configured repository will not accept the new passkey for approval. In your trusted account flow, call its `revoke()` inside a transaction on the shared application connection, so the revocation and its signed audit entry commit together and a failure to sign or store the entry leaves the passkey active; the demo's [`PasskeyRevocation`](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/src/Account/PasskeyRevocation.php) shows it. The wrapper accepts any `RevocableCredentialRepository`, so the passkeys and the audit rows (`AuditStore`) can live in your own storage.

The [WebAuthn guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/webauthn/README.md#trusted-enrollment) explains the browser payload and adapter responsibilities. The bundle's review controller handles approval ceremonies once a credential is enrolled; it does not supply enrollment or recovery controllers.

WebAuthn requires user verification. A biometric or device PIN may satisfy it. The server receives the verification result, not a fingerprint or face image, and cannot claim which local method the authenticator used.

## 8. Check the integration

For this PDO walkthrough, after copying the files, mapping your real account/grant records, configuring the bundle and importing its routes:

```bash
php bin/console lint:container
php bin/console app:approval:install
php bin/console assets:install
php bin/console cache:clear
```

On Doctrine, use `php bin/console verified-human-approval:install` for the package tables instead of the PDO walkthrough's `app:approval:install` command; the [Doctrine publishing example](https://github.com/scottfalconer/verified-human-approval/blob/main/examples/doctrine-publishing/README.md) gives its entity schema and setup.

Apply your application schema through your normal migration process before making a request. The package installation command creates only the approval, credential and audit tables. Keep those tables, the document tables and the signing key persistent.

Serve the application so that every request for a path that is not a file in `public/` reaches `public/index.php`, as the Symfony CLI's `symfony serve` does. PHP's built-in server started with only `-t public` treats any path with an extension as a file, so `/approval/receipt-keys.json` answers 404 there.

Use a real test account in your application, enroll its passkey through your trusted process, and create a draft through your normal application flow. Check these outcomes:

| Check | Expected result |
| --- | --- |
| Request publication | A pending request and review URL; published fields unchanged |
| Review and approve | The exact frozen title/body become public; one execution and its original signed receipt exist |
| Retry with the same request key and unchanged draft | The same request/result; no second mutation |
| Read with another account or tenant | Refused |
| Remove `approval.read` from the validated token or live grant, then retry/read status | No stored result is exposed |
| Change the draft while approval is pending | Approval stops; the newer draft is not silently substituted |
| Revoke the initiating grant or publication permission before completing | Approval stops; nothing is published |
| Call `PublishDocument::execute()` directly | `approval_required`; no business write |
| Cause a postcondition or signing failure | The business write, credential update and receipt commit all roll back |
| Download the receipt from the completed review page, then check it and an edited copy against `/approval/receipt-keys.json` | The original verifies; the edited copy fails |
| Open `/approval/receipt-keys.json` while signed out | The public keys, readable only if the bundle's access-control rule from the integration guide is in place |
| Run `php bin/console verified-human-approval:audit-head` | The audit log's newest sequence and hash, and that every entry verifies |

[Verify a receipt](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md) explains how to download the original receipt and verify it against the application's published keys, and against the trusted enrollment record to check the passkey signature too. Reading `approval_status` alone is not that verification step.
