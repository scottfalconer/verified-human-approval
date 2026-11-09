# Reusing an existing Drupal MCP endpoint

Verified Human Approval does not create an MCP endpoint or issue OAuth tokens.
A host module can expose its `verified_human_approval.approval_manager` service
through an existing Drupal MCP installation. The browser approval and passkey
enrollment still use the human's Drupal cookie session.

The approval module itself needs the library's portable storage contracts, on
`main` since [PR #3](https://github.com/scottfalconer/verified-human-approval/pull/3).
This prerequisite is separate from the existing MCP and OAuth installation.

## Verified extension points

The following tagged releases were checked on 9 November 2026:

| Package | Checked release | Public integration point |
| --- | --- | --- |
| `drupal/mcp_server` | `2.0.0-beta7` | Existing `/mcp` endpoint; native `#[Tool]` plugins extending `ToolPluginBase`. |
| `drupal/tool` | `1.0.0-beta12` | `#[ToolMethod]` on a registered service's public method, or a `ToolBase` plugin under `Plugin/tool/Tool`. |
| `drupal/mcp_server_tool_bridge` | `1.0.0-beta5` | An enabled `mcp_tool_config` entity maps a Tool API tool to an MCP tool. |

These are prereleases. Composer successfully resolved those exact three
versions with this module, its two VHA library packages, Drupal 11.4.12, and
actual PHP 8.4.24 in an isolated `--dry-run`. That verifies dependency
resolution; it is not an installed or end-to-end MCP/OAuth test. The source APIs
below were checked against the official tagged release archives. No OAuth
package was installed for that check.

For a site already using MCP Server OAuth and Simple OAuth, prefer the Tool API
and Tool Bridge path. The OAuth companion's documented per-tool scope policy
applies to Tool Bridge configuration entities. A native MCP plugin registered
only in code does not automatically receive that scope check.

## Example host wrapper — not installed or end-to-end tested

This example belongs in a separate site integration module, such as
`vha_mcp_tools`. That module depends on `verified_human_approval` and `tool`.
It uses the existing endpoint's Tool Bridge and authorization configuration.
It does not add Tool API or MCP dependencies to the reusable approval module.

Create `src/ApprovalTools.php` in that integration module:

```php
<?php

declare(strict_types=1);

namespace Drupal\vha_mcp_tools;

use Drupal\Core\Url;
use Drupal\tool\Attribute\ToolMethod;
use Drupal\tool\Tool\ToolOperation;
use Drupal\verified_human_approval\ApprovalManager;

final class ApprovalTools {

  public function __construct(private readonly ApprovalManager $approvals) {}

  /**
   * Requests human approval to publish the specified node revision.
   *
   * @return array{request_id: string, status: string, approval_url: string}
   *   The request to review in the human's Drupal browser session.
   */
  #[ToolMethod(
    id: 'vha.request_publish',
    operation: ToolOperation::Trigger,
    permission: 'request verified human approval',
  )]
  public function requestPublish(
    int $node_id,
    int $revision_id,
    string $langcode,
    ?string $request_key = NULL,
  ): array {
    $state = $this->approvals->requestPublish(
      $node_id, $revision_id, $langcode, $request_key,
    );
    return [
      'request_id' => $state->request->id,
      'status' => $state->status,
      'approval_url' => Url::fromRoute(
        'verified_human_approval.review',
        ['request_id' => $state->request->id],
        ['absolute' => TRUE],
      )->toString(),
    ];
  }

  /**
   * Reads the status of an approval request owned by the current actor.
   *
   * @return array{request_id: string, status: string}
   *   The stored request state; this does not execute publication.
   */
  #[ToolMethod(
    id: 'vha.approval_status',
    operation: ToolOperation::Read,
    permission: 'use verified human approval',
  )]
  public function status(string $request_id): array {
    $state = $this->approvals->get($request_id);
    return ['request_id' => $state->request->id, 'status' => $state->status];
  }

}
```

Register the service in `vha_mcp_tools.services.yml`:

```yaml
services:
  vha_mcp_tools.approvals:
    class: Drupal\vha_mcp_tools\ApprovalTools
    arguments: ['@verified_human_approval.approval_manager']
```

Tool API beta12 discovers these attributed methods during container/plugin
discovery. It derives inputs from native parameter types and outputs from the
array-shape return documentation. Rebuild Drupal caches after adding the module.

In `/admin/config/services/mcp-server/tools`, map `vha.request_publish` to a
configuration named `vha_request_publish`; map `vha.approval_status` similarly.
The first MCP wire name is then `tool_api__vha_request_publish`. Configure the
existing OAuth companion to require authentication and the appropriate scopes
on **each** mapping. The declared Drupal permissions and VHA's node access
checks must also pass.

The generated approval URL uses a Drupal route, not a caller-supplied URL. The
site must have its ordinary trusted-host/proxy settings configured correctly.
The link identifies a request; it does not authorize its approval. Do not expose
`begin()`, `complete()`, or passkey enrollment as model-callable tools.

## Required host identity bridge

The sample cannot accept OAuth requests with the default actor provider. That
provider deliberately rejects bearer authentication and supports cookie login
only. Before enabling the tool mappings for OAuth clients, the host must
replace or decorate `verified_human_approval.actor_provider` with an
`ActorProviderInterface` implementation:

- `current()` derives identity from the host's already validated OAuth context.
  Bind the Drupal user UUID, this site's tenant ID, and a server-owned stable
  delegation ID. Never accept user, tenant, client, grant, or scope assertions
  from tool arguments. A client ID alone is not a delegation ID.
- `interactive()` retains the cookie-session checks for the person approving
  or enrolling. A bearer identity must not be converted into interactive login.
- `accountFor(Actor)` reloads the recorded delegation and user from
  authoritative storage. Reject revoked, expired, disabled, wrong-user,
  wrong-site, wrong-resource, or insufficiently scoped delegation. Perform this
  again when the person approves, since the original MCP request may have ended
  and its authority may have changed.

Keep enough trusted delegation metadata to repeat those checks without the
original bearer header. A persisted token record ID can bind one token's
lifetime, provided expiration and revocation are checked. Supporting token
refresh across one longer-lived grant needs an explicit grant model; do not
silently substitute a newer token or treat the whole OAuth client as the grant.
Do not store bearer token strings as approval evidence.

Loading a full Drupal user entity must not widen an OAuth token's permission
set. The effective authority is the intersection of current Drupal access and
the original, still-valid delegation. `accountFor()` must enforce the grant's
baseline capability before returning that user. If grants restrict particular
nodes, operations, or other action data, also decorate
`verified_human_approval.authorizer`: its `assertAllowed(Actor, PreparedAction)`
has the action context needed to enforce those restrictions. The ordinary
authorizer must still run. The initial endpoint scope check alone is
insufficient for a later browser completion.

## Protocol limits of this sample

This is a request/link/status workflow through existing MCP tools. The human
opens the review page, approves with a passkey, and the client can then call the
status tool. A pending result is successful creation of an approval request,
not successful publication.

Tool Bridge beta5 explicitly does not pass the MCP `ClientGateway` into its
backing Tool API tool. Its source identifies that integration as an open design
question. Consequently this sample does not implement URL-mode elicitation or
`InputRequiredResult` continuation. Those require a separate protocol adapter
and authorization wiring. A native MCP plugin can access the gateway, but its
scope enforcement would need to be added explicitly; switching plugin styles
must not remove the existing grant checks.

Before claiming an OAuth/MCP integration is supported, test it against the
site's actual OAuth provider: correct user/grant ownership, required scopes,
token expiry and revocation before browser completion, cross-user request
reads, and a revoked grant between preparation and final publication. This
module's independent Drupal kernel suite does not substitute for those tests.

## Sources

- [MCP Server project and current extension examples](https://www.drupal.org/project/mcp_server)
- [MCP Server releases](https://www.drupal.org/project/mcp_server/releases)
- [Tool API beta12 service method documentation](https://git.drupalcode.org/project/tool/-/blob/1.0.0-beta12/docs/developers/tool-methods.md)
- [Tool API beta12 service discovery compiler pass](https://git.drupalcode.org/project/tool/-/blob/1.0.0-beta12/src/DependencyInjection/ToolMethodPass.php)
- [Tool Bridge beta5 execution adapter and gateway limitation](https://git.drupalcode.org/project/mcp_server_tool_bridge/-/blob/1.0.0-beta5/src/Plugin/mcp_server/Tool/ToolApi.php)
- [MCP Server Tool Bridge configuration](https://www.drupal.org/project/mcp_server_tool_bridge)
- [Existing MCP Server OAuth scope policy and code-only tool limitation](https://www.drupal.org/project/mcp_server_oauth)
