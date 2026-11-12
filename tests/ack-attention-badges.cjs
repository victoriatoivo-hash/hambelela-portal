const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const turn=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const html='<a class="ack-contact-launch" aria-label="Chat"></a><a class="ess-nav-item" href="/apps/acknowledgments/index.php">My instructions</a><a class="ess-module-card" href="/apps/acknowledgments/index.php">My instructions</a><b id="bell">4</b>';
 const dom=new JSDOM(html,{url:'https://portal.test/',pretendToBeVisual:true,runScripts:'dangerously'});
 try{const w=dom.window,d=w.document;let data={pending:2,attention:{private:1,reports:0}};
 w.fetch=async()=>({ok:true,headers:{get:()=>'application/json'},json:async()=>data});
 const script=d.createElement('script');script.textContent=fs.readFileSync('assets/js/acknowledgments-popup.js','utf8');d.body.append(script);
 const badges=()=>[...d.querySelectorAll('[data-ack-count]')].map(b=>b.hidden?'-':b.textContent);
 await turn();assert.deepEqual(badges(),['1','3','3']);
 const update=async()=>{w.dispatchEvent(new w.Event('ack:attention-changed'));await turn();};
 await update();assert.deepEqual(badges(),['1','3','3']);data={pending:2,attention:{private:0,reports:0}};await update();assert.deepEqual(badges(),['-','2','2']);
 data={pending:0,attention:{private:0,reports:0}};await update();assert.deepEqual(badges(),['-','-','-']);assert.equal(d.querySelector('#bell').textContent,'4');
 }finally{dom.window.close();}
 console.log('PASS: chat, sidebar and dashboard attention counts, deferral persistence, empty state; notification bell unchanged.');
})().catch(e=>{console.error(e);process.exitCode=1;});
