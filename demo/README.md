# Riverside Council alerts: the demo application

A complete Symfony application using the Verified Human Approval bundle. It is the public alerts site of a fictional council. Issuing a public alert is high-stakes, so the administrator's rule says who may issue one directly, who must approve the exact alert with a passkey first, and who may not issue one at all. An agent such as Claude can ask for the alert through MCP; the site decides.

Everything here is demo code, not part of the bundle: the accounts and their roles, the alert content and its page, the per-role policy form, the audit page, the passkey links, and a small stand-in OAuth server for remote clients such as claude.ai. All commands below run from the repository root.

## Run it in five minutes

You need PHP 8.3+ with the `pdo_sqlite`, `sodium` and `openssl` extensions, Composer, and Chrome or Safari. To see the Claude side, you also need Claude Desktop or Claude Code.

```bash
git clone https://github.com/scottfalconer/verified-human-approval.git
cd verified-human-approval
composer install
php demo/bin/setup.php
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php
```

Setup prints fresh passwords, personal agent tokens and one-time passkey links once, plus the exact commands for connecting Claude. The server runs several workers because the card's polling, the model's calls and your browser reach it at the same time. If you ran an earlier version, start fresh with `php demo/bin/setup.php --reset`.

The administrator's rule starts like this:

| Account | Role | Issuing a public alert |
| --- | --- | --- |
| `manager` | Emergency manager, the administrator | Directly |
| `editor` | County staff | Only after approving the exact alert with their own passkey |
| `reader` | Read-only colleague | Not allowed |
| `other` | Another organization | Cannot see these alerts |

1. **Add a passkey.** Open http://localhost:8080, sign in as `editor`, then open the editor's `passkey_link` from setup and register a passkey. On a Mac, Touch ID works. A password alone cannot add an approval passkey: the first one needs this one-time link, and any further one needs an existing passkey.
2. **Connect Claude** as the editor, using the commands setup printed:
   - **Claude Desktop:** add the printed `verified-human-approval` entry under `mcpServers` in `claude_desktop_config.json`, then restart Claude. It uses `mcp-remote`, and keeps the token in a private file.
   - **Claude Code:** run the printed `claude mcp add --transport http …` command.
3. **Ask Claude:** "Issue the Riverside Road evacuation alert."
4. **Approve.** Claude Desktop shows an approval card, and Claude Code shows an "Open link" prompt. Either one opens the review page, which shows the exact alerts page residents will see. Approve with your passkey, and the card updates to show it's done.
5. **Check the result** at http://localhost:8080/alerts. It said "No active alerts" until you approved. Now it shows the alert you approved.

Sign in as `manager`, add the manager's passkey with its link, and open **Administrator settings** to change the rule. You can let staff issue directly, or stop them issuing at all. Changing the rule is itself high-stakes, so proposing a change takes you to a review page, and nothing changes until you approve it with your passkey. Then any request still waiting under the old rule can no longer be approved.

**Audit log**, also for the administrator, shows how every change happened:
- who asked, through which agent;
- under which rule;
- whether a person verified it, and with which passkey.

It re-checks every record each time it opens. It also lists passkeys, agent connections and the signed security log, and can revoke either a passkey or a connection.

To start over for another run, use `php demo/bin/setup.php --reset`.

On a Mac, `demo/bin/server start` runs the site under launchd instead, so it keeps running when the terminal or Claude quits. It also takes `stop`, `restart` and `status`.

## claude.ai, and approving on your phone

claude.ai and phones need the site on a public HTTPS address. On a Mac with `cloudflared` installed:

```bash
demo/bin/public start
```

This opens a Cloudflare quick tunnel and starts a second copy of the demo behind it, under launchd. It prints the public address and the connector URL. The new accounts' passwords are in `var/public-demo-setup.txt`. A quick tunnel gets a new hostname each time it starts, and passkeys belong to a hostname, so a new hostname gets a fresh demo.

1. Open the public address, sign in as `editor`, open the editor's `passkey_link` from `var/public-demo-setup.txt` and add a passkey. With iCloud Keychain or Google Password Manager, the same passkey reaches your phone.
2. In claude.ai, open **Settings → Connectors**, add a custom connector, and paste the printed `https://…/mcp` URL. Claude signs in through the demo's OAuth: sign in as `editor` and allow access.
3. Ask Claude to issue the alert. In the card, **Review and approve** opens the review in a new tab. **Approve on your phone** shows a QR code instead: scan it with the phone's camera, check the alert, and approve with Face ID. The card shows the result either way.

The phone code works once, for two minutes, and opens only that one review. It is not a sign-in: the phone can't reach anything else on the site, and approving still takes the person's own passkey.

To run it again, `demo/bin/public all-clear` takes the alert down and keeps the accounts, passkeys and the claude.ai connection; the take-down is recorded in the audit log. `demo/bin/public reset` starts a fresh copy on the same address. Stop it with `demo/bin/public stop`.

## Where things are

| Path | What it shows |
| --- | --- |
| [`src/DemoKernel.php`](src/DemoKernel.php) | The bundle's configuration, the two firewalls (a session firewall for people, a stateless one for `/mcp`), the review routes' request matcher, and the account and admin pages opting in to the review pages' security headers |
| [`src/Mcp/DemoTools.php`](src/Mcp/DemoTools.php) | The MCP tools: `read_alert`, the protected `publish_alert`, and the scope checks around them |
| [`src/Action/PublishAlert.php`](src/Action/PublishAlert.php) | The action adapter: what exactly is frozen, its version, preconditions and postconditions |
| [`src/Action/AlertPublisher.php`](src/Action/AlertPublisher.php) | The guarded mutation: it refuses to run outside a validated execution |
| [`src/Action/ChangeAlertRule.php`](src/Action/ChangeAlertRule.php), [`src/Action/DemoPolicyProvider.php`](src/Action/DemoPolicyProvider.php) | The administrator's per-role rule, and changing it as an approved action |
| [`src/Security`](src/Security) | Accounts, personal agent tokens, the actor provider and the requester label |
| [`src/Controller/AccountController.php`](src/Controller/AccountController.php) | Passkey enrollment: one-time links for the first passkey, an existing passkey for the next |
| [`src/Controller/AuditController.php`](src/Controller/AuditController.php) | The audit page, which re-checks every receipt and passkey signature as it opens |
| [`src/OAuth`](src/OAuth), [`src/Controller/OAuthController.php`](src/Controller/OAuthController.php) | The stand-in MCP OAuth server for remote clients: metadata, PKCE, client ID metadata documents, rotating refresh tokens |
| [`bin/setup.php`](bin/setup.php), [`bin/server`](bin/server), [`bin/public`](bin/public) | Setup and the launchd scripts for the local and public copies |

The clients in [`../examples`](../examples) drive this application over real MCP: `mcp.php` with the official SDK client, and `agent.php` with Symfony AI's `Agent` and `McpToolbox`, scripted or against a real model. The tests in [`../tests`](../tests) boot this kernel for the Symfony, OAuth, phone-code and evidence suites, and the Python scripts there drive it with real browsers and a virtual passkey.
