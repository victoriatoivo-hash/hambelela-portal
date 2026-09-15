const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/packing-list.js'), 'utf8');
function section(start, end) { return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))); }
let resets = 0;
const input = { value: 'old.pdf' }, label = { textContent: 'old.pdf' }, remove = { hidden: false };
const button = { disabled: true, classList: { remove() {}, add() {} } };
const storage = new Map([['draft', 'old rows'], ['theme', 'olive']]);
let finish;
const context = vm.createContext({
  AbortController, console, invoiceDraftRows: [{ item_name: 'old' }], manualDraftRows: [{ item_name: 'old manual' }],
  invoiceCorrectionDraft: {}, invoiceImportId: 'old-id', invoiceAutoRedistribute: false,
  invoiceExtractionVersion: 0, invoiceExtractionController: null, invoiceCorrectionStorageKey: 'draft',
  packingDraftMode: 'invoice', invoicePriority: null,
  autoDistributionSnapshot: ['7'], distributionReviewOpen: true,
  localStorage: { removeItem: key => storage.delete(key) },
  invoiceModal: { querySelector: selector => ({ '[data-invoice-draft-form]': { reset() { resets++; } }, '[name="invoice_file"]': input, '[data-invoice-file-name]': label, '[data-remove-invoice-file]': remove })[selector] },
  document: { querySelector: () => button },
  setPackingDraftMode() {}, renderInvoiceDraft() {}, setInvoiceProgress() {}, setInvoiceStep() {}, setInvoiceStatus() {},
  FormData: class { set() {} }, config: { actionUrl: '/test' },
  fetch: () => new Promise(resolve => { finish = resolve; }), readJson: async () => ({ rows: [{ item_name: 'stale' }] }),
  detectedUnit() { throw new Error('Stale extraction applied'); }, quantityPlanParts() { return []; }
});
vm.runInContext(section('  function saveInvoiceCorrectionDraft()', '  function parseReceivedStock('), context);
vm.runInContext(section('  function clearPackingUploadDraft()', '  function renderPagination('), context);
vm.runInContext(section('  async function extractInvoiceDraft(', '  async function createInvoiceDraft('), context);
(async () => {
  const pending = context.extractInvoiceDraft({});
  const controller = context.invoiceExtractionController;
  context.clearPackingUploadDraft();
  assert(controller.signal.aborted);
  finish({});
  await pending;
  assert.equal(context.invoiceDraftRows.length, 0);
  assert.equal(context.manualDraftRows.length, 0);
  assert.equal(context.invoiceCorrectionDraft, null);
  assert.equal(context.invoiceImportId, '');
  assert.equal(context.autoDistributionSnapshot.length, 0);
  assert.equal(context.distributionReviewOpen, false);
  assert.equal(input.value, '');
  assert.equal(label.textContent, 'No PDF selected');
  assert.equal(remove.hidden, true);
  assert.equal(button.disabled, false);
  assert.equal(resets, 1);
  assert.equal(storage.has('draft'), false);
  assert.equal(storage.get('theme'), 'olive');
  assert.equal(context.restoreInvoiceCorrectionDraft(), false);
  assert(!source.includes('localStorage.setItem(invoiceCorrectionStorageKey'));
  assert.match(source, /if \(closeModal\) \{\s*clearPackingUploadDraft\(\)/);
  assert.match(source, /const storedTheme[^\n]+\n\s*clearPackingUploadDraft\(\)/);
  console.log('PASS: cancel resets forms and both drafts; stale extraction ignored; reload has no persisted draft; unrelated storage preserved.');
})().catch(error => { console.error(error); process.exitCode = 1; });
