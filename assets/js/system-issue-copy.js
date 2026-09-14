(() => {
  'use strict';

  const BUTTON_SELECTOR = '[data-copy-brief]';
  const DEFAULT_LABEL = 'Copy Codex Brief';
  let requestPending = false;

  const notify = (message, type = 'success') => {
    if (typeof window.showPortalToast === 'function') {
      window.showPortalToast({ title: 'System Issues Log', message, type });
      return;
    }
    const page = document.getElementById('system-issues-page');
    if (!page) return;
    let node = page.querySelector('[data-sil-live-status]');
    if (!node) {
      node = document.createElement('div');
      node.dataset.silLiveStatus = 'true';
      node.className = 'system-issues-alert';
      node.setAttribute('role', 'status');
      node.setAttribute('aria-live', 'polite');
      page.prepend(node);
    }
    node.classList.toggle('is-error', type === 'error');
    node.textContent = message;
  };

  const setButtonState = (button, state, icon = 'copy') => {
    const label = button.querySelector('.sil-copy-label');
    if (label) label.textContent = state;
    button.classList.toggle('is-copied', state === 'Copied');
    const iconNode = button.querySelector('[data-lucide]');
    if (iconNode) iconNode.setAttribute('data-lucide', icon);
    if (window.lucide) window.lucide.createIcons();
  };

  const legacyCopy = (text) => {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.left = '-9999px';
    area.style.top = '0';
    document.body.appendChild(area);
    area.focus();
    area.select();
    area.setSelectionRange(0, area.value.length);
    let copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (_) {
      copied = false;
    }
    area.remove();
    return copied;
  };

  const showManualCopy = (text) => {
    document.querySelector('[data-manual-brief-copy]')?.remove();
    const dialog = document.createElement('dialog');
    dialog.className = 'system-issue-dialog sil-manual-copy-dialog';
    dialog.dataset.manualBriefCopy = 'true';
    dialog.innerHTML = '<form method="dialog"><header><div><span>Clipboard unavailable</span><h2>Copy Codex Brief manually</h2><p>Select and copy the complete brief below.</p></div><button type="submit" aria-label="Close">&times;</button></header><label><span>Codex brief</span><textarea readonly data-manual-brief-text></textarea></label><footer><button type="button" class="button" data-select-manual-brief>Select all</button><button type="submit" class="button primary">Close</button></footer></form>';
    document.body.appendChild(dialog);
    const area = dialog.querySelector('[data-manual-brief-text]');
    area.value = text;
    dialog.querySelector('[data-select-manual-brief]').addEventListener('click', () => {
      area.focus();
      area.select();
      area.setSelectionRange(0, area.value.length);
    });
    dialog.addEventListener('close', () => dialog.remove(), { once: true });
    dialog.showModal();
    area.focus();
    area.select();
    area.setSelectionRange(0, area.value.length);
  };

  const writeBrief = async (text) => {
    if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (_) {
        // Browser permission and iOS gesture restrictions are handled by the legacy path.
      }
    }
    return legacyCopy(text);
  };

  const parseResponse = async (response) => {
    const raw = await response.text();
    try {
      return JSON.parse(raw);
    } catch (_) {
      throw new Error('The server returned an invalid copy response.');
    }
  };

  const handleCopy = async (button) => {
    if (requestPending || button.disabled) return;
    requestPending = true;
    button.disabled = true;
    setButtonState(button, 'Copying...');
    try {
      const body = new URLSearchParams({
        csrf: button.dataset.csrf || '',
        issue_id: button.dataset.issueId || '',
        brief_version: button.dataset.briefVersion || ''
      });
      const copyUrl = new URL(button.dataset.copyUrl || '', window.location.href);
      const expectedVersion = copyUrl.searchParams.get('expected_version');
      if (expectedVersion) body.set('expected_version', expectedVersion);
      const response = await fetch(copyUrl.toString(), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString(),
        credentials: 'same-origin'
      });
      const data = await parseResponse(response);
      if (!response.ok || !data.ok || typeof data.brief !== 'string') {
        throw new Error(typeof data.error === 'string' && data.error ? data.error : 'The brief could not be prepared for copying.');
      }
      const copied = await writeBrief(data.brief);
      if (!copied) {
        showManualCopy(data.brief);
        setButtonState(button, 'Copy failed', 'alert-circle');
        notify('Automatic copy was blocked. The brief is selected for manual copying.', 'error');
        return;
      }
      setButtonState(button, 'Copied', 'check');
      notify('Codex brief copied.');
      document.querySelector('[data-system-issue-workflow]')?._refreshWorkflow?.();
    } catch (error) {
      setButtonState(button, 'Copy failed', 'alert-circle');
      notify(error instanceof Error && error.message ? error.message : 'Unable to copy the brief. Please try again.', 'error');
    } finally {
      requestPending = false;
      button.disabled = false;
      window.setTimeout(() => setButtonState(button, DEFAULT_LABEL, 'copy'), 2200);
    }
  };

  document.addEventListener('click', (event) => {
    const button = event.target.closest(BUTTON_SELECTOR);
    if (!button) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    handleCopy(button);
  }, true);

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(BUTTON_SELECTOR).forEach((button) => {
      setButtonState(button, DEFAULT_LABEL, 'copy');
      button.setAttribute('aria-label', DEFAULT_LABEL);
      button.setAttribute('title', DEFAULT_LABEL);
    });
  });
})();
