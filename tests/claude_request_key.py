#!/usr/bin/env python3
"""Optional check with real Claude models: does an agent leave the request key out?

A request key is for a client that generates a unique id for each new request. Asked for "your own
id for this request", Claude models built one from the alert, its revision and the date, and often
built the same one in a later conversation, where it returns the earlier request and its outcome.
The tools describe the argument with ApprovalFlow::REQUEST_KEY_DESCRIPTION, which says to leave it
out unless the client generates such ids.

This runs Claude Code in print mode against the demo's MCP endpoint over HTTP: a new conversation
for each run, a plain system prompt, and a request that says nothing about arguments. It fails if
a run sends a request key. It also checks what leaving the key out relies on: every conversation
got the same pending request, so the application stored one. A model sometimes shows the draft and
asks before it sends anything; such a run says nothing about the key and is reported, not failed.

CLAUDE_RUNS model calls (default 3) for each model in CLAUDE_MODELS (default claude-haiku-5-5);
each call is a short conversation. No browser, and nothing is approved.

    CLAUDE_BIN=/path/to/claude PHP_BIN=php python3 tests/claude_request_key.py
"""
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
CLAUDE = os.environ.get("CLAUDE_BIN") or shutil.which("claude")
MODELS = [model for model in os.environ.get("CLAUDE_MODELS", "claude-haiku-5-5").split(",") if model]
RUNS = int(os.environ.get("CLAUDE_RUNS", "3"))
ASK = os.environ.get("CLAUDE_ASK", "Send the evacuation order for Riverside Road.")
SYSTEM = "You are Claude, a helpful AI assistant made by Anthropic. The user has connected a tool server for their council's website."
if not (PHP and CLAUDE):
    raise SystemExit("Set PHP_BIN and CLAUDE_BIN.")


def ask_claude(config: Path, work: Path, model: str) -> dict:
    """One new conversation. Returns the arguments of each publish_alert call the model made."""
    tools = ",".join("mcp__verified-human-approval__" + name for name in ("read_alert", "publish_alert", "approval_status"))
    # A session started inside Claude Code would otherwise be refused as nested.
    env = {key: value for key, value in os.environ.items() if key not in ("CLAUDECODE", "CLAUDE_CODE_ENTRYPOINT")}
    done = subprocess.run([CLAUDE, "-p", ASK, "--output-format", "stream-json", "--verbose", "--mcp-config", str(config), "--strict-mcp-config",
                           "--setting-sources", "project", "--system-prompt", SYSTEM, "--allowedTools", tools, "--model", model, "--max-turns", "8"],
                          cwd=work, env=env, stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=240)
    run = {"model": model, "claude_code_version": None, "publish_alert": [], "tools": [], "reply": None}
    for line in done.stdout.splitlines():
        try:
            message = json.loads(line)
        except ValueError:
            continue
        if message.get("type") == "system" and message.get("subtype") == "init":
            run["claude_code_version"] = message.get("claude_code_version")
            run["model"] = message.get("model") or model
        elif message.get("type") == "assistant":
            for block in message.get("message", {}).get("content") or []:
                if block.get("type") == "tool_use":
                    run["tools"].append(block.get("name", "").split("__")[-1])
                    if block.get("name", "").endswith("__publish_alert"):
                        run["publish_alert"].append(block.get("input"))
        elif message.get("type") == "result":
            run["reply"] = (message.get("result") or message.get("subtype") or "")[:300]
    if not run["publish_alert"] and not run["reply"]:
        run["reply"] = (done.stderr or done.stdout)[-300:]
    return run


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="verified-human-approval-request-key-") as temporary:
        work = Path(temporary)
        project = work / "empty-project"
        project.mkdir()
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        origin = f"http://localhost:{port}"
        env = {**os.environ, "VERIFIED_HUMAN_APPROVAL_RUNTIME": str(work / "runtime"), "VERIFIED_HUMAN_APPROVAL_ORIGIN": origin}
        setup = subprocess.run([PHP, "-r", 'require $argv[1]; echo json_encode(VerifiedHumanApproval\\Demo\\DemoSetup::initialize(getenv("VERIFIED_HUMAN_APPROVAL_RUNTIME"), getenv("VERIFIED_HUMAN_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")], env=env, capture_output=True, text=True, check=True)
        editor = json.loads(setup.stdout)["accounts"]["editor"]
        config = work / "mcp.json"
        config.write_text(json.dumps({"mcpServers": {"verified-human-approval": {"type": "http", "url": origin + "/mcp", "headers": {"Authorization": "Bearer " + editor["personal_token"]}}}}))
        config.chmod(0o600)

        with open(work / "server.log", "w") as log:
            server = subprocess.Popen([PHP, "-S", f"localhost:{port}", "-t", str(ROOT / "demo/public"), str(ROOT / "demo/public/index.php")], env={**env, "PHP_CLI_SERVER_WORKERS": "4"}, stdout=log, stderr=log, start_new_session=True)
            try:
                for _ in range(100):
                    try:
                        with urllib.request.urlopen(origin + "/alerts", timeout=1):
                            break
                    except OSError:
                        time.sleep(0.1)
                runs = [ask_claude(config, project, model) for model in MODELS for _ in range(RUNS)]
                with sqlite3.connect(work / "runtime/application.sqlite") as db:
                    stored = db.execute("SELECT idempotency_key, status FROM verified_human_approval_requests").fetchall()
            finally:
                os.killpg(server.pid, signal.SIGTERM)  # the server and its forked workers
                server.wait(timeout=10)

    report = {
        "ask": ASK,
        "runs": [{"model": run["model"], "claude_code_version": run["claude_code_version"], "tools_called": run["tools"], "publish_alert_arguments": run["publish_alert"], "reply": run["reply"]} for run in runs],
        "requests_stored": [{"stored_key": key, "status": status} for key, status in stored],
        "live_model_calls": len(runs),
    }
    print(json.dumps(report, indent=2))
    asked = [run for run in runs if run["publish_alert"]]
    assert asked, "No run asked to publish, so this says nothing about the request key. See each run's reply."
    sent = [arguments for run in asked for arguments in run["publish_alert"] if "request_key" in arguments]
    assert not sent, f"A model sent a request key: {sent}"
    assert stored == [(None, "pending")], f"Every conversation should have reused one pending request: {stored}"
    print(json.dumps({"passed": True, "runs_that_asked_to_publish": len(asked), "runs_that_stopped_before_asking": len(runs) - len(asked)}))


if __name__ == "__main__":
    main()
