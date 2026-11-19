#!/usr/bin/env python3
"""Approve on your phone, end to end in real Chrome.

The demo runs behind a stand-in for a public HTTPS tunnel: Chrome asks for https://alerts.example,
and each request reaches the local server with the forwarding headers a tunnel adds. One browser
context is the computer, where the person signs in and adds a passkey. A second, separate context is
the phone: it never signs in, and it holds a copy of the same passkey, the way a synced passkey
(iCloud Keychain, Google Password Manager) reaches a phone. The phone opens the one-time link the
card would show as a QR code, approves with the passkey, and the agent's status call sees the result.
It needs Python Playwright and CHROMIUM_BIN.
"""
import html
import http.client
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
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
CHROMIUM = os.environ.get("CHROMIUM_BIN") or shutil.which("chromium")
SHOTS = Path(os.environ["SCREENSHOT_DIR"]) if os.environ.get("SCREENSHOT_DIR") else None
HOST = "alerts.example"
ORIGIN = "https://" + HOST


def main():
    with tempfile.TemporaryDirectory(prefix="verified-human-approval-phone-") as temporary:
        runtime = Path(temporary) / "runtime"
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        env = {**os.environ, "VERIFIED_HUMAN_APPROVAL_RUNTIME": str(runtime), "VERIFIED_HUMAN_APPROVAL_ORIGIN": ORIGIN}
        setup = subprocess.run(
            [PHP, "-r", 'require $argv[1]; echo json_encode(VerifiedHumanApproval\\Demo\\DemoSetup::initialize(getenv("VERIFIED_HUMAN_APPROVAL_RUNTIME"), getenv("VERIFIED_HUMAN_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")],
            env=env, capture_output=True, text=True, check=True,
        )
        accounts = json.loads(setup.stdout)["accounts"]
        # The review page loads the bundle's script from demo/public/bundles/, as after assets:install in any Symfony app.
        subprocess.run([PHP, str(ROOT / "demo/bin/console"), "assets:install", str(ROOT / "demo/public"), "--symlink", "--relative"], env=env, capture_output=True, check=True)
        log = open(Path(temporary) / "server.log", "w+")
        server = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "demo/public"), str(ROOT / "demo/public/index.php")],
                                  env={**env, "PHP_CLI_SERVER_WORKERS": "4"}, stdout=log, stderr=log, start_new_session=True)

        def forward(method, path, headers=None, body=None):
            """One request through the stand-in tunnel, the way a tunnel forwards it to the local server."""
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=30)
            sent = {k: v for k, v in (headers or {}).items() if k.lower() not in ("host", "connection", "content-length", "accept-encoding")}
            sent.update({"Host": HOST, "X-Forwarded-Proto": "https", "X-Forwarded-Host": HOST, "X-Forwarded-Port": "443", "X-Forwarded-For": "203.0.113.7"})
            connection.request(method, path, body=body, headers=sent)
            response = connection.getresponse()
            data = response.read()
            connection.close()
            return response, data

        def tunnel(route):
            try:
                request = route.request
                parts = urlsplit(request.url)
                response, data = forward(request.method, parts.path + ("?" + parts.query if parts.query else ""), request.all_headers(), request.post_data_buffer)
                headers = {}
                for name, value in response.getheaders():
                    headers[name.lower()] = headers[name.lower()] + "\n" + value if name.lower() in headers else value
                if 300 <= response.status < 400 and request.is_navigation_request():
                    # Chromium does not route the target of a fulfilled redirect, so the stand-in follows
                    # it in the page (keeping its cookies; a meta refresh, as the site's CSP allows no inline
                    # script). HandoffTest checks the server's real 302.
                    target = headers.pop("location")
                    headers.pop("content-length", None)
                    headers["content-type"] = "text/html; charset=utf-8"
                    route.fulfill(status=200, headers=headers, body='<!doctype html><meta http-equiv="refresh" content="0;url=' + html.escape(target, quote=True) + '">')
                    return
                route.fulfill(status=response.status, headers=headers, body=data)
            except Exception as error:  # surfaced at the end instead of falling through to real DNS
                tunnel_errors.append(f"{route.request.method} {route.request.url}: {error!r}")
                route.abort()

        tunnel_errors = []

        def tool(name, arguments):
            """The agent's side: the official PHP MCP SDK over real HTTP, through the same stand-in tunnel."""
            script = ('require $argv[1]."/vendor/autoload.php"; $f = new Nyholm\\Psr7\\Factory\\Psr17Factory();'
                      '$http = new Symfony\\Component\\HttpClient\\Psr18Client(Symfony\\Component\\HttpClient\\HttpClient::create(["timeout" => 20, "max_redirects" => 0]), $f, $f);'
                      '$c = Mcp\\Client::builder()->setClientInfo("phone-smoke", "1.0")->setMaxRetries(0)->build();'
                      '$c->connect(new Mcp\\Client\\Transport\\HttpTransport(endpoint: $argv[3], headers: ["Authorization" => "Bearer ".$argv[2], "Host" => "' + HOST + '",'
                      ' "X-Forwarded-Proto" => "https", "X-Forwarded-Host" => "' + HOST + '", "X-Forwarded-Port" => "443"], httpClient: $http, requestFactory: $f, streamFactory: $f));'
                      '$r = $c->callTool($argv[4], json_decode($argv[5], true)); echo json_encode(["isError" => $r->isError, "structuredContent" => $r->structuredContent]);')
            result = subprocess.run([PHP, "-r", script, str(ROOT), accounts["editor"]["personal_token"], f"http://127.0.0.1:{port}/mcp", name, json.dumps(arguments)],
                                    env=env, capture_output=True, text=True, check=True, timeout=60)
            return json.loads(result.stdout)

        def row():
            with sqlite3.connect(runtime / "application.sqlite") as db:
                db.row_factory = sqlite3.Row
                return dict(db.execute("SELECT * FROM demo_alerts WHERE id = 'riverside-evacuation'").fetchone())

        try:
            for _ in range(100):
                try:
                    if forward("GET", "/login")[0].status == 200:
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise AssertionError("Demo server did not start.")

            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(executable_path=CHROMIUM, headless=True, args=["--no-sandbox"])
                errors = []

                def device(**options):
                    context = browser.new_context(**options)
                    context.route(ORIGIN + "/**", tunnel)
                    page = context.new_page()
                    page.on("pageerror", lambda error: errors.append(str(error)))
                    cdp = context.new_cdp_session(page)
                    cdp.send("WebAuthn.enable")
                    authenticator = cdp.send("WebAuthn.addVirtualAuthenticator", {"options": {
                        "protocol": "ctap2", "transport": "internal", "hasResidentKey": True,
                        "hasUserVerification": True, "isUserVerified": True, "automaticPresenceSimulation": True,
                    }})["authenticatorId"]
                    return page, cdp, authenticator

                # The computer: sign in and add a passkey.
                computer, computer_cdp, computer_key = device(viewport={"width": 1180, "height": 900})
                computer.goto(ORIGIN + "/login")
                computer.locator("#username").fill("editor")
                computer.locator("#password").fill(accounts["editor"]["password"])
                computer.get_by_role("button", name="Sign in", exact=True).click()
                computer.wait_for_url(ORIGIN + "/")
                computer.goto(accounts["editor"]["passkey_link"])
                computer.wait_for_url(ORIGIN + "/account/passkeys")
                computer.locator("[data-approve]").click()
                expect(computer.get_by_text("Personal passkey", exact=True)).to_be_visible(timeout=15000)
                synced = computer_cdp.send("WebAuthn.getCredentials", {"authenticatorId": computer_key})["credentials"]
                assert len(synced) == 1 and synced[0]["rpId"] == HOST, synced

                # The agent asks; the card asks for a phone code.
                requested = tool("publish_alert", {"alert_id": "riverside-evacuation", "request_key": "phone-one"})
                request_id = requested["structuredContent"]["requestId"]
                assert requested["structuredContent"]["status"] == "pending" and row()["mutation_count"] == 0
                assert requested["structuredContent"]["approval"]["handoffTool"] == "approval_handoff"
                code = tool("approval_handoff", {"request_id": request_id})
                assert not code["isError"], code
                link = code["structuredContent"]["url"]
                assert link.startswith(ORIGIN + "/approval/handoff/")

                # The phone: never signed in, holding the synced passkey.
                phone, phone_cdp, phone_key = device(viewport={"width": 390, "height": 844}, device_scale_factor=3, is_mobile=True, has_touch=True)
                phone_cdp.send("WebAuthn.addCredential", {"authenticatorId": phone_key, "credential": synced[0]})
                phone.goto(link)
                # Opening the link shows a Continue button, so a link previewer cannot use the code up.
                expect(phone.get_by_role("button", name="Continue")).to_be_visible()
                phone.get_by_role("button", name="Continue").click()
                phone.wait_for_url(f"{ORIGIN}/approval/{request_id}#return=handoff")
                expect(phone.locator(".statement")).to_contain_text("Evacuation order")
                expect(phone.locator(".rc-frame .rc-body")).to_contain_text("Hill Street route")
                if SHOTS:
                    phone.screenshot(path=str(SHOTS / "phone-1-review.png"))
                    phone.screenshot(path=str(SHOTS / "phone-1-review-full.png"), full_page=True)
                # The phone's session reaches nothing but this one approval.
                jar = "; ".join(f"{c['name']}={c['value']}" for c in phone.context.cookies(ORIGIN))
                for path in ["/", "/account/passkeys", "/admin/approval-policy", f"/approval/{request_id}/evidence"]:
                    status = forward("GET", path, {"Cookie": jar})[0].status
                    assert status in (302, 403, 404), (path, status)
                phone.goto(f"{ORIGIN}/approval/{request_id}#return=handoff")
                phone.locator("[data-approve]").click()
                expect(phone.locator(".notice")).to_contain_text("completed", timeout=15000)
                expect(phone.locator("[data-return-hint]")).to_contain_text("on your computer to see the result")
                if SHOTS:
                    phone.screenshot(path=str(SHOTS / "phone-2-approved.png"))
                assert row()["mutation_count"] == 1 and row()["published_revision"] == 1
                assert tool("approval_status", {"request_id": request_id})["structuredContent"]["status"] == "completed"

                # The code worked once.
                stranger, _, _ = device(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
                expired = stranger.goto(link)
                assert expired.status == 410, expired.status
                expect(stranger.locator("h1")).to_contain_text("already been used or has expired")
                assert errors == [], errors
                assert tunnel_errors == [], tunnel_errors
                browser.close()

            print(json.dumps({
                "passed": True,
                "path": "card code -> phone browser (separate, never signed in) -> synced passkey -> application records approval",
                "checks": ["one-time link opens only its approval", "phone session reaches nothing else", "approved with the synced passkey",
                           "alert issued once", "agent status sees completed", "code refused the second time"],
                "physical_biometrics_tested": False,
            }, indent=2))
        finally:
            os.killpg(server.pid, signal.SIGTERM)  # the server and its forked workers
            log.seek(0)
            failures = [line for line in log.read().splitlines() if "PHP Fatal" in line]
            assert not failures, failures


if __name__ == "__main__":
    main()
