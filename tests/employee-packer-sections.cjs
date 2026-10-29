const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const source=fs.readFileSync('assets/js/employee-performance.js','utf8');
const mapping=source.slice(source.indexOf('  const isPacker='),source.indexOf('  const action='));
const switching=source.slice(source.indexOf('  function selectTab('),source.indexOf('  async function loadEvidence('));
const expected=['Overview','Orders','Packing List','Tasks','Courier Handover','Quality & Errors','Attendance','Review History'];
const keys=['packing','quality','orders','packing_list','tasks','attendance','courier'];
for(const role of ['packer','production_packer','marketing_sales_assistant','front_desk']){
  const data={categories:Object.fromEntries(keys.map(k=>[k,{label:'Original '+k,metrics:{},weight_hundredths:1000,status:'provisional'}]))};
  const original=JSON.stringify(data),panels=[{dataset:{epPanel:'packing_list'}},{dataset:{epPanel:'orders'}}];
  const context=vm.createContext({root:{dataset:{roleKey:role},querySelectorAll:()=>panels},nav:{querySelectorAll:()=>[]},data,activeTab:'overview',esc:String,label:String,icon:()=>'',resultScore:()=>'',pct:String,movement:String,measures:c=>`<measure>${c.label}</measure>`,measurementControls:k=>`<controls>${k}</controls>`,section:(k,html)=>`<panel key="${k}">${html}</panel>`,loadEvidence:()=>{}});
  vm.runInContext(mapping+switching+';globalThis.output={packer:isPacker,tabs:packerTabs.map(t=>t[1]),html:categoryPanels(Object.entries(data.categories))};selectTab("packing");globalThis.selected=activeTab;',context);
  if(role.includes('packer')){
    assert.deepEqual(Array.from(context.output.tabs),expected);
    assert.equal(context.selected,'packing_list');
    assert.equal(panels[0].hidden,false);assert.equal(panels[1].hidden,true);
    const groups=[...context.output.html.matchAll(/<panel key="([^"]+)">([\s\S]*?)<\/panel>/g)];
    assert.deepEqual(groups.map(g=>g[1]),['orders','packing_list','tasks','courier','quality','attendance']);
    const packing=groups.find(g=>g[1]==='packing_list')[2];
    for(const key of ['packing','packing_list']){assert.ok(packing.includes(`<controls>${key}</controls>`));assert.ok(packing.includes(`data-ep-view-category="${key}"`));}
    assert.ok(context.output.html.includes('Quality & Errors'));
    assert.ok(context.output.html.includes('Courier Handover'));
  }else{
    assert.equal(context.selected,'packing');
    assert.equal((context.output.html.match(/<panel /g)||[]).length,keys.length);
    assert.ok(context.output.html.includes('Original orders'));
    assert.equal(vm.runInContext('tabInfo("orders")[0]',context),'Fulfilment');
  }
  assert.equal(JSON.stringify(data),original,'Presentation must not mutate score/evidence data');
}
assert.match(source,/const tabs=isPacker\?packerTabs:/);
console.log('PASS: eight packer tabs, preserved grouped measures/evidence, overview routing and unchanged other roles/scores.');
