#!/usr/bin/env python3
"""Exercise the reference OAuth issuer over real HTTP, without external accounts or model calls."""
import base64
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
if not PHP:
    raise SystemExit("Set PHP_BIN to a PHP 8.3+ executable.")


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "form":
            self.current = {"action": attributes.get("action", ""), "fields": {}}
            self.forms.append(self.current)
        elif tag == "input" and self.current is not None and "name" in attributes:
            self.current["fields"][attributes["name"]] = attributes.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self.current = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, target):
        return None


with tempfile.TemporaryDirectory(prefix="verified-human-approval-oauth-http-") as temporary:
    runtime = Path(temporary) / "runtime"
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    origin = f"http://localhost:{port}"
    callback = "https://claude.ai/api/mcp/auth_callback"
    env = {**os.environ, "VERIFIED_HUMAN_APPROVAL_RUNTIME": str(runtime), "VERIFIED_HUMAN_APPROVAL_ORIGIN": origin}
    setup = subprocess.run([PHP, "-r", 'require $argv[1]; echo json_encode(VerifiedHumanApproval\\Demo\\DemoSetup::initialize(getenv("VERIFIED_HUMAN_APPROVAL_RUNTIME"), getenv("VERIFIED_HUMAN_APPROVAL_ORIGIN")), JSON_THROW_ON_ERROR);', str(ROOT / "vendor/autoload.php")], env=env, capture_output=True, text=True, check=True)
    accounts = json.loads(setup.stdout)["accounts"]
    browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(path, form=None, data=None, headers=None):
        target = urllib.parse.urljoin(origin, path)
        assert urllib.parse.urlparse(target).netloc == urllib.parse.urlparse(origin).netloc, "Never follow the external callback."
        headers = headers or {}
        if form is not None:
            data = urllib.parse.urlencode(form).encode()
            headers = {**headers, "Content-Type": "application/x-www-form-urlencoded"}
        elif data is not None:
            data = json.dumps(data).encode()
            headers = {**headers, "Content-Type": "application/json"}
        try:
            response = browser.open(urllib.request.Request(target, data=data, headers=headers), timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode()

    def tokens(form):
        status, _, body = request("/oauth/token", form=form)
        return status, json.loads(body)

    def consent(client_id):
        verifier = secrets.token_urlsafe(48)
        challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
        query = {"response_type": "code", "client_id": client_id, "redirect_uri": callback, "state": "http-proof", "code_challenge": challenge, "code_challenge_method": "S256", "resource": origin + "/mcp"}
        status, headers, _ = request("/oauth/authorize?" + urllib.parse.urlencode(query))
        assert status == 302, "Authorization must first redirect to consent."
        consent_url = headers["Location"]
        status, headers, html = request(consent_url)
        assert status == 200 and "Connecting does not approve anything" in html
        assert headers["X-Frame-Options"] == "DENY"
        form = next(form for form in Forms(html).forms if "_csrf_token" in form["fields"] and not form["action"])
        status, headers, _ = request(consent_url, form=form["fields"])
        assert status == 302
        status, headers, _ = request(headers["Location"])
        assert status == 302 and headers["Location"].startswith(callback + "?")
        returned = urllib.parse.parse_qs(urllib.parse.urlparse(headers["Location"]).query)
        assert returned["state"] == ["http-proof"] and returned["iss"] == [origin]
        return {"grant_type": "authorization_code", "code": returned["code"][0], "redirect_uri": callback, "client_id": client_id, "code_verifier": verifier, "resource": origin + "/mcp"}

    def mcp(token, *arguments):
        call_env = {**env, "VERIFIED_HUMAN_APPROVAL_MCP_URL": origin + "/mcp", "VERIFIED_HUMAN_APPROVAL_TOKEN": token}
        completed = subprocess.run([PHP, str(ROOT / "examples/mcp.php"), *arguments], env=call_env, capture_output=True, text=True, timeout=30)
        assert completed.returncode == 0, "The real SDK MCP call must succeed."
        return json.loads(completed.stdout)

    def authenticated_status(token):
        message = {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "http-proof", "version": "1"}}}
        return request("/mcp", data=message, headers={"Authorization": "Bearer " + token, "Accept": "application/json, text/event-stream"})[0]

    with open(Path(temporary) / "server.log", "w+") as log:
        server = subprocess.Popen([PHP, "-S", f"localhost:{port}", "-t", str(ROOT / "demo/public"), str(ROOT / "demo/public/index.php")], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    if request("/login")[0] == 200:
                        break
                except (OSError, TimeoutError):
                    time.sleep(0.1)
            else:
                raise AssertionError("The isolated reference server did not start.")
            status, headers, _ = request("/mcp", data={"jsonrpc": "2.0", "id": 1, "method": "ping"})
            assert status == 401 and 'resource_metadata="' + origin in headers["WWW-Authenticate"]
            assert json.loads(request("/.well-known/oauth-authorization-server")[2])["code_challenge_methods_supported"] == ["S256"]
            login = next(form for form in Forms(request("/login")[2]).forms if "_username" in form["fields"])
            assert request("/login", form={**login["fields"], "_username": "editor", "_password": accounts["editor"]["password"]})[0] == 302
            status, _, body = request("/oauth/register", data={"client_name": "Isolated HTTP proof", "redirect_uris": [callback]})
            assert status == 201
            client_id = json.loads(body)["client_id"]
            status, _, html = request("/oauth/consent?" + urllib.parse.urlencode({"client_id": client_id, "client_name": "Claude", "redirect_host": "claude.ai"}))
            assert status == 410 and "claude.ai" not in html, "A consent link cannot choose what the page shows."
            status, _, body = request("/oauth/register", data={"client_name": "Claude", "redirect_uris": ["https://attacker.example\\@claude.ai/api/mcp/auth_callback"]})
            assert status == 400 and json.loads(body)["error"] == "invalid_redirect_uri", "A redirect URI a browser reads with another host is refused."
            status, _, body = request("/oauth/register", data={"client_name": "Claude", "redirect_uris": ["x-safari-https://attacker.example/api/mcp/auth_callback"]})
            assert status == 400 and json.loads(body)["error"] == "invalid_redirect_uri", "A redirect URI is on https or back to this computer, never an application's own link."
            wrong = consent(client_id)
            wrong["code_verifier"] = "a" * 43
            assert tokens(wrong)[1]["error"] == "invalid_grant", "PKCE must reject a wrong verifier."
            exchange = consent(client_id)
            status, issued = tokens(exchange)
            assert status == 200 and issued["token_type"] == "Bearer"
            assert tokens(exchange)[1]["error"] == "invalid_grant", "A code must work only once."
            assert mcp(issued["access_token"], "read", "riverside-evacuation")["structuredContent"]["revision"] == 1
            pending = mcp(issued["access_token"], "publish", "riverside-evacuation", "oauth-http-proof")["structuredContent"]
            assert pending["status"] == "pending", "A real OAuth credential must not bypass passkey approval."
            refresh = {"grant_type": "refresh_token", "refresh_token": issued["refresh_token"], "client_id": client_id, "resource": origin + "/mcp"}
            status, refreshed = tokens(refresh)
            assert status == 200 and refreshed["access_token"] != issued["access_token"]
            assert tokens(refresh)[1]["error"] == "invalid_grant", "Refresh must rotate."
            assert authenticated_status(issued["access_token"]) == 401
            assert mcp(refreshed["access_token"], "status", pending["requestId"])["structuredContent"]["status"] == "pending"
            connections = Forms(request("/account/passkeys")[2]).forms
            revoke = next(form for form in connections if "/account/connections/oauth-" in form["action"])
            assert request(revoke["action"], form=revoke["fields"])[0] == 302
            assert authenticated_status(refreshed["access_token"]) == 401
            refresh["refresh_token"] = refreshed["refresh_token"]
            assert tokens(refresh)[1]["error"] == "invalid_grant", "Revocation must also end refresh."
            with sqlite3.connect(runtime / "application.sqlite") as database:
                assert database.execute("SELECT mutation_count FROM demo_alerts WHERE id='riverside-evacuation'").fetchone()[0] == 0
                assert database.execute("SELECT COUNT(*) FROM verified_human_approval_executions").fetchone()[0] == 0
            print(json.dumps({"passed": True, "checks": ["real HTTP metadata and session login", "consent shows only the validated request", "redirect URIs only in plain form, on https or back to this computer", "consent with issuer and state", "S256 verifier rejection", "single-use code exchange", "real SDK calls using issued access token", "publication remains pending", "refresh rotation and old access revocation", "browser connection revocation ends access and refresh", "zero protected mutations without passkey approval"], "issuer": "League OAuth2 server + omouren MCP OAuth bundle", "external_accounts_used": False, "physical_passkey_tested": False}, indent=2))
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
