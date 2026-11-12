const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const read=p=>fs.readFileSync(p,'utf8'), template=read('apps/acknowledgments/index.php').split('?><main')[1].split('</main>')[0];
(async()=>{for(const owner of [false,true]){
 const markup=('<main'+template+'</main>').replace(/<\?php if\(\$isManager\): \?>([\s\S]*?)<\?php endif; \?>/g,(_,s)=>owner?s:'').replace(/<\?[\s\S]*?\?>/g,'');
 const dom=new JSDOM(markup,{url:'https://example.test/index.php',runScripts:'outside-only',pretendToBeVisual:true});
 try{const w=dom.window,q=s=>w.document.querySelector(s);w.fetch=async()=>({ok:true,headers:{get:()=>'application/json'},json:async()=>({owner,records:[{title:'Test instruction',employee_name:'Test employee',release_state:'released',id:1,status:'pending'}]})});
 w.eval(read('assets/js/acknowledgments.js'));await new Promise(r=>setImmediate(r));
 assert.ok(q('[data-view]'));assert.equal(!!q('#ack-new'),owner);assert.equal(!!q('[data-tab="Scheduled"]'),owner);
 assert.equal(!!q('[data-record-action="edit"]'),owner);assert.equal(!!q('[data-record-action="delete"]'),owner);
 assert.equal(!!q('td[data-label="Employee"]'),owner);assert.equal(!!q('td[data-label="Scheduled For"]'),owner);
 assert.equal(q('tbody tr').children.length,w.document.querySelectorAll('th').length);
 }finally{dom.window.close()}
}console.log('PASS: employee instructions and Owner management render distinct controls and columns.');})().catch(e=>{console.error(e);process.exitCode=1});
