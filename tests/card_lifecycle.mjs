// Actual approval-card script with a deterministic host/DOM shim. Browser rendering stays in card_smoke.py.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const card = new URL('../src/mcp/resources/approval-card.html', import.meta.url);
const script = readFileSync(card, 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const flush = async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); };
const approval = (status = 'pending', requestId = 'r1') => ({ content: [], structuredContent: { approval: {
  requestId, status, approvedWithPasskey: status === 'completed', reviewUrl: `https://alerts.example/approval/${requestId}`,
  statusTool: 'approval_status', handoffTool: 'approval_handoff', statement: 'I approve this exact public alert.',
} } });
const phoneReply = (requestId = 'r1') => ({ content: [], structuredContent: {
  requestId, modules: Array(21).fill('0'.repeat(21)), expiresIn: 120,
} });

function host() {
  let now = 0, sequence = 0;
  const timers = new Map(), messages = [], listeners = {}, elements = new Map();
  function element(id) {
    if (!elements.has(id)) elements.set(id, {
      hidden: true, disabled: false, textContent: id === 'status' ? 'Waiting for the request…' : '', children: [], handlers: {},
      addEventListener(name, handler) { this.handlers[name] = handler; },
      setAttribute() {}, append(...children) { this.children.push(...children); }, replaceChildren(...children) { this.children = children; },
    });
    return elements.get(id);
  }
  const parent = { postMessage(message) { messages.push(structuredClone(message)); } };
  const context = vm.createContext({
    parent, URL, Map, Promise, Error, navigator: { userAgent: 'card-lifecycle-test' },
    Date: class extends Date { static now() { return now; } },
    document: {
      hidden: false, body: { dataset: {} }, documentElement: { scrollHeight: 300, dataset: {}, style: { setProperty() {} } },
      getElementById: element, createElementNS: (_ns, name) => element(`svg-${name}-${++sequence}`),
      addEventListener(name, handler) { listeners[`document-${name}`] = handler; },
    },
    addEventListener(name, handler) { listeners[name] = handler; },
    setTimeout(callback, delay) { const id = ++sequence; timers.set(id, { callback, at: now + delay }); return id; },
    clearTimeout(id) { timers.delete(id); },
  });
  vm.runInContext(script, context, { filename: card.pathname });
  const dispatch = data => listeners.message({ source: parent, data: { jsonrpc: '2.0', ...data } });
  return {
    element, messages, dispatch,
    status: () => element('status').textContent,
    call: name => messages.findLast(m => m.method === 'tools/call' && m.params.name === name),
    async initialize(capabilities = { openLinks: {}, serverTools: {} }) {
      dispatch({ id: messages[0].id, result: { protocolVersion: '2026-01-26', hostCapabilities: capabilities, hostContext: { platform: 'web' } } });
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
  assert.equal(h.element('phone-show').hidden, true);
  assert.equal(h.element('open').hidden, true);
});
