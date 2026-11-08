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

async function post(url, csrf, body = {}) {
    const response = await fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify(body),
    });
    const value = await response.json();
    if (!response.ok) throw new Error(value.message ?? 'This request could not be completed. Reload the page and try again.');
    return value;
}

for (const panel of document.querySelectorAll('[data-passkey-panel]')) {
    const button = panel.querySelector('[data-approve]');
    const message = panel.querySelector('[data-message]');
    button?.addEventListener('click', async () => {
        button.disabled = true;
        message.textContent = 'Waiting for your device…';
        try {
            if (!window.PublicKeyCredential || !window.isSecureContext) throw new Error('Passkeys need a supported browser on HTTPS (or localhost).');
            const registration = panel.dataset.mode === 'registration';
            const ceremony = await post(panel.dataset.optionsUrl, panel.dataset.csrf);
            const options = {publicKey: publicKeyOptions(ceremony.publicKey, registration)};
            const credential = registration ? await navigator.credentials.create(options) : await navigator.credentials.get(options);
            if (!credential) throw new Error('Approval cancelled. Nothing was executed.');
            const result = await post(panel.dataset.completeUrl, panel.dataset.csrf, {
                ceremony_id: ceremony.ceremony_id,
                credential: serializeCredential(credential),
            });
            message.textContent = result.message ?? (registration ? 'Passkey registered.' : `Action status: ${result.status}.`);
            if (result.status === 'completed' || result.status === 'registered') window.location.reload();
        } catch (error) {
            message.textContent = error.name === 'NotAllowedError'
                ? 'The device request was cancelled or timed out. Nothing was approved.'
                : error.message;
        } finally {
            button.disabled = false;
        }
    });
    panel.querySelector('[data-cancel]')?.addEventListener('click', async event => {
        event.target.disabled = true;
        try {
            const result = await post(panel.dataset.cancelUrl, panel.dataset.csrf);
            message.textContent = result.message ?? 'Request cancelled. Nothing was executed.';
            window.location.reload();
        } catch (error) {
            message.textContent = error.message;
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
