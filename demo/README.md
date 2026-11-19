# Riverside Council demo

This Symfony application runs a fictional council's public alerts site. An agent can ask to issue an alert. The application's rule decides whether the caller may issue it directly, must approve it with a passkey, or is refused.

The walkthrough uses the real MCP SDK client supplied in this repository. You do not need Claude, a model API key, or a paid model. You will request an alert, review the exact proposal, approve it, and check the recorded result.

The accounts, alert pages, administrator screens and OAuth setup belong to this application, not to the approval packages. Its OAuth server comes from two maintained bundles, `league/oauth2-server-bundle` and `omouren/mcp-oauth-bundle`; the approval packages neither include nor need one. [Authentication ownership](authentication.md) says which part does what, including the consent step, agent connections and a version alias the OAuth bundle needs. For your own application, start with the [Symfony bundle guide](../src/symfony-bundle/README.md).

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

Setup prints, as JSON, each account's `password`, `personal_token`, and `passkey_link`, then the commands to start the server and connect Claude. Save this output privately before you start the server: credentials are shown only once, and the server's log scrolls them out of view. With `--json`, setup prints only that JSON, for a script to read. Setup also writes the editor's token to a private header file for the optional Claude Desktop connection.

Start the server in the same terminal and leave it running:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php
```

The four workers allow browser requests and MCP calls to run at the same time. Open the site at `http://localhost:8080`, not `127.0.0.1`: its passkeys belong to the name `localhost`. On macOS the server may listen only on IPv6, so `127.0.0.1` may not connect at all; at any other address that reaches the server, the demo answers only with where to open it.

#### If port 8080 is taken

If PHP's server stops at once with `Failed to listen on localhost:8080 (reason: Address already in use)`, another program has that port. The demo answers only at the address it was set up for, because its passkeys and the links it gives agents belong to that address; opened at another one, it says where to open it. To use port 8090 instead, set the demo up for that address and start the server on the same port:

```bash
VERIFIED_HUMAN_APPROVAL_ORIGIN=http://localhost:8090 php demo/bin/setup.php --reset
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8090 -t demo/public demo/public/index.php
```

`--reset` replaces a demo you already set up, with its accounts and passkeys. Setup prints the server command for the address it was given. It reads the address each time it runs, so set `VERIFIED_HUMAN_APPROVAL_ORIGIN` again for a later `--reset`; without it, setup uses `http://localhost:8080`. In step 3, export `VERIFIED_HUMAN_APPROVAL_MCP_URL=http://localhost:8090/mcp` instead, and use port 8090 wherever this guide links to the site. On macOS, `demo/bin/server start` serves it on port 8090 too: it reads the address from the demo's settings.

### 2. Add the editor's passkey

1. Open [the local site](http://localhost:8080) and sign in as `editor`, using the password from setup.
2. Open that account's `passkey_link` from the same setup output, in the same browser.
3. Choose **Register a passkey** and complete your browser's passkey prompt.

The first passkey requires this one-time link, which expires after seven days. A password alone cannot enroll it. Adding another passkey requires confirming with an existing one, or a new one-time link from an administrator, who confirms with their own passkey to issue it.

Your device may use a fingerprint, face recognition, or a device PIN to unlock the passkey. The application verifies a WebAuthn signature; it does not receive your biometric data.

### 3. Request an alert through MCP

In **Terminal B**, change to the same checkout. The first command names the demo's MCP endpoint, which receives the editor's token: if you set the demo up for another port, use that port. Then enter the editor's `personal_token` at the prompt. Use the token, not the account password. These commands work in Bash and zsh and hide the pasted value:

```bash
export VERIFIED_HUMAN_APPROVAL_MCP_URL=http://localhost:8080/mcp
printf 'Editor personal token: '
read -r -s VERIFIED_HUMAN_APPROVAL_TOKEN
printf '\n'
export VERIFIED_HUMAN_APPROVAL_TOKEN

php examples/mcp.php list
php examples/mcp.php read riverside-evacuation
php examples/mcp.php publish riverside-evacuation first-demo-request
```

`list` prints the definition of every tool the connection can discover, several hundred lines of JSON; look for the names `read_alert`, `publish_alert` and `approval_status`. `read` shows the draft and current published content. `publish` returns a tool result containing `requestId`, `status: pending`, and `review_url`.

Keep the request ID. At this point, [the public alerts page](http://localhost:8080/alerts) still says **No active alerts**. A pending request has not issued anything.

### 4. Review, approve, and check the result

Open the returned `review_url` while signed in as `editor`. The page shows the frozen alert and the statement you are approving. Check both, then choose **Approve with your passkey**. Successful approval executes the action; there is no separate publish step. The page then says you can close the tab and return to your AI assistant: in this walkthrough, that is Terminal B.

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

The application signs the receipt when the action commits. This lets a later verifier detect changes to the stored approval or execution record. See [Verify a receipt](../docs/evidence.md) for the trust keys, verification commands, and limits.

To change the site's signing key, run `php demo/bin/console demo:rotate-receipt-key` while nobody is approving. The old key stays trusted for what it signed, so your downloaded receipt still checks against a freshly saved `/approval/receipt-keys.json`, and the audit log records the rotation. `php demo/bin/console verified-human-approval:audit-head` prints the log's newest entry, a checkpoint to keep elsewhere. See [Rotate the signing key](../docs/evidence.md#rotate-the-signing-key).

These are the initial account rules:

| Account | Role | Issuing an alert |
| --- | --- | --- |
| `editor` | County staff | Requires approval with the editor's passkey |
| `manager` | Emergency manager and administrator | Allowed directly |
| `reader` | Read-only colleague | Refused |
| `other` | Another organization | Cannot see this organization's alerts |

To try a policy change, enroll the manager's passkey using its own setup link, then open **Alert rule**. Changing the rule requires the manager's passkey approval. Requests still waiting under the previous rule cannot be approved under the new one.

## Run it again

To take down the alert while keeping accounts, passkeys, connections, and past records:

```bash
php demo/bin/console demo:all-clear
```

This operator command records the take-down in the audit log. Use a new request key, such as `second-demo-request`, for the next publication.

To start with a completely fresh local demo, stop the server, run `php demo/bin/setup.php --reset`, save the new credentials, and restart the server. If you set the demo up for another port, put the same `VERIFIED_HUMAN_APPROVAL_ORIGIN=...` in front of `php demo/bin/setup.php --reset`, or setup goes back to `http://localhost:8080`. Reset removes the previous local demo data and keys; enroll a passkey and set the new token again.

On macOS, `demo/bin/server start` runs an initialized demo under launchd instead of in a terminal, at the address it was set up for, so it survives terminal restarts. Stop the foreground server first. `start` refuses while another program holds the port, and says how to free it or use another one. `demo/bin/server status` says whether this checkout's server or another program holds the port, and `demo/bin/server stop` stops only the server this script started under launchd, never one that another folder or project started. It also accepts `restart`.

For debug mode, which shows stack traces, put `APP_DEBUG=1` in front of the foreground server command. Use it only for a demo on this computer.

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

This starts a second demo and a Cloudflare quick tunnel under launchd. It prints the address and MCP connector URL. The second demo's credentials and passkey links are in `var/public-demo-setup.txt`; keep that file private. Its server uses port 8081 on this computer, and the tunnel publishes whatever answers there, so nothing else may use that port while the public demo runs. Passkeys belong to a hostname, so a tunnel that comes back with a new one needs a fresh demo: `start` then says what it would replace and asks first. `demo/bin/public start --yes` answers yes, for use without a terminal.

1. Open the public address, sign in as its `editor`, and enroll a passkey through that editor's link. Use a passkey available on the phone if you want to approve there.
2. In claude.ai, add the printed MCP URL as a custom connector, and sign in as the public demo's editor. The consent page should say the client was published by claude.ai and returns to claude.ai; choose **Allow**.
3. Request the alert. Use **Review and approve**, or **Approve on your phone** when the client displays the card. Scan the QR code, review the alert, and approve with the enrolled passkey.

The consent page can name claude.ai because claude.ai identifies itself with a client ID metadata document on its own host; clients cannot register themselves on the public demo. The demo accepts ChatGPT's document too, but [no run with ChatGPT is recorded](../docs/verification.md#what-is-not-proven).

The phone code works once for two minutes. It opens only that review, without granting a general login, and still requires the person's passkey.

`demo/bin/public status` shows the tunnel's address and whether the public demo is served, or what else holds its port. `demo/bin/public all-clear` takes down the alert and keeps the accounts, passkeys, and connector. `demo/bin/public reset` replaces the public demo with a fresh one, with new accounts, passkey links and connections, on the same address. So it runs only while `status` shows this checkout's tunnel at that address: after `stop`, or once the tunnel has stopped or come back at a new address, it sets nothing up and says to run `start`. `demo/bin/public stop` stops the tunnel and the public demo's server.

`start` and `reset` refuse while anything else holds port 8081. Whenever either is refused, fails or is interrupted, by Ctrl-C for instance, before the public demo is served, it also stops this checkout's tunnel. Without a network connection, for example, `start` says that the tunnel did not start, points to `var/public-tunnel.log`, and leaves the public demo as it was. A `kill -9` leaves the tunnel running: stop it with `demo/bin/public stop`. Once the tunnel has stopped, the next `start` gets a new address, and so needs a fresh demo: new accounts and passkeys, and a new connector URL in claude.ai.

Only one public demo runs on a computer at a time. From another checkout, `start` refuses and names the checkout that runs it, `reset` refuses too, and `stop` leaves it running.

## Source map

| Path | Purpose |
| --- | --- |
| [`src/DemoKernel.php`](src/DemoKernel.php) | Bundle configuration, firewalls, routes, service wiring, and the scopes each tool needs, which a token lacking one is told with HTTP 403 |
| [`src/Mcp/DemoTools.php`](src/Mcp/DemoTools.php) | The read and publish tools. Reading needs the connection's `alert.read` scope. Publishing needs `approval.read`, since a retry can return an earlier result, and the authorizer also checks `alert.publish` |
| [`src/Action/PublishAlert.php`](src/Action/PublishAlert.php) | Frozen proposal, version, preconditions, and postconditions |
| [`src/Action/AlertPublisher.php`](src/Action/AlertPublisher.php) | The guarded database mutation |
| [`src/Action/DemoPolicyProvider.php`](src/Action/DemoPolicyProvider.php) | The administrator's rules |
| [`src/Controller/AccountController.php`](src/Controller/AccountController.php) | Passkey enrollment and confirmation |
| [`src/Controller/AuditController.php`](src/Controller/AuditController.php) | Receipt and passkey checks in the administrator view |
| [`src/OAuth`](src/OAuth) | Application storage, connection lifecycle, bounded metadata fetching, and the consent step bound to the exact authorization request, for the maintained OAuth providers |
| [`src/Security/AgentTokenAuthenticator.php`](src/Security/AgentTokenAuthenticator.php) | Host firewall: validate an OAuth access token with League or look up a personal token by its hash, resolve the actual local user, and pass verified scopes to the MCP SDK |
| [`../examples/README.md`](../examples/README.md) | SDK and Symfony AI clients |
