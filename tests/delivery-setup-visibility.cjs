const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const page=fs.readFileSync(require('node:path').join(__dirname,'../apps/delivery/driver-setup.php'),'utf8');
const script=page.match(/<script nonce="[^\n]+">([\s\S]*?)<\/script>/)[1];
function element(extra={}){return {value:'',type:'password',hidden:true,events:{},attrs:{},textContent:'',addEventListener(n,f){this.events[n]=f},setAttribute(n,v){this.attrs[n]=v},setCustomValidity(v){this.error=v},...extra};}
const els={'driver-secret':element(),'driver-confirmation':element(),'driver-setup-form':element(),'secret-feedback':element()};
const toggles=['driver-secret','driver-confirmation'].map(id=>element({dataset:{secretToggle:id}}));
const doc={hidden:false,events:{},getElementById:id=>els[id],querySelectorAll:()=>toggles,addEventListener(n,f){this.events[n]=f}};
vm.runInNewContext(script,{document:doc,window:{addEventListener(){}},TextEncoder});
toggles[0].events.click();assert.equal(els['driver-secret'].type,'text');assert.equal(toggles[0].textContent,'Hide');
toggles[0].events.click();assert.equal(els['driver-secret'].type,'password');
els['driver-secret'].value='short';els['driver-secret'].events.input();assert.match(els['driver-secret'].error,/16/);
els['driver-secret'].value='Synthetic-Only-Secret-123';els['driver-confirmation'].value='Different';els['driver-confirmation'].events.input();assert.match(els['driver-confirmation'].error,/match/);
els['driver-confirmation'].value=els['driver-secret'].value;els['driver-confirmation'].events.input();assert.equal(els['driver-confirmation'].error,'');assert.equal(els['secret-feedback'].textContent,'The secrets match.');
els['driver-secret'].value='€'.repeat(25);els['driver-secret'].events.input();assert.match(els['driver-secret'].error,/72/);
toggles[1].events.click();doc.hidden=true;doc.events.visibilitychange();assert.equal(els['driver-confirmation'].type,'password');
toggles[0].events.click();els['driver-setup-form'].events.submit();assert.equal(els['driver-secret'].type,'password');
assert.doesNotMatch(page,/value="<\?=[^\n]*\$_POST\['(?:secret|confirmation)'\]/);
console.log('PASS: Show/Hide, minimum length, byte limit, matching, auto-mask and no secret echo.');
