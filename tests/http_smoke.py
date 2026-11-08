#!/usr/bin/env python3
"""Real HTTP MCP + Symfony AI smoke test; model responses are scripted, never reported as a live model."""
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
if not PHP:
    raise SystemExit("Set PHP_BIN to a PHP 8.3+ executable.")

with tempfile.TemporaryDirectory(prefix="exact-approval-http-") as temporary:
    runtime = Path(temporary) / "runtime"
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    origin = f"http://localhost:{port}"
    env = {**os.environ, "EXACT_APPROVAL_RUNTIME": str(runtime), "EXACT_APPROVAL_ORIGIN": origin}
    setup = subprocess.run([PHP, "-r", 'require $argv[1]; echo json_encode(ExactApproval\\Demo\\DemoSetup::initialize(getenv("EXACT_APPROVAL_RUNTIME"), getenv("EXACT_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")], env=env, capture_output=True, text=True, check=True)
    credentials = json.loads(setup.stdout)["accounts"]
    env.update({"EXACT_APPROVAL_MCP_URL": origin + "/mcp", "EXACT_APPROVAL_TOKEN": credentials["editor"]["personal_token"]})
    with open(Path(temporary) / "server.log", "w+") as log:
        server = subprocess.Popen([PHP, "-S", f"localhost:{port}", "-t", str(ROOT / "examples/symfony/public"), str(ROOT / "examples/symfony/public/index.php")], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with urllib.request.urlopen(origin + "/login", timeout=1) as response:
                        if response.status == 200:
                            break
                except (OSError, TimeoutError):
                    time.sleep(0.1)
            else:
                raise AssertionError("Demo server did not start.")

            def mcp(*args):
                result = subprocess.run([PHP, str(ROOT / "examples/clients/mcp.php"), *args], env=env, capture_output=True, text=True, check=True, timeout=30)
                return json.loads(result.stdout)

            names = {tool["name"] for tool in mcp("list")["tools"]}
            assert {"publish_alert", "read_alert", "approval_status"} <= names
            assert mcp("read", "riverside-evacuation")["structuredContent"]["revision"] == 1
            pending = mcp("publish", "riverside-evacuation", "http-test-request")["structuredContent"]
            assert pending["status"] == "pending" and pending["review_url"].startswith(origin)
            assert mcp("status", pending["requestId"])["structuredContent"]["status"] == "pending"
            replay = mcp("publish", "riverside-evacuation", "http-test-request")["structuredContent"]
            assert replay["requestId"] == pending["requestId"]
            agent = subprocess.run([PHP, str(ROOT / "examples/clients/agent.php"), "--scripted"], env=env, capture_output=True, text=True, timeout=30)
            if agent.returncode:
                raise AssertionError(agent.stderr)
            assert "pending" in agent.stdout and "review_url" in agent.stdout, agent.stdout
            with sqlite3.connect(runtime / "application.sqlite") as db:
                assert db.execute("SELECT mutation_count FROM demo_announcements WHERE id='riverside-evacuation'").fetchone()[0] == 0
                assert db.execute("SELECT COUNT(*) FROM exact_approval_executions").fetchone()[0] == 0
            print(json.dumps({"passed": True, "checks": ["real HTTP MCP discovery", "read the draft alert", "request returns approval URL", "status remains pending", "request retry reuses proposal", "Symfony AI Agent + McpToolbox over real HTTP", "zero protected mutations without human approval"], "transport": "official MCP SDK and Symfony MCP Bundle", "live_model_calls": 0, "model_mode": "Symfony MockPlatformFactory scripted responses", "browser_or_physical_device_tested": False}, indent=2))
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
