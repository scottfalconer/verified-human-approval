# Verified Human Approval for Symfony (`verified-human-approval/symfony-bundle`)

An AI agent can ask for a high-stakes action through MCP. Your Symfony application decides, by its administrator's rule, whether the person must verify it, and when they must, it freezes the exact proposal, shows it in Claude as an MCP App card or a native prompt, lets the person approve it on your own site with a passkey, and executes that stored proposal once.

```bash
composer require verified-human-approval/symfony-bundle
```

The integration guide is in [docs/index.md](docs/index.md): configuration, mapping your account model, wrapping an MCP tool, registering an action adapter and protecting its mutation, passkeys and review screens, the approval card, the phone code and evidence.

This package is the Symfony integration; the engine is `verified-human-approval/core`, the passkey verifier `verified-human-approval/webauthn` and the MCP presentation `verified-human-approval/mcp`. The repository's [demo](../../demo) is a complete Symfony application using it.
