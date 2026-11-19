# Example MCP clients

The [publishing example](doctrine-publishing/README.md) is a separate application: a newsroom on Doctrine ORM where publishing, live edits, archiving and deleting each need the person's passkey. The rest of this page is about the clients below.

These clients connect to the [Riverside Council demo](../demo/README.md) over HTTP. They use the real MCP SDK and the application's normal authentication, policy, and approval flow.

Run all commands from the repository root after `composer install`. Start the demo server and enroll the editor's passkey using the [demo walkthrough](../demo/README.md). Keep the server running in a separate terminal.

If you finished that walkthrough, run `php demo/bin/console demo:all-clear` and sign back in as `editor` in the browser. Restore the staff rule's passkey requirement if you changed it.

## Connection settings

The examples require the editor's `personal_token` from setup. Enter it without putting the token in the command itself. This works in Bash and zsh:

```bash
printf 'Editor personal token: '
read -r -s VERIFIED_HUMAN_APPROVAL_TOKEN
printf '\n'
export VERIFIED_HUMAN_APPROVAL_TOKEN
```

| Variable | Purpose |
| --- | --- |
| `VERIFIED_HUMAN_APPROVAL_TOKEN` | Required personal connection token. It authenticates requests; it cannot approve them. |
| `VERIFIED_HUMAN_APPROVAL_MCP_URL` | Defaults to `http://localhost:8080/mcp`. For a demo at another address, such as another port or a public instance's HTTPS one, set it to the MCP URL setup printed. |
| `OPENAI_API_KEY` | Required only for the live model example. |
| `VERIFIED_HUMAN_APPROVAL_MODEL` | Optional model for the live model example, instead of `gpt-4.1-mini`. |

Use a token from the instance you are connecting to. The local and public demos have separate accounts and tokens. The client permits HTTPS endpoints and HTTP on `localhost`; it does not follow redirects.

## MCP SDK client

[`mcp.php`](mcp.php) discovers tools and calls them directly. It does not use a language model.

```bash
php examples/mcp.php list
php examples/mcp.php read riverside-evacuation
php examples/mcp.php publish riverside-evacuation example-request-1
```

| Command | What it does |
| --- | --- |
| `list` | Lists the tools the connection can discover. This is the default command. |
| `read [alert-id]` | Calls `read_alert`; the default alert is `riverside-evacuation`. |
| `publish [alert-id] [request-key]` | Calls `publish_alert`. It generates a new random key if you omit the request key. |
| `status REQUEST_ID` | Calls `approval_status` to read a stored request's outcome. |

For the editor under the initial policy, `publish` returns a pending result with `requestId` and `review_url`. Open that URL, review the proposal, and approve with the editor's passkey. Then replace `REQUEST_ID` below with the returned ID:

```bash
php examples/mcp.php status REQUEST_ID
```

Only `completed` confirms that the action ran. Save the request ID and use status checks after interruptions. To retry a request, reuse its request key and arguments. For an unchanged proposal, this returns the same request; a new key asks for a new action. If a retry is refused, check the original request's status before making another request.

## Symfony AI example

### Scripted model responses

```bash
php examples/agent.php --scripted
```

This runs Symfony AI's real `Agent` and `McpToolbox`, discovers tools from the server, and sends a real publication request. Only the model responses are scripted. The script always requests the `riverside-evacuation` alert with a fresh request key, then prints the tool result.

No model API key is needed. Human approval is not simulated: with the editor's initial policy, the initial result is pending. Approval still happens on the site, and the request can expire or be cancelled. Use the returned review URL and check status with `mcp.php` as above. This mode checks the integration flow; it is not a language-model benchmark.

If you already issued the alert in the earlier example, follow [Run it again](../demo/README.md#run-it-again) before demonstrating another publication.

### Live model

The same script can use the Symfony AI OpenAI bridge. Supply an API key:

```bash
printf 'OpenAI API key: '
read -r -s OPENAI_API_KEY
printf '\n'
export OPENAI_API_KEY

php examples/agent.php 'Issue the riverside-evacuation alert. If approval is needed, show its review link and stop.'
```

It uses `gpt-4.1-mini`. To use another model your API account offers, set `VERIFIED_HUMAN_APPROVAL_MODEL` to its ID first.

This mode makes a live model request and may incur API charges. Its instructions tell the agent to show the application's review URL and stop while approval is pending. The application enforces approval regardless of the model's response. Check the application's recorded status before treating an action as complete.

## Source map

| File | Purpose |
| --- | --- |
| [`connection.php`](connection.php) | Endpoint validation, bearer token, HTTP transport, MCP client, and Symfony AI toolset |
| [`mcp.php`](mcp.php) | Direct tool discovery and calls |
| [`agent.php`](agent.php) | Symfony AI runner with scripted or live model responses |
| [`PrintReviewLink.php`](PrintReviewLink.php) | Prints a URL prompt's review link and dismisses the client prompt; it does not cancel the application's pending request |

The demo normally returns the review URL in its tool result to these clients. Its optional URL prompt is configured for selected client names. Neither a client prompt response nor the personal token can replace passkey approval on the application.
