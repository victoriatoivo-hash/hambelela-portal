// Loopback only: run the actual PHP form and session code without portal credentials.
// Only the database and the destination page are fixtures. No production accounts.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn,execFileSync} = require('node:child_process');
const {once} = require('node:events');
const root = path.resolve(__dirname, '..');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'first-signin-'));
const site = path.join(dir, 'site');
let server;
(async () => {
  for (const sub of ['shared','logs','assets/css']) fs.mkdirSync(path.join(site,sub),{recursive:true});
  for (const f of ['auth','employee-device-session','temporary-access-codes','login-security'])
    fs.copyFileSync(path.join(root,'shared',f+'.php'),path.join(site,'shared',f+'.php'));
  if(process.env.LOGIN_TEST_PREVIOUS==='1'){
    fs.writeFileSync(path.join(site,'login.php'),execFileSync('git',['show','c7bd45da:login.php'],{cwd:root}));
  } else fs.copyFileSync(path.join(root,'login.php'),path.join(site,'login.php'));
  fs.copyFileSync(path.join(root,'assets/css/login-olive.css'),path.join(site,'assets/css/login-olive.css'));
  fs.writeFileSync(path.join(site,'config.php'),"<?php define('BASE_PATH',__DIR__); define('BASE_URL',''); define('APP_NAME','Fixture'); date_default_timezone_set('Africa/Windhoek');");
  fs.writeFileSync(path.join(site,'shared/database.php'),`<?php
function db(): PDO {
  static $db;
  if ($db) return $db;
  $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $db->sqliteCreateFunction('FIELD',function(...$values){return 0;},-1);
  $db->sqliteCreateFunction('DATABASE',function(){return 'fixture';},0);
  $db->exec("ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.COLUMNS(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME);
    CREATE TABLE ops_roles(id,role_key,name);
    INSERT INTO ops_roles VALUES (1,'owner_admin','Fixture');
    CREATE TABLE ops_employees(id INTEGER,full_name,email,password_hash,role_id,status);
    CREATE TABLE ops_login_events(employee_id, employee_name,role_key,source,ip_address,user_agent)");
  $db->prepare("INSERT INTO ops_employees VALUES(1,'Fixture Staff','fixture@example.invalid',?,1,'active')")->execute([password_hash('fixture-only-code',PASSWORD_DEFAULT)]);
  return $db;
}`);
  fs.writeFileSync(path.join(site,'index.php'),"<?php require __DIR__.'/config.php'; require __DIR__.'/shared/auth.php'; echo portal_validate_authenticated_session() ? 'signed in' : 'guest';");
  const probe=net.createServer(); probe.listen(0,'127.0.0.1'); await once(probe,'listening');
  const port=probe.address().port; await new Promise(resolve=>probe.close(resolve));
  const args=process.env.PHP_FIXTURE_EXT ? ['-d','extension_dir='+process.env.PHP_FIXTURE_EXT,'-d','extension=pdo_sqlite'] : [];
  server=spawn(process.env.PHP_BIN || 'php',[...args,'-d','display_errors=0','-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t',site],{stdio:['ignore','ignore','pipe'],windowsHide:true});
  server.stderr.resume();
  const origin='http://127.0.0.1:'+port;
  let ready=false;
  for (let n=0;n<60;n++) {try{await fetch(origin+'/login.php'); ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}
  assert.ok(ready,'loopback PHP started');
  for (const entry of ['/login.php?action=logout','/login.php','/login.php?reason=session_expired&return=%2Findex.php%3Ffrom%3Dorders']) {
    const cookies=new Map();
    async function request(route, data) {
      const r=await fetch(origin+route,{redirect:'manual',method:data?'POST':'GET',headers:{cookie:[...cookies].map(([k,v])=>k+'='+v).join('; ')},body:data && new URLSearchParams(data)});
      for(const c of r.headers.getSetCookie()){const [k,v]=c.split(';')[0].split('=');cookies.set(k,v);}
      return r;
    }
    let r=await request(entry);
    let formUrl=entry;
    if(r.status===303){formUrl=r.headers.get('location');assert.equal(formUrl,'/login.php');r=await request(formUrl);}
    const html=await r.text();
    assert.match(html,/data-value="db:1"/);
    const action=html.match(/<form[^>]*id="login-form"[^>]*action="([^"]+)"/)?.[1] || formUrl;
    r=await request(action,{identity:'db:1',code:'fixture-only-code'});assert.equal(r.status,303,'first valid submit redirects');
    const next=r.headers.get('location');assert.equal(next,entry.includes('return=')?'/index.php?from=orders':'/index.php');
    assert.equal(await (await request(next)).text(),'signed in','rotated session survives redirect');
    assert.equal(action,entry.includes('return=')?'/login.php?return=%2Findex.php%3Ffrom%3Dorders':'/login.php');
    await request('/login.php?action=logout');assert.equal(await (await request('/index.php')).text(),'guest');
    r=await request(action,{identity:'db:1',code:'wrong-fixture-code'});assert.equal(r.status,200);assert.match(await r.text(),/Unable to sign in\. Check your details/);
    assert.equal(await (await request('/index.php')).text(),'guest');
    console.log('PASS first submit, session continuity, logout and invalid credentials:',entry);
  }
  if(process.env.LOGIN_TEST_CHROME) {
    const browser=await require('playwright').chromium.launch({executablePath:process.env.LOGIN_TEST_CHROME,headless:true});
    try {
      for(const width of [375,768,1440]){
        const ctx=await browser.newContext({viewport:{width,height:900},hasTouch:width<1000});
        try {
          const page=await ctx.newPage();
          await page.goto(origin+'/login.php?action=logout');
          const activate=async locator=>width<1000?locator.tap():locator.click();
          await activate(page.locator('.portal-custom-select-trigger'));
          await page.getByRole('searchbox').fill('Fixture');
          await activate(page.getByRole('option',{name:/Fixture Staff/}));
          await page.locator('#code').fill('fixture-only-code');
          await page.evaluate(()=>dispatchEvent(new PageTransitionEvent('pageshow',{persisted:false})));
          assert.equal(await page.locator('#code').inputValue(),'fixture-only-code');
          await Promise.all([page.waitForURL(origin+'/index.php'),activate(page.getByRole('button',{name:'Continue'}))]);
          assert.equal(await page.locator('body').innerText(),'signed in');
          console.log('PASS first browser sign-in at '+width+'px');
        } finally {await ctx.close();}
      }
    } finally {await browser.close();}
  }
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{
  if(server && server.exitCode===null){const done=once(server,'exit');server.kill();await done;}
  fs.rmSync(dir,{recursive:true,force:true});
});
