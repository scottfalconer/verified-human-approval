/**
 * Native WebAuthn ceremonies, shared with the exact-approval PHP integration.
 * Drupal's session, CSRF check and the server-side verifier authorize the work.
 */
(function (Drupal, once) {
  'use strict';

  const decode = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
  const encode = (value) => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

  function publicKeyOptions(options, registration) {
    const result = { ...options, challenge: decode(options.challenge) };
    if (registration) {
      result.user = { ...options.user, id: decode(options.user.id) };
      result.excludeCredentials = (options.excludeCredentials || []).map((c) => ({ ...c, id: decode(c.id) }));
    }
    else {
      result.allowCredentials = (options.allowCredentials || []).map((c) => ({ ...c, id: decode(c.id) }));
    }
    return result;
  }

  function serializeCredential(credential) {
    const response = { clientDataJSON: encode(credential.response.clientDataJSON) };
    ['attestationObject', 'authenticatorData', 'signature', 'userHandle'].forEach((key) => {
      if (credential.response[key] != null) response[key] = encode(credential.response[key]);
    });
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
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    });
    let value;
    try {
      value = await response.json();
    }
    catch (error) {
      throw new Error(Drupal.t('The application did not return a readable status. Reload this page to check the result.'));
    }
    if (!response.ok) throw new Error(value.message || Drupal.t('This request could not be completed. Reload this page to check its status.'));
    return value;
  }

  Drupal.behaviors.exactApprovalPasskey = {
    attach(context) {
      once('exact-approval-passkey', '[data-passkey-panel]', context).forEach((panel) => {
        const approve = panel.querySelector('[data-approve]');
        const cancel = panel.querySelector('[data-cancel]');
        const reviewed = panel.querySelector('[data-reviewed]');
        const message = panel.querySelector('[data-message]');
        let busy = false;
        const updateButtons = () => {
          if (approve) approve.disabled = busy || (reviewed && !reviewed.checked);
          if (cancel) cancel.disabled = busy;
        };
        if (reviewed) reviewed.addEventListener('change', updateButtons);
        updateButtons();

        if (approve) approve.addEventListener('click', async () => {
          if (busy || (reviewed && !reviewed.checked)) return;
          busy = true;
          updateButtons();
          let submitting = false;
          const registration = panel.dataset.mode === 'registration';
          message.textContent = Drupal.t('Waiting for your device…');
          try {
            if (!window.PublicKeyCredential || !window.isSecureContext) {
              throw new Error(Drupal.t('Passkeys need a supported browser on HTTPS, or localhost for development.'));
            }
            const ceremony = await post(panel.dataset.optionsUrl, panel.dataset.csrf);
            const options = { publicKey: publicKeyOptions(ceremony.publicKey, registration) };
            const credential = registration
              ? await navigator.credentials.create(options)
              : await navigator.credentials.get(options);
            if (!credential) throw new Error(Drupal.t('The device request was cancelled.'));
            submitting = true;
            const result = await post(panel.dataset.completeUrl, panel.dataset.csrf, {
              ceremony_id: ceremony.ceremony_id,
              credential: serializeCredential(credential),
            });
            message.textContent = result.message || Drupal.t('Request status: @status.', { '@status': result.status });
            // Reload the persisted state rather than rendering an optimistic success.
            if (result.status === 'completed' || result.status === 'registered') window.location.reload();
          }
          catch (error) {
            if (submitting && error instanceof TypeError) {
              message.textContent = Drupal.t('The connection ended before the result was confirmed. Reload this page to check the result before trying again.');
            }
            else {
              message.textContent = error.name === 'NotAllowedError'
                ? Drupal.t('The device request was cancelled or timed out. No approval was submitted.')
                : error.message;
            }
          }
          finally {
            busy = false;
            updateButtons();
          }
        });

        if (cancel) cancel.addEventListener('click', async () => {
          if (busy) return;
          busy = true;
          updateButtons();
          try {
            await post(panel.dataset.cancelUrl, panel.dataset.csrf);
            window.location.reload();
          }
          catch (error) {
            message.textContent = error.message;
            busy = false;
            updateButtons();
          }
        });
      });
    },
  };
})(Drupal, once);
