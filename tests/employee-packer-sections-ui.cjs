// Synthetic browser fixtures only: no live records or authenticated sessions.
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const source=fs.readFileSync('assets/js/employee-performance.js','utf8');
const css=fs.readFileSync('assets/css/employee-performance.css','utf8');
const expected=['Overview','Orders','Packing List','Tasks','Courier Handover','Quality & Errors','Attendance','Review History'];
const categories=Object.fromEntries(['packing','quality','orders','packing_list','tasks','attendance','courier'].map(key=>[key,{label:'Source '+key,weight_hundredths:1000,status:'provisional',metrics:{sample:{label:'Sample '+key,eligible_volume:2,numerator:2,rate_hundredths:10000,status:'provisional',reason:'incomplete_source',confidence:'low',measurement_explanation:'Synthetic fixture'}}}]));
const performance={status:'provisional',period_start:'2026-10-01',categories,result_id:1,evidence:[],historical_incidents:[],excluded:[],personal_risk:[],team_risk:{},trend:[]};
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try{
  for(const width of [1440,390])for(const role of ['packer','front_desk']){
   const page=await browser.newPage({viewport:{width,height:900}}),errors=[],requests=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('https://fixture.test/**',route=>{
    const url=route.request().url();requests.push(route.request());
    if(url.includes('action=performance_evidence'))return route.fulfill({json:{ok:true,records:[],total:0,page:1,pages:1}});
    if(url.includes('kpi-employee-data.php'))return route.fulfill({json:{ok:true,performance}});
    return route.fulfill({contentType:'text/html',body:`<main id="kpi-employee-profile" class="kpi-employee-page" data-role-key="${role}" data-employee-id="7" data-can-review="0"><input data-ep-month><label data-ep-employee-label><select data-ep-employee></select></label><a data-ep-export></a><nav class="employee-kpi-jump-nav"></nav><div data-kpi-adoption></div><div data-kpi-error hidden></div><div data-kpi-employee-content></div><dialog data-kpi-timeline><div data-kpi-timeline-content></div></dialog></main>`});
   });
   await page.goto('https://fixture.test/profile');await page.addStyleTag({content:css});await page.addScriptTag({content:source});
   await page.locator('[role=tab]').first().waitFor();
   if(role==='packer'){
    assert.deepEqual(await page.locator('[role=tab]').allTextContents(),expected);
    for(const name of expected){
     await page.getByRole('tab',{name,exact:true}).click();
     assert.equal(await page.locator('[data-ep-panel]:visible').count(),1,name);
     assert.equal(await page.getByRole('tab',{name,exact:true}).getAttribute('aria-selected'),'true');
    }
    await page.getByRole('tab',{name:'Packing List',exact:true}).click();
    assert.equal(await page.locator('[data-ep-panel="packing_list"] .ep-area').count(),2);
    for(const key of ['packing','packing_list'])assert.equal(await page.locator(`[data-ep-panel="packing_list"] [data-ep-view-category="${key}"]`).count(),1);
    await page.getByRole('tab',{name:'Overview',exact:true}).click();
    await page.locator('.ep-breakdown-row[data-ep-tab="packing"]').click();
    assert.equal(await page.getByRole('tab',{name:'Packing List',exact:true}).getAttribute('aria-selected'),'true');
    await page.locator('[data-ep-view-category="packing"]').click();
    await page.waitForResponse(r=>r.url().includes('action=performance_evidence'));
    assert.equal(await page.locator('[data-ep-evidence-filter] select[name=category]').inputValue(),'packing');
   }else{
    const labels=await page.locator('[role=tab]').allTextContents();
    assert.ok(labels.includes('Productivity'));assert.ok(labels.includes('Fulfilment'));assert.ok(labels.includes('History'));assert.ok(!labels.includes('Courier Handover'));
   }
   assert.deepEqual(errors,[]);assert.ok(requests.every(r=>r.method()==='GET'),'UI navigation must not write records');
   console.log(`PASS ${role} ${width}px: navigation, retained evidence and no writes/errors`);await page.close();
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
