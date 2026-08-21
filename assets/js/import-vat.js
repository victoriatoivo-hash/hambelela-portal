(() => {
  const root = document.querySelector('[data-import-vat]');
  if (!root) return;
  const $ = selector => root.querySelector(selector);
  const api = root.dataset.api;
  const csrf = root.dataset.csrf;
  let month = root.dataset.currentMonth;
  let view = 'imports';
  let state = null;
  let activeStatement = null;
  let reviewNeedsOnly = false;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const money = value => 'N$ ' + Number(value || 0).toLocaleString('en-NA', {minimumFractionDigits:2, maximumFractionDigits:2});
  const monthName = value => new Date(value + '-02T12:00:00').toLocaleDateString('en-NA', {month:'long', year:'numeric'});
  const shortDate = value => value ? new Date(value + 'T12:00:00').toLocaleDateString('en-NA', {day:'numeric', month:'short', year:'numeric'}) : '—';
  const taxPeriodName = row => {
    const period = Number(row.tax_period || 0), year = Number(row.tax_year || 0);
    if (period >= 1 && period <= 12 && year) return new Date(year - 1, period + 2, 1).toLocaleDateString('en-NA', {month:'short', year:'numeric'});
    return [row.tax_year,row.tax_period].filter(Boolean).join(' / ') || '—';
  };

  async function request(url, options = {}) {
    const response = await fetch(url, {credentials:'same-origin', ...options});
    const json = await response.json();
    if (!response.ok || !json.ok) throw new Error(json.message || 'Import VAT request failed.');
    return json;
  }
  async function post(action, source) {
    const form = source instanceof FormData ? source : new FormData(source);
    form.set('action', action); form.set('month', month); form.set('view', view); form.set('csrf', csrf);
    return request(api, {method:'POST', body:form});
  }
  const card = (icon,label,value,note) => `<article><div class="accounts-summary-heading"><i data-lucide="${icon}"></i><small>${label}</small></div><div class="accounts-summary-value"><strong>${value}</strong><span>${note}</span></div></article>`;
  function navigation() {
    const year = +month.slice(0,4), select = $('[data-year]'); select.innerHTML = '';
    for (let n=2024;n<=new Date().getFullYear()+1;n++) select.innerHTML += `<option ${n===year?'selected':''}>${n}</option>`;
    $('[data-tabs]').innerHTML = state.progress.map((p,i) => `<button class="input-vat-month-tab ${p.month===month?'is-active':''}" data-month="${p.month}"><span>${new Date(2026,i,1).toLocaleDateString('en',{month:'short'})}</span><small>${p.status.replaceAll('_',' ')}</small></button>`).join('');
    $('[data-active-period]').textContent = monthName(month); $('[data-export]').href = `${api}?action=export&month=${month}&view=${view}`;
  }
  function render() {
    const s=state.summary; navigation();
    $('[data-summary]').innerHTML = card('ship','Imports this month',s.imports,'Liabilities in this view')+card('receipt-text','Import VAT due',money(s.import_vat),'NamRA assessed VAT')+card('circle-check','Paid',money(s.paid),'From payment ledger')+card('circle-dollar-sign','Outstanding',money(s.outstanding),'Total due less valid payments');
    $('[data-month-status]').innerHTML = `<div class="input-vat-month-status-copy"><strong>${monthName(month).toUpperCase()}</strong><span>${view==='imports'?'Import month':'Payment due month'}</span><small>${s.imports} imports · ${money(s.outstanding)} outstanding</small></div>`;
    $('[data-analysis]').innerHTML = `<article><h2>Payment Summary</h2><div class="summary-line"><span>Total due</span><strong>${money(s.total_due)}</strong></div><div class="summary-line"><span>Paid</span><strong>${money(s.paid)}</strong></div><div class="summary-line"><span>Overdue</span><strong>${money(s.overdue)}</strong></div></article><article><h2>Import Charges</h2><div class="summary-line"><span>Import VAT</span><strong>${money(s.import_vat)}</strong></div><div class="summary-line"><span>Duty and levies</span><strong>${money(s.other_charges)}</strong></div></article><article><h2>Ledger Basis</h2><p class="output-vat-help">Paid is the sum of active payment records. Statement rows change this ledger only after owner review and confirmation.</p></article>`;
    filter(); if(window.lucide) lucide.createIcons();
  }
  function filter() {
    const q=$('[data-search]').value.toLowerCase(), status=$('[data-status]').value;
    const rows=state.records.filter(r=>(!q||`${r.supplier} ${r.reference} ${r.description}`.toLowerCase().includes(q))&&(!status||r.status===status));
    $('[data-rows]').innerHTML=rows.length?rows.map(r=>`<tr><td>${esc(r.import_date)}</td><td><strong>${esc(r.reference)}</strong></td><td>${esc(r.supplier||'—')}</td><td>${esc(r.description)}</td><td class="money">${money(r.import_vat_amount)}</td><td class="money">${money(+r.duty_amount + +r.other_charge_amount)}</td><td class="money">${money(r.total_due)}</td><td>${esc(r.due_date)}</td><td class="money">${money(r.paid)}</td><td class="money">${money(r.outstanding)}</td><td><span class="status-pill ${esc(r.status)}">${esc(r.status.replaceAll('_',' '))}</span></td><td><div class="row-actions"><button data-pay="${r.id}" data-outstanding="${r.outstanding}" title="Record payment"><i data-lucide="wallet-cards"></i></button>${!r.payments.length?`<button data-delete="${r.id}" title="Delete"><i data-lucide="trash-2"></i></button>`:''}</div></td></tr>`).join(''):`<tr><td colspan="12" class="empty-row">No import liabilities for ${esc(monthName(month))}.</td></tr>`;
    $('[data-totals]').innerHTML=`<tr><th colspan="4">Totals</th><td>${money(state.summary.import_vat)}</td><td>${money(state.summary.other_charges)}</td><td>${money(state.summary.total_due)}</td><td></td><td>${money(state.summary.paid)}</td><td>${money(state.summary.outstanding)}</td><td colspan="2"></td></tr>`;
    if(window.lucide) lucide.createIcons();
  }
  async function load(){state=(await request(`${api}?month=${month}&view=${view}&t=${Date.now()}`)).data;render();}
  async function loadHistory(){const rows=(await request(`${api}?action=statement_history&t=${Date.now()}`)).data;$('[data-statement-history]').innerHTML=rows.length?rows.map(r=>`<tr><td>${esc(r.uploaded_at)}<small>${esc(r.uploaded_by_name)}</small></td><td><strong>${esc(r.original_filename)}</strong><small>${esc(r.mime_type)}</small></td><td>${esc(r.statement_period||'—')}</td><td>${r.rows_detected}</td><td>${r.liabilities_detected} assessments · ${r.revision_count} revisions<br>${r.payments_detected} payments · ${r.penalty_count} penalties ignored · ${r.interest_count} interest ignored</td><td><span class="status-pill ${esc(r.status)}">${esc(r.status.replaceAll('_',' '))}</span></td><td>${r.confirmed_at?`${r.new_records} periods · ${r.matched_payments} payments · ${r.duplicates_skipped} skipped`:`${r.needs_review} need review`}</td><td><div class="row-actions"><button data-review-statement="${r.id}" title="Review statement"><i data-lucide="scan-search"></i></button><a href="import-vat-file.php?type=statement&id=${r.id}" target="_blank" title="Open source"><i data-lucide="file-text"></i></a></div></td></tr>`).join(''):'<tr><td colspan="8" class="empty-row">No NamRA statements uploaded yet.</td></tr>';if(window.lucide)lucide.createIcons();}
  async function loadSettings(){const settings=(await request(`${api}?action=settings&t=${Date.now()}`)).data;const form=$('[data-exclusion-settings]');form.penalty_interest_exclusion_active.checked=settings.penalty_interest_exclusion_active==='1';form.penalty_interest_review_date.value=settings.penalty_interest_review_date||'';form.penalty_interest_review_date.dispatchEvent(new Event('input',{bubbles:true}));form.penalty_interest_review_date.dispatchEvent(new Event('change',{bubbles:true}));}
  function renderReview(statement){
    activeStatement=statement;
    const counts={assessment:0,revision:0,payment:0,ignored_penalty:0,ignored_interest:0,needs_review:0};
    statement.rows.forEach(row=>{counts[row.classification]=(counts[row.classification]||0)+1;});
    $('[data-review-title]').textContent=statement.original_filename;
    $('[data-review-status]').textContent=`${statement.rows.length} transaction rows detected`;
    $('[data-review-summary]').innerHTML=[['receipt-text','Assessments',counts.assessment],['file-pen-line','Revisions',counts.revision],['landmark','VIA payments',counts.payment],['shield-minus','Penalties excluded',counts.ignored_penalty],['clock-3','Interest excluded',counts.ignored_interest],['triangle-alert','Needs review',counts.needs_review]].map(([icon,label,value])=>`<article><i data-lucide="${icon}"></i><span>${label}</span><strong>${value}</strong></article>`).join('');
    $('[data-review-needs-count]').textContent=counts.needs_review;
    $('[data-review-needs]').hidden=counts.needs_review===0;
    $('[data-source-file]').href=`import-vat-file.php?type=statement&id=${statement.id}`;
    $('[data-confirm-statement]').hidden=statement.status==='confirmed'||!statement.rows.length;
    renderReviewRows();
    if(!$('[data-review-dialog]').open)$('[data-review-dialog]').showModal();
    if(window.lucide)lucide.createIcons();
  }
  function renderReviewRows(){
    if(!activeStatement)return;
    const q=($('[data-review-search]').value||'').trim().toLowerCase(),type=$('[data-review-type]').value,treatmentFilter=$('[data-review-treatment]').value;
    const rows=activeStatement.rows.filter(r=>{const ignored=String(r.classification).startsWith('ignored_'),treatment=r.match_status==='possible_duplicate'?'duplicate':ignored||r.excluded?'excluded':r.classification==='payment'?'payment':r.included_in_payable==1?'payable':'review';return(!q||`${r.doc_number||''} ${r.reference||''} ${r.transaction_type||''} ${r.liability_type||''} ${r.tax_year||''} ${r.tax_period||''} ${r.transaction_amount||''}`.toLowerCase().includes(q))&&(!type||r.classification===type)&&(!treatmentFilter||treatment===treatmentFilter)&&(!reviewNeedsOnly||r.classification==='needs_review');});
    $('[data-review-rows]').innerHTML=rows.length?rows.map(r=>{const ignored=String(r.classification).startsWith('ignored_'),duplicate=r.match_status==='possible_duplicate',treatment=duplicate?'Duplicate — not posted':ignored?'Audit only — excluded':r.classification==='payment'?'Payment ledger':r.included_in_payable==1?'Principal payable':'Needs review',status=duplicate?'Already imported':ignored||r.excluded?'Excluded':r.classification==='payment'?'VIA Payment':r.classification==='needs_review'?'Needs Review':r.classification==='revision'?'Revision':'Assessment',statusClass=duplicate?'duplicate':ignored||r.excluded?'excluded':r.classification==='payment'?'payment':r.classification==='needs_review'?'needs-review':'assessment';return `<tr class="${r.excluded||ignored||duplicate?'is-excluded':''}"><td data-label="Type"><strong>${esc(r.transaction_type||r.classification.replaceAll('_',' '))}</strong><small>Row ${r.source_row_number} · ${esc(r.confidence)} confidence</small><input type="hidden" value="${esc(r.description||'')}" data-row-description="${r.id}"></td><td data-label="Document / Reference"><input value="${esc(r.reference||r.doc_number||'')}" data-row-reference="${r.id}" aria-label="Reference for row ${r.source_row_number}"><small>${esc(r.doc_number&&r.reference!==r.doc_number?r.doc_number:(r.liability_type||''))}</small></td><td data-label="Tax Period"><strong>${esc(taxPeriodName(r))}</strong></td><td data-label="Due Date"><span class="namra-date-display">${esc(shortDate(r.due_date))}</span><input type="date" value="${esc(r.due_date||'')}" data-row-due="${r.id}" aria-label="Due date for row ${r.source_row_number}"></td><td data-label="Action Date"><span class="namra-date-display">${esc(shortDate(r.transaction_date||r.action_date))}</span><input type="date" value="${esc(r.transaction_date||r.action_date||'')}" data-row-date="${r.id}" aria-label="Action date for row ${r.source_row_number}"></td><td data-label="Amount" class="money namra-amount"><strong>${money(r.transaction_amount)}</strong><input type="hidden" value="${r.import_vat_amount}" data-row-vat="${r.id}"><input type="hidden" value="${r.payment_amount}" data-row-payment="${r.id}"></td><td data-label="Accounting Treatment"><select data-row-classification="${r.id}" aria-label="Accounting treatment for row ${r.source_row_number}"><option value="needs_review" ${r.classification==='needs_review'?'selected':''}>Needs review</option><option value="assessment" ${r.classification==='assessment'?'selected':''}>Assessment (201)</option><option value="revision" ${r.classification==='revision'?'selected':''}>Revision (204)</option><option value="payment" ${r.classification==='payment'?'selected':''}>VIA payment (129)</option><option value="ignored_penalty" ${r.classification==='ignored_penalty'?'selected':''}>Penalty — excluded (481)</option><option value="ignored_interest" ${r.classification==='ignored_interest'?'selected':''}>Interest — excluded (304)</option></select><small>${treatment}</small></td><td data-label="Status"><span class="namra-status ${statusClass}">${status}</span><label class="import-vat-row-exclude"><input type="checkbox" data-row-excluded="${r.id}" ${r.excluded?'checked':''}> Exclude</label></td><td data-label="Action"><button class="portal-button portal-button--secondary" data-save-row="${r.id}">Save</button></td></tr>`;}).join(''):'<tr><td colspan="9" class="empty-row">No statement rows match these filters.</td></tr>';
    if(window.lucide)lucide.createIcons();
  }
  async function openStatement(id){renderReview((await request(`${api}?action=statement&id=${id}&t=${Date.now()}`)).data);}
  root.addEventListener('click',event=>{
    const save=event.target.closest('[data-save-row]');
    if(!save)return;
    const id=save.dataset.saveRow,select=$(`[data-row-classification="${id}"]`),cell=save.closest('td');
    if(select)select.setAttribute('data-row-kind',id);
    if(cell&&!cell.querySelector(`[data-row-other="${id}"]`))cell.insertAdjacentHTML('beforeend',`<input type="hidden" value="0" data-row-other="${id}"><input type="hidden" value="" data-row-match="${id}">`);
  },true);
  root.addEventListener('click',async event=>{try{const monthButton=event.target.closest('[data-month]');if(monthButton){month=monthButton.dataset.month;await load();}const tab=event.target.closest('[data-view]');if(tab){view=tab.dataset.view;root.querySelectorAll('[data-view]').forEach(b=>b.classList.toggle('is-active',b===tab));await load();}if(event.target.closest('[data-upload-statement]')){$('[data-statement-form]').reset();$('[data-statement-form] [name=statement_period]').value=month;$('[data-statement-dialog]').showModal();}if(event.target.closest('[data-add]')){$('[data-import-form]').reset();$('[data-import-form] [name=import_date]').value=new Date().toISOString().slice(0,10);$('[data-import-dialog]').showModal();}const close=event.target.closest('[data-close]');if(close)close.closest('dialog').close();const pay=event.target.closest('[data-pay]');if(pay){$('[data-payment-form] [name=import_id]').value=pay.dataset.pay;$('[data-payment-form] [name=amount]').value=pay.dataset.outstanding;$('[data-payment-dialog]').showModal();}const del=event.target.closest('[data-delete]');if(del&&confirm('Move this import to audit history?')){const f=new FormData();f.set('id',del.dataset.delete);state=(await post('delete',f)).data;render();}if(event.target.closest('[data-prev],[data-next]')){const d=new Date(month+'-02');d.setMonth(d.getMonth()+(event.target.closest('[data-next]')?1:-1));month=d.toISOString().slice(0,7);await load();}if(event.target.closest('[data-print]'))print();if(event.target.closest('[data-refresh-history]'))await loadHistory();const review=event.target.closest('[data-review-statement]');if(review)await openStatement(review.dataset.reviewStatement);const save=event.target.closest('[data-save-row]');if(save){event.preventDefault();const id=save.dataset.saveRow,f=new FormData();f.set('id',activeStatement.id);f.set('row_id',id);for(const [key,selector] of [['transaction_date','date'],['due_date','due'],['reference','reference'],['description','description'],['row_kind','kind'],['import_vat_amount','vat'],['other_charge_amount','other'],['payment_amount','payment'],['matched_import_id','match']])f.set(key,$(`[data-row-${selector}="${id}"]`).value);if($(`[data-row-excluded="${id}"]`).checked)f.set('excluded','1');activeStatement=(await post('update_statement_row',f)).data;renderReview(activeStatement);}if(event.target.closest('[data-confirm-statement]')){const f=new FormData();f.set('id',activeStatement.id);activeStatement=(await post('confirm_statement',f)).data;$('[data-review-message]').textContent='Statement confirmed. The ledger was updated once.';renderReview(activeStatement);await load();await loadHistory();}}catch(error){const target=$('[data-review-message]');if(target)target.textContent=error.message;}});
  $('[data-import-form]').addEventListener('submit',async event=>{event.preventDefault();try{state=(await post('save',event.currentTarget)).data;render();$('[data-import-dialog]').close();}catch(error){$('[data-import-message]').textContent=error.message;}});
  $('[data-payment-form]').addEventListener('submit',async event=>{event.preventDefault();try{state=(await post('payment',event.currentTarget)).data;render();$('[data-payment-dialog]').close();}catch(error){$('[data-payment-message]').textContent=error.message;}});
  $('[data-statement-form]').addEventListener('submit',async event=>{event.preventDefault();const message=$('[data-statement-message]');message.textContent='Uploading and checking statement…';try{const result=await post('upload_statement',event.currentTarget);message.textContent=result.message;$('[data-statement-dialog]').close();renderReview(result.data.statement);await loadHistory();}catch(error){message.textContent=error.message;}});
  $('[data-search]').addEventListener('input',filter);$('[data-status]').addEventListener('change',filter);$('[data-year]').addEventListener('change',event=>{month=event.target.value+month.slice(4);load();});
  $('[data-review-search]').addEventListener('input',renderReviewRows);$('[data-review-type]').addEventListener('change',renderReviewRows);$('[data-review-treatment]').addEventListener('change',renderReviewRows);$('[data-review-needs]').addEventListener('click',event=>{reviewNeedsOnly=!reviewNeedsOnly;event.currentTarget.classList.toggle('is-active',reviewNeedsOnly);renderReviewRows();});
  $('[data-exclusion-settings]').addEventListener('submit',async event=>{event.preventDefault();try{await post('save_settings',event.currentTarget);$('[data-settings-message]').textContent='Rule saved. No historical principal totals were changed.';}catch(error){$('[data-settings-message]').textContent=error.message;}});
  Promise.all([load(),loadHistory(),loadSettings()]).catch(error=>$('[data-rows]').innerHTML=`<tr><td colspan="12" class="empty-row">${esc(error.message)}</td></tr>`);
})();
