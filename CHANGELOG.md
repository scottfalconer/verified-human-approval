# Changelog

Newest first. Until 1.0, a minor version may change interfaces; [docs/releasing.md](docs/releasing.md) says what each version promises.

## Unreleased: 0.1.0

The first release, of seven packages: `verified-human-approval/core`, `webauthn`, `mcp`, `symfony-bundle`, `doctrine`, `store-contracts` and `testing`.

- **Approval of an exact action.** An agent or a person proposes a change; the application freezes it as canonical JSON. Only a passkey assertion bound to that proposal approves it, and only the person who asked can approve.
- **One execution, in one transaction.** On approval, the application runs the stored proposal once, re-checking current permissions, its approval rule and its preconditions. The change, the proof, the execution record and a receipt signed with the application's key commit together, or none do.
- **After it runs.** The executing action can read which approval it runs under, and best-effort observers can follow a completed approval after the commit.
- **Receipts and keys.** A person can download the signed receipt of what they approved, and anyone can check it offline against the published key set, including after a key rotation.
- **A signed audit log** of security events, with a hash chain, checkpoints of its head, and key rotation that keeps old entries checkable.
- **Passkeys** through WebAuthn, with user verification required, enrollment anchored in the audit log, and revocation.
- **MCP**: the approval card as an MCP App, a phone handoff code, and optional URL-mode elicitation.
- **Symfony bundle**: review pages with a strict Content-Security-Policy and a nonce for your own layout, a translation domain, a "verified" badge with a public verification page, console commands for the audit log and key rotation, and an `ApprovalCompleted` event.
- **Scope challenges for MCP over HTTP** (Symfony bundle, opt-in): `McpScopeChallengeSubscriber` applies the SDK's `ScopePolicy` before the MCP controller runs and answers a token that lacks a tool's scope with HTTP 403, `insufficient_scope` and every scope the operation needs, so a client can ask for more and retry.
- **Storage**: reference stores on SQLite through PDO, and interfaces for your own.
- **Doctrine** (`verified-human-approval/doctrine`): the approval store, the audit log and the passkey repository on the application's own DBAL connection, on SQLite, PostgreSQL, MySQL 8.0.17+ and MariaDB, in one transaction with its entities; an ORM base action that locks and flushes inside the approval, a tripwire that refuses unapproved changes to protected entities, schema listeners for migrations, and `persistence: { driver: doctrine }` in the bundle.
- **Publishing example** (`examples/doctrine-publishing`): a runnable Symfony application on Doctrine ORM, where publishing, editing live content, archiving (one article or a batch under one approval) and deleting each need the person's passkey, from the web and from MCP through the same approval service; protected entity fields, locking reads, frozen previews and the public badge, with tests for SQLite, PostgreSQL and MySQL.
- **Storage contracts** (`verified-human-approval/store-contracts`): the test suites a store must pass, for the approval store, the audit store and the passkey repository, including concurrency races, to run against your own adapter.

- **Testing support** (`verified-human-approval/testing`): installable software passkey, BrowserKit panel helper and in-process MCP HTTP client, with a host integration guide and separate-package consumer checks.
- **ORM enforcement and evidence display.** Unloaded deletion references fail closed; the final update check includes changes made by earlier lifecycle listeners. Damaged stored evidence removes its badge without breaking the content page. Completion observers are documented as best effort, with possible duplicates.
- **Maintained reference authentication.** The demo delegates OAuth issuance and validation to League OAuth2 Server Bundle and MCP metadata/client integration to MCP OAuth Bundle. Account permissions and original delegation binding remain application-owned. The reference application's temporary MCP beta compatibility alias does not enter the installable packages.
- **Expired browser sessions.** The passkey panel recognizes the host's explicit JSON authentication response, links back to the same request for sign-in, and preserves uncertainty after submission. Generic network or gateway failures never establish an outcome.
- **Adoption recipes and an independent consumer.** The cookbook covers completed retries, generated revision metadata, existing non-null workflow states and trusted enrollment. CI installs the seven current packages into Editorial Desk's own Symfony application and exercises its PHP, HTTP and browser acceptance scenarios.
- **Request keys and agents.** `ApprovalFlow::REQUEST_KEY_DESCRIPTION` describes a tool's optional request key so that a language model leaves it out. Asked for an id of their own, Claude models built a key from the arguments and the date and often built the same one in a later conversation, where it returned the earlier request and its outcome without asking the person again. The demo, the publishing example and the guide use the description, and `tests/claude_request_key.py` checks it against a real model.
