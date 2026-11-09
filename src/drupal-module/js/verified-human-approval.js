/**
 * Drupal browser ceremonies. The server determines what can be approved.
 */
(function (Drupal, once) {
  'use strict';

  function decode(value) {
    return Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), function (character) {
      return character.charCodeAt(0);
    });
  }

  function encode(value) {
    let binary = '';
    new Uint8Array(value).forEach(function (byte) {
      binary += String.fromCharCode(byte);
    });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function optionsFromJson(options, registration) {
    const result = Object.assign({}, options, {challenge: decode(options.challenge)});
    if (registration) {
      result.user = Object.assign({}, options.user, {id: decode(options.user.id)});
      result.excludeCredentials = (options.excludeCredentials || []).map(function (credential) {
        return Object.assign({}, credential, {id: decode(credential.id)});
      });
    }
    else {
      result.allowCredentials = (options.allowCredentials || []).map(function (credential) {
        return Object.assign({}, credential, {id: decode(credential.id)});
      });
    }
    return result;
  }

  function serializeCredential(credential) {
    const response = {clientDataJSON: encode(credential.response.clientDataJSON)};
    ['attestationObject', 'authenticatorData', 'signature', 'userHandle'].forEach(function (key) {
      if (credential.response[key] !== undefined && credential.response[key] !== null) {
        response[key] = encode(credential.response[key]);
      }
    });
    if (typeof credential.response.getTransports === 'function') {
      response.transports = credential.response.getTransports();
    }
    return {
      id: credential.id,
      rawId: encode(credential.rawId),
      type: credential.type,
      response: response,
      clientExtensionResults: credential.getClientExtensionResults(),
    };
  }

  class Refusal extends Error {
    constructor(message, uncertain, requestStatus) {
      super(message);
      this.uncertain = Boolean(uncertain);
      this.requestStatus = requestStatus || null;
    }
  }

  async function post(url, csrf, body) {
    const controller = new AbortController();
    const timer = setTimeout(function () { controller.abort(); }, 30000);
    let response;
    let data;
    try {
      response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });
      data = await response.json();
      if (!data || typeof data !== 'object' || Array.isArray(data)) {
        throw new Error('Invalid response');
      }
    }
    catch (error) {
      // A dropped or unreadable answer does not establish whether a write ran.
      throw new Refusal('', true);
    }
    finally {
      clearTimeout(timer);
    }
    if (!response.ok) {
      throw new Refusal(
        data.message || Drupal.t('The request was refused. Reload the page and check that you are signed in.'),
        data.reason === 'execution_uncertain',
        data.request_status,
      );
    }
    return data;
  }

  Drupal.behaviors.verifiedHumanApproval = {
    attach: function (context) {
      once('verified-human-approval', '[data-vha-ceremony]', context).forEach(function (panel) {
        const start = panel.querySelector('[data-vha-start]');
        const cancel = panel.querySelector('[data-vha-cancel]');
        const message = panel.querySelector('[data-vha-message]');
        const reload = panel.querySelector('[data-vha-reload]');
        const registration = panel.dataset.mode === 'registration';
        let busy = false;

        function setBusy(value) {
          busy = value;
          start.disabled = value;
          if (cancel) {
            cancel.disabled = value;
          }
          panel.setAttribute('aria-busy', value ? 'true' : 'false');
        }

        function showRecordedState() {
          window.location.assign(panel.dataset.successUrl);
        }

        start.addEventListener('click', async function () {
          if (busy) {
            return;
          }
          const label = panel.querySelector('[data-vha-label]');
          if (registration && (!label.value.trim() || !label.reportValidity())) {
            label.focus();
            return;
          }
          setBusy(true);
          reload.hidden = true;
          message.textContent = Drupal.t('Preparing the request for your device…');
          let sent = false;
          let checkBeforeRetry = false;
          try {
            if (!window.PublicKeyCredential || !window.isSecureContext || !navigator.credentials) {
              throw new Error(Drupal.t('Passkeys need a supported browser on HTTPS, or localhost for development.'));
            }
            const body = registration ? {grant: panel.dataset.grant, label: label.value.trim()} : {};
            const ceremony = await post(panel.dataset.optionsUrl, panel.dataset.csrf, body);
            if (typeof ceremony.ceremony_id !== 'string' || !ceremony.publicKey) {
              throw new Error(Drupal.t('The site did not return usable passkey options. Reload the page and try again.'));
            }
            message.textContent = Drupal.t('Follow the prompt on your device…');
            const options = {publicKey: optionsFromJson(ceremony.publicKey, registration)};
            const credential = registration
              ? await navigator.credentials.create(options)
              : await navigator.credentials.get(options);
            if (!credential) {
              throw new Error(Drupal.t('No passkey response was sent from this page.'));
            }
            const serialized = serializeCredential(credential);
            message.textContent = Drupal.t('Sending your passkey response to the site…');
            sent = true;
            const result = await post(panel.dataset.completeUrl, panel.dataset.csrf, {
              ceremony_id: ceremony.ceremony_id,
              credential: serialized,
            });
            message.textContent = result.message || Drupal.t('Loading the recorded result…');
            showRecordedState();
          }
          catch (error) {
            if (error.requestStatus && error.requestStatus !== 'pending') {
              showRecordedState();
              return;
            }
            checkBeforeRetry = sent && error.uncertain;
            if (error.name === 'NotAllowedError') {
              message.textContent = Drupal.t('The device prompt was cancelled or timed out. No passkey response was sent from this page.');
            }
            else if (error.uncertain) {
              message.textContent = sent
                ? (registration
                  ? Drupal.t('The connection ended before the site answered, so we cannot confirm whether your passkey was registered. Check your passkey list before trying again.')
                  : Drupal.t('The connection ended before the site answered, so we cannot confirm whether the request completed. Reload it to check the recorded status.'))
                : Drupal.t('The site did not answer. No passkey response was sent from this page. Reload the page and try again.');
            }
            else {
              message.textContent = error.message;
            }
            reload.hidden = false;
          }
          finally {
            // After an uncertain write, require a recorded-state check first.
            setBusy(checkBeforeRetry);
            if (checkBeforeRetry) {
              panel.setAttribute('aria-busy', 'false');
            }
          }
        });

        if (cancel) {
          cancel.addEventListener('click', async function () {
            if (busy) {
              return;
            }
            setBusy(true);
            reload.hidden = true;
            let checkBeforeRetry = false;
            message.textContent = Drupal.t('Asking the site to cancel this request…');
            try {
              await post(panel.dataset.cancelUrl, panel.dataset.csrf, {});
              // A concurrent approval may have completed first. Read the record.
              showRecordedState();
            }
            catch (error) {
              if (error.requestStatus && error.requestStatus !== 'pending') {
                showRecordedState();
                return;
              }
              checkBeforeRetry = error.uncertain;
              message.textContent = error.uncertain
                ? Drupal.t('The connection ended before the site answered, so we cannot confirm whether the request was cancelled. Reload it to check the recorded status.')
                : error.message;
              reload.hidden = false;
            }
            finally {
              setBusy(checkBeforeRetry);
              if (checkBeforeRetry) {
                panel.setAttribute('aria-busy', 'false');
              }
            }
          });
        }
      });
    },
  };
})(Drupal, once);
