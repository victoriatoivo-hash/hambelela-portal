import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const code=read('assets/js/employee-profile-v3.js'),php=read('apps/operations/kpi-employee.php'),client=read('assets/js/kpi-employee.js'),css=read('assets/css/employee-profile-v3.css');
const listeners={},hosts=[];
const root={dataset:{profileVersion:'3'},querySelectorAll:selector=>selector.startsWith('[data-ep-table]')?hosts.filter(h=>!h.dataset.epMounted):[],addEventListener:(type,fn)=>listeners[type]=fn};
const window={};
vm.runInNewContext(code,{window,document:{querySelector:()=>root},location:{href:'https://portal.test/apps/operations/kpi-employee.php',origin:'https://portal.test'},URL,MutationObserver:class{observe(){}}});
const api=window.HambelelaEmployeeProfileV3;
const rows=Array.from({length:25},(_,i)=>({reference:'Synthetic '+i,status:i%2?'open':'completed',scheduled:i<20?'2026-11-01':'2026-11-02'}));
let rendered=[];
const html=api.paged(rows,page=>{rendered=page;return JSON.stringify(page);},{columns:[['Scheduled','scheduled'],['Status','status']]});
const nodes=Object.fromEntries(['[data-ep-rows]','[data-ep-count]','[data-ep-prev]','[data-ep-next]','[data-ep-status]'].map(k=>[k,{}]));
const host={dataset:{epTable:html.match(/data-ep-table="(\d+)"/)[1]},closest:()=>null,querySelector:s=>nodes[s],querySelectorAll:()=>[]};hosts.push(host);
api.mountTables();assert.equal(rendered.length,10);assert.equal(nodes['[data-ep-prev]'].disabled,true);assert.match(nodes['[data-ep-count]'].textContent,/1–10 of 25/);
const click=key=>listeners.click({target:{closest:s=>s==='[data-ep-table]'?host:s===key?{}:null}});
click('[data-ep-next]');assert.equal(rendered[0].reference,'Synthetic 10');
const filter=(key,value)=>listeners.input({target:{value,matches:s=>s===key,closest:()=>host}});
filter('[data-ep-size]','20');assert.equal(rendered.length,20);click('[data-ep-next]');assert.equal(rendered.length,5);assert.equal(nodes['[data-ep-next]'].disabled,true);
filter('[data-ep-search]','synthetic 24');assert.equal(rendered.length,1);
click('[data-ep-clear]');filter('[data-ep-status]','open');assert.equal(rendered.length,12);
filter('[data-ep-from]','2026-11-02');assert.equal(rendered.length,2);
filter('[data-ep-to]','2026-11-01');assert.equal(rendered.length,0);assert.match(nodes['[data-ep-count]'].textContent,/No matching/);
assert.equal(rows.length,25);assert.equal(api.safeHref('javascript:alert(1)'),'');assert.equal(api.safeHref('https://outside.test/private'),'');assert.equal(api.safeHref('/apps/delivery/workspace.php?delivery=10'),'https://portal.test/apps/delivery/workspace.php?delivery=10');
assert.match(php,/\['overview'=>'Overview'\]\+\$profileSections/);assert.match(client,/overview:\['overview'\]/);assert.match(client,/key:'overview'/);
assert.match(php,/\$hidePortalSidebar = false/);assert.doesNotMatch(php,/performance-command-rail/);
assert.match(client,/sectionData=data\?\.sections\?\.\[key==='website'\?'website_updates'/);
assert.match(client,/loadController\.signal/);assert.match(code,/driverController\.signal/);
assert.match(code,/No approved|no approved/);assert.match(code,/score\.score==null/);assert.match(code,/No dated|taskTrend/);
assert.doesNotMatch(code,/method\s*:\s*['"]POST|localStorage|sessionStorage/);
assert.match(css,/#kpi-employee-profile \.ep-evidence-toolbar/);assert.match(css,/@media\(max-width:720px\)/);
assert.match(css,/#kpi-employee-profile \.kpi-period-panel \.portal-date-field \{height:38px !important;min-height:38px !important;\}/);
assert.match(css,/#kpi-employee-profile \.kpi-employee-header \.portal-header-actions\{flex:1 1 auto !important;flex-wrap:wrap !important;justify-content:flex-start !important;\}/);
assert.match(css,/#kpi-employee-profile \.kpi-employee-header \.portal-header-account\{flex:0 0 auto !important;width:auto !important;\}/);
assert.deepEqual(['Front Desk Orders in Scope','Completed Orders in Scope','Completed On Time','Completed Late','Orders Still Pending Completion','Completion Compliance'].map((name,index)=>api.orderStatAppearance(name,index).tone),['olive','blue','lavender','coral','amber','green']);
assert.equal(new Set(['Assigned','Delivered','Still outstanding','Past scheduled date','Currently failed','Proof-confirmed outcomes'].map((name,index)=>api.orderStatAppearance(name,index).tone)).size,6);
assert.match(css,/#kpi-employee-profile #orders \.ep-order-stat-copy > span\{[^}]*500 9px\/1\.1 Jost/);
assert.match(css,/#kpi-employee-profile #orders \.ep-order-stat-copy \.kpi-number\{[^}]*500 19px\/1 Jost/);
assert.match(code,/copy\.append\(heading,number\)/); // Move existing data nodes; never recalculate or replace their values.
assert.match(code,/footer\.append\(evidence\)/); // Preserve the original delegated evidence action.
assert.match(code,/notes\.forEach\(note=>body\.append\(note\)\)/); // Explanations remain accessible in Details.
const renamedAttributes={};
const aliasNode={nodeType:3,textContent:'Front Desk Orders in Scope '};
const aliasHeading={firstChild:aliasNode,children:[{}],querySelector:()=>({setAttribute:(key,value)=>renamedAttributes.help=value}),closest:()=>({querySelector:selector=>({setAttribute:(key,value)=>renamedAttributes[selector]=value})})};
const overviewLabel={firstChild:{nodeType:3,textContent:'Front Desk Orders in Scope'},children:[],querySelector:()=>null,closest:()=>null};
const additionalLabels=['Completed Orders in Scope','Completed On Time','Completed Late','Orders Still Pending Completion','Completion Compliance'].map(text=>({firstChild:{nodeType:3,textContent:text},children:[],querySelector:()=>null,closest:()=>null}));
const originalQuery=root.querySelectorAll;
root.querySelectorAll=selector=>{assert.equal(selector,'#orders .ep-order-stat-copy > span, #overview .ep-stat-label');return [aliasHeading,overviewLabel,...additionalLabels];};
api.renameOrderSummaryLabel();api.renameOrderSummaryLabel();
root.querySelectorAll=originalQuery;
assert.equal(aliasNode.textContent,'Orders ');
assert.equal(overviewLabel.firstChild.textContent,'Orders');
assert.deepEqual(additionalLabels.map(heading=>heading.firstChild.textContent),['Orders Completed','Completed On Time','Completed Late','Pending Orders','Completion Compliance']);
assert.equal(renamedAttributes.help,'About Orders');
assert.equal(renamedAttributes['.kpi-view-evidence'],'View evidence for Orders');
assert.equal(renamedAttributes['.ep-order-stat-details > summary'],'Details for Orders');
// Presentation release must not change any source calculation or permission endpoint.
for(const file of ['apps/operations/kpi-employee-data.php','apps/operations/performance-command-data.php','assets/js/performance-command-people.js']){
 const baseline=execFileSync('git',['show','b732f77f:'+file],{encoding:'utf8'});
 assert.equal(read(file).replaceAll('\r\n','\n'),baseline.replaceAll('\r\n','\n'),file+' must remain unchanged');
}
console.log('PASS: profile overview/role routing, unchanged sources, null-score safeguards, same-origin links, 10/20 paging, search/status/inclusive-date filters, clear/empty/final-page states and stale-response protection');
