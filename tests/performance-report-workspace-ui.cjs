const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const metric=(value,format='number')=>({value,format,delta:null,sample:8,low_data:false});
const health={ok:true,period:{from:'2026-10-01',to:'2026-10-29',adoption_date:'2026-07-14'},cards:{orders:metric(40),revenue:metric(4000,'currency'),fulfilment:metric(65,'minutes'),settlement:metric(null,'minutes'),attendance:metric(60,'percent'),pack_speed:metric(40,'minutes')},team:[],attention:[],live_activity:[],scores:[],scores_disabled:true,scores_message:'Insufficient verified evidence',operational_score:null,operational_score_message:'Not calculated — incomplete operational evidence',orders_overview:{counts:{received:40,new:3,in_progress:2,completed:35,outstanding:5,reopened:0},timing:{packer:{average:'40 min'},front_desk:{average:'20 min'}}},trends:{orders:[],packing:[]},recognition:{overall:[],strengths:[],risks:[]}};
const activity={ok:true,period:{from:'2026-10-01',to:'2026-10-29'},cards:[{label:'Recorded activities',value:1}],rows:[{occurred_at:'2026-10-29T10:00:00+02:00',action:'order_completed',actor:'Fixture employee',actor_type:'employee',module:'orders',result:'successful',kpi_effect:'employee_evidence',record_id:1}],pagination:{page:1,pages:1}};
const workforce={ok:true,employees:[],workforce:{period_start:'2026-10-01',reports:[]}};
health.cards.revenue=metric(271022.84,'currency');
health.cards.order_start_response=metric(82,'minutes');
health.cards.walk_in_completion=metric(91,'minutes');
health.cards.final_closure=metric(1017,'minutes');
health.team=[{id:2,name:'Fixture Employee',role:'Front Desk',online:true,summary_label:'Monthly result',summary_empty:'Not calculated',metrics:[{label:'Work evidence',rows:[{label:'Completed orders',value:'18 / 20'},{label:'Tasks',value:'5 / 6'}]},{label:'Open items',value:2}],summary_note:'Provisional evidence only'}];
health.attention=Array.from({length:12},(_,i)=>({description:'Overdue task: Front Shop Stock Checklist '+(i+1),severity:'critical',due_at:'2026-10-20 16:30:00',overdue:'8 d 22 h',href:'checklists.php?id='+(i+1)}));
health.operational_score_components=[{label:'Orders',score:97},{label:'Packing',score:89},{label:'Tasks',score:60},{label:'Unknown',score:null}];
health.live_activity=[{employee:'Fixture Employee',module:'order',action:'status_changed_from_in_progress_to_completed',record_id:38336,occurred_at:'2026-10-29 15:14:00'},{employee:'Fixture Employee',module:'checklist_task',action:'task_reminder_generated',record_id:395,occurred_at:'2026-10-29 15:15:00'}];
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.TEST_BROWSER_CHANNEL||'chrome'});
 const context=await browser.newContext();fs.mkdirSync('.run-artifacts/report-workspace',{recursive:true});
 let pending=[],defer=false;
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url()),p=u.pathname;
  if(u.hostname!=='fixture.test')return route.abort();
  if(p.endsWith('/reports-data.php')){if(defer){pending.push(route);return;}return route.fulfill({json:health});}
  if(p.endsWith('/reports-business-activity-data.php'))return route.fulfill({json:activity});
  if(p.endsWith('/reports-performance-reports-data.php'))return route.fulfill({json:workforce});
  if(p.startsWith('/assets/')){const file=path.join(process.cwd(),p);if(fs.existsSync(file))return route.fulfill({body:fs.readFileSync(file),contentType:p.endsWith('.css')?'text/css':p.endsWith('.js')?'text/javascript':'application/octet-stream'});}
  if(p.endsWith('/reports.php')){const html=execFileSync(process.env.PHP_BIN||'php',['tests/performance-report-workspace-fixture.php',u.searchParams.get('tab')],{encoding:'utf8'});return route.fulfill({body:html,contentType:'text/html'});}
  return route.fulfill({status:404,body:''});
 });
 for(const width of [1440,390])for(const tab of ['business-health','business-activity','settings','employees']){
  const page=await context.newPage();await page.setViewportSize({width,height:960});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`https://fixture.test/apps/operations/reports.php?tab=${tab}`,{waitUntil:'networkidle'});
  const main=page.locator('main.performance-report-workspace');await main.waitFor();
  assert.equal(await page.locator('.pr-breadcrumb a').getAttribute('href'),'reports.php');
  assert.match(await page.locator('h1').evaluate(e=>getComputedStyle(e).fontFamily),/Jost/);
  assert.equal(await page.locator('h1').evaluate(e=>getComputedStyle(e).fontSize),width<520?'17px':tab==='business-health'?'22px':'24px');
  const layout=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));assert.ok(layout.scroll<=width+1,`${tab} ${width}: page overflow ${layout.scroll}`);
  const bounds=await main.boundingBox();assert.ok(bounds.x+bounds.width<=width+1,`${tab} ${width}: main clipped ${JSON.stringify(bounds)}`);
  if(tab==='settings'){
   await page.locator('#reporting-controls>summary').click();assert.equal(await page.locator('#reporting-controls').getAttribute('open'),'');
   assert.ok(await page.locator('[name="kpi_action"][value="save_settings"]').count());assert.ok(await page.locator('[name="weight_points_s"]').count());
   await page.locator('#reporting-controls>summary').click();
  }
  if(tab==='business-health'){
   await page.locator('.kpi-measure-card').first().waitFor();
   assert.equal(await page.locator('.kpi-measure-card').count(),9);
   const compact=await page.locator('.kpi-measure-card').first().boundingBox();assert.ok(compact.height<=105,`compact widget height ${compact.height}`);
   if(width<520)assert.ok(compact.width>width*.8,'mobile widgets must use a full-width compact row');
   assert.equal(await page.locator('.kpi-measure-copy>strong').first().evaluate(e=>getComputedStyle(e).fontSize),'18px');
   assert.equal(await page.locator('.kpi-measure-icon svg').count(),9);
   assert.ok(await page.locator('.kpi-measure-copy').evaluateAll(nodes=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)),'widget text must fit');
   await page.locator('.pr-summary-strip').waitFor();assert.match(await page.locator('.pr-score').textContent(),/Not calculated/);
   assert.equal(await page.locator('.pr-component meter').count(),3,'only measured components have bars');
   assert.equal(await page.locator('.kpi-attention-row').count(),12);
   assert.ok((await page.locator('.kpi-attention-row').first().boundingBox()).height<100,'compact attention row');
   assert.equal(await page.locator('.pr-activity-table tbody tr').count(),1,'automatic reminders must not be presented as employee changes');
   assert.match(await page.locator('.pr-activity-table time').textContent(),/15:14/,'explicit Namibia time');
   assert.equal(await page.locator('.kpi-person-overview__identity h3').evaluate(e=>getComputedStyle(e).color),'rgb(52, 65, 38)');
   if(width===1440){assert.ok((await page.locator('.kpi-period-panel').first().boundingBox()).height<=65,'compact reporting controls');assert.ok((await page.locator('.pr-summary-strip').boundingBox()).height<=85,'compact period summary');}
   await page.getByText('Order mix & completion detail',{exact:true}).click();assert.equal(await page.locator('.kpi-orders-overview-grid article').count(),15);
   assert.equal(await page.locator('[data-kpi-error]').isVisible(),false);
   if(width===1440){
    defer=true;await page.locator('[data-kpi-period]').selectOption('today');await page.waitForTimeout(50);await page.locator('[data-kpi-period]').selectOption('last_month');await page.waitForTimeout(50);
    assert.equal(pending.length,2);await pending[1].fulfill({json:{...health,period:{...health.period,from:'2026-09-01',to:'2026-09-30'}}});await page.waitForTimeout(100);
    await pending[0].fulfill({json:health}).catch(()=>{});await page.waitForTimeout(50);assert.match(await page.locator('[data-kpi-caption]').textContent(),/2026-09-01/);defer=false;pending=[];
   }
  }
  if(tab==='business-activity')assert.ok(await page.locator('#kpi-business-timeline').evaluate(e=>e.getBoundingClientRect().top<document.querySelector('[data-kpi-section-content]').getBoundingClientRect().top));
  await page.screenshot({path:`.run-artifacts/report-workspace/${tab}-${width}.png`,fullPage:true});
  await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:`.run-artifacts/report-workspace/${tab}-${width}-viewport.png`});assert.deepEqual(errors,[],`${tab} browser errors`);await page.close();
  console.log(`PASS ${tab} ${width}px: navigation, Jost, width, render and interactions`);
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
