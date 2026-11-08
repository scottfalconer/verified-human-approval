#!/usr/bin/env python3
"""Exercise real Drupal HTML/session/CSRF/JSON endpoints with a synthetic device.

Start the local PHP server first and wait for the separate DB acceptance suite.
No browser, fingerprint reader, Touch ID or physical passkey interaction is claimed.
Only the isolated demo credentials are read, never printed or copied into reports.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import socket
import sqlite3
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urljoin, urlsplit

import requests
from bs4 import BeautifulSoup


PROJECT = Path(__file__).resolve().parents[4]
DEMO = PROJECT / "examples/drupal"


class Smoke:
    def __init__(self, php: str) -> None:
        self.settings = json.loads((DEMO / "var/demo.json").read_text())
        self.origin = self.settings["origin"].rstrip("/")
        if urlsplit(self.origin).hostname not in {"localhost", "127.0.0.1"}:
            raise RuntimeError("Refusing to send local demo credentials to a non-loopback host")
        self.editor = requests.Session()
        self.admin = requests.Session()
        self.editor.trust_env = False
        self.admin.trust_env = False
        self.results: list[dict] = []
        self.checks = 0
        self.device = subprocess.Popen(
            [php, str(Path(__file__).with_name("http_authenticator.php")), self.origin],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1,
        )
        self.run_id = str(time.time_ns())

    def request(self, session: requests.Session, method: str, path: str, **kwargs) -> requests.Response:
        url = urljoin(self.origin, path)
        if urlsplit(url).netloc != urlsplit(self.origin).netloc:
            raise AssertionError("The Drupal form attempted to leave the demo origin")
        return session.request(method, url, timeout=30, **kwargs)

    def check(self, condition: bool, message: str) -> None:
        self.checks += 1
        if not condition:
            raise AssertionError(message)

    def scenario(self, name: str, callback) -> None:
        start = time.monotonic()
        try:
            callback()
            self.results.append({"name": name, "status": "passed", "seconds": round(time.monotonic() - start, 3)})
        except Exception as error:
            self.results.append({"name": name, "status": "failed", "reason": str(error), "seconds": round(time.monotonic() - start, 3)})
            raise

    def form(self, response: requests.Response, selector: str) -> tuple[str, dict]:
        self.check(response.status_code == 200, f"Form GET returned HTTP {response.status_code} at {urlsplit(response.url).path}")
        soup = BeautifulSoup(response.text, "html.parser")
        form = soup.select_one(selector)
        self.check(form is not None, f"Expected form {selector} was not rendered")
        fields = {}
        for field in form.select("input[name]"):
            if field.get("disabled") is not None:
                continue
            kind = field.get("type", "text")
            if kind in {"checkbox", "radio"} and not field.has_attr("checked"):
                continue
            if kind in {"submit", "button", "file"}:
                continue
            fields[field["name"]] = field.get("value", "")
        for field in form.select("textarea[name]"):
            fields[field["name"]] = field.text
        for field in form.select("select[name]"):
            selected = field.select("option[selected]")
            if field.has_attr("multiple"):
                if selected:
                    fields[field["name"]] = [option.get("value", "") for option in selected]
                continue
            if not selected:
                selected = field.select("option")[:1]
            if selected:
                fields[field["name"]] = selected[0].get("value", "")
        submit = form.select_one('input[type="submit"][name], button[type="submit"][name]')
        if submit:
            fields[submit["name"]] = submit.get("value", submit.text)
        return urljoin(response.url, form.get("action", response.url)), fields

    def login(self, session: requests.Session, username: str, password: str) -> None:
        action, data = self.form(self.request(session, "GET", "/user/login"), 'form.user-login-form')
        data.update({"name": username, "pass": password})
        result = self.request(session, "POST", action, data=data)
        self.check(result.status_code == 200 and "/user/login" not in urlsplit(result.url).path, f"Fictional {username} login failed")

    def json_post(self, session: requests.Session, url: str, token: str | None, value: dict | None = None, **kwargs) -> requests.Response:
        headers = kwargs.pop("headers", {})
        if token is not None:
            headers["X-CSRF-Token"] = token
        return self.request(session, "POST", url, json=value or {}, headers=headers, **kwargs)

    def ceremony_panel(self, response: requests.Response) -> dict:
        self.check(response.status_code == 200, f"Ceremony page returned HTTP {response.status_code}")
        panel = BeautifulSoup(response.text, "html.parser").select_one("[data-passkey-panel]")
        self.check(panel is not None, "Native passkey ceremony controls were not rendered")
        return dict(panel.attrs)

    def device_response(self, mode: str, options: dict) -> dict:
        self.device.stdin.write(json.dumps({"mode": mode, "options": options}) + "\n")
        self.device.stdin.flush()
        line = self.device.stdout.readline()
        self.check(bool(line), "Synthetic authenticator process ended unexpectedly")
        result = json.loads(line)
        self.check("error" not in result, "Synthetic authenticator could not produce proof")
        return result

    def enroll(self) -> None:
        page = self.request(self.editor, "GET", "/user/exact-approval/keys")
        panel = self.ceremony_panel(page)
        missing = self.json_post(self.editor, panel["data-options-url"], None)
        self.check(missing.status_code == 403, "Enrollment accepted a missing CSRF token")
        options = self.json_post(self.editor, panel["data-options-url"], panel["data-csrf"])
        self.check(options.status_code == 200, f"Enrollment options returned HTTP {options.status_code}")
        ceremony = options.json()
        self.check(ceremony["publicKey"]["authenticatorSelection"]["userVerification"] == "required", "Enrollment did not require user verification")
        proof = self.device_response("register", ceremony["publicKey"])
        payload = {"ceremony_id": ceremony["ceremony_id"], "credential": proof}
        complete = self.json_post(self.editor, panel["data-complete-url"], panel["data-csrf"], payload)
        self.check(complete.status_code == 200 and complete.json().get("status") == "registered", "HTTP WebAuthn enrollment failed")
        self.credential_id = proof["id"]
        replay = self.json_post(self.editor, panel["data-complete-url"], panel["data-csrf"], payload)
        self.check(replay.status_code == 403, "Enrollment ceremony replay was not denied")

    def create_draft(self, label: str) -> int:
        action, data = self.form(self.request(self.editor, "GET", "/node/add/announcement"), 'form.node-announcement-form')
        for key in list(data):
            if key.startswith("status["):
                del data[key]
        data["title[0][value]"] = f"HTTP smoke {self.run_id}: {label}"
        result = self.request(self.editor, "POST", action, data=data)
        node_match = re.search(r"/node/(\d+)(?:$|\?)", result.url)
        self.check(result.status_code == 200 and node_match is not None, f"Could not create an unpublished HTTP fixture (HTTP {result.status_code})")
        nid = int(node_match.group(1))
        self.check(self.node(nid)["status"] == 0, "New fixture was unexpectedly published")
        return nid

    def node(self, nid: int) -> dict | None:
        # Read-only local verification independently checks persisted state.
        with sqlite3.connect(f"file:{DEMO / 'var/drupal.sqlite'}?mode=ro", uri=True) as db:
            db.row_factory = sqlite3.Row
            row = db.execute("SELECT nid, title, status FROM node_field_data WHERE nid = ? AND default_langcode = 1", (nid,)).fetchone()
            return dict(row) if row else None

    def prepare(self, nid: int, operation: str, native_delete: bool = False) -> tuple[str, dict]:
        path = f"/node/{nid}/delete" if native_delete else f"/node/{nid}/exact-approval/{operation}"
        response = self.request(self.editor, "GET", path)
        selector = 'form.node-announcement-delete-form' if native_delete else 'form.exact-approval-node-action'
        action, data = self.form(response, selector)
        if native_delete:
            self.check("Prepare exact approval" in response.text, "Native Delete confirmation still offers a direct deletion")
        result = self.request(self.editor, "POST", action, data=data)
        match = re.search(r"/exact-approval/(apr_[a-f0-9]+)$", urlsplit(result.url).path)
        self.check(match is not None, f"Preparation did not redirect to a stored exact review (HTTP {result.status_code})")
        self.check(self.node(nid) is not None, "Preparation unexpectedly deleted content")
        return match.group(1), self.ceremony_panel(result)

    def approve(self, request_id: str, panel: dict, strict_body_check: bool = False) -> None:
        options = self.json_post(self.editor, panel["data-options-url"], panel["data-csrf"])
        self.check(options.status_code == 200, f"Approval options returned HTTP {options.status_code}")
        ceremony = options.json()
        proof = self.device_response("assert", ceremony["publicKey"])
        payload = {"ceremony_id": ceremony["ceremony_id"], "credential": proof}
        if strict_body_check:
            replacement = self.json_post(self.editor, panel["data-complete-url"], panel["data-csrf"], {**payload, "input": {"title": "Replacement"}})
            self.check(replacement.status_code == 400, "Completion accepted replacement action fields")
        complete = self.json_post(self.editor, panel["data-complete-url"], panel["data-csrf"], payload)
        self.check(complete.status_code == 200 and complete.json().get("status") == "completed", f"Exact action did not complete (HTTP {complete.status_code}, status {complete.json().get('status')})")
        replay = self.json_post(self.editor, panel["data-complete-url"], panel["data-csrf"], payload)
        self.check(replay.status_code == 403, "Consumed browser approval ceremony was accepted again")
        for suffix in ["audit", "evidence", "credential", "trust"]:
            evidence = self.request(self.editor, "GET", f"/exact-approval/{request_id}/{suffix}")
            self.check(evidence.status_code == 200, f"Stored {suffix} unavailable after completion")

    def nonhuman_boundaries(self) -> None:
        token = self.ceremony_panel(self.request(self.editor, "GET", "/user/exact-approval/keys"))["data-csrf"]
        basic = requests.Session()
        basic.trust_env = False
        basic.auth = ("editor", self.settings["editor_password"])
        for path in ["/user/exact-approval/keys", "/admin/config/people/exact-approval"]:
            response = self.request(basic, "GET", path)
            self.check(response.status_code in {401, 403}, f"Basic-auth caller reached {path}")
        options = self.json_post(basic, "/user/exact-approval/keys/options", token)
        self.check(options.status_code in {401, 403}, "Basic-auth caller could start key enrollment")
        basic_admin = requests.Session()
        basic_admin.trust_env = False
        basic_admin.auth = ("admin", self.settings["admin_password"])
        self.check(self.request(basic_admin, "GET", "/admin/config/people/exact-approval").status_code in {401, 403}, "Basic-auth administrator could access the human policy form")
        cookie_and_bearer = self.json_post(self.editor, "/user/exact-approval/keys/options", token, headers={"Authorization": "Bearer forbidden-in-human-ceremony"})
        self.check(cookie_and_bearer.status_code == 403, "Agent Authorization header was accepted alongside a human cookie")

    def publishing(self) -> None:
        self.published_node = self.create_draft("publish")
        request_id, panel = self.prepare(self.published_node, "publish")
        self.check(self.node(self.published_node)["status"] == 0, "Preparing publication already changed the node")
        wrong_owner = self.request(self.admin, "GET", f"/exact-approval/{request_id}")
        self.check(wrong_owner.status_code == 404, "Another Drupal UID could open the owner's approval ceremony")
        missing = self.json_post(self.editor, panel["data-options-url"], None)
        self.check(missing.status_code == 403, "Approval accepted a missing CSRF token")
        basic = requests.Session()
        basic.trust_env = False
        basic.auth = ("editor", self.settings["editor_password"])
        self.check(self.json_post(basic, panel["data-options-url"], panel["data-csrf"]).status_code in {401, 403}, "Basic-auth owner could enter the human approval ceremony")
        self.approve(request_id, panel, strict_body_check=True)
        self.check(self.node(self.published_node)["status"] == 1, "Signed publication was not persisted")

    def edit_boundary(self) -> None:
        before = self.node(self.published_node)
        response = self.request(self.editor, "GET", f"/node/{self.published_node}/edit")
        action, data = self.form(response, 'form.node-announcement-edit-form')
        self.check("Prepare approval for the latest saved revision" in response.text, "Protected edit form lacks approval guidance")
        data["title[0][value]"] = "Unapproved replacement that must not persist"
        data["status[value]"] = "1"
        result = self.request(self.editor, "POST", action, data=data)
        self.check(result.status_code == 200 and "This save would publish protected content" in result.text, "Protected edit did not return a polite validation error")
        self.check(self.node(self.published_node) == before, "Unapproved published-content edits reached the database")

    def deletion(self) -> None:
        nid = self.create_draft("delete")
        request_id, panel = self.prepare(nid, "delete", native_delete=True)
        self.approve(request_id, panel)
        self.check(self.node(nid) is None, "Signed deletion did not remove the node")
        self.check(self.request(self.editor, "GET", f"/exact-approval/{request_id}/evidence").status_code == 200, "Evidence disappeared with the deleted content")

    def admin_pages(self) -> None:
        for path in ["/admin/config/people/exact-approval", "/admin/config/people/exact-approval/keys", "/user/exact-approval/history"]:
            response = self.request(self.admin, "GET", path)
            self.check(response.status_code == 200, f"Administrator page failed: {path} HTTP {response.status_code}")
        response = self.request(self.admin, "GET", "/admin/config/people/exact-approval")
        self.check("administer node published status" in response.text and "delete any announcement content" in response.text, "Policy form does not expose real native permission bindings")
        action, original = self.form(response, "form.exact-approval-settings")
        with sqlite3.connect(f"file:{DEMO / 'var/drupal.sqlite'}?mode=ro", uri=True) as database:
            workflows_present = database.execute("SELECT COUNT(*) FROM config WHERE name LIKE 'workflows.workflow.%'").fetchone()[0] > 0
        if workflows_present:
            self.check(any(" transition " in option.get("value", "") for option in BeautifulSoup(response.text, "html.parser").select("select option")), "Policy picker omitted installed moderation transition permissions")
        saved = self.request(self.admin, "POST", action, data=original)
        self.check(saved.status_code == 200 and "The configuration options have been saved" in saved.text, "Administrator could not save existing policy and {{site}} statement through Form API")
        self.check(not BeautifulSoup(saved.text, "html.parser").select(".messages--error, [data-drupal-message-type='error']"), "Unchanged policy submission returned a validation error")
        _, refreshed = self.form(saved, "form.exact-approval-settings")
        protected = lambda values: {key: value for key, value in values.items() if key.startswith(("policies[", "statements[")) or key in {"origin", "rp_id", "lifetime"}}
        self.check(protected(original) == protected(refreshed), "Policy form changed saved selections or statements unexpectedly")

    def run(self) -> int:
        try:
            self.scenario("Real Drupal editor and administrator login sessions", lambda: (self.login(self.editor, "editor", self.settings["editor_password"]), self.login(self.admin, "admin", self.settings["admin_password"])))
            self.scenario("Administrator settings, audit and registered-key pages render", self.admin_pages)
            self.scenario("HTTP WebAuthn enrollment, required UV and one-use ceremony", self.enroll)
            self.scenario("Agent credentials cannot enroll keys or administer policy", self.nonhuman_boundaries)
            self.scenario("Exact publication through HTTP with owner, CSRF and payload checks", self.publishing)
            self.scenario("Native node edit refuses unapproved published-state saves politely", self.edit_boundary)
            self.scenario("Native Delete confirmation prepares, signs and retains evidence", self.deletion)
        except Exception:
            pass
        finally:
            self.device.terminate()
            self.device.wait(timeout=5)
        report = {
            "suite": "exact-approval/drupal-http-ui/v1",
            "run": self.run_id,
            "tests": len(self.results),
            "assertions": self.checks,
            "failures": sum(item["status"] == "failed" for item in self.results),
            "authenticator": "Synthetic ES256 device through real HTTP endpoints; no physical passkey or browser interaction claimed.",
            "outcomes": self.results,
        }
        (DEMO / "var/http-ui-result.json").write_text(json.dumps(report, indent=2) + "\n")
        print(json.dumps(report, indent=2))
        return int(report["failures"] > 0)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=os.environ.get("PHP_BINARY", "php"))
    parser.add_argument("--start-server", action="store_true", help="Run a temporary PHP server in this process's network namespace")
    arguments = parser.parse_args()
    server = None
    server_log = None
    try:
        if arguments.start_server:
            demo = json.loads((DEMO / "var/demo.json").read_text())
            origin = urlsplit(demo["origin"])
            if origin.hostname not in {"localhost", "127.0.0.1"} or origin.scheme != "http":
                raise RuntimeError("The temporary demo server requires HTTP on loopback")
            port = origin.port or 80
            server_log = (DEMO / "var/http-server.log").open("w")
            server = subprocess.Popen([arguments.php, "-S", f"{origin.hostname}:{port}", ".ht.router.php"], cwd=DEMO / "web", stdout=server_log, stderr=server_log)
            for attempt in range(100):
                try:
                    with socket.create_connection((origin.hostname, port), timeout=1):
                        break
                except OSError:
                    if server.poll() is not None:
                        raise RuntimeError("The temporary PHP server failed to start; inspect var/http-server.log")
                    time.sleep(0.05)
            else:
                raise RuntimeError("The temporary PHP server did not become ready")
        raise SystemExit(Smoke(arguments.php).run())
    finally:
        if server is not None:
            server.terminate()
            server.wait(timeout=5)
        if server_log is not None:
            server_log.close()
