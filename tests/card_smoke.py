#!/usr/bin/env python3
"""Real Chrome against the real approval card, inside a scripted MCP Apps host.

The host speaks the card's side of io.modelcontextprotocol/ui over postMessage: it answers
ui/initialize, sends the tool result, and answers the card's tools/call requests. The test
clicks "Approve on your phone", photographs the QR code the card drew, and reads it with a
real QR decoder. It needs Python Playwright, CHROMIUM_BIN, and PHP with GD.
"""
import html
import json
import os
from pathlib import Path
import subprocess
import tempfile

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
CARD = (ROOT / "packages/mcp/resources/approval-card.html").read_text()
LINK = "https://alerts.example/approval/handoff/" + "Q" * 43
REVIEW = "https://alerts.example/approval/0b7d5f0e-6a4c-4c1e-9f57-1f3f8b6f2a10"


def php(code, *args):
    return subprocess.run([PHP, "-r", "require $argv[1].'/vendor/autoload.php'; " + code, str(ROOT), *args],
                          check=True, capture_output=True, text=True).stdout


MODULES = json.loads(php("echo json_encode(ExactApproval\\Symfony\\Handoff\\ApprovalHandoff::modules($argv[2]));", LINK))

# The host page: one sandboxed frame, like a real MCP Apps host, and a scripted server behind it.
HOST = """<!doctype html><meta charset="utf-8"><body style="margin:0;background:%(background)s">
<script>
  const card = () => document.getElementById('card');
  window.calls = []; window.opened = [];
  window.server = { status: 'pending', expiresIn: 120 };
  const view = (status) => ({ requestId: 'r1', status, reason: null, reviewUrl: %(review)s, statusTool: 'approval_status',
    statement: 'I approve issuing the public alert “Evacuation order: Riverside Road area” (revision 1) on Riverside Council website. Every resident sees it as soon as I approve.',
    handoffTool: %(handoff)s });
  const reply = (id, result) => card().contentWindow.postMessage({ jsonrpc: '2.0', id, result }, '*');
  addEventListener('message', (event) => {
    const m = event.data;
    if (!card() || event.source !== card().contentWindow || !m || m.jsonrpc !== '2.0') return;
    if (m.method === 'ui/initialize') reply(m.id, { protocolVersion: '2026-01-26', hostInfo: { name: 'card-smoke', version: '1' },
      hostCapabilities: { openLinks: {}, serverTools: {} }, hostContext: { theme: %(theme)s, platform: %(platform)s } });
    if (m.method === 'ui/notifications/initialized') card().contentWindow.postMessage({ jsonrpc: '2.0', method: 'ui/notifications/tool-result',
      params: { content: [], structuredContent: { approval: view('pending') } } }, '*');
    if (m.method === 'ui/notifications/size-changed') card().style.height = m.params.height + 'px';
    if (m.method === 'ui/open-link') { window.opened.push(m.params.url); reply(m.id, {}); }
    if (m.method === 'tools/call') {
      window.calls.push(m.params.name);
      if (m.params.name === 'approval_status') reply(m.id, { content: [], structuredContent: { approval: view(window.server.status) } });
      if (m.params.name === 'approval_handoff') reply(m.id, { content: [], structuredContent:
        { requestId: m.params.arguments.request_id, url: %(link)s, expiresIn: window.server.expiresIn, modules: %(modules)s } });
    }
  });
</script>
<iframe id="card" sandbox="allow-scripts" style="border:0;width:560px;height:200px" srcdoc="%(card)s"></iframe>"""


def host(browser, review=REVIEW, handoff="approval_handoff", theme="light", platform="desktop"):
    # A fresh page each time: set_content keeps the window, and the host script's constants with it.
    page = browser.new_page(viewport={"width": 600, "height": 520}, device_scale_factor=2)
    page.set_content(HOST % {
        "background": "#1f1f1e" if theme == "dark" else "#ffffff", "review": json.dumps(review), "handoff": json.dumps(handoff),
        "theme": json.dumps(theme), "platform": json.dumps(platform), "link": json.dumps(LINK), "modules": json.dumps(MODULES),
        "card": html.escape(CARD, quote=True)})
    card = page.frame_locator("#card")
    expect(card.locator("#statement")).to_contain_text("Evacuation order")
    return page, card


def main():
    shots = Path(os.environ["SCREENSHOT_DIR"]) if os.environ.get("SCREENSHOT_DIR") else None
    checks = []
    with tempfile.TemporaryDirectory(prefix="card-smoke-") as tmp, sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=os.environ.get("CHROMIUM_BIN"), headless=True)
        page, card = host(browser)
        expect(card.locator("#open")).to_be_visible()
        card.locator("#phone-show").click()
        code = card.locator("#qr svg")
        expect(code).to_be_visible()
        expect(card.locator("#status")).to_contain_text("on your phone")
        photo = Path(tmp) / "code.png"
        code.screenshot(path=str(photo))
        read = php("echo (new chillerlan\\QRCode\\QRCode())->readFromFile($argv[2]);", str(photo))
        assert read == LINK, f"the drawn code reads {read!r}"
        checks.append("the drawn QR code scans to the one-time link")
        if shots: page.screenshot(path=str(shots / "card-phone-light.png"))

        page.evaluate("window.server.status = 'completed'")
        expect(card.locator("#status")).to_contain_text("Approved with a passkey", timeout=8000)
        expect(card.locator("#phone-code")).to_be_hidden()
        expect(card.locator("#phone-show")).to_be_hidden()
        checks.append("the code disappears when the site records the approval")

        page, card = host(browser, theme="dark")
        card.locator("#phone-show").click()
        expect(card.locator("#qr svg")).to_be_visible()
        page.wait_for_timeout(300)
        if shots: page.screenshot(path=str(shots / "card-phone-dark.png"))
        page.evaluate("window.server.expiresIn = 1")
        card.locator("#phone-show").click()
        expect(card.locator("#status")).to_contain_text("That code expired", timeout=5000)
        expect(card.locator("#phone-code")).to_be_hidden()
        expect(card.locator("#phone-show")).to_have_text("Show a new code")
        checks.append("an expired code is taken down")

        for label, options in [("a local address", {"review": "http://localhost:8080/approval/r1", "handoff": None}),
                               ("a server that does not offer it", {"handoff": None}),
                               ("Claude on a phone", {"platform": "mobile"})]:
            page, card = host(browser, **options)
            expect(card.locator("#open")).to_be_visible()
            expect(card.locator("#phone-show")).to_be_hidden()
            checks.append("no phone option on " + label)

        browser.close()
    print(json.dumps({"passed": True, "checks": checks}, indent=2))


if __name__ == "__main__":
    main()
