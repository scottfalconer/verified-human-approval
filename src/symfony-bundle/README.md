# Verified Human Approval for Symfony (`verified-human-approval/symfony-bundle`)

Add passkey approval to selected actions in your Symfony application. An agent requests an action through MCP. Your application freezes the proposal, lets the person review and approve it on your site, checks their current permissions, and applies the stored change once.

For an existing CMS, the [adoption cookbook](docs/adoption.md) covers completed retries with generated revisions, non-null workflow columns and a tested host enrollment flow.

This is a development preview. The packages are not on Packagist yet. Start with the [integration guide](docs/index.md) for installation from this repository, including the WebAuthn package, and a complete first action.

The bundle supplies service configuration, review routes, CSRF checks, rate limits on each person's approval attempts and on their agents' requests (`attempts_per_minute`, `agent_requests_per_minute`), the MCP approval card, status tool, a scoped phone handoff that `phone_handoff: false` turns off, the person's receipt download and the public keys that check receipts. Your application supplies accounts, current permissions, trusted enrollment and the guarded business action.

Two storages are supplied. The PDO store keeps approvals on SQLite, on one PDO connection your application shares. With `verified-human-approval/doctrine`, the bundle keeps them on your Doctrine DBAL connection, on SQLite, PostgreSQL, MySQL 8.0.17 or later, or MariaDB. Either way, the approval and the business change commit in one transaction on one connection.

The engine is `verified-human-approval/core`, the passkey verifier is `verified-human-approval/webauthn` and the MCP presentation is `verified-human-approval/mcp`. The repository's [demo](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/README.md) is a complete reference application. Its account screens, audit page and OAuth integration, built on `league/oauth2-server-bundle` and `omouren/mcp-oauth-bundle`, are demo code, not features of the bundle; the bundle uses your application's own authentication.

This package is developed in the [Verified Human Approval repository](https://github.com/scottfalconer/verified-human-approval). Open issues and pull requests there.
