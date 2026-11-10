# Use the host application's authentication

Verified Human Approval is not an OAuth issuer. The installable packages have no client registration, authorization-code, refresh-token or login implementation. Use your existing Symfony Security firewall and maintained OAuth or access-token integration. The bundle adds the exact-action passkey ceremony after authentication and ordinary authorization.

The [reference application](../../../demo/README.md) demonstrates an issuer integration; its accounts, token storage and enrollment authorization belong to that application. They are not required by the bundle. Do not copy its issuer into an application that already has one.

## Incoming MCP request

Keep `/mcp` on a stateless host firewall. Your existing authenticator or resource-server integration must:

1. Validate the incoming credential using its issuer's supported implementation, including signature, intended resource/audience, expiry and the issuer's revocation rules.
2. Resolve the actual local account and its tenant. Resolve the **original** delegation associated with that credential, not the latest connection for the account.
3. Check the account and delegation are currently active. Restrict the effective scopes to both the token's grant and the live local delegation.
4. Authenticate that principal through Symfony Security and supply the SDK's verified token on the request:

```php
use Mcp\Server\Authorization\AccessToken;

// Only after validation by your resource server, with the verified token's claims/scopes.
$request->attributes->set(AccessToken::class, $validatedSdkToken);
```

An `AccessToken` object is not itself proof of validation. Never construct its identity or scopes from unverified JSON, tool arguments or arbitrary headers. A different host can map its own validated principal and claims; the bundle does not prescribe the issuer's grant tables.

## ActorProvider

Implement the three methods using the authenticated host principal:

| Method | Host contract |
| --- | --- |
| `current()` | Return `Actor(userId, tenantId, delegationId)` for this authenticated request. Reject an absent or disabled account. |
| `interactive()` | Return the authenticated human browser account. Reject an MCP bearer even if it belongs to that person. |
| `assertCanReadApproval($state)` | Check current account/tenant ownership and delegated result-read access before returning any stored proposal or outcome. |

The first-action guide has a [concrete identity adapter](first-action.md#3-map-the-authenticated-person). The Doctrine example supplies a separate [adapter](../../../examples/doctrine-publishing/src/Security/ApprovalActors.php). An account identifier in `localContext`, a JSON argument or a request attribute alone does not establish Symfony's authenticated user.

Keep the review and enrollment pages on the host's browser session firewall. A person approving in that browser does not replace the original initiating delegation. The browser proves the right human approved; the execution authorizer still checks the initiating grant.

## Current authority at execution

Implement `Authorizer::assertAllowed()` using authoritative account, resource and delegation state on the same transaction connection as execution. Check the requested action, tenant and target permissions, live delegation scopes and lifetime. Missing or revoked authority must fail, even when the WebAuthn proof is valid. There is no fallback to an administrator or application identity.

Use the [HTTP scope challenge adapter](index.md#scope-challenges-over-http) when your MCP transport does not already supply the SDK's authorization middleware. It consumes the host-validated SDK token and SDK `ScopePolicy`; it neither issues nor validates OAuth tokens. Keep tool and domain authorization checks as well.

## Acceptance checks

Test an absent credential, wrong audience, expired/revoked credential, insufficient scopes, wrong account/tenant, and a revoked initiating delegation while approval is pending. Confirm a valid browser approval never widens the original grant. A token for reading stored results must not execute the operation, and an agent token must not enroll or act as the interactive approver.

## Recover an expired browser session

The browser script sends `Accept: application/json`. Configure the host's browser authentication entry point to return **HTTP 401** with `{"status":"authentication_required"}` when authentication is needed for that JSON request. Keep normal HTML navigation on the host's existing login flow. The [publishing example entry point](../../../examples/doctrine-publishing/src/Security/BrowserEntryPoint.php) and its [firewall configuration](../../../examples/doctrine-publishing/config/packages/security.php) show this using Symfony Security's `AuthenticationEntryPointInterface`; the bundle does not authenticate the person or select a login route.

The exact marker displays sign-in recovery and a link to the **current page**. Following that link makes a new GET; the host should save its target and return the signed-in person to this existing request. It never repeats the approval POST or creates a new operation. If the passkey response was already submitted, the page retains the unconfirmed-outcome warning until the host reports the recorded state. Cancellation and enrollment follow the same rule. A plain 401, HTML login redirect, gateway failure or unreadable response cannot establish either session expiry or an operation's outcome.

Test session loss before options, while the device prompt is open, and a lost response after execution commits. Check that signing back in shows the original request, no second execution occurs, and an authenticated permission denial remains a normal domain refusal. `node --test tests/approval_recovery.mjs` exercises the shipped script's response handling; the independent Editorial Desk consumer also exercises host login and recovery in a real browser.
