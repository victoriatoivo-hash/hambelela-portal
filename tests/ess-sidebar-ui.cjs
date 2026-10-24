const fs=require('fs'),assert=require('assert/strict'),{execFileSync}=require('child_process'),{JSDOM}=require('jsdom');
const html=execFileSync(process.env.PHP_BIN||'php',['-r',"define('BASE_URL','');define('BASE_PATH',getcwd());function current_role_key(){return 'owner_admin';} require 'shared/ess-navigation.php'; if(!function_exists('ess_can_access_settings')){function ess_can_access_settings(){return true;}} $apps=[['name'=>'Cost Workbook','href'=>'/apps/cost-manager/workbook.php','icon'=>'table']];include 'shared/ess-sidebar.php';"],{encoding:'utf8'});
const script=fs.readFileSync('assets/js/ess-sidebar.js','utf8');
const create=(saved,blocked=false)=>{const dom=new JSDOM('<body class="ess-dashboard">'+html+'</body>',{url:'https://portal.example',runScripts:'outside-only'}),w=dom.window;w.matchMedia=()=>({matches:false});if(saved!==null)w.localStorage.setItem('ess-sidebar-collapsed',saved);if(blocked)Object.defineProperty(w,'localStorage',{get(){throw Error('Storage blocked');}});w.eval(script);return dom;};
for(const blocked of [false,true]){
 const dom=create(null,blocked),w=dom.window,d=w.document,toggle=d.querySelector('[data-ess-sidebar-toggle]'),sub=d.querySelector('[data-ess-subnav-toggle]'),panel=d.getElementById(sub.getAttribute('aria-controls'));
 assert.ok(html.includes('/assets/js/ess-sidebar.js?v='),'Behaviour loads from actual sidebar without relying on footer');
 assert.equal(toggle.getAttribute('aria-expanded'),'true');toggle.click();assert.ok(d.body.classList.contains('ess-sidebar-collapsed'));assert.equal(toggle.getAttribute('aria-label'),'Expand sidebar');assert.equal(toggle.getAttribute('aria-expanded'),'false');if(!blocked)assert.equal(w.localStorage.getItem('ess-sidebar-collapsed'),'true');
 // Reloading/initialising the script must never register a second click handler.
 w.eval(script);toggle.click();assert.equal(d.body.classList.contains('ess-sidebar-collapsed'),false);toggle.click();sub.click();assert.equal(d.body.classList.contains('ess-sidebar-collapsed'),false);assert.equal(panel.hidden,false);assert.equal(sub.getAttribute('aria-expanded'),'true');toggle.click();assert.equal(panel.hidden,true);assert.equal(sub.getAttribute('aria-expanded'),'false');
 // Page-only dashboard controls may be absent; sidebar must remain independent.
 assert.equal(d.querySelector('[data-ess-search]'),null);assert.equal(d.querySelector('#ess-more-dialog'),null);dom.window.close();
}
const restored=create('true');assert.ok(restored.window.document.body.classList.contains('ess-sidebar-collapsed'));restored.window.close();
assert.ok(!fs.readFileSync('assets/js/ess-dashboard.js','utf8').includes('sidebarToggle.addEventListener'),'Dashboard must not double-bind toggle');
const sections=fs.readFileSync('shared/cost-workbook-sections.php','utf8');assert.ok(!sections.includes("['landed-product-costs','calculator'"));
assert.match(fs.readFileSync('shared/cost-workbook-page-shell.php','utf8'),/\$hiddenNavigation=\['landed-product-costs'/);
console.log('Shared sidebar: independent page load, collapse/expand, persisted choice, duplicate binding, blocked storage, submenus and workbook card removal PASS');
