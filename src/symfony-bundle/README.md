# Verified Human Approval for Symfony (`verified-human-approval/symfony-bundle`)

Add passkey approval to selected actions in your Symfony application. An agent requests an action through MCP. Your application freezes the proposal, lets the person review and approve it on your site, checks their current permissions, and applies the stored change once.

This is a development preview. The packages are not on Packagist yet. Start with the [integration guide](docs/index.md) for installation from this repository, including the WebAuthn package, and a complete first action.

The bundle supplies service configuration, review routes, CSRF checks, rate limits, the MCP approval card, status tool, scoped phone handoff, the person's receipt download and the public key that checks receipts. Your application supplies accounts, current permissions, trusted enrollment and the guarded business action. The supported example uses SQLite and a single shared PDO connection.

The engine is `verified-human-approval/core`, the passkey verifier is `verified-human-approval/webauthn` and the MCP presentation is `verified-human-approval/mcp`. The repository's [demo](https://github.com/scottfalconer/verified-human-approval/blob/main/demo/README.md) is a complete reference application. Its account screens, audit page and OAuth server are demo code, not bundled application features.
