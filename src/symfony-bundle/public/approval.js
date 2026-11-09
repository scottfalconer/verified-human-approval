/** Native WebAuthn ceremonies. The server, not this script, decides what may execute. */
const decode = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

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

async function post(url, csrf, body = {}) {
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
        throw new Refusal(value.message ?? 'This request could not be completed. Reload the page and try again.', {
            uncertain: value.reason === 'execution_uncertain',
            requestStatus: value.request_status ?? null,
        });
    }
    return value;
}

// What a dropped connection means depends on how far the call got. Before the passkey response is sent, nothing
// can have been approved; after it, only the site knows, and this page does not guess.
const UNCONFIRMED = {
    approval: 'The connection dropped before the site answered, so we can’t confirm whether your approval was saved. Reload this page to see where the request stands.',
    registration: 'The connection dropped before the site answered, so we can’t confirm whether your passkey was registered. Reload this page to check.',
    confirm: 'The connection dropped before the site answered, so your confirmation may not have been saved. Reload this page and try again.',
};

for (const panel of document.querySelectorAll('[data-passkey-panel]')) {
    const button = panel.querySelector('[data-approve]');
    const message = panel.querySelector('[data-message]');
    const mode = panel.dataset.mode;
    // A request that finished or stopped is shown as the site renders it, with no button that can no longer work.
    // The outcome is at the top of the page, so the reloaded page starts there rather than where the button was.
    const showFinalState = () => {
        history.scrollRestoration = 'manual';
        window.location.reload();
    };
    button?.addEventListener('click', async () => {
        button.disabled = true;
        message.textContent = 'Waiting for your device…';
        let sent = false;
        try {
            if (!window.PublicKeyCredential || !window.isSecureContext) throw new Error('Passkeys need a supported browser on HTTPS (or localhost).');
            const registration = mode === 'registration';
            const ceremony = await post(panel.dataset.optionsUrl, panel.dataset.csrf);
            const options = {publicKey: publicKeyOptions(ceremony.publicKey, registration)};
            const credential = registration ? await navigator.credentials.create(options) : await navigator.credentials.get(options);
            if (!credential) throw new Error('Approval cancelled. Nothing was executed.');
            message.textContent = 'Sending your passkey response to the site…';
            sent = true;
            const result = await post(panel.dataset.completeUrl, panel.dataset.csrf, {
                ceremony_id: ceremony.ceremony_id,
                credential: serializeCredential(credential),
            });
            message.textContent = result.message ?? (registration ? 'Passkey registered.' : 'Loading the result…');
            return showFinalState();
        } catch (error) {
            if (error.requestStatus && error.requestStatus !== 'pending') return showFinalState();
            message.textContent = error.name === 'NotAllowedError'
                ? 'The device request was cancelled or timed out. Nothing was approved.'
                : error.uncertain
                    ? (sent ? UNCONFIRMED[mode] ?? UNCONFIRMED.approval : 'The site did not answer, so nothing changed. Try again.')
                    : error.message;
        }
        button.disabled = false;
    });
    panel.querySelector('[data-cancel]')?.addEventListener('click', async event => {
        event.target.disabled = true;
        try {
            const result = await post(panel.dataset.cancelUrl, panel.dataset.csrf);
            // The request may have finished first, on another device: then the reloaded page says so.
            message.textContent = result.status === 'cancelled' ? 'Request cancelled. Nothing was executed.' : 'Loading the result…';
            return showFinalState();
        } catch (error) {
            if (error.requestStatus && error.requestStatus !== 'pending') return showFinalState();
            message.textContent = error.uncertain
                ? 'The connection dropped before the site answered, so we can’t confirm whether the request was cancelled. Reload this page to see where it stands.'
                : error.message;
            event.target.disabled = false;
        }
    });
}

// After approval, return the person to where the request came from. The card in Claude adds
// #return=claude-desktop, claude-mobile or claude-web to the review link; without it, the page just says so.
const back = new URLSearchParams(location.hash.slice(1)).get('return');
const returnPanel = document.querySelector('[data-return]');
if (returnPanel && back) {
    const app = returnPanel.querySelector('[data-return-app]');
    const tab = returnPanel.querySelector('[data-return-tab]');
    const hint = document.querySelector('[data-return-hint]');
    if (back === 'claude-desktop' || back === 'claude-mobile') {
        returnPanel.hidden = false; app.hidden = false;
        if (hint) hint.textContent = 'Claude already shows the result. This tab can be closed.';
    } else if (back === 'handoff') {
        if (hint) hint.textContent = 'Done. Claude on your computer already shows the result.';
    } else if (back === 'claude-web') {
        returnPanel.hidden = false; tab.hidden = false;
        tab.addEventListener('click', () => {
            window.close();
            setTimeout(() => { if (hint) hint.textContent = 'Switch back to your Claude tab: it already shows the result.'; }, 300);
        });
    }
}
