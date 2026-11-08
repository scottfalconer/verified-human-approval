#!/usr/bin/env python3
"""Real Chromium + MCP/HTTP + virtual WebAuthn acceptance test. No physical biometrics or LLM calls."""
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

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
if not PHP:
    raise SystemExit("Set PHP_BIN to a PHP 8.3+ executable.")
CHROMIUM = os.environ.get("CHROMIUM_BIN") or shutil.which("chromium")
SHOTS = Path(os.environ["SCREENSHOT_DIR"]) if os.environ.get("SCREENSHOT_DIR") else None


def main():
    with tempfile.TemporaryDirectory(prefix="exact-approval-browser-") as temporary:
        runtime = Path(temporary) / "runtime"
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        origin = f"http://localhost:{port}"
        env = {**os.environ, "EXACT_APPROVAL_RUNTIME": str(runtime), "EXACT_APPROVAL_ORIGIN": origin}
        setup = subprocess.run(
            [PHP, "-r", 'require $argv[1]; echo json_encode(ExactApproval\\Demo\\DemoSetup::initialize(getenv("EXACT_APPROVAL_RUNTIME"), getenv("EXACT_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")],
            env=env, capture_output=True, text=True, check=True,
        )
        credentials = json.loads(setup.stdout)["accounts"]
        env.update({"EXACT_APPROVAL_MCP_URL": origin + "/mcp", "EXACT_APPROVAL_TOKEN": credentials["editor"]["personal_token"]})
        log = open(Path(temporary) / "server.log", "w+")
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

            def row():
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.row_factory = sqlite3.Row
                    return dict(db.execute("SELECT * FROM demo_announcements WHERE id = 'flood-update'").fetchone())

            catalogue = mcp("list")
            names = {tool["name"] for tool in catalogue["tools"]}
            assert {"publish_announcement", "read_announcement", "approval_status"} <= names
            initial = mcp("publish", "flood-update", "browser-approval-one")["structuredContent"]
            assert initial["status"] == "pending" and row()["mutation_count"] == 0

            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(executable_path=CHROMIUM, headless=True, args=["--no-sandbox"])
                page = browser.new_page(viewport={"width": 1180, "height": 900})
                errors = []
                page.on("pageerror", lambda error: errors.append(str(error)))
                cdp = page.context.new_cdp_session(page)
                cdp.send("WebAuthn.enable")
                cdp.send("WebAuthn.addVirtualAuthenticator", {"options": {
                    "protocol": "ctap2", "transport": "internal", "hasResidentKey": True,
                    "hasUserVerification": True, "isUserVerified": True, "automaticPresenceSimulation": True,
                }})
                page.goto(initial["review_url"])
                expect(page.locator("#username")).to_be_visible()
                page.locator("#username").fill("editor")
                page.locator("#password").fill(credentials["editor"]["password"])
                page.get_by_role("button", name="Sign in", exact=True).click()
                page.wait_for_url(initial["review_url"])
                page.goto(origin + "/account/passkeys")
                page.locator("[data-approve]").click()
                expect(page.get_by_text("Personal passkey", exact=True)).to_be_visible(timeout=15000)

                page.goto(initial["review_url"])
                expect(page.locator(".statement")).to_contain_text("Flood update")
                expect(page.locator(".content-preview")).to_contain_text("Hill Street detour")
                # The approval page shows the final public page, rendered from the frozen proposal.
                approved_title = page.locator(".rc-frame .rc-title").inner_text()
                approved_body = page.locator(".rc-frame .rc-body").inner_text()
                assert page.request.get(origin + "/news/flood-update").status == 404, "Nothing is live before approval"
                if SHOTS:
                    page.screenshot(path=str(SHOTS / "1-approval-page.png"), full_page=True)
                page.locator("[data-approve]").click()
                expect(page.locator(".notice")).to_contain_text("completed", timeout=15000)
                assert row()["mutation_count"] == 1 and row()["published_revision"] == 1
                page.goto(origin + "/news/flood-update")
                assert page.locator(".rc-title").inner_text() == approved_title
                assert page.locator(".rc-body").inner_text() == approved_body
                if SHOTS:
                    page.screenshot(path=str(SHOTS / "2-live-page.png"), full_page=True)
                status = mcp("status", initial["requestId"])["structuredContent"]
                assert status["status"] == "completed"
                replay = mcp("publish", "flood-update", "browser-approval-one")["structuredContent"]
                assert replay["status"] == "completed" and row()["mutation_count"] == 1

                # Stage a *new* draft through a versioned writer, request approval, then change it after review.
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.execute("UPDATE demo_announcements SET revision = revision + 1, body = body || ' New draft.' WHERE id = 'flood-update'")
                pending = mcp("publish", "flood-update", "browser-stale-two")["structuredContent"]
                page.goto(pending["review_url"])
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.execute("UPDATE demo_announcements SET revision = revision + 1, body = body || ' Changed after review.' WHERE id = 'flood-update'")
                page.locator("[data-approve]").click()
                expect(page.locator("[data-message]")).to_contain_text("no longer eligible", timeout=15000)
                stale = mcp("status", pending["requestId"])["structuredContent"]
                assert stale["status"] == "invalidated" and row()["mutation_count"] == 1
                assert errors == [], errors
                browser.close()

            # Exercise Symfony AI's actual Agent/McpToolbox, with a clearly labelled scripted model.
            agent = subprocess.run([PHP, str(ROOT / "examples/clients/agent.php"), "--scripted"], env=env, capture_output=True, text=True, check=True, timeout=30)
            assert "pending" in agent.stdout and "review_url" in agent.stdout, agent.stdout
            assert row()["mutation_count"] == 1
            print(json.dumps({
                "passed": True,
                "mcp_transport": "official PHP SDK over real HTTP to Symfony MCP Bundle",
                "browser": "Chromium, headless; native WebAuthn API with CDP virtual authenticator",
                "checks": ["tool discovery", "pending before approval", "human login", "passkey enrollment", "exact content review", "verified approval commits once", "MCP status", "idempotent request replay", "stale proposal refuses execution", "Symfony AI scripted runner uses real MCP"],
                "committed_effects": 1,
                "physical_biometrics_tested": False,
                "external_hosted_clients_tested": False,
                "live_model_calls": 0,
            }, indent=2))
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
            log.close()


if __name__ == "__main__":
    main()
