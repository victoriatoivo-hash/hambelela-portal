(() => {
  'use strict';
  function initialise(panel) {
    if (panel.dataset.detailReady) return;
    panel.dataset.detailReady = 'true';
    const form = panel.querySelector('[data-task-progress-form]');
    const complete = panel.querySelector('[data-save-task]');
    const initialFiles = new Set([...panel.querySelectorAll('[data-task-attachment-id]')].map(el => Number(el.dataset.taskAttachmentId)));
    const eligibleProof = new Set(JSON.parse(form?.dataset.proofIds || '[]'));
    const sync = () => {
      const checks = [...panel.querySelectorAll('.task-detail-checklist__item input')];
      checks.forEach(input => input.closest('label').classList.toggle('is-complete', input.checked));
      const count = panel.querySelector('[data-task-checklist-count]');
      if (count) count.textContent = `${checks.filter(input => input.checked).length} / ${checks.length} complete`;
      if (!form || !complete || panel.dataset.canWork !== '1' || form.dataset.submitting === 'true') return;
      const note = form.querySelector('[data-completion-note]')?.value.trim() || '';
      const proofReady = form.dataset.proofRequired !== '1' || [...panel.querySelectorAll('[data-task-attachment-id]')].some(el => eligibleProof.has(Number(el.dataset.taskAttachmentId)) || !initialFiles.has(Number(el.dataset.taskAttachmentId)));
      complete.disabled = checks.some(input => !input.checked) || note.length < 5 || !proofReady;
    };
    panel.addEventListener('input', sync);
    panel.addEventListener('change', sync);
    const fileList=panel.querySelector('[data-task-file-list]');if(fileList)new MutationObserver(sync).observe(fileList,{childList:true});
    panel.querySelector('[data-task-detail-start]')?.addEventListener('click', () => {
      if (panel.dataset.canStart === '1') promptTaskStart(panel.dataset.taskPanel, panel, true);
    });
    // Read-only checklists and view forms never submit work, even via Enter.
    panel.querySelectorAll('.task-detail-work-form:not([data-task-progress-form])').forEach(form => form.addEventListener('submit', event => event.preventDefault()));
    sync();
  }
  const scan = root => { if (root.matches?.('.task-details-drawer')) initialise(root); root.querySelectorAll?.('.task-details-drawer').forEach(initialise); };
  scan(document);
  new MutationObserver(changes => changes.forEach(change => change.addedNodes.forEach(node => { if (node instanceof Element) scan(node); }))).observe(document.body, {childList:true,subtree:true});
  window.initialiseTaskDetails = scan;
})();
