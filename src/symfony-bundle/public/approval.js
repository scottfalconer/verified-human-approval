/** Native WebAuthn ceremonies. The server, not this script, decides what may execute. */
const decode = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

/**
 * What this script says, in English. A page hands over its own wording, such as the site's translation, as a JSON
 * object of these keys in data-messages on the passkey panel and on the return panel. Whatever it leaves out, or
 * gives as anything but text, stays as it is here.
 */
const ENGLISH = {
    waiting: 'Waiting for your device…',
    unsupported: 'Passkeys need a supported browser on HTTPS (or localhost).',
    approvalCancelled: 'Approval cancelled. Nothing was executed.',
    sending: 'Sending your passkey response to the site…',
    registered: 'Passkey registered.',
    loading: 'Loading the result…',
    deviceCancelled: 'The device request was cancelled or timed out. Nothing was approved.',
    noAnswer: 'The site did not answer, so nothing changed. Try again.',
    failed: 'This request could not be completed. Reload the page and try again.',
    // What a dropped connection means depends on how far the call got. Before the passkey response is sent, nothing
    // can have been approved; after it, only the site knows, and this page does not guess.
    unconfirmedApproval: 'The connection dropped before the site answered, so we can’t confirm whether your approval was saved. Reload this page to see where the request stands.',
    unconfirmedRegistration: 'The connection dropped before the site answered, so we can’t confirm whether your passkey was registered. Reload this page to check.',
    unconfirmedConfirm: 'The connection dropped before the site answered, so your confirmation may not have been saved. Reload this page and try again.',
    requestCancelled: 'Request cancelled. Nothing was executed.',
    cancelUnconfirmed: 'The connection dropped before the site answered, so we can’t confirm whether the request was cancelled. Reload this page to see where it stands.',
    returnApp: 'Claude already shows the result. This tab can be closed.',
    returnHandoff: 'Done. Claude on your computer already shows the result.',
    returnTab: 'Switch back to your Claude tab: it already shows the result.',
};

function messages(element) {
    const text = {...ENGLISH};
    let given = null;
    try {
        given = JSON.parse(element?.dataset.messages ?? 'null');
    } catch {
        // Unreadable wording leaves the English in place.
    }
    for (const key of Object.keys(ENGLISH)) {
        if (typeof given?.[key] === 'string' && given[key] !== '') text[key] = given[key];
    }
    return text;
}

function publicKeyOptions(options, registration) {
    const result = {...options, challenge: decode(options.challenge)};
    if (registration) {
        result.user = {...options.user, id: decode(options.user.id)};
        result.excludeCredentials = (options.excludeCredentials ?? []).map(c => ({...c, id: decode(c.id)}));
    } else {
        result.allowCredentials = (options.allowCredentials ?? []).map(c => ({...c, id: decode(c.id)}));
    }
    return result;
}

function serializeCredential(credential) {
    const response = {clientDataJSON: encode(credential.response.clientDataJSON)};
    for (const key of ['attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
        if (credential.response[key] != null) response[key] = encode(credential.response[key]);
    }
    if (typeof credential.response.getTransports === 'function') response.transports = credential.response.getTransports();
    return {
        id: credential.id,
        rawId: encode(credential.rawId),
        type: credential.type,
        response,
        clientExtensionResults: credential.getClientExtensionResults(),
    };
}

/**
 * The site refused, or its answer never arrived. `uncertain` means the call may still have taken effect: the
 * connection dropped, or the site itself could not confirm the outcome.
 */
class Refusal extends Error {
    constructor(message, {uncertain = false, requestStatus = null} = {}) {
        super(message);
        this.uncertain = uncertain;
        this.requestStatus = requestStatus;
    }
}

async function post(url, csrf, body = {}, failed = ENGLISH.failed) {
    let response, value;
    try {
        response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(body),
        });
        value = await response.json();
    } catch {
        // A dropped connection, or an answer that is not the site's JSON: the call may have reached the site.
        throw new Refusal(null, {uncertain: true});
    }
    if (!response.ok) {
        // Only the site's own refusal is definite. Any other error, such as a gateway's or a server error, means
        // the site's answer was lost on the way: the call may still have taken effect.
        const refused = response.status < 500 && ['rejected', 'rate_limited'].includes(value?.status) && value.reason !== 'execution_uncertain';
        throw new Refusal(value?.message ?? failed, {
            uncertain: !refused,
            requestStatus: value?.request_status ?? null,
        });
    }
    return value;
}

for (const panel of document.querySelectorAll('[data-passkey-panel]')) {
    const button = panel.querySelector('[data-approve]');
    const message = panel.querySelector('[data-message]');
    const mode = panel.dataset.mode;
    const text = messages(panel);
    const unconfirmed = {approval: text.unconfirmedApproval, registration: text.unconfirmedRegistration, confirm: text.unconfirmedConfirm};
    // A request that finished or stopped is shown as the site renders it, with no button that can no longer work.
    // The outcome is at the top of the page, so the reloaded page starts there rather than where the button was.
    const showFinalState = () => {
        history.scrollRestoration = 'manual';
        window.location.reload();
    };
    button?.addEventListener('click', async () => {
        button.disabled = true;
        message.textContent = text.waiting;
        let sent = false;
        try {
            if (!window.PublicKeyCredential || !window.isSecureContext) throw new Error(text.unsupported);
            const registration = mode === 'registration';
            const ceremony = await post(panel.dataset.optionsUrl, panel.dataset.csrf, {}, text.failed);
            const options = {publicKey: publicKeyOptions(ceremony.publicKey, registration)};
            const credential = registration ? await navigator.credentials.create(options) : await navigator.credentials.get(options);
            if (!credential) throw new Error(text.approvalCancelled);
            message.textContent = text.sending;
            sent = true;
            const result = await post(panel.dataset.completeUrl, panel.dataset.csrf, {
                ceremony_id: ceremony.ceremony_id,
                credential: serializeCredential(credential),
            }, text.failed);
            message.textContent = result.message ?? (registration ? text.registered : text.loading);
            return showFinalState();
        } catch (error) {
            if (error.requestStatus && error.requestStatus !== 'pending') return showFinalState();
            message.textContent = error.name === 'NotAllowedError'
                ? text.deviceCancelled
                : error.uncertain
                    ? (sent ? (Object.hasOwn(unconfirmed, mode) ? unconfirmed[mode] : unconfirmed.approval) : text.noAnswer)
                    : error.message;
        }
        button.disabled = false;
    });
    panel.querySelector('[data-cancel]')?.addEventListener('click', async event => {
        event.target.disabled = true;
        try {
            const result = await post(panel.dataset.cancelUrl, panel.dataset.csrf, {}, text.failed);
            // The request may have finished first, on another device: then the reloaded page says so.
            message.textContent = result.status === 'cancelled' ? text.requestCancelled : text.loading;
            return showFinalState();
        } catch (error) {
            if (error.requestStatus && error.requestStatus !== 'pending') return showFinalState();
            message.textContent = error.uncertain ? text.cancelUnconfirmed : error.message;
            event.target.disabled = false;
        }
    });
}

// Times in the reader's own time zone. The page renders them in the server's, which stays if this cannot run.
const timeFormats = {
    time: {hour: '2-digit', minute: '2-digit', timeZoneName: 'short'},
    datetime: {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short'},
};
for (const time of document.querySelectorAll('time[data-local-time]')) {
    const when = new Date(time.dateTime);
    const format = timeFormats[time.dataset.localTime];
    if (!format || Number.isNaN(when.getTime())) continue;
    try {
        time.textContent = new Intl.DateTimeFormat(document.documentElement.lang || undefined, format).format(when);
    } catch {
        // An unknown page language leaves the server's rendering in place.
    }
}

// After approval, return the person to where the request came from. The card in Claude adds
// #return=claude-desktop, claude-mobile or claude-web to the review link; without it, the page just says so.
const back = new URLSearchParams(location.hash.slice(1)).get('return');
const returnPanel = document.querySelector('[data-return]');
if (returnPanel && back) {
    const text = messages(returnPanel);
    const app = returnPanel.querySelector('[data-return-app]');
    const tab = returnPanel.querySelector('[data-return-tab]');
    const hint = document.querySelector('[data-return-hint]');
    if (back === 'claude-desktop' || back === 'claude-mobile') {
        returnPanel.hidden = false; app.hidden = false;
        if (hint) hint.textContent = text.returnApp;
    } else if (back === 'handoff') {
        if (hint) hint.textContent = text.returnHandoff;
    } else if (back === 'claude-web') {
        returnPanel.hidden = false; tab.hidden = false;
        tab.addEventListener('click', () => {
            window.close();
            setTimeout(() => { if (hint) hint.textContent = text.returnTab; }, 300);
        });
    }
}
