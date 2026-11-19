# Riverside Council demo

This Symfony application runs a fictional council's public alerts site. An agent can ask to issue an alert. The application's rule decides whether the caller may issue it directly, must approve it with a passkey, or is refused.

The walkthrough uses the real MCP SDK client supplied in this repository. You do not need Claude, a model API key, or a paid model. You will request an alert, review the exact proposal, approve it, and check the recorded result.

The accounts, alert pages, administrator screens, and OAuth wiring are reference application code. The OAuth protocol is supplied by `league/oauth2-server-bundle` and `omouren/mcp-oauth-bundle`; the approval packages do not implement or require an OAuth issuer. See [Authentication ownership](authentication.md) for the connection lifecycle and the reference-only dependency compatibility alias. For your own application, start with the [Symfony bundle guide](../src/symfony-bundle/README.md).

## Run the demo

### 1. Start the application

You need:

- PHP 8.3 or later and Composer. Enable `pdo_sqlite`, `sodium`, `openssl`, `mbstring`, and XML support. The default Composer install also needs DOM and XMLWriter for development tools; Composer checks the full extension requirements.
- A browser and device that support passkeys, such as Chrome or Safari with a compatible authenticator.
- Two terminal windows. The commands below use Bash or zsh on macOS or Linux and run from the repository root.

In **Terminal A**:

```bash
git clone https://github.com/scottfalconer/verified-human-approval.git
cd verified-human-approval
composer install
php demo/bin/setup.php
```

Setup prints each account's `password`, `personal_token`, and `passkey_link`. Save this output privately before continuing: credentials are shown only once. Setup also writes the editor's token to a private header file for the optional Claude Desktop connection.

Start the server in the same terminal and leave it running:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php
```

The four workers allow browser requests and MCP calls to run at the same time. Use `localhost` consistently; the passkey configuration is for that hostname.

### 2. Add the editor's passkey

1. Open [the local site](http://localhost:8080) and sign in as `editor`, using the password from setup.
2. Open that account's `passkey_link` from the same setup output, in the same browser.
3. Register a passkey using the browser's prompt.

The first passkey requires this one-time link, which expires after seven days. A password alone cannot enroll it. Adding another passkey requires an existing passkey.

Your device may use a fingerprint, face recognition, or a device PIN to unlock the passkey. The application verifies a WebAuthn signature; it does not receive your biometric data.

### 3. Request an alert through MCP

In **Terminal B**, change to the same checkout. Enter the editor's `personal_token` at this prompt. Use the token, not the account password. These commands work in Bash and zsh and hide the pasted value:

```bash
printf 'Editor personal token: '
read -r -s VERIFIED_HUMAN_APPROVAL_TOKEN
printf '\n'
export VERIFIED_HUMAN_APPROVAL_TOKEN

php examples/mcp.php list
php examples/mcp.php read riverside-evacuation
php examples/mcp.php publish riverside-evacuation first-demo-request
```

`list` discovers the tools. `read` shows the draft and current published content. `publish` returns a tool result containing `requestId`, `status: pending`, and `review_url`.

Keep the request ID. At this point, [the public alerts page](http://localhost:8080/alerts) still says **No active alerts**. A pending request has not issued anything.

### 4. Review, approve, and check the result

Open the returned `review_url` while signed in as `editor`. The page shows the frozen alert and the statement you are approving. Check both, then approve with your passkey. Successful approval executes the action; there is no separate publish step.

Approval requests expire after ten minutes. If a request expires, publish with a new request key and review the new proposal.

In Terminal B, replace `REQUEST_ID` with the returned ID:

```bash
php examples/mcp.php status REQUEST_ID
```

Expect `status: completed`. The result also carries the site's signed `receipt`. Refresh [the public alerts page](http://localhost:8080/alerts) to see the approved alert.

Back on the review page, **Download your receipt** saves your own copy: the exact request, your passkey's signature on it, what ran, and the site's receipt signed as it ran. The site publishes the keys that check it at [/approval/receipt-keys.json](http://localhost:8080/approval/receipt-keys.json). [Verify a receipt](../docs/evidence.md#try-it-with-the-demo) checks your copy against those keys, then shows an edited copy failing.

To try a retry, repeat the original command with the same request key:

```bash
php examples/mcp.php publish riverside-evacuation first-demo-request
```

For this unchanged proposal, the command returns the existing request and result. It does not issue the alert again. If a retry is refused or a connection drops, read the existing request's status before trying a new request. Opening a link, dismissing a client prompt, or losing a connection does not establish the outcome.

### 5. Inspect the evidence and policy

Sign out, sign in as `manager`, and open **Audit log**. Find the completed action. The page shows the requester, rule, outcome, receipt check, and passkey check. It rechecks completed records when you open it. The **Evidence** link downloads the record for independent verification.

The application signs the receipt when the action commits. This lets a later verifier detect changes to the stored approval or execution record. See [Evidence and verification](../docs/evidence.md) for the trust keys, verification commands, and limits.

To change the site's signing key, run `php demo/bin/console demo:rotate-receipt-key` while nobody is approving. The old key stays trusted for what it signed, so your downloaded receipt still checks against a freshly saved `/approval/receipt-keys.json`, and the audit log records the rotation. `php demo/bin/console verified-human-approval:audit-head` prints the log's newest entry, a checkpoint to keep elsewhere. See [Rotate the signing key](../docs/evidence.md#rotate-the-signing-key).

These are the initial account rules:

| Account | Role | Issuing an alert |
| --- | --- | --- |
| `editor` | County staff | Requires approval with the editor's passkey |
| `manager` | Emergency manager and administrator | Allowed directly |
| `reader` | Read-only colleague | Refused |
| `other` | Another organization | Cannot see this organization's alerts |

To try a policy change, enroll the manager's passkey using its own setup link, then open **Administrator settings**. Changing the rule requires the manager's passkey approval. Requests still waiting under the previous rule cannot be approved under the new one.

## Run it again

To take down the alert while keeping accounts, passkeys, connections, and past records:

```bash
php demo/bin/console demo:all-clear
```

This operator command records the take-down in the security log. Use a new request key, such as `second-demo-request`, for the next publication.

To start with a completely fresh local demo, stop the server, run `php demo/bin/setup.php --reset`, save the new credentials, and restart the server. Reset removes the previous local demo data and keys; enroll a passkey and set the new token again.

On macOS, `demo/bin/server start` runs an initialized demo under launchd so it survives terminal restarts. Use this instead of the foreground server. It also accepts `stop`, `restart`, and `status`. For local debug mode, add `APP_DEBUG=1` before the foreground PHP server command. Keep debug mode off for the public demo.

## Use an agent client

Before trying another client, run `php demo/bin/console demo:all-clear` and sign back in as `editor` in the browser. If you changed the staff rule, restore its requirement for passkey approval first.

Setup prints connection instructions for the editor account:

- **Claude Desktop:** install Node.js/npm so `npx` is available. Add the printed `verified-human-approval` entry under `mcpServers` in `claude_desktop_config.json`, then restart Claude. The `mcp-remote` bridge reads the token from the private header file.
- **Claude Code:** run the printed `claude mcp add --transport http …` command with Claude Code installed. It contains your personal token; keep it private.

Ask: **“Issue the Riverside Road evacuation alert.”** A compatible client shows an approval card or an open-link prompt. Otherwise, use the returned review URL. Review and approve on the application, then check the recorded status. See [the example clients](../examples/README.md) for Symfony AI integration.

## Optional: public access and phone approval

Phones and claude.ai need a reachable HTTPS address. On macOS with `cloudflared` installed:

```bash
demo/bin/public start
```

This starts a second demo and a Cloudflare quick tunnel under launchd. It prints the address and MCP connector URL. Its credentials and passkey links are in `var/public-demo-setup.txt`; keep that file private. Passkeys belong to a hostname, so a tunnel that comes back with a new one needs a fresh demo: `start` then says what it would replace and asks first. `demo/bin/public start --yes` answers yes, for use without a terminal.

1. Open the public address, sign in as its `editor`, and enroll a passkey through that editor's link. Use a passkey available on the phone if you want to approve there.
2. Add the printed HTTPS MCP URL as a custom connector in claude.ai. Sign in as the public demo's editor and grant access through the demo's OAuth flow. claude.ai identifies itself with a client ID metadata document; the public demo does not let clients register themselves.
3. Request the alert. Use **Review and approve**, or **Approve on your phone** when the client displays the card. Scan the QR code, review the alert, and approve with the enrolled passkey.

The phone code works once for two minutes. It opens only that review, without granting a general login, and still requires the person's passkey.

`demo/bin/public all-clear` takes down the alert and keeps the accounts, passkeys, and connector. `demo/bin/public reset` replaces the public demo data on the same address. `demo/bin/public stop` stops it.

## Source map

| Path | Purpose |
| --- | --- |
| [`src/DemoKernel.php`](src/DemoKernel.php) | Bundle configuration, firewalls, routes, service wiring, and the scopes each tool needs, which a token lacking one is told with HTTP 403 |
| [`src/Mcp/DemoTools.php`](src/Mcp/DemoTools.php) | Read and publish tools, which check the connection's scopes again for themselves |
| [`src/Action/PublishAlert.php`](src/Action/PublishAlert.php) | Frozen proposal, version, preconditions, and postconditions |
| [`src/Action/AlertPublisher.php`](src/Action/AlertPublisher.php) | The guarded database mutation |
| [`src/Action/DemoPolicyProvider.php`](src/Action/DemoPolicyProvider.php) | The administrator's rules |
| [`src/Controller/AccountController.php`](src/Controller/AccountController.php) | Passkey enrollment and confirmation |
| [`src/Controller/AuditController.php`](src/Controller/AuditController.php) | Receipt and passkey checks in the administrator view |
| [`src/OAuth`](src/OAuth) | Application storage, connection lifecycle, bounded metadata fetching, and the consent step bound to the exact authorization request, for the maintained OAuth providers |
| [`src/Security/AgentTokenAuthenticator.php`](src/Security/AgentTokenAuthenticator.php) | Host firewall: validate the bearer token with League, resolve the actual local user, and pass verified scopes to the MCP SDK |
| [`../examples/README.md`](../examples/README.md) | SDK and Symfony AI clients |
