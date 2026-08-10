(() => {
  'use strict';

  const form = document.querySelector('[data-owner-recommendation-form]');
  if (!form) return;

  const textarea = form.querySelector('textarea[name="owner_recommendation"]');
  const buttons = [...form.querySelectorAll('button[type="submit"]')];
  const savedState = form.querySelector('[data-recommendation-saved]');
  const briefSection = document.querySelector('.sil-ai-brief');
  const endpoint = form.dataset.recommendationUrl || '';
  const labels = new Map(buttons.map((button) => [button, button.textContent]));

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[character]);
  const notify = (message, type = 'success') => {
    if (typeof window.showPortalToast === 'function') {
      window.showPortalToast({ title: 'System Issues Log', message, type });
      return;
    }
    let node = document.querySelector('[data-sil-live-status]');
    if (!node) {
      node = document.createElement('div');
      node.dataset.silLiveStatus = 'true';
      node.className = 'system-issues-alert';
      node.setAttribute('role', 'status');
      node.setAttribute('aria-live', 'polite');
      document.getElementById('system-issues-page')?.prepend(node);
    }
    node.classList.toggle('is-error', type === 'error');
    node.textContent = message;
  };
  const setBusy = (active, submitter = null) => {
    buttons.forEach((button) => {
      button.disabled = active;
      button.textContent = active && button === submitter
        ? (button.value === 'update_ai_brief' ? 'Updating Brief…' : 'Saving…')
        : labels.get(button);
    });
    form.setAttribute('aria-busy', String(active));
  };
  const displayDate = (value) => {
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString([], {
      day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
  };
  const renderSavedState = (data) => {
    if (!savedState) return;
    savedState.hidden = false;
    savedState.innerHTML = `<strong>Saved owner recommendation</strong><small>Last updated: ${escapeHtml(displayDate(data.updated_at || data.saved_at))} · Saved by: ${escapeHtml(data.saved_by || 'Owner')}</small>`;
    textarea.value = data.recommendation || textarea.value;
  };
  const valueHtml = (value) => {
    const text = Array.isArray(value) ? value.join('\n') : String(value ?? '—');
    return escapeHtml(text).replace(/\n/g, '<br>');
  };
  const renderBrief = (data) => {
    if (!briefSection || !data.brief) return;
    const fields = [
      ['summary', 'Summary'],
      ['owner_recommendations', 'Owner Recommendations & Business Context'],
      ['observed_behaviour', 'Observed behaviour'],
      ['expected_behaviour', 'Expected behaviour'],
      ['steps_to_reproduce', 'Steps to reproduce'],
      ['affected_module', 'Affected module'],
      ['likely_root_cause', 'Likely root cause'],
      ['scope_of_fix', 'Scope of fix'],
      ['do_not_change', 'Do not change'],
      ['acceptance_criteria', 'Acceptance criteria'],
      ['required_tests', 'Required tests'],
      ['deployment_and_live_verification_requirements', 'Deployment and live verification requirements'],
      ['missing_information', 'Missing information or uncertainties']
    ];
    const header = briefSection.querySelector('.sil-brief-header');
    const selector = briefSection.querySelector('.sil-brief-selector');
    briefSection.querySelectorAll(':scope > div:not(.sil-brief-header)').forEach((node) => node.remove());
    fields.forEach(([key, label]) => {
      const row = document.createElement('div');
      row.innerHTML = `<strong>${escapeHtml(label)}</strong><p>${valueHtml(data.brief[key])}</p>`;
      briefSection.appendChild(row);
    });
    const version = header?.querySelector('.sil-brief-version');
    if (version) version.textContent = `Version ${data.brief_version} · Current · ${String(data.risk_level || '').toUpperCase()} risk`;
    const copyButton = header?.querySelector('[data-copy-brief]');
    if (copyButton) copyButton.dataset.briefVersion = String(data.brief_version);
    if (selector) {
      const select = selector.querySelector('select[name="brief_version"]');
      if (select && ![...select.options].some((option) => option.value === String(data.brief_version))) {
        select.add(new Option(`Version ${data.brief_version} · Current`, String(data.brief_version), true, true));
      }
      selector.hidden = false;
    }
  };

  briefSection?.querySelectorAll(':scope > div > strong').forEach((label) => {
    if (label.textContent.trim() === 'Owner recommendations') {
      label.textContent = 'Owner Recommendations & Business Context';
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!endpoint || form.getAttribute('aria-busy') === 'true') return;
    const submitter = event.submitter || document.activeElement;
    const action = submitter?.value || '';
    if (!['save_owner_recommendation', 'update_ai_brief'].includes(action)) return;
    const body = new FormData(form);
    body.set('action', action);
    setBusy(true, submitter);
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const contentType = String(response.headers.get('content-type') || '').toLowerCase();
      if (!contentType.includes('application/json')) throw new Error('The server returned an invalid response.');
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'The request could not be completed.');
      if (action === 'save_owner_recommendation') renderSavedState(data);
      if (action === 'update_ai_brief') renderBrief(data);
      notify(data.message || (action === 'update_ai_brief' ? 'AI Brief updated.' : 'Recommendation saved.'));
    } catch (error) {
      notify(error.message || 'The request could not be completed.', 'error');
    } finally {
      setBusy(false);
    }
  });
})();
