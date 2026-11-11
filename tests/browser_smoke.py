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
    with tempfile.TemporaryDirectory(prefix="verified-human-approval-browser-") as temporary:
        runtime = Path(temporary) / "runtime"
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        origin = f"http://localhost:{port}"
        env = {**os.environ, "VERIFIED_HUMAN_APPROVAL_RUNTIME": str(runtime), "VERIFIED_HUMAN_APPROVAL_ORIGIN": origin}
        setup = subprocess.run(
            [PHP, "-r", 'require $argv[1]; echo json_encode(VerifiedHumanApproval\\Demo\\DemoSetup::initialize(getenv("VERIFIED_HUMAN_APPROVAL_RUNTIME"), getenv("VERIFIED_HUMAN_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")],
            env=env, capture_output=True, text=True, check=True,
        )
        credentials = json.loads(setup.stdout)["accounts"]
        # The review page loads the bundle's script from demo/public/bundles/, as after assets:install in any Symfony app.
        subprocess.run([PHP, str(ROOT / "demo/bin/console"), "assets:install", str(ROOT / "demo/public"), "--symlink", "--relative"], env=env, capture_output=True, check=True)
        env.update({"VERIFIED_HUMAN_APPROVAL_MCP_URL": origin + "/mcp", "VERIFIED_HUMAN_APPROVAL_TOKEN": credentials["editor"]["personal_token"]})
        log = open(Path(temporary) / "server.log", "w+")
        server = subprocess.Popen([PHP, "-S", f"localhost:{port}", "-t", str(ROOT / "demo/public"), str(ROOT / "demo/public/index.php")], env=env, stdout=log, stderr=log)
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
                result = subprocess.run([PHP, str(ROOT / "examples/mcp.php"), *args], env=env, capture_output=True, text=True, check=True, timeout=30)
                return json.loads(result.stdout)

            def row():
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.row_factory = sqlite3.Row
                    return dict(db.execute("SELECT * FROM demo_alerts WHERE id = 'riverside-evacuation'").fetchone())

            catalogue = mcp("list")
            names = {tool["name"] for tool in catalogue["tools"]}
            assert {"publish_alert", "read_alert", "approval_status"} <= names
            initial = mcp("publish", "riverside-evacuation", "browser-approval-one")["structuredContent"]
            assert initial["status"] == "pending" and row()["mutation_count"] == 0

            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(executable_path=CHROMIUM, headless=True, args=["--no-sandbox"])
                # A reader far from the server: the page shows its times in the reader's own zone.
                page = browser.new_page(viewport={"width": 1180, "height": 900}, timezone_id="Asia/Tokyo")
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
                # A password alone cannot add an approval passkey: the first one needs the account's one-time link.
                page.goto(credentials["editor"]["passkey_link"])
                page.wait_for_url(origin + "/account/passkeys")
                page.locator("[data-approve]").click()
                expect(page.get_by_text("Personal passkey", exact=True)).to_be_visible(timeout=15000)

                # An expired browser session asks for sign-in, then returns to this existing request.
                page.goto(initial["review_url"] + "#return=claude-web")
                page.context.clear_cookies()
                page.locator("[data-approve]").click()
                expect(page.locator("[data-message]")).to_contain_text("Your sign-in expired.")
                expect(page.locator("[data-authentication-recovery]")).to_have_attribute("href", initial["review_url"] + "#return=claude-web")
                assert row()["mutation_count"] == 0
                page.locator("[data-authentication-recovery]").click()
                page.wait_for_url(lambda url: url.split("#", 1)[0] == origin + "/login")
                page.locator("#username").fill("editor")
                page.locator("#password").fill(credentials["editor"]["password"])
                page.get_by_role("button", name="Sign in", exact=True).click()
                page.wait_for_url(lambda url: url.split("#", 1)[0] == initial["review_url"])
                expect(page.locator("[data-approve]")).to_be_visible()
                assert row()["mutation_count"] == 0
                recovered = mcp("status", initial["requestId"])["structuredContent"]
                assert recovered["status"] == "pending"

                # Opened the way Claude Desktop's card opens it, so the page offers the way back.
                page.goto(initial["review_url"] + "#return=claude-desktop")
                expect(page.locator(".statement")).to_contain_text("Evacuation order")
                expect(page.locator(".rc-frame .rc-body")).to_contain_text("Hill Street route")
                expect(page.locator('time[data-local-time="time"]')).to_contain_text("GMT+9")
                expect(page.locator('time[data-local-time="datetime"]')).to_contain_text("GMT+9")
                # The approval page shows the final public page, rendered from the frozen proposal.
                approved_title = page.locator(".rc-frame .rc-title").inner_text()
                approved_body = page.locator(".rc-frame .rc-body").inner_text()
                assert "No active alerts" in page.request.get(origin + "/alerts").text(), "Nothing is live before approval"
                if SHOTS:
                    page.screenshot(path=str(SHOTS / "1-approval-page.png"), full_page=True)
                page.locator("[data-approve]").click()
                expect(page.locator(".notice")).to_contain_text("completed", timeout=15000)
                assert row()["mutation_count"] == 1 and row()["published_revision"] == 1
                expect(page.locator("[data-return-app]")).to_be_visible()
                assert page.locator("[data-return-app]").get_attribute("href") == "claude://"
                if SHOTS:
                    page.screenshot(path=str(SHOTS / "3-approved.png"), full_page=True)
                page.goto(origin + "/alerts")
                assert page.locator(".rc-title").inner_text() == approved_title
                assert page.locator(".rc-body").inner_text() == approved_body
                if SHOTS:
                    page.screenshot(path=str(SHOTS / "2-live-page.png"), full_page=True)
                status = mcp("status", initial["requestId"])["structuredContent"]
                assert status["status"] == "completed"
                replay = mcp("publish", "riverside-evacuation", "browser-approval-one")["structuredContent"]
                assert replay["status"] == "completed" and row()["mutation_count"] == 1

                # Stage a *new* draft through a versioned writer, request approval, then change it after review.
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.execute("UPDATE demo_alerts SET revision = revision + 1, body = body || ' New draft.' WHERE id = 'riverside-evacuation'")
                pending = mcp("publish", "riverside-evacuation", "browser-stale-two")["structuredContent"]
                page.goto(pending["review_url"])
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.execute("UPDATE demo_alerts SET revision = revision + 1, body = body || ' Changed after review.' WHERE id = 'riverside-evacuation'")
                page.locator("[data-approve]").click()
                # The site says the request stopped, so the page shows it stopped, with no button left to press.
                expect(page.locator(".approval-sheet .notice")).to_contain_text("can no longer be approved", timeout=15000)
                expect(page.locator("[data-approve]")).to_have_count(0)
                stale = mcp("status", pending["requestId"])["structuredContent"]
                assert stale["status"] == "invalidated" and row()["mutation_count"] == 1

                # The site acts on the passkey response, but its answer never reaches the page. The page does
                # not guess: it says it can't confirm, and a reload shows what the site recorded.
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.execute("UPDATE demo_alerts SET revision = revision + 1, body = body || ' Third draft.' WHERE id = 'riverside-evacuation'")
                lost = mcp("publish", "riverside-evacuation", "browser-answer-lost")["structuredContent"]
                page.goto(lost["review_url"])

                def answer_lost(route):
                    route.fetch()
                    route.abort("connectionreset")

                page.route("**/approval/*/complete", answer_lost)
                page.locator("[data-approve]").click()
                expect(page.locator("[data-message]")).to_contain_text("we can’t confirm whether your approval was saved", timeout=15000)
                assert row()["mutation_count"] == 2, "The site published; the page must not say otherwise"
                page.unroute("**/approval/*/complete")
                page.reload()
                expect(page.locator(".approval-sheet .notice")).to_contain_text("Approved with your passkey", timeout=15000)

                # A gateway in front of the site replaces the site's answer with its own JSON error. Only the
                # site's own refusal is definite, so the page says it can't confirm rather than "refused".
                with sqlite3.connect(runtime / "application.sqlite") as db:
                    db.execute("UPDATE demo_alerts SET revision = revision + 1, body = body || ' Fourth draft.' WHERE id = 'riverside-evacuation'")
                gateway = mcp("publish", "riverside-evacuation", "browser-gateway-error")["structuredContent"]
                page.goto(gateway["review_url"])

                def gateway_error(route):
                    route.fetch()
                    route.fulfill(status=502, content_type="application/json", body='{"message": "Bad gateway"}')

                page.route("**/approval/*/complete", gateway_error)
                page.locator("[data-approve]").click()
                expect(page.locator("[data-message]")).to_contain_text("we can’t confirm whether your approval was saved", timeout=15000)
                assert row()["mutation_count"] == 3, "The site published; a gateway's error must not read as a refusal"
                page.unroute("**/approval/*/complete")
                page.reload()
                expect(page.locator(".approval-sheet .notice")).to_contain_text("Approved with your passkey", timeout=15000)
                assert errors == [], errors
                browser.close()

            # Exercise Symfony AI's actual Agent/McpToolbox, with a clearly labelled scripted model.
            agent = subprocess.run([PHP, str(ROOT / "examples/agent.php"), "--scripted"], env=env, capture_output=True, text=True, check=True, timeout=30)
            assert "pending" in agent.stdout and "review_url" in agent.stdout, agent.stdout
            assert row()["mutation_count"] == 3
            print(json.dumps({
                "passed": True,
                "mcp_transport": "official PHP SDK over real HTTP to Symfony MCP Bundle",
                "browser": "Chromium, headless; native WebAuthn API with CDP virtual authenticator",
                "checks": ["tool discovery", "pending before approval", "human login", "passkey enrollment", "expired session offers sign-in recovery", "sign-in returns to the existing pending request without execution", "exact content review", "verified approval commits once", "MCP status", "idempotent request replay", "stale proposal refuses execution and shows as stopped", "a lost answer is reported as unconfirmed, not guessed", "a gateway error after sending is unconfirmed, not a refusal", "Symfony AI scripted runner uses real MCP"],
                "committed_effects": 3,
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
