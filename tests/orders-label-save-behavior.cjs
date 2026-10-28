const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('assets/js/orders-board.js','utf8');
const dom = new JSDOM('<main id="rows"></main><div id="menu"></div><p id="sync"></p>');
const document = dom.window.document, labelMenu = document.querySelector('#menu');
const requests=[], errors=[], toasts=[];
let finish, reject;
const ctx={document, labelMenu, syncState:document.querySelector('#sync'), window:dom.window,
  esc:String, itemColor:i=>i[2], itemText:i=>i[1],
  modeLabels:[['delivery','Delivery','#aaa']],paymentLabels:[],
  statusLabels:[['new_order','NEW ORDER','#aaa'],['assigned','NEW ORDER','#aaa'],['in_progress','IN PROGRESS','#fa0'],['completed','COMPLETE','#f00']],
  showError:e=>errors.push(e.message),
  updateRichLabelValue:(id,field,value)=>{requests.push({id,field,value});return new Promise((resolve,rejectFn)=>{finish=resolve;reject=rejectFn;});}
};
dom.window.showPortalToast=x=>toasts.push(x);
vm.createContext(ctx);
function load(start,end){vm.runInContext(source.slice(source.indexOf(start),source.indexOf(end)),ctx);}
load('  function richLabelFieldTitle(', '  function renderRichLabelEditor(');
load('  function bindRichLabelPicker(', '  function bindRichLabelEditorUI(');
const settle=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  for(const id of ['today-order','38336','earliest-month-order']) {
    labelMenu.dataset.richLabelOrder=id;labelMenu.dataset.richLabelField='status';
    labelMenu.innerHTML=ctx.renderRichLabelPicker('status');ctx.bindRichLabelPicker();
    assert.equal([...labelMenu.querySelectorAll('button')].filter(b=>b.textContent.trim()==='NEW ORDER').length,1);
    const button=labelMenu.querySelector('[data-rich-label-value="in_progress"]');
    const before=requests.length;button.click();button.click();
    assert.equal(requests.length,before+1,'one request per click sequence');
    assert.deepEqual(requests.at(-1),{id,field:'status',value:'in_progress'});
    assert.equal(button.disabled,true);
    assert.equal(labelMenu.querySelector('[data-label-save-feedback]').textContent,'Saving…');
    reject(new Error('Please select who packed this order before marking it Complete.'));await settle();
    assert.equal(button.disabled,false,'failed request can be retried');
    assert.match(labelMenu.querySelector('[data-label-save-feedback]').textContent,/select who packed/);
    assert.equal(toasts.at(-1).message,errors.at(-1),'backend error is visible, not an unhandled rejection');
    button.click();finish(true);await settle();
    assert.equal(ctx.syncState.textContent,'Status saved.');
  }
  labelMenu.querySelector('[data-rich-label-value="in_progress"]').click();finish(false);await settle();
  assert.equal(labelMenu.querySelector('[data-label-save-feedback]').hidden,true,'cancelled courier prompt is not left saving');

  // Execute the same read rejection condition used before any polling merge/cursor advancement.
  const guard=source.match(/if \(activeOrderMutationCount \|\| orderRevisionAtRequest !== orderMutationRevision\) return data;/)[0];
  const accept=vm.runInContext(`(function(activeOrderMutationCount,orderRevisionAtRequest,orderMutationRevision){const data='discarded';${guard}return 'apply';})`,ctx);
  assert.equal(accept(1,1,1),'discarded');
  assert.equal(accept(0,1,2),'discarded');
  assert.equal(accept(0,2,2),'apply');
  assert.ok(source.indexOf(guard)<source.indexOf("liveCursor = String(payload.cursor"),'stale reads cannot advance cursor');
  assert.match(source,/if \(!details\) return \[\];/);
  assert.match(source,/if \(!changes.length\) return false/);
  assert.match(source,/changes.map\(\(change\) => change.id\).forEach/,'only confirmed saved rows repaint');
  console.log('PASS: today/38336/earliest-date status targeting, visible backend errors/retry, no duplicate saves/status options, courier cancel, stale polling suppression, partial-save repaint safeguard');
})().finally(()=>dom.window.close()).catch(e=>{console.error(e);process.exitCode=1;});
