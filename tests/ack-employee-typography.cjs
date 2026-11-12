// Render the actual messaging page and composer with synthetic employee data.
const assert=require('node:assert/strict'),fs=require('node:fs'),puppeteer=require('puppeteer-core');
const read=p=>fs.readFileSync(p,'utf8');
const php=read('apps/acknowledgments/messages.php');
const markup=php.slice(php.indexOf('<main'),php.indexOf('</main>')+7)
 .replace(/<\?php if\(\$anon\):[\s\S]*?<\?php else: \?>/,'').replace(/<\?php endif; \?>/g,'');
const css=['portal','portal-responsive','acknowledgments','ess-dashboard','ack-communication'].map(n=>read('assets/css/'+n+'.css')).join('\n');
(async()=>{
 const b=await puppeteer.launch({executablePath:process.env.CHROME_BIN||'/usr/bin/google-chrome',args:['--no-sandbox']});
 try{for(const width of [1280,375]){
  const p=await b.newPage();await p.setViewport({width,height:800});
  await p.setRequestInterception(true);p.on('request',r=>r.isNavigationRequest()?r.respond({status:200,contentType:'text/html',body:'<!doctype html>'}):r.abort());
  await p.goto('https://portal.example/messages');await p.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><body class="ess-dashboard">'+markup+'</body>');
  await p.evaluate(()=>{window.testOwner=false;window.fetch=async()=>({ok:true,json:async()=>({owner:window.testOwner,employee:2,threads:[],reports:[],messages:[{sender_id:2,body:'Question',created_at:'10:00'},{sender_id:1,body:'Reply',created_at:'10:01'}]})});});
  await p.addScriptTag({content:read('assets/js/ack-inbox.js')});await p.click('#ack-private-open');await p.type('textarea','Sample message');
  const style=sel=>p.$eval(sel,e=>{const s=getComputedStyle(e);return [s.fontFamily.split(',')[0],s.fontSize,s.fontWeight]});
  assert.deepEqual(await style('textarea'),['Jost',width<700?'16px':'12px','400']);
  assert.deepEqual(await style('h1'),['Jost','24px','600']);assert.deepEqual(await style('h2'),['Jost','15px','600']);
  assert.deepEqual(await style('form button'),['Jost','11.5px','500']);
  for(const owner of [false,true]){
   await p.evaluate(v=>window.testOwner=v,owner);await p.click('#ack-private-open');
   await p.waitForSelector('#ack-private [data-sender="owner"]');
   const result=await p.evaluate(()=>{const box=document.querySelector('#ack-private').getBoundingClientRect();
    return [...document.querySelectorAll('#ack-private [data-sender]')].map(e=>{const r=e.getBoundingClientRect();return [e.dataset.sender,Math.round(r.left-box.left),Math.round(box.right-r.right),getComputedStyle(e).backgroundColor];});});
   assert.equal(result[0][1],0);assert.equal(result[1][2],0);assert.ok(result[0][2]>5 && result[1][1]>5);
   assert.equal(result[0][3],'rgb(242, 245, 238)');assert.equal(result[1][3],'rgb(234, 242, 250)');
  }
  await p.close();
  console.log('PASS employee message typography at',width);
 }}finally{await b.close()}
})().catch(e=>{console.error(e);process.exitCode=1;});
