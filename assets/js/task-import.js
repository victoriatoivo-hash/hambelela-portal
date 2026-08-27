(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) {
    root.TaskImportParser = api;
    if (root.document) api.initialise();
  }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  const instructionHeadings = new Set(['what to do', 'what you need to do', 'instructions', 'steps', 'task instructions']);
  const checklistHeadings = new Set(['quick check before completing', 'checklist', 'before completing', 'final check', 'completion checklist', 'check before completing']);

  const stripMarkdown = (value) => String(value || '')
    .trim()
    .replace(/^#{1,6}\s+/, '')
    .replace(/^(?:\*\*|__)(.*)(?:\*\*|__)$/, '$1')
    // ChatGPT frequently mixes inline emphasis with otherwise plain text.
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/__(.+?)__/g, '$1')
    .replace(/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/g, '$1')
    .replace(/(?<!_)_(?!\s)(.+?)(?<!\s)_(?!_)/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .trim();

  const headingKey = (value) => stripMarkdown(value)
    .replace(/:$/, '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, ' ');

  const checkboxText = (value) => {
    const match = String(value || '').match(/^\s*(?:[-*+]\s*)?(?:\[\s*(?:[xX✓])?\s*\]|[☐□☑✓])\s*(.+?)\s*$/u);
    return match ? stripMarkdown(match[1]) : '';
  };

  const normaliseLines = (value) => String(value || '')
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((line) => line.replace(/[\t ]+$/g, ''));

  function parse(value) {
    const lines = normaliseLines(value);
    const meaningful = lines.map((line, index) => ({ line, index })).filter(({ line }) => line.trim() !== '');
    if (!meaningful.length) return { title: '', instructionLines: [], checklist: [], notices: [] };

    let title = '';
    const titleIndexes = new Set();
    for (let position = 0; position < meaningful.length; position += 1) {
      const current = meaningful[position];
      const explicit = stripMarkdown(current.line).match(/^task\s*title\s*:?[\t ]*(.*)$/i);
      if (!explicit) continue;
      titleIndexes.add(current.index);
      if (explicit[1].trim()) title = stripMarkdown(explicit[1]);
      else if (meaningful[position + 1]) {
        title = stripMarkdown(meaningful[position + 1].line);
        titleIndexes.add(meaningful[position + 1].index);
      }
      break;
    }

    if (!title) {
      const first = meaningful[0];
      const key = headingKey(first.line);
      const markdownHeading = /^#{1,6}\s+/.test(first.line.trim());
      const boldStandalone = /^(?:\*\*|__).+(?:\*\*|__)$/.test(first.line.trim());
      const genericSection = instructionHeadings.has(key) || checklistHeadings.has(key);
      const listLine = /^\s*(?:\d+[.)]|[-*+])\s+/.test(first.line) || checkboxText(first.line) !== '';
      const confidentPlainTitle = !genericSection && !listLine && stripMarkdown(first.line).length <= 120 && !/[.!?]$/.test(stripMarkdown(first.line));
      if (!genericSection && !listLine && (markdownHeading || boldStandalone || confidentPlainTitle)) {
        title = stripMarkdown(first.line);
        titleIndexes.add(first.index);
      }
    }

    const checklist = [];
    const instructionLines = [];
    let inChecklist = false;
    lines.forEach((rawLine, index) => {
      if (titleIndexes.has(index)) return;
      const key = headingKey(rawLine);
      if (checklistHeadings.has(key)) { inChecklist = true; return; }
      const explicitCheckbox = checkboxText(rawLine);
      if (explicitCheckbox) { checklist.push(explicitCheckbox); return; }
      if (inChecklist) {
        const bullet = rawLine.match(/^\s*[-*+]\s+(.+?)\s*$/);
        if (bullet) { checklist.push(bullet[1].trim()); return; }
        if (rawLine.trim() === '') return;
      }
      const cleaned = stripMarkdown(rawLine);
      if (instructionHeadings.has(key)) instructionLines.push(cleaned.replace(/:$/, '') + ':');
      else instructionLines.push(rawLine.trim() ? cleaned : '');
    });

    while (instructionLines[0] === '') instructionLines.shift();
    while (instructionLines[instructionLines.length - 1] === '') instructionLines.pop();
    const compactInstructions = instructionLines.filter((line, index) => line !== '' || instructionLines[index - 1] !== '');
    const notices = [];
    if (title.length > 120) {
      title = title.slice(0, 120).trimEnd();
      notices.push('The title was shortened to the 120-character task limit.');
    }
    if (!title) notices.push('A task title was not detected.');
    if (!compactInstructions.some((line) => line.trim())) notices.push('Instructions were not detected.');
    if (!checklist.length) notices.push('Checklist was not detected, so that section was left blank.');
    return { title, instructionLines: compactInstructions, checklist, notices };
  }

  function renderInstructions(editor, lines) {
    const fragment = document.createDocumentFragment();
    let index = 0;
    while (index < lines.length) {
      const line = String(lines[index] || '');
      if (!line) { index += 1; continue; }
      const numbered = line.match(/^\s*\d+[.)]\s+(.+)$/);
      const bullet = line.match(/^\s*[-*+]\s+(.+)$/);
      if (numbered) {
        const list = document.createElement('ol');
        while (index < lines.length) {
          const item = String(lines[index] || '').match(/^\s*\d+[.)]\s+(.+)$/);
          if (!item) break;
          const entry = document.createElement('li'); entry.textContent = item[1].trim(); list.appendChild(entry); index += 1;
        }
        fragment.appendChild(list); continue;
      }
      if (bullet) {
        const list = document.createElement('ul');
        while (index < lines.length) {
          const item = String(lines[index] || '').match(/^\s*[-*+]\s+(.+)$/);
          if (!item) break;
          const entry = document.createElement('li'); entry.textContent = item[1].trim(); list.appendChild(entry); index += 1;
        }
        fragment.appendChild(list); continue;
      }
      const paragraph = document.createElement('p');
      if (instructionHeadings.has(headingKey(line))) {
        const strong = document.createElement('strong'); strong.textContent = stripMarkdown(line); paragraph.appendChild(strong);
      } else paragraph.textContent = line;
      fragment.appendChild(paragraph); index += 1;
    }
    editor.replaceChildren(fragment);
    editor.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function initialise() {
    const form = document.querySelector('[data-task-create-form]');
    const modal = document.querySelector('[data-task-import-modal]');
    const trigger = document.querySelector('[data-task-import-open]');
    if (!form || !modal || !trigger || modal.dataset.initialised === 'true') return;
    modal.dataset.initialised = 'true';
    const textarea = modal.querySelector('[data-task-import-content]');
    const error = modal.querySelector('[data-task-import-error]');
    const confirm = modal.querySelector('[data-task-import-confirm]');
    const load = modal.querySelector('[data-task-import-load]');
    const title = form.querySelector('[name="task_name"]');
    const editor = form.querySelector('[data-task-rich-editor] .task-rich-editor__surface');
    const hiddenInstructions = form.querySelector('[name="instructions"]');
    const checklistList = form.querySelector('[data-task-checklist-list]');
    const checklistInput = form.querySelector('[data-task-checklist-input]');
    const checklistAdd = form.querySelector('[data-task-checklist-add]');
    const createPanel = document.querySelector('[data-task-create-panel]');
    let pending = null;

    const notify = (message, type = 'success') => {
      if (typeof window.showPortalToast === 'function') window.showPortalToast({ title: 'Task Management', message, type });
      else window.dispatchEvent(new CustomEvent('portal:toast', { detail: { title: 'Task Management', message, type } }));
    };
    const setOpen = (open) => {
      modal.hidden = !open; modal.setAttribute('aria-hidden', open ? 'false' : 'true');
      createPanel?.classList.toggle('is-child-modal-open', open);
      if (createPanel && 'inert' in createPanel) createPanel.inert = open;
      document.body.classList.toggle('task-import-open', open);
      if (open) window.setTimeout(() => textarea.focus(), 0); else trigger.focus();
      if (!open) { error.hidden = true; confirm.hidden = true; pending = null; }
    };
    const hasExistingContent = () => title.value.trim() || editor.textContent.trim() || checklistList.querySelector('input');
    const highlight = (element) => { element.classList.remove('is-imported'); void element.offsetWidth; element.classList.add('is-imported'); window.setTimeout(() => element.classList.remove('is-imported'), 900); };
    const apply = (parsed) => {
      title.value = parsed.title;
      renderInstructions(editor, parsed.instructionLines);
      hiddenInstructions.value = editor.innerHTML;
      checklistList.replaceChildren();
      parsed.checklist.forEach((item) => { checklistInput.value = item; checklistAdd.click(); });
      highlight(title); highlight(editor); highlight(checklistList.closest('[data-task-checklist-builder]'));
      textarea.value = ''; setOpen(false);
      const warning = parsed.notices.length ? ` ${parsed.notices.join(' ')}` : '';
      notify(`Task loaded. Review the details before creating it.${warning}`, parsed.notices.length ? 'warning' : 'success');
    };
    const read = () => {
      error.hidden = true;
      if (!textarea.value.trim()) { error.textContent = 'Paste a task before loading.'; error.hidden = false; textarea.focus(); return; }
      load.disabled = true; load.textContent = 'Reading task…';
      pending = parse(textarea.value);
      load.disabled = false; load.textContent = 'Load Task';
      if (hasExistingContent()) { confirm.hidden = false; confirm.querySelector('[data-task-import-keep]').focus(); return; }
      apply(pending);
    };

    trigger.addEventListener('click', () => setOpen(true));
    modal.querySelectorAll('[data-task-import-close]').forEach((button) => button.addEventListener('click', () => setOpen(false)));
    modal.querySelector('[data-task-import-keep]').addEventListener('click', () => { confirm.hidden = true; pending = null; textarea.focus(); });
    modal.querySelector('[data-task-import-replace]').addEventListener('click', () => { if (pending) apply(pending); });
    load.addEventListener('click', read);
    textarea.addEventListener('keydown', (event) => { if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); read(); } });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) { event.preventDefault(); event.stopImmediatePropagation(); setOpen(false); } }, true);
    document.querySelectorAll('[data-task-create-close]').forEach((button) => button.addEventListener('click', () => { if (modal.hidden) textarea.value = ''; }));
  }

  return { parse, headingKey, checkboxText, initialise };
});
