const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const login=fs.readFileSync('login.php','utf8'), script=login.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
const form=login.slice(login.indexOf('<form method="post"'),login.indexOf('</form>')+7)
  .replace(/<\?php foreach \(\$loginEmployees[\s\S]*?<\?php endforeach; \?>/,
    ['A','B','C','D','E','F','G','Hope Kahuika','Z'].map((n,i)=>'<button type="button" class="portal-custom-select-option" data-value="db:'+i+'"><span class="login-option-name">'+n+'</span></button>').join(''))
  .replace(/<\?[\s\S]*?\?>/g,'');
const {window:w}=new JSDOM(form,{runScripts:'outside-only'});
w.eval(script);
const q=s=>w.document.querySelector(s), type=v=>{q('[type=search]').value=v;q('[type=search]').dispatchEvent(new w.Event('input'));};
q('.portal-custom-select-trigger').click();assert.equal(w.document.activeElement,q('[type=search]'));
type('kah');assert.equal(q('.portal-custom-select-option:not([hidden])').textContent,'Hope Kahuika');
type('nomatch');assert.equal(q('[data-no-profiles]').hidden,false);type('hOp');
q('[type=search]').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',cancelable:true}));
assert.equal(q('#identity').value,'db:7');assert.equal(q('.portal-custom-select-value').textContent,'Hope Kahuika');
q('.portal-custom-select-trigger').click();assert.equal(w.document.querySelectorAll('.portal-custom-select-option:not([hidden])').length,9);
const presence=fs.readFileSync('assets/js/portal-presence.js','utf8');
async function exercise(path,status,payload,edit=false){
  const dom=new JSDOM('<section data-portal-header-status data-presence-endpoint="/heartbeat">'+
    ['date','time','online-widget','online-avatars','online-count','online-popover','online-list'].map(n=>'<span data-portal-'+n+'></span>').join('')+'</section><textarea></textarea>',{pretendToBeVisual:true});
  const win=dom.window, redirects=[], timers=[];win.setInterval=f=>{timers.push(f);};
  const context={window:win,document:win.document,requestAnimationFrame:fn=>fn(),URL,Intl,Date,location:{origin:'https://portal.example',pathname:path,search:'',replace:u=>redirects.push(u)},
    fetch:async()=>({ok:status===200,status,json:async()=>payload})};
  vm.runInNewContext(presence,context);
  if(edit)win.document.querySelector('textarea').dispatchEvent(new win.Event('input',{bubbles:true}));
  vm.runInNewContext(presence,context);await new Promise(setImmediate);await timers[1]();
  assert.equal(timers.length,2);win.close();return redirects;
}
(async()=>{
  const resume={ok:true,resume_dashboard:true,dashboard:'/index.php',employees:[]};
  for(const p of ['/index.php','/'])assert.deepEqual(await exercise(p,200,resume),[]);
  assert.deepEqual(await exercise('/apps/marketing/index.php',200,resume),['https://portal.example/index.php']);
  assert.deepEqual(await exercise('/apps/marketing/index.php',200,resume,true),[]);
  assert.deepEqual(await exercise('/apps/marketing/index.php',200,{...resume,resume_dashboard:false}),[]);
  assert.equal((await exercise('/index.php',401,{resume_dashboard:true,dashboard:'/login.php'}))[0],'https://portal.example/login.php?return=%2Findex.php');
  console.log('PASS: searchable ninth-option list, saved identity, duplicate handler, same-dashboard resume, genuine return, edits and expired session.');
})().catch(e=>{console.error(e);process.exitCode=1;});
