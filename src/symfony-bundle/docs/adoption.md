# Adopting an existing editorial workflow

Start with the [first action](first-action.md), [host authentication](authentication.md) and [trusted enrollment](enrollment.md). These recipes cover two traps in adopting an existing workflow: retries after generated revision metadata changes, and a pre-existing non-null workflow column. They use the existing action, policy and Doctrine services; no additional bundle abstraction is needed.

[Editorial Desk](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/EditorialDesk/README.md) is an independent Symfony application that the repository's tests use to check the packages from the outside. It has its own `App` namespace, accounts, permissions, drafts, account screens and workflow adapters, and its tests install the current packages with Composer instead of using the monorepo's autoloader. It is part of this repository and, like the rest of it, MIT licensed.

## Generated revisions and completed retries

A keyed `ApprovalService::request()` retry can call `prepare()` again after the original operation completed. It checks that the requested target and semantic effect still match the stored proposal before returning the original outcome. It does not execute that operation again. A changing precondition or display value alone does not replace the stored proposal. The returned result is the historical outcome of that request, not a promise that the resource still has that state today.

This matters when execution creates a revision:

```php
// Fragile when the next prepare() sees the revision created by this operation:
$effect = ['body' => $page->body, 'revision' => $page->revision + 1];
```

An identical retry would propose the next revision and conflict with the original effect. For generated bookkeeping, keep the semantic effect stable and derive the revision from the signed starting state:

```php
// In prepare():
$effect = ['body' => $page->body, 'status' => 'published'];
$preconditions = ['revision' => $page->revision, 'status' => $page->status];
$display = ['title' => 'Publish this page', 'body' => $page->body,
    'revision' => $page->revision + 1];

// In apply(), after current authorization and frozen preconditions pass:
$page->status = 'published';
$page->revision = $action->preconditions['revision'] + 1;
```

The preconditions and display are part of the signed proposal too. Check the current revision and source content in `assertPreconditions()` and the exact new revision and content in `assertPostconditions()`. The person still sees the exact destination revision; generated metadata is not allowed to drift during execution.

See the executable [publication adapter](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Doctrine/Fixture/PublishLegacyDraft.php) and [cookbook tests](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Doctrine/AdoptionCookbookTest.php). The tests complete a publication, retry the same key, compare the original result and receipt, and assert one execution and one revision increment. After a later content change, the old key produces `idempotency_conflict`.

This is one modeling choice, not a rule that revisions can never belong in an effect. An explicitly selected revision can be semantic intent. An action can also refuse preparation after its terminal result, such as `no_change` or a deleted target; the service can then return the existing keyed outcome. The original input must still match. For example, Editorial Desk's [archive adapter](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/EditorialDesk/src/Workflow/ArchivePage.php) uses this terminal-state pattern, refusing with `not_published` once the page is archived, while its [publication adapter](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/EditorialDesk/src/Workflow/PublishPage.php) uses stable effects and frozen revision preconditions. Test both immediate retries and a later valid operation; a terminal refusal cannot tell a caller whether they should intentionally start a new action.

Changing an adapter's meaning requires changing its version. A keyed retry whose target or semantic effect differs from the stored proposal is refused with `idempotency_conflict`, so an old key never stands for a different change. When a completion response is lost, read the existing request's status; do not generate a new key as recovery.

## A non-null draft/published/archived column

`#[RequiresApproval]` treats any non-null protected value as held state on insertion and deletion. That includes `'draft'`, `false`, `0` and the empty string. It cannot infer your CMS's workflow semantics. Marking an existing non-null `status` column therefore also protects creation of a draft.

Choose one of these application designs:

| Existing workflow | Integration |
| --- | --- |
| Drafts and public snapshots can be separated | Keep the freely editable working draft separate from nullable protected public fields. They remain null until publication. Editorial Desk demonstrates forward drafts and preservation of public evidence. |
| The existing non-null status must remain | Keep status protected. Create drafts through a tightly constrained action and an explicit host policy exemption for that action. Other transitions retain their approval policy. |

For the second choice, the [legacy page](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Doctrine/Fixture/LegacyPage.php) has a non-null protected status. Its [draft creation adapter](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Doctrine/Fixture/CreateLegacyDraft.php):

1. Accepts exactly an identifier and a non-empty body. The client cannot provide status or a policy-exemption flag.
2. Freezes the target and an effect whose status is always `draft`.
3. Checks the identifier is unused, then constructs the draft and calls `EntityAction::persist()` inside guarded execution.
4. Checks after the flush that the stored entity is still exactly the constrained draft.

Configure the action's `PolicyProvider` to return `ApprovalPolicy(..., required: false)` for this creation action only. Call it through `ApprovalService::request()` just like a protected action. That still checks current application authority and preconditions, enters the execution guard, permits only its targets, flushes under the shared transaction and records the result. A policy-exempt record has no human approval proof and must not display the human approval badge.

The fixture uses a small shared test authorizer; your host must enforce its actual create permission for draft creation and the appropriate permission for each later action. An exemption from the passkey ceremony does not confer permission to create content.

The [regression tests](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Doctrine/AdoptionCookbookTest.php) prove that an ordinary draft insertion is refused, constrained creation succeeds without a human proof, a disabled account cannot create it, a caller cannot inject `status: published`, and ordinary ORM publication or archive transitions remain refused. The publication adapter then exercises the approved transition through the same guard.

Keep every public-content field and relevant transition protected. Do not remove protection from status or add a general “draft values are safe” exception: that can allow insertion of already published content, deletion or another transition to bypass the action boundary. DQL and raw SQL do not run the unit-of-work listener; production writers using them need their own enforced boundary. The test's direct database mutation only models a later separately authorized edit for the retry assertion.

## A complete host-owned enrollment flow

The [enrollment guide](enrollment.md#complete-reference-flow-and-acceptance-tests) maps Editorial Desk's actor provider, security configuration, enrollment service, grant table, controller and template to the trust decisions they implement. Reuse your established account and recovery processes. The bundle provides WebAuthn verification and the browser panel; it does not decide who may enroll a new authenticator.

Run the focused recipes from the repository root:

```bash
php vendor/bin/phpunit tests/Doctrine/AdoptionCookbookTest.php
php tests/Package/check-editorial-consumer.php
```

Editorial Desk's [README](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/EditorialDesk/README.md) describes the runner's HTTP and browser prerequisites. A software authenticator and passing CI show nothing about a physical passkey or a real MCP client: test those with the devices and clients you support.
