#!/usr/bin/env python3
"""Real Chromium against the real approval card, as the server writes it, inside a scripted MCP Apps host.

The host plays the host's side of io.modelcontextprotocol/ui over postMessage: it answers
ui/initialize, sends the tool result, and answers the card's tools/call requests. The test
clicks "Approve on your phone", photographs the QR code the card drew, and reads it with a
real QR decoder. It also reads the colors the card ends up with under hosts that send a theme,
a theme and style variables, or neither, and checks that its text is readable on the surface it paints.
It needs Python Playwright, and PHP with GD and this repository's Composer dependencies. It runs
the browser CHROMIUM_BIN names, or else Playwright's own Chromium.
"""
import html
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
LINK = "https://alerts.example/approval/handoff/" + "Q" * 43
REVIEW = "https://alerts.example/approval/0b7d5f0e-6a4c-4c1e-9f57-1f3f8b6f2a10"


def php(code, *args):
    return subprocess.run([PHP, "-r", "require $argv[1].'/vendor/autoload.php'; " + code, str(ROOT), *args],
                          check=True, capture_output=True, text=True).stdout


MODULES = json.loads(php("echo json_encode(VerifiedHumanApproval\\Bundle\\Handoff\\ApprovalHandoff::modules($argv[2]));", LINK))


def served(review, handoff):
    # The card as the server writes it for the site this review page is on, with the site's tools.
    origin = re.match(r"https?://[^/]+", review).group(0)
    return php("echo VerifiedHumanApproval\\Mcp\\ApprovalCard::html($argv[2], 'approval_status', '' === $argv[3] ? null : $argv[3]);", origin, handoff or "")

# The host page: one sandboxed frame, like a real MCP Apps host, and a scripted server behind it.
HOST = """<!doctype html><meta charset="utf-8"><body style="margin:0;background:%(background)s">
<script>
  const card = () => document.getElementById('card');
  window.calls = []; window.opened = [];
  window.server = { status: 'pending', expiresIn: 120 };
  const view = (status) => ({ requestId: 'r1', status, reason: null, approvedWithPasskey: status === 'completed', reviewUrl: %(review)s, statusTool: 'approval_status',
    statement: 'I approve issuing the public alert “Evacuation order: Riverside Road area” (revision 1) on Riverside Council website. Every resident sees it as soon as I approve.',
    handoffTool: %(handoff)s });
  const reply = (id, result) => card().contentWindow.postMessage({ jsonrpc: '2.0', id, result }, '*');
  addEventListener('message', (event) => {
    const m = event.data;
    if (!card() || event.source !== card().contentWindow || !m || m.jsonrpc !== '2.0') return;
    if (m.method === 'ui/initialize') setTimeout(() => reply(m.id, { protocolVersion: '2026-01-26', hostInfo: %(info)s,
      hostCapabilities: %(capabilities)s, hostContext: %(context)s }), %(delay)d);
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


def host(browser, review=REVIEW, handoff="approval_handoff", theme="light", platform="desktop", capabilities=None,
         styles=None, background=None, system="light", delay=0, name="card-smoke"):
    # A fresh page each time: set_content keeps the window, and the host script's constants with it.
    # `system` is the device's own light or dark setting, which a host's theme need not follow.
    # `delay`: milliseconds before the host answers ui/initialize. With a delay the host page's clock stands still until
    # the test moves it (the sandboxed card keeps real time), and the caller waits for the statement itself.
    # `name`: what the host calls itself in that answer (hostInfo.name).
    page = browser.new_page(viewport={"width": 600, "height": 520}, device_scale_factor=2, color_scheme=system)
    if delay:
        page.clock.install()
    context = {"platform": platform, **({"theme": theme} if theme else {}), **({"styles": {"variables": styles}} if styles else {})}
    page.set_content(HOST % {
        "background": background or ("#1f1f1e" if theme == "dark" else "#ffffff"), "review": json.dumps(review), "handoff": json.dumps(handoff),
        "context": json.dumps(context), "link": json.dumps(LINK), "modules": json.dumps(MODULES),
        "capabilities": json.dumps({"openLinks": {}, "serverTools": {}} if capabilities is None else capabilities),
        "delay": delay, "info": json.dumps({"name": name, "version": "1"}), "card": html.escape(served(review, handoff), quote=True)})
    card = page.frame_locator("#card")
    if not delay:
        expect(card.locator("#statement")).to_contain_text("Evacuation order")
    return page, card


# A host's style variables. The colors are one light theme and one dark theme; the rest must reach the card in every case.
LIGHT = {"--color-background-primary": "rgb(255, 255, 255)", "--color-text-primary": "rgb(20, 20, 19)", "--color-text-secondary": "rgb(61, 61, 58)",
         "--color-background-inverse": "rgb(20, 20, 19)", "--color-text-inverse": "rgb(255, 255, 255)"}
DARK = {"--color-background-primary": "rgb(38, 38, 36)", "--color-text-primary": "rgb(250, 249, 245)", "--color-text-secondary": "rgb(194, 192, 182)",
        "--color-background-inverse": "rgb(250, 249, 245)", "--color-text-inverse": "rgb(20, 20, 19)"}
SHAPE = {"--font-sans": "Georgia, serif", "--border-radius-md": "3px"}
# The form the specification asks of a host: one light-dark() value for each color, which the view resolves for the host's theme.
EITHER = {name: f"light-dark({LIGHT[name]}, {DARK[name]})" for name in LIGHT}
# The Claude iOS app on 2026-11-18, read off a recording: this surface behind the card, and LIGHT's text color drawn on it.
DARK_PAGE = "rgb(29, 29, 29)"


def channels(color):
    # Chromium reports an sRGB color as rgb(r, g, b), or rgba(r, g, b, a) when it is not opaque.
    values = [float(value) for value in re.fullmatch(r"rgba?\((.+)\)", color).group(1).split(",")]
    return values[:3], values[3] if len(values) > 3 else 1.0


def luminance(color):
    # WCAG 2 relative luminance, from 0 (black) to 1 (white).
    red, green, blue = (c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4 for c in (value / 255 for value in channels(color)[0]))
    return 0.2126 * red + 0.7152 * green + 0.0722 * blue


def contrast(one, other):
    lighter, darker = sorted((luminance(one), luminance(other)), reverse=True)
    return (lighter + 0.05) / (darker + 0.05)


def painted(card):
    """The card's text color and the surface it painted, once both are shown to be readable together.

    Every text the card draws is checked against the surface behind it, at WCAG AA for body text (4.5 to 1).
    """
    read = "(element) => [getComputedStyle(element).color, getComputedStyle(element).backgroundColor]"
    text, surface = card.locator("body").evaluate(read)
    assert channels(surface)[1] == 1, f"the card left its surface to whatever the host drew behind it: {surface}"
    label, button = card.locator("#open").evaluate(read)
    for name, ink, paper in [("the statement", text, surface), ("the site line", card.locator("#site").evaluate(read)[0], surface),
                             ("the status line", card.locator("#status").evaluate(read)[0], surface), ("the button", label, button)]:
        assert channels(paper)[1] == 1 and contrast(ink, paper) >= 4.5, f"{name} is {ink} on {paper}, {contrast(ink, paper):.2f} to 1"
    return text, surface


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
                               ("Claude on a phone", {"platform": "mobile", "name": "claude-ios"}),
                               ("another host on a phone", {"platform": "mobile"})]:
            page, card = host(browser, **options)
            expect(card.locator("#open")).to_be_visible()
            expect(card.locator("#phone-show")).to_be_hidden()
            checks.append("no phone option on " + label)

        # The button asks the host to open the site's own page and says where the person came from: back to Claude only
        # for a host that names itself Claude, and to the page's own line for any other.
        page, card = host(browser, name="claude-desktop")
        card.locator("#open").click()
        expect(card.locator("#status")).to_contain_text("Approve on the site with your passkey")
        opened = page.evaluate("window.opened")
        assert opened == [REVIEW + "#return=claude-desktop"], f"the card asked the host to open {opened!r}"
        # Two status checks sent means one answered "pending" and drawn, and the line is still there.
        page.wait_for_function("window.calls.filter((name) => name === 'approval_status').length >= 2", timeout=10000)
        expect(card.locator("#status")).to_contain_text("Approve on the site with your passkey")
        checks.append("the button opens the review page through the host, and its line outlasts a status check")
        page, card = host(browser, name="vscode")
        card.locator("#open").click()
        expect(card.locator("#status")).to_contain_text("Approve on the site with your passkey")
        opened = page.evaluate("window.opened")
        assert opened == [REVIEW + "#return=agent"], f"a host that is not Claude was sent back to Claude: {opened!r}"
        checks.append("a host that does not name itself Claude is not sent back to Claude")

        # A host that declares neither capability is sent neither request, and the card says what it cannot do.
        page, card = host(browser, capabilities={})
        expect(card.locator("#status")).to_contain_text("can’t check the result in this app")
        expect(card.locator("#status")).to_contain_text("Use the review link in the reply")
        expect(card.locator("#open")).to_be_hidden()
        expect(card.locator("#phone-show")).to_be_hidden()
        page.wait_for_timeout(3500)
        assert page.evaluate("window.calls.length + window.opened.length") == 0, "the card called a host that offered nothing"
        checks.append("a host without link opening or server tools gets no button and no status checks")

        # The site cannot vouch for its own record, so the card claims neither an approval nor that nothing changed.
        page, card = host(browser)
        page.evaluate("window.server.status = 'integrity_error'")
        expect(card.locator("#status")).to_contain_text("stored approval record is inconsistent", timeout=8000)
        expect(card.locator("#status")).not_to_contain_text("Nothing changed")
        expect(card.locator("#open")).to_be_hidden()
        checks.append("an integrity error is shown as an unconfirmed outcome")

        # The host stops the tool call. The site may have acted, so the card claims nothing either way.
        page, card = host(browser)
        page.evaluate("card().contentWindow.postMessage({ jsonrpc: '2.0', method: 'ui/notifications/tool-cancelled', params: { reason: 'The person stopped the reply.' } }, '*')")
        expect(card.locator("#status")).to_contain_text("can’t tell whether anything changed")
        expect(card.locator("#status")).not_to_contain_text("Nothing changed")
        expect(card.locator("#open")).to_be_hidden()
        checks.append("a stopped tool call is not reported as changing nothing")

        # A host that names a theme and sends its colors as the specification asks. The card takes the side of each
        # that the theme names, also where the device is set the other way. Left to itself a light-dark() color takes
        # its light side: dark text over a dark host, which is the picture the Claude iOS app showed.
        for theme, colors, system in [("dark", DARK, "light"), ("light", LIGHT, "dark")]:
            page, card = host(browser, theme=theme, styles={**EITHER, **SHAPE}, background=colors["--color-background-primary"], system=system)
            assert painted(card) == (colors["--color-text-primary"], colors["--color-background-primary"]), f"not the {theme} host's colors: {painted(card)}"
            if shots: page.screenshot(path=str(shots / f"card-{theme}-host.png"))
            checks.append(f"a {theme} host's colors are used for its theme on a device set to {system}")

        # The host changes its theme and says only that. The colors it sent before follow.
        page.evaluate("card().contentWindow.postMessage({ jsonrpc: '2.0', method: 'ui/notifications/host-context-changed', params: { theme: 'dark' } }, '*')")
        expect(card.locator("html")).to_have_attribute("data-theme", "dark")
        assert painted(card) == (DARK["--color-text-primary"], DARK["--color-background-primary"]), f"not the dark colors after the change: {painted(card)}"
        checks.append("a change of theme alone moves the host's colors to the new theme")

        # The other way to that picture: a host that names the dark theme and sends the light theme's colors outright,
        # over its dark page. The card uses its own dark colors. The host's font and corner radius still apply.
        page, card = host(browser, theme="dark", styles={**LIGHT, **SHAPE}, background=DARK_PAGE)
        text, surface = painted(card)
        assert luminance(text) > 0.5 > luminance(surface), f"a dark theme needs light text on a dark surface, not {text} on {surface}"
        assert card.locator("body").evaluate("(body) => getComputedStyle(body).fontFamily") == SHAPE["--font-sans"], "the host's font was dropped with its colors"
        assert card.locator("#open").evaluate("(button) => getComputedStyle(button).borderRadius") == SHAPE["--border-radius-md"], "the host's radius was dropped with its colors"
        if shots: page.screenshot(path=str(shots / "card-dark-theme-light-colors.png"))
        # When the host then sends the dark theme's colors, the card takes them.
        page.evaluate("(styles) => card().contentWindow.postMessage({ jsonrpc: '2.0', method: 'ui/notifications/host-context-changed', params: { styles } }, '*')",
                      {"variables": {**DARK, **SHAPE}})
        expect(card.locator("body")).to_have_css("color", DARK["--color-text-primary"])
        assert painted(card) == (DARK["--color-text-primary"], DARK["--color-background-primary"])
        checks.append("a host that names the dark theme and sends the light theme's colors gets the card's own dark colors, with the host's font and radius")

        # A host that sends a text color and no surface. The card does not put the host's text on its own surface.
        page, card = host(browser, theme="dark", styles={"--color-text-primary": DARK["--color-text-primary"], **SHAPE}, background=DARK_PAGE)
        text, surface = painted(card)
        assert text != DARK["--color-text-primary"] and luminance(text) > 0.5 > luminance(surface), f"not the card's own dark pair: {text} on {surface}"
        checks.append("a host that sends a text color without a surface gets the card's own text and surface")

        # A host that sends no styles. The card follows its theme, or the device where it names none, and since the host
        # may be drawn the other way the card's own surface has to carry its text.
        for theme, system, behind in [("dark", "light", DARK_PAGE), ("light", "dark", "#ffffff"), (None, "light", DARK_PAGE), (None, "dark", "#ffffff")]:
            page, card = host(browser, theme=theme, background=behind, system=system)
            text, surface = painted(card)
            assert (luminance(text) > luminance(surface)) == ((theme or system) == "dark"), f"{text} on {surface} is not the {theme or system} palette"
            if shots: page.screenshot(path=str(shots / f"card-no-styles-{theme or 'no-theme'}-on-{system}-device.png"))
        checks.append("a host that sends no styles gets the card's own colors on a surface the card paints")

        # A device set to light, a host drawn dark that takes its time to answer. Until it answers the card paints no
        # surface, so nothing white flashes in the dark app; left unanswered, it paints its own after a moment.
        page, card = host(browser, theme="dark", styles={**EITHER, **SHAPE}, background=DARK_PAGE, system="light", delay=5000)
        surface, age = card.locator("body").evaluate("(body) => [getComputedStyle(body).backgroundColor, performance.now()]")
        # The card's own timer runs in real time; on a machine too slow to look inside it, there is nothing left to see.
        assert age > 250 or channels(surface)[1] == 0, f"the card painted {surface} before the host said how it is drawn"
        expect(card.locator("body")).to_have_css("background-color", "rgb(255, 255, 255)")
        expect(card.locator("#statement")).to_be_hidden()
        page.clock.run_for(5000)
        expect(card.locator("#statement")).to_contain_text("Evacuation order")
        assert painted(card) == (DARK["--color-text-primary"], DARK["--color-background-primary"]), f"not the dark host's colors once it answered: {painted(card)}"
        checks.append("the card paints no surface until the host answers, and its own if the host stays silent")

        browser.close()
    print(json.dumps({"passed": True, "checks": checks}, indent=2))


if __name__ == "__main__":
    main()
