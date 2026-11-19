// Run the shipped ceremony script. These tests exercise response loss, not WebAuthn cryptography.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../src/symfony-bundle/public/approval.js', import.meta.url), 'utf8');
const json = (status, body) => ({status, ok: status < 400, async json() { return body; }});
const expired = () => json(401, {status: 'authentication_required'});
const options = () => json(200, {ceremony_id: 'c1', publicKey: {challenge: 'YQ', user: {id: 'YQ'}}});

function host(responses, mode = 'approval', device = null) {
    const requests = [], links = [];
    let reloads = 0, devices = 0;
    const control = () => ({disabled: false, handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }});
    const approve = control(), cancel = control();
    const message = {textContent: '', after(link) { links.push(link); }};
    const panel = {
        dataset: {mode, csrf: 'csrf', optionsUrl: '/options', completeUrl: '/complete', cancelUrl: '/cancel'},
        querySelector(selector) {
            return ({'[data-approve]': approve, '[data-cancel]': cancel, '[data-message]': message,
                '[data-authentication-recovery]': links[0]})[selector] ?? null;
        },
    };
    const location = {href: 'https://host.example/approval/existing#return=claude-web', hash: '', reload() { reloads++; }};
    const credential = {id: 'credential', rawId: new Uint8Array([1]), type: 'public-key',
        response: {clientDataJSON: new Uint8Array([2])}, getClientExtensionResults: () => ({})};
    const ceremony = async () => { devices++; return device ? await device() : credential; };
    const context = vm.createContext({
        URLSearchParams, Uint8Array, atob, btoa, location, history: {},
        window: {PublicKeyCredential: {}, isSecureContext: true, location},
        navigator: {credentials: {get: ceremony, create: ceremony}},
        document: {
            querySelectorAll: selector => selector === '[data-passkey-panel]' ? [panel] : [],
            querySelector: () => null,
            createElement: () => ({...control(), dataset: {}}),
        },
        fetch: async (url, init) => {
            requests.push({url, ...init});
            const response = responses.shift();
            if (response instanceof Error) throw response;
            assert.ok(response, 'No automatic retry or unexpected request');
            return response;
        },
    });
    vm.runInContext(script, context);
    return {approve, cancel, message, links, requests, reloads: () => reloads, devices: () => devices};
}

test('expired session before options offers a GET of the existing request without starting a ceremony', async () => {
    const h = host([expired()]);
    await h.approve.handlers.click();
    assert.equal(h.devices(), 0);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].headers.Accept, 'application/json');
    assert.match(h.message.textContent, /sign-in expired.*this same page/);
    assert.doesNotMatch(h.message.textContent, /nothing changed|approval was saved/i);
    assert.equal(h.links[0].href, 'https://host.example/approval/existing#return=claude-web');
    assert.equal(h.approve.disabled, true);
    assert.equal(h.cancel.disabled, true);
    assert.equal(h.reloads(), 0);
    let prevented = false;
    h.links[0].handlers.click({preventDefault() { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(h.reloads(), 1, 'Recovery forces a GET even with a same-document return fragment');
    assert.equal(h.requests.length, 1, 'Recovery never replays the POST');
});

for (const mode of ['approval', 'registration', 'confirm']) {
    test(`${mode}: session loss after submission preserves uncertainty and never replays completion`, async () => {
        const h = host([options(), expired()], mode);
        await h.approve.handlers.click();
        assert.equal(h.devices(), 1);
        assert.equal(h.requests.length, 2);
        assert.match(h.message.textContent, /sign-in expired/);
        assert.match(h.message.textContent, /can’t confirm|may not have been saved/);
        assert.doesNotMatch(h.message.textContent, /nothing changed|nothing was executed/i);
        assert.equal(h.links.length, 1);
        assert.equal(h.reloads(), 0);
    });
}

test('lost, HTML and unmarked gateway responses after submission stay unconfirmed', async () => {
    for (const response of [new Error('lost after commit'),
        {status: 200, ok: true, async json() { throw new SyntaxError('HTML login'); }},
        json(401, {message: 'Gateway authentication'}), json(503, {status: 'authentication_required'})]) {
        const h = host([options(), response]);
        await h.approve.handlers.click();
        assert.match(h.message.textContent, /can’t confirm whether your approval was saved/);
        assert.doesNotMatch(h.message.textContent, /sign-in expired|nothing changed/i);
        assert.equal(h.links.length, 0);
        assert.equal(h.requests.length, 2);
    }
});

test('an authenticated permission refusal stays distinct from session expiry', async () => {
    const h = host([options(), json(403, {status: 'rejected', reason: 'forbidden', message: 'Publishing is no longer permitted.'})]);
    await h.approve.handlers.click();
    assert.equal(h.message.textContent, 'Publishing is no longer permitted.');
    assert.equal(h.links.length, 0);
    assert.equal(h.approve.disabled, false);
});

test('session loss during cancellation offers recovery without repeating cancellation', async () => {
    const h = host([expired()]);
    await h.cancel.handlers.click({target: h.cancel});
    assert.match(h.message.textContent, /sign-in expired/);
    assert.match(h.message.textContent, /can’t confirm whether the request was cancelled/);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/cancel');
    assert.equal(h.links.length, 1);
    assert.equal(h.cancel.disabled, true);
});

test('a confirmed terminal refusal still reloads the recorded outcome', async () => {
    const h = host([options(), json(409, {status: 'rejected', request_status: 'completed'})]);
    await h.approve.handlers.click();
    assert.equal(h.reloads(), 1);
    assert.equal(h.requests.length, 2);
});

for (const reject of [false, true]) {
    test(`late device ${reject ? 'cancellation' : 'assertion'} cannot replace authentication recovery or submit approval`, async () => {
        let resolveDevice, rejectDevice;
        const pending = new Promise((resolve, reject) => { resolveDevice = resolve; rejectDevice = reject; });
        const h = host([options(), expired()], 'approval', () => pending);
        const approve = h.approve.handlers.click();
        for (let i = 0; i < 8; i++) await Promise.resolve();
        assert.equal(h.devices(), 1);
        await h.cancel.handlers.click({target: h.cancel});
        const recorded = h.message.textContent;
        if (reject) rejectDevice(Object.assign(new Error('Cancelled'), {name: 'NotAllowedError'}));
        else resolveDevice({});
        await approve;
        assert.equal(h.message.textContent, recorded);
        assert.match(recorded, /sign-in expired/);
        assert.equal(h.approve.disabled, true);
        assert.equal(h.cancel.disabled, true);
        assert.equal(h.requests.length, 2, 'Only options and cancellation reached the host');
        assert.equal(h.requests.some(request => request.url === '/complete'), false);
    });
}

// The finished page's way back, as approval.js sets it from the fragment the approval card added.
function returnPage(hash) {
    const hint = {textContent: 'You can close this tab and return to Claude.'};
    const app = {hidden: true}, tab = {hidden: true, handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }};
    const panel = {hidden: true, dataset: {messages: '{}'}, querySelector: selector => ({'[data-return-app]': app, '[data-return-tab]': tab})[selector] ?? null};
    const context = vm.createContext({
        URLSearchParams, location: {hash}, history: {}, window: {close() {}}, setTimeout,
        document: {querySelectorAll: () => [], querySelector: selector => ({'[data-return]': panel, '[data-return-hint]': hint})[selector] ?? null},
    });
    vm.runInContext(script, context);
    return {panel, app, tab, hint};
}

test('the way back opens Claude only for the card in Claude, and any other host keeps the page’s own line', () => {
    for (const hash of ['#return=claude-desktop', '#return=claude-mobile']) {
        const page = returnPage(hash);
        assert.equal(page.panel.hidden, false, hash);
        assert.equal(page.app.hidden, false, hash);
        assert.match(page.hint.textContent, /Claude/);
    }
    const web = returnPage('#return=claude-web');
    assert.equal(web.tab.hidden, false);
    assert.equal(web.app.hidden, true);
    for (const hash of ['#return=agent', '', '#return=something-else']) {
        const page = returnPage(hash);
        assert.equal(page.panel.hidden, true, hash);
        assert.equal(page.app.hidden, true, hash);
        assert.equal(page.tab.hidden, true, hash);
        assert.equal(page.hint.textContent, 'You can close this tab and return to Claude.', hash);
    }
});

test('the refusal the enrollment guide shows is shown as the site’s refusal, and an unmarked one as unconfirmed', async () => {
    const guide = readFileSync(new URL('../src/symfony-bundle/docs/enrollment.md', import.meta.url), 'utf8');
    const refusal = JSON.parse(guide.match(/`(\{"status":"rejected"[^`]*\})`/)[1]);
    const refused = host([options(), json(400, refusal)], 'registration');
    await refused.approve.handlers.click();
    assert.equal(refused.message.textContent, refusal.message);
    const unmarked = host([options(), json(400, {message: refusal.message})], 'registration');
    await unmarked.approve.handlers.click();
    assert.match(unmarked.message.textContent, /can’t confirm whether your passkey was registered/);
});
