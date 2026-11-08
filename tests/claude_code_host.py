#!/usr/bin/env python3
"""Optional check with the real Claude Code engine: URL-mode elicitation end to end.

Runs Claude Code in the stream-json host mode the Claude desktop app uses, against the demo's
MCP endpoint over HTTP. When the native prompt reaches the host, it approves on the review page
in Chrome with a virtual passkey, then answers "accept" the way a person clicking "I'm done"
would. The retried tool call must return the outcome the application recorded. One small model
call; no physical biometrics.

    CLAUDE_BIN=/path/to/claude CHROMIUM_BIN=/path/to/chrome PHP_BIN=php python3 tests/claude_code_host.py
"""
import json
import os
from pathlib import Path
import select
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
CLAUDE = os.environ.get("CLAUDE_BIN") or shutil.which("claude")
CHROMIUM = os.environ.get("CHROMIUM_BIN") or shutil.which("chromium")
MODEL = os.environ.get("CLAUDE_MODEL", "claude-haiku-5-5")
if not (PHP and CLAUDE and CHROMIUM):
    raise SystemExit("Set PHP_BIN, CLAUDE_BIN and CHROMIUM_BIN.")


def approve_in_browser(url: str, origin: str, password: str) -> None:
    """The person: sign in, enroll a passkey, review the exact page and approve it."""
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=CHROMIUM, headless=True, args=["--no-sandbox"])
        page = browser.new_page()
        cdp = page.context.new_cdp_session(page)
        cdp.send("WebAuthn.enable")
        cdp.send("WebAuthn.addVirtualAuthenticator", {"options": {
            "protocol": "ctap2", "transport": "internal", "hasResidentKey": True,
            "hasUserVerification": True, "isUserVerified": True, "automaticPresenceSimulation": True,
        }})
        page.goto(url)
        page.locator("#username").fill("editor")
        page.locator("#password").fill(password)
        page.get_by_role("button", name="Sign in", exact=True).click()
        page.wait_for_url(url)
        page.goto(origin + "/account/passkeys")
        page.locator("[data-approve]").click()
        expect(page.get_by_text("Personal passkey", exact=True)).to_be_visible(timeout=15000)
        page.goto(url)
        expect(page.locator(".rc-frame .rc-title")).to_contain_text("Flood update")
        page.locator("[data-approve]").click()
        expect(page.locator(".notice")).to_contain_text("completed", timeout=15000)
        browser.close()


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="exact-approval-claude-code-") as temporary:
        work = Path(temporary)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        origin = f"http://localhost:{port}"
        env = {**os.environ, "EXACT_APPROVAL_RUNTIME": str(work / "runtime"), "EXACT_APPROVAL_ORIGIN": origin}
        setup = subprocess.run([PHP, "-r", 'require $argv[1]; echo json_encode(ExactApproval\\Demo\\DemoSetup::initialize(getenv("EXACT_APPROVAL_RUNTIME"), getenv("EXACT_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")], env=env, capture_output=True, text=True, check=True)
        editor = json.loads(setup.stdout)["accounts"]["editor"]
        config = work / "mcp.json"
        config.write_text(json.dumps({"mcpServers": {"exact-approval": {"type": "http", "url": origin + "/mcp", "headers": {"Authorization": "Bearer " + editor["personal_token"]}}}}))
        config.chmod(0o600)

        with open(work / "server.log", "w") as log:
            server = subprocess.Popen([PHP, "-S", f"localhost:{port}", "-t", str(ROOT / "examples/symfony/public"), str(ROOT / "examples/symfony/public/index.php")], env={**env, "PHP_CLI_SERVER_WORKERS": "4"}, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with urllib.request.urlopen(origin + "/login", timeout=1):
                            break
                    except OSError:
                        time.sleep(0.1)
                report = run_claude(config, work, origin, editor["password"])
                with sqlite3.connect(work / "runtime/application.sqlite") as db:
                    report["committed_effects"] = db.execute("SELECT mutation_count FROM demo_announcements WHERE id = 'flood-update'").fetchone()[0]
            finally:
                server.terminate()
                server.wait(timeout=10)

    assert report["elicitation"] and report["elicitation"]["mode"] == "url", report
    assert report["elicitation"]["url"].startswith(origin + "/approval/apr_"), report
    assert any('"status":"completed"' in result.replace(" ", "") for result in report["tool_results"]), report
    assert report["committed_effects"] == 1, report
    report["passed"] = True
    print(json.dumps(report, indent=2))


def run_claude(config: Path, work: Path, origin: str, password: str) -> dict:
    tools = "mcp__exact-approval__publish_announcement,mcp__exact-approval__approval_status"
    claude = subprocess.Popen([CLAUDE, "-p", "--input-format", "stream-json", "--output-format", "stream-json", "--verbose",
                               "--mcp-config", str(config), "--strict-mcp-config", "--allowedTools", tools,
                               "--model", MODEL, "--max-turns", "6"],
                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd=work)

    def send(message: dict) -> None:
        claude.stdin.write(json.dumps(message) + "\n")
        claude.stdin.flush()

    send({"type": "control_request", "request_id": "init-1", "request": {"subtype": "initialize"}})
    send({"type": "user", "message": {"role": "user", "content":
          'Use the exact-approval server. Call publish_announcement once with announcement_id "flood-update". '
          "Then answer in one sentence with the status it reports."}})
    report = {"claude_code_version": None, "model": MODEL, "elicitation": None, "host_answer": None, "tool_results": [], "final": None}
    deadline = time.time() + 240
    while time.time() < deadline:
        ready, _, _ = select.select([claude.stdout], [], [], 5)
        if not ready:
            if claude.poll() is not None:
                break
            continue
        line = claude.stdout.readline()
        if not line:
            break
        try:
            message = json.loads(line)
        except ValueError:
            continue
        kind = message.get("type")
        if kind == "system" and message.get("subtype") == "init":
            report["claude_code_version"] = message.get("claude_code_version")
        elif kind == "control_request":
            request = message.get("request", {})
            if request.get("subtype") == "elicitation":
                report["elicitation"] = {key: request.get(key) for key in ("mcp_server_name", "mode", "url", "message")}
                approve_in_browser(request["url"], origin, password)
                send({"type": "control_response", "response": {"subtype": "success", "request_id": message["request_id"], "response": {"action": "accept"}}})
                report["host_answer"] = "accept, after the person approved with a passkey"
            elif request.get("subtype") == "can_use_tool":
                send({"type": "control_response", "response": {"subtype": "success", "request_id": message["request_id"], "response": {"behavior": "allow", "updatedInput": request.get("input", {})}}})
        elif kind == "user":
            for content in (message.get("message", {}).get("content") or []):
                if isinstance(content, dict) and content.get("type") == "tool_result":
                    body = content.get("content")
                    text = body if isinstance(body, str) else " ".join(part.get("text", "") for part in body or [] if isinstance(part, dict))
                    report["tool_results"].append(text[:600])
        elif kind == "result":
            report["final"] = (message.get("result") or "")[:300]
            break
    claude.terminate()
    return report


if __name__ == "__main__":
    main()
