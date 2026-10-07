import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const require = createRequire(process.env.HR_TEST_NODE_MODULES + '/package.json');
const {chromium} = require('playwright');
const base='http://127.0.0.1:8098';
const browser=await chromium.launch({headless:true,channel:process.env.HR_TEST_BROWSER_CHANNEL || 'chrome'});
const context=await browser.newContext();
const page=await context.newPage();
mkdirSync('test-evidence',{recursive:true});
const check=(ok,label)=>{assert.ok(ok,label);console.log('PASS '+label);};
try {
 await page.goto(base+'/login-test.php?id=15');
 const business=(await context.cookies()).find(c=>c.name==='business_fixture_session');
 await page.goto(base+'/apps/hr-portal/portal-login.php?employee_id=1');
 check(page.url().endsWith('/self-service.php'),'bridge lands on self-service without second login');
 const identity=JSON.parse(await page.locator('body').innerText());
 check(identity.name==='Hope Kahuika' && identity.emp_id===4 && identity.portal_user_id===15 && identity.role==='employee','bridge uses authenticated Hope mapping, ignores supplied other employee ID');
 check(identity.capabilities.length===0,'employee receives no HR admin capabilities');
 const cookies=await context.cookies();
 check(cookies.find(c=>c.name==='hambelela_hr_test_session').value!==business.value,'Business and HR session IDs are independent');
 check(cookies.find(c=>c.name==='business_fixture_session').value===business.value,'Business session ID preserved');
 await page.goto(base+'/business-test.php');
 check(JSON.parse(await page.locator('body').innerText()).id===15,'Business session remains Hope after opening HR');
 for(const route of ['dashboard.php','employees.php','payroll.php','settings.php','loans.php']) {
  await page.goto(base+'/apps/hr-portal/'+route);
  check(page.url().endsWith('/self-service.php'),'employee blocked from administration: '+route);
 }
 await page.goto(base+'/state-test.php?state=inactive');
 for(const width of [1366,390,430]) {
  await page.setViewportSize({width,height:844});
  const response=await page.goto(base+'/apps/hr-portal/portal-login.php');
  check(response.status()===503,'inactive bridge denied at '+width+'px');
  check(await page.getByRole('heading',{name:'HR Portal access isn’t ready'}).isVisible(),'themed error heading at '+width+'px');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'error page has no horizontal overflow at '+width+'px');
  check(!/SELECT|users|employee_id|SQLSTATE|Klaudia/.test(await page.locator('body').innerText()),'employee error hides internal and cross-person details');
  await page.screenshot({path:'test-evidence/hr-access-error-'+width+'.png',fullPage:true});
 }
 await page.goto(base+'/state-test.php?state=ready');
 await page.goto(base+'/state-test.php?state=wrong_role');
 check((await page.goto(base+'/apps/hr-portal/portal-login.php')).status()===503,'wrong HR role denied before session creation');
 await page.goto(base+'/state-test.php?state=ready');
 for(const file of ['my-payslips.php','my-leave.php','my-loans.php']) {
  const source=readFileSync('live-baseline/apps/hr-portal/'+file,'utf8');
  check(source.includes("$user['emp_id']") && /employee_id\s*=\s*\?/.test(source),'current live '+file+' scopes queries to HR session employee');
 }
 check(readFileSync('apps/operations/my-account.php','utf8').includes("current_role_key() !== 'owner_admin'"),'Settings health and repair remain owner-only');
} finally {await browser.close();}
