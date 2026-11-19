// Actual approval-card script with a deterministic host/DOM shim. Browser rendering stays in card_smoke.py.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const card = new URL('../src/mcp/resources/approval-card.html', import.meta.url);
const script = readFileSync(card, 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const flush = async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); };
const approval = (status = 'pending', requestId = 'r1', extra = {}) => ({ content: [], structuredContent: { approval: {
  requestId, status, approvedWithPasskey: status === 'completed', reviewUrl: `https://alerts.example/approval/${requestId}`,
  statusTool: 'approval_status', handoffTool: 'approval_handoff', statement: 'I approve this exact public alert.', ...extra,
} } });
const phoneReply = (requestId = 'r1') => ({ content: [], structuredContent: {
  requestId, modules: Array(21).fill('0'.repeat(21)), expiresIn: 120,
} });

function host() {
  let now = 0, sequence = 0;
  const timers = new Map(), messages = [], listeners = {}, elements = new Map(), answered = new Set(), applied = new Map(), resized = [];
  function element(id) {
    if (!elements.has(id)) elements.set(id, {
      hidden: true, disabled: false, textContent: id === 'status' ? 'Waiting for the request…' : '', children: [], handlers: {},
      addEventListener(name, handler) { this.handlers[name] = handler; },
      setAttribute() {}, append(...children) { this.children.push(...children); }, replaceChildren(...children) { this.children = children; },
    });
    return elements.get(id);
  }
  const parent = { postMessage(message) { messages.push(structuredClone(message)); } };
  const document = {
    hidden: false, body: { dataset: {} },
    documentElement: { scrollHeight: 300, dataset: {}, style: { setProperty(name, value) { applied.set(name, value); }, removeProperty(name) { applied.delete(name); } } },
    getElementById: element, createElementNS: (_ns, name) => element(`svg-${name}-${++sequence}`),
    addEventListener(name, handler) { listeners[`document-${name}`] = handler; },
  };
  const context = vm.createContext({
    parent, URL, Map, Promise, Error, navigator: { userAgent: 'card-lifecycle-test' }, document,
    // Records what the card watches; a test calls it back as the browser would after a reflow.
    ResizeObserver: class { constructor(callback) { this.callback = callback; } observe(target) { resized.push(() => this.callback([{ target }])); } },
    Date: class extends Date { static now() { return now; } },
    addEventListener(name, handler) { listeners[name] = handler; },
    setTimeout(callback, delay) { const id = ++sequence; timers.set(id, { callback, at: now + delay }); return id; },
    clearTimeout(id) { timers.delete(id); },
  });
  vm.runInContext(script, context, { filename: card.pathname });
  const dispatch = data => listeners.message({ source: parent, data: { jsonrpc: '2.0', ...data } });
  return {
    element, messages, dispatch,
    status: () => element('status').textContent,
    // The status the card's colour follows (body[data-status]).
    shade: () => document.body.dataset.status,
    // The theme and the style variables the card has put on its root element.
    theme: () => document.documentElement.dataset.theme,
    variables: () => Object.fromEntries(applied),
    call: name => messages.findLast(m => m.method === 'tools/call' && m.params.name === name),
    calls: name => messages.filter(m => m.method === 'tools/call' && m.params.name === name).length,
    opened: () => messages.findLast(m => m.method === 'ui/open-link'),
    sizes: () => messages.filter(m => m.method === 'ui/notifications/size-changed').length,
    reflow() { for (const observer of resized) observer(); },
    async visible(value) { document.hidden = !value; listeners['document-visibilitychange'](); await flush(); },
    // Lets time pass a second at a time while the site answers every status check with "pending".
    async pendingFor(milliseconds) {
      for (let elapsed = 0; elapsed < milliseconds; elapsed += 1000) {
        await this.advance(1000);
        const check = this.call('approval_status');
        if (check && !answered.has(check.id)) { answered.add(check.id); await this.reply(check, approval()); }
      }
    },
    // The host's answer to ui/initialize. Unless a test says, the host does not name itself.
    async initialize(capabilities = { openLinks: {}, serverTools: {} }, context = { platform: 'web' }, info = undefined) {
      dispatch({ id: messages[0].id, result: { protocolVersion: '2026-01-26', hostCapabilities: capabilities, hostInfo: info, hostContext: context } });
      await flush();
    },
    show(result = approval()) { dispatch({ method: 'ui/notifications/tool-result', params: result }); },
    async reply(call, result) { dispatch({ id: call.id, result }); await flush(); },
    async advance(milliseconds) {
      const target = now + milliseconds;
      let iterations = 0;
      while (true) {
        const next = [...timers].filter(([, value]) => value.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
        if (!next) break;
        assert.ok(++iterations < 1000, 'timers must not loop without time advancing');
        timers.delete(next[0]); now = next[1].at; next[1].callback(); await flush();
      }
      now = target;
      await flush();
    },
  };
}

for (const control of ['phone-show', 'open']) {
  for (const terminal of ['completed', 'cancelled', 'expired']) {
    for (const failed of [false, true]) {
      test(`late ${control} ${failed ? 'error' : 'response'} cannot replace the site's ${terminal} status`, async () => {
        const h = host(); await h.initialize(); h.show();
        const click = h.element(control).handlers.click(); await flush();
        const held = control === 'phone-show' ? h.call('approval_handoff') : h.messages.findLast(m => m.method === 'ui/open-link');
        await h.advance(3000);
        await h.reply(h.call('approval_status'), approval(terminal));
        const recorded = h.status();
        assert.match(recorded, terminal === 'completed' ? /site recorded the change/ : /Nothing changed/);
        if (failed) {
          h.dispatch({ id: held.id, error: { code: -32603, message: 'The delayed operation failed.' } });
          await flush();
        } else {
          await h.reply(held, control === 'phone-show' ? phoneReply() : {});
        }
        await click;
        assert.equal(h.status(), recorded);
        // Interrupting communication later cannot erase an outcome the site already confirmed.
        h.dispatch({ method: 'ui/notifications/tool-cancelled' });
        assert.equal(h.status(), recorded);
        assert.equal(h.element('phone-code').hidden, true);
        assert.equal(h.element('phone-show').hidden, true);
        assert.equal(h.element('open').hidden, true);
      });
    }
  }

  test(`late ${control} response cannot revive a host-interrupted card`, async () => {
    const h = host(); await h.initialize(); h.show();
    const click = h.element(control).handlers.click(); await flush();
    const held = control === 'phone-show' ? h.call('approval_handoff') : h.messages.findLast(m => m.method === 'ui/open-link');
    h.dispatch({ method: 'ui/notifications/tool-cancelled', params: { reason: 'The person stopped the reply.' } });
    const interrupted = h.status();
    assert.match(interrupted, /can’t tell whether anything changed/);
    await h.reply(held, control === 'phone-show' ? phoneReply() : {});
    await click;
    h.show(approval()); // A late parent result must not reopen a stopped view either.
    assert.equal(h.status(), interrupted);
    assert.equal(h.element('phone-code').hidden, true);
    assert.equal(h.element('phone-show').hidden, true);
    assert.equal(h.element('open').hidden, true);
  });
}

test('an old handoff cannot populate a replacement approval', async () => {
  const h = host(); await h.initialize(); h.show();
  const click = h.element('phone-show').handlers.click(); await flush();
  const held = h.call('approval_handoff');
  h.show(approval('pending', 'r2'));
  const replacement = h.status();
  await h.reply(held, phoneReply()); await click;
  assert.equal(h.status(), replacement);
  assert.equal(h.element('phone-code').hidden, true);
  assert.equal(h.element('phone-show').disabled, false);
  h.element('phone-show').handlers.click(); await flush();
  assert.equal(h.call('approval_handoff').params.arguments.request_id, 'r2');
});

test('teardown retires outstanding UI work and acknowledges the host', async () => {
  const h = host(); await h.initialize(); h.show();
  const click = h.element('phone-show').handlers.click(); await flush();
  const held = h.call('approval_handoff');
  h.dispatch({ method: 'ui/resource-teardown', id: 'teardown' });
  const before = h.status();
  await h.reply(held, phoneReply()); await click;
  assert.deepEqual(h.messages.find(m => m.id === 'teardown').result, {});
  assert.equal(h.status(), before);
  assert.equal(h.element('phone-code').hidden, true);
});

test('a missing host reply times out, frees the phone button, and ignores the late reply', async () => {
  const h = host(); await h.initialize(); h.show();
  const click = h.element('phone-show').handlers.click(); await flush();
  const held = h.call('approval_handoff');
  await h.advance(15000); await click;
  assert.match(h.status(), /phone code could not be made/);
  assert.equal(h.element('phone-show').disabled, false);
  await h.reply(held, phoneReply());
  assert.equal(h.element('phone-code').hidden, true);
});

test('a lost status reply reports uncertainty and polling can recover', async () => {
  const h = host(); await h.initialize(); h.show();
  await h.advance(18000);
  assert.match(h.status(), /can’t confirm the current status/);
  await h.advance(3000);
  await h.reply(h.call('approval_status'), approval('completed'));
  assert.match(h.status(), /site recorded the change/);
});

test('initialization has a bounded fallback and absent capabilities do not send unsupported requests', async () => {
  const lost = host(); await lost.advance(15000);
  assert.match(lost.status(), /Use the review link in the reply/);
  const h = host(); await h.initialize({}); h.show();
  await h.advance(60000);
  assert.equal(h.element('open').hidden, true);
  assert.equal(h.element('phone-show').hidden, true);
  assert.equal(h.messages.some(m => m.method === 'tools/call' || m.method === 'ui/open-link'), false);
  assert.match(h.status(), /can’t check the result in this app.* Use the review link in the reply\.$/);
});

test('a host that answers initialize late still gets a working card', async () => {
  const h = host(); await h.advance(15000);
  assert.match(h.status(), /did not start the card/);
  await h.initialize();
  assert.equal(h.status(), 'Waiting for the request…');
  h.show();
  assert.equal(h.element('open').hidden, false);
  assert.match(h.status(), /Nothing has changed yet/);
  await h.advance(3000);
  assert.equal(h.calls('approval_status'), 1);
});

test('a result delivered before the initialize answer is drawn again with what the host allows', async () => {
  const h = host(); h.show();
  assert.equal(h.element('open').hidden, true);
  assert.match(h.status(), /can’t check the result in this app/);
  await h.advance(15000);
  // The slow-start hint never replaces a result the host has delivered.
  assert.doesNotMatch(h.status(), /did not start the card/);
  assert.equal(h.messages.some(m => m.method === 'tools/call' || m.method === 'ui/open-link'), false);
  await h.initialize();
  assert.equal(h.element('open').hidden, false);
  assert.equal(h.element('phone-show').hidden, false);
  assert.equal(h.status(), 'Waiting for approval on the site. Nothing has changed yet.');
  await h.advance(3000);
  assert.equal(h.calls('approval_status'), 1);
});

test('a card that cannot follow the request says so instead of claiming that nothing has changed', async () => {
  const unlisted = approval(); delete unlisted.structuredContent.approval.statusTool;
  for (const [capabilities, result] of [[{ openLinks: {} }, approval()], [{ openLinks: {}, serverTools: {} }, unlisted]]) {
    const h = host(); await h.initialize(capabilities); h.show(result);
    assert.equal(h.element('open').hidden, false);
    assert.match(h.status(), /can’t check the result in this app/);
    assert.doesNotMatch(h.status(), /Nothing has changed|review link/);
    const click = h.element('open').handlers.click(); await flush();
    await h.reply(h.opened(), {}); await click;
    assert.match(h.status(), /can’t check the result in this app/);
    await h.advance(60000);
    assert.equal(h.calls('approval_status'), 0);
  }
  // Without ui/open-link the card still follows the request, and points at the link in the reply.
  const h = host(); await h.initialize({ serverTools: {} }); h.show();
  assert.equal(h.element('open').hidden, true);
  assert.equal(h.status(), 'Waiting for approval on the site. Nothing has changed yet. Use the review link in the reply.');
  await h.advance(3000);
  assert.equal(h.calls('approval_status'), 1);
  assert.equal(h.opened(), undefined);
});

test('a phone code that cannot be made points at a way the person still has', async () => {
  for (const [capabilities, advice] of [[{ openLinks: {}, serverTools: {} }, /Approve on this device instead\.$/], [{ serverTools: {} }, /Use the review link in the reply\.$/]]) {
    const h = host(); await h.initialize(capabilities); h.show();
    const click = h.element('phone-show').handlers.click(); await flush();
    await h.reply(h.call('approval_handoff'), { isError: true, content: [] }); await click;
    assert.match(h.status(), /^The phone code could not be made\./);
    assert.match(h.status(), advice);
  }
});

test('a request key that named a finished request shows its earlier outcome, not a new approval', async () => {
  const h = host(); await h.initialize();
  h.show(approval('completed', 'r1', { earlier: true, completedAt: '2026-11-08T10:01:00Z' }));
  const when = new Date('2026-11-08T10:01:00Z').toLocaleString('en', { dateStyle: 'medium', timeStyle: 'short' });
  assert.equal(h.status(), `Completed earlier, on ${when}. Nothing ran now.`);
  assert.equal(h.element('site').textContent, 'Earlier request on alerts.example');
  assert.equal(h.shade(), 'completed');
  // A stopped one says what it always says, under the same heading, and neither is checked again.
  const stopped = host(); await stopped.initialize();
  stopped.show(approval('cancelled', 'r2', { earlier: true, completedAt: null }));
  assert.equal(stopped.status(), 'Cancelled on the site. Nothing changed.');
  assert.equal(stopped.element('site').textContent, 'Earlier request on alerts.example');
  await h.advance(60000); await stopped.advance(60000);
  assert.equal(h.calls('approval_status') + stopped.calls('approval_status'), 0);
  // A completion this call made reads as it always has.
  const fresh = host(); await fresh.initialize(); fresh.show(approval('completed', 'r3', { earlier: false, completedAt: '2026-11-08T10:01:00Z' }));
  assert.equal(fresh.status(), 'Approved with a passkey. The site recorded the change.');
  assert.equal(fresh.element('site').textContent, 'alerts.example');
});

test('a stopped request is told in the review page’s words, with no cause the site did not record', async () => {
  const invalidated = 'This request can no longer be approved: something it depends on changed after it was made. Nothing changed.';
  const denied = 'This request is no longer allowed. Nothing changed.';
  for (const [status, reason, line] of [['invalidated', 'policy_changed', invalidated], ['invalidated', 'stale_resource', invalidated],
    ['denied', 'delegation_revoked', denied], ['denied', 'permission_denied', denied]]) {
    const h = host(); await h.initialize(); h.show();
    await h.advance(3000);
    await h.reply(h.call('approval_status'), approval(status, 'r1', { reason }));
    assert.equal(h.status(), line, `${status} / ${reason}`);
  }
});

test('only a host that names itself Claude is sent back by Claude’s links; any other host gets the page’s own line', async () => {
  const opens = async (info, context) => {
    const h = host(); await h.initialize(undefined, context, info); h.show();
    const click = h.element('open').handlers.click(); await flush();
    const url = h.opened().params.url;
    await h.reply(h.opened(), {}); await click;
    return url.slice('https://alerts.example/approval/r1'.length);
  };
  assert.equal(await opens({ name: 'claude-desktop', version: '1.0.0' }, { platform: 'desktop' }), '#return=claude-desktop');
  assert.equal(await opens({ name: 'Claude', version: '1.0.0' }, { platform: 'mobile' }), '#return=claude-mobile');
  assert.equal(await opens(undefined, { platform: 'web', userAgent: 'claude.ai/1.0' }), '#return=claude-web');
  for (const [info, context] of [[{ name: 'vscode', version: '1.110' }, { platform: 'desktop' }], [{ name: 'chatgpt', version: '1' }, { platform: 'web' }],
    [{ name: 'basic-host', version: '1' }, {}], [undefined, { platform: 'mobile' }], [undefined, {}]]) {
    assert.equal(await opens(info, context), '#return=agent', JSON.stringify([info, context]));
  }
});

test('a host on a phone is offered no phone code, whoever it is', async () => {
  for (const info of [{ name: 'claude-ios', version: '1' }, { name: 'another-host', version: '1' }, undefined]) {
    const h = host(); await h.initialize(undefined, { platform: 'mobile' }, info); h.show();
    assert.equal(h.element('phone-show').hidden, true, JSON.stringify(info));
  }
  const h = host(); await h.initialize(undefined, { platform: 'desktop' }, { name: 'vscode', version: '1.110' }); h.show();
  assert.equal(h.element('phone-show').hidden, false);
});

test('a refusal that names no request is shown as a refusal, and an unknown status as it is', async () => {
  const h = host(); await h.initialize();
  h.show({ isError: true, content: [], structuredContent: { status: 'rejected', reason: 'permission_denied', message: 'This account may not issue alerts.' } });
  assert.equal(h.status(), 'The site refused this request. Nothing changed.');
  assert.equal(h.shade(), 'rejected');
  assert.equal(h.element('open').hidden, true);
  for (const status of ['constructor', '__proto__', 'toString', 'completedDirect', 'interrupted']) {
    const g = host(); await g.initialize(); g.show(approval(status));
    assert.equal(g.status(), `Status: ${status}.`, status);
  }
});

test('the card declares that it is drawn inline, and reports its size when the host restyles it', async () => {
  const h = host();
  assert.deepEqual(h.messages[0].params.appCapabilities, { availableDisplayModes: ['inline'] });
  await h.initialize(); h.show();
  const before = h.sizes();
  h.dispatch({ method: 'ui/notifications/host-context-changed', params: { styles: { variables: { '--font-text-md-size': '22px' } } } });
  h.reflow();
  assert.equal(h.sizes(), before + 1);
});

test('a new request does not inherit the colour of the one before it', async () => {
  const h = host(); await h.initialize(); h.show(approval('completed'));
  assert.equal(h.shade(), 'completed');
  h.dispatch({ method: 'ui/notifications/tool-input', params: { arguments: {} } });
  assert.equal(h.shade(), undefined);
  assert.equal(h.status(), 'Waiting for the request…');
  h.show(approval('pending', 'r2'));
  assert.equal(h.shade(), 'pending');
});

test('where the card cannot see its colours it starts all the same, with its own colours and the host’s font', async () => {
  // This shim computes no styles, like a browser that will not report them or read a canvas back.
  const h = host();
  await h.initialize(undefined, { theme: 'dark', styles: { variables: {
    '--color-text-primary': '#141413', '--color-background-primary': '#ffffff', '--font-sans': 'Georgia, serif', 'border-radius-md': '3px',
    '--font-weight-semibold': undefined, '--fg': '#141413',
  } } });
  assert.equal(h.status(), 'Waiting for the request…');
  assert.equal(h.theme(), 'dark');
  // The host's colours are not used unseen, and a variable outside the specification cannot replace one of the card's own.
  assert.deepEqual(h.variables(), { '--font-sans': 'Georgia, serif', '--border-radius-md': '3px' });
  h.show();
  assert.equal(h.element('open').hidden, false);
  // A change of theme alone is taken, and the colours sent earlier are still not used unseen.
  h.dispatch({ method: 'ui/notifications/host-context-changed', params: { theme: 'light' } });
  assert.equal(h.theme(), 'light');
  assert.deepEqual(h.variables(), { '--font-sans': 'Georgia, serif', '--border-radius-md': '3px' });
});

test('an unanswered open-link request is not reported as a refusal, and a refusal is', async () => {
  const h = host(); await h.initialize(); h.show();
  let click = h.element('open').handlers.click(); await flush();
  const held = h.opened();
  await h.advance(15000); await click;
  assert.match(h.status(), /^If the page did not open/);
  await h.reply(held, { isError: true }); // Too late to count either way.
  assert.match(h.status(), /^If the page did not open/);
  for (const refusal of [{ result: { isError: true } }, { error: { code: -32000, message: 'The person declined to open the link.' } }]) {
    click = h.element('open').handlers.click(); await flush();
    h.dispatch({ id: h.opened().id, ...refusal }); await flush(); await click;
    assert.match(h.status(), /^The page did not open/);
  }
});

test('a line about the person’s last step survives a status check that finds the request still pending', async () => {
  const h = host(); await h.initialize(); h.show();
  const click = h.element('open').handlers.click(); await flush();
  await h.reply(h.opened(), { isError: true }); await click;
  assert.match(h.status(), /^The page did not open/);
  await h.advance(3000);
  await h.reply(h.call('approval_status'), approval());
  assert.match(h.status(), /^The page did not open/);
  await h.advance(3000);
  assert.equal(h.calls('approval_status'), 2);
  await h.reply(h.call('approval_status'), approval('completed'));
  assert.match(h.status(), /site recorded the change/);
});

test('status checks stop after a quarter of an hour, say so, and resume when the card is seen or used again', async () => {
  const h = host(); await h.initialize(); h.show();
  await h.pendingFor(14 * 60 * 1000);
  assert.match(h.status(), /Nothing has changed yet/);
  await h.pendingFor(2 * 60 * 1000);
  assert.match(h.status(), /stopped checking/);
  assert.doesNotMatch(h.status(), /Nothing has changed/);
  const checks = h.calls('approval_status');
  await h.advance(10 * 60 * 1000);
  assert.equal(h.calls('approval_status'), checks);
  // Coming back into view checks once, without waiting.
  await h.visible(false); await h.visible(true);
  assert.equal(h.calls('approval_status'), checks + 1);
  await h.reply(h.call('approval_status'), approval());
  assert.match(h.status(), /stopped checking/);
  await h.advance(60000);
  assert.equal(h.calls('approval_status'), checks + 1);
  // Using a button restarts the quick checks.
  const click = h.element('open').handlers.click(); await flush();
  await h.reply(h.opened(), {}); await click;
  assert.match(h.status(), /This card updates when the site records the result/);
  await h.advance(3000);
  assert.equal(h.calls('approval_status'), checks + 2);
  await h.reply(h.call('approval_status'), approval('completed'));
  assert.match(h.status(), /site recorded the change/);
});

test('a hidden card does not check, and checks at once when it is shown again', async () => {
  const h = host(); await h.initialize(); h.show();
  await h.visible(false);
  await h.advance(60000);
  assert.equal(h.calls('approval_status'), 0);
  await h.visible(true);
  assert.equal(h.calls('approval_status'), 1);
  await h.reply(h.call('approval_status'), approval('completed'));
  assert.match(h.status(), /site recorded the change/);
});

test('the card answers a ping and refuses the requests it does not implement', async () => {
  const h = host(); await h.initialize();
  h.dispatch({ id: 'ping-1', method: 'ping' });
  assert.deepEqual(h.messages.find(m => m.id === 'ping-1').result, {});
  h.dispatch({ id: 'list-1', method: 'tools/list' });
  assert.equal(h.messages.find(m => m.id === 'list-1').error.code, -32601);
  const sent = h.messages.length;
  h.dispatch({ method: 'ui/notifications/not-known-to-this-card', params: {} });
  h.dispatch({ id: 9999, result: {} }); // An answer to a request the card gave up on.
  assert.equal(h.messages.length, sent);
});

test('an integrity error retires pending work without claiming that nothing changed', async () => {
  const h = host(); await h.initialize(); h.show();
  const click = h.element('phone-show').handlers.click(); await flush();
  const held = h.call('approval_handoff');
  await h.advance(3000);
  await h.reply(h.call('approval_status'), { isError: true, content: [], structuredContent: {
    requestId: 'r1', status: 'integrity_error', reason: 'record_integrity_error',
  } });
  assert.match(h.status(), /stored approval record is inconsistent/);
  assert.doesNotMatch(h.status(), /Nothing changed|Nothing has changed/);
  const failure = h.status();
  await h.reply(held, phoneReply()); await click;
  assert.equal(h.status(), failure);
  assert.equal(h.element('phone-code').hidden, true);
});

test('an integrity error without a request view is still shown as an unconfirmed outcome', async () => {
  const h = host(); await h.initialize();
  h.show({ isError: true, content: [], structuredContent: { status: 'integrity_error', reason: 'record_integrity_error' } });
  assert.match(h.status(), /stored approval record is inconsistent/);
  assert.doesNotMatch(h.status(), /Nothing changed|Nothing has changed|No approval is waiting/);
  assert.equal(h.shade(), 'integrity_error');
  assert.equal(h.element('phone-show').hidden, true);
  assert.equal(h.element('open').hidden, true);
});
