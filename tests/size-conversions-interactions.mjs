import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
// Execute the production controller against a small DOM/event harness. No live writes.
class Element {
  constructor(){this.value='';this.textContent='';this.dataset={};this.hidden=false;this.disabled=false;this.attrs={};this.handlers={};this.children={};this.classes=new Set();this.classList={add:k=>this.classes.add(k),remove:k=>this.classes.delete(k)};}
  querySelector(s){return this.children[s];}
  querySelectorAll(){return [];}
  setAttribute(k,v){this.attrs[k]=v;}
  removeAttribute(k){delete this.attrs[k];}
  addEventListener(k,fn){this.handlers[k]=fn;}
  focus(){document.activeElement=this;}
  getClientRects(){return [{}];}
  async fire(k,extra={}){await this.handlers[k]?.({preventDefault(){},currentTarget:this,target:this,...extra});}
}
const root=new Element(),drawer=new Element(),form=new Element(),backdrop=new Element(),add=new Element(),toast=new Element();
const document={body:{style:{overflow:''}},activeElement:add,querySelector(s){return {'[data-size-conversion-api]':root,'[data-conversion-drawer]':drawer,'[data-conversion-backdrop]':backdrop,'[data-size-conversion-add]':add,'[data-conversion-toast]':toast}[s];}};
const selectors=['[data-conversion-size]','[data-conversion-preview]','.size-conversions__drawer-title','.size-conversions__drawer-description','[data-conversion-error]','[data-conversion-save]','[data-conversion-discard]','[data-conversion-preview-label]','#scConvertedHelp','#scSizeHelp','[data-conversion-keep]','[data-conversion-close]','[data-conversion-cancel]','[data-conversion-discard-confirm]'];
selectors.forEach(s=>drawer.children[s]=new Element());drawer.children['[data-conversion-form]']=form;
form.elements=Object.fromEntries(['id','action','measurement_type','amount','unit'].map(k=>[k,new Element()]));form.reset=()=>Object.values(form.elements).forEach(e=>e.value='');
const volume=new Element(),weight=new Element();volume.dataset.conversionType='volume';weight.dataset.conversionType='weight';
drawer.querySelectorAll=s=>s==='[data-conversion-type]'?[volume,weight]:[volume,weight,...Object.values(drawer.children)].filter(e=>!e.hidden);
const initial={id:1,label:'500ml',measurement_type:'volume',quantity:500,unit:'ml',base_value:.5};
const row=new Element();row.dataset.conversion=JSON.stringify(initial);root.querySelectorAll=()=>[row];
const tables={volume:new Element(),weight:new Element()};root.querySelector=s=>s.includes('tbody')?(s.includes('volume')?tables.volume:tables.weight):add;
root.dataset={sizeConversionApi:'size-conversions-api.php',sizeConversionCsrf:'test-csrf'};
let calls=[],responseError='',stored=[initial],resolveFetch=null;
async function fetch(url,options){
  const body=JSON.parse(options.body);calls.push(body);assert.equal(options.headers['X-CW-CSRF'],'test-csrf');
  if(resolveFetch)await new Promise(resolve=>resolveFetch=resolve);
  if(responseError)return {ok:false,json:async()=>({ok:false,error:responseError})};
  const conversion={id:body.id?Number(body.id):stored.length+1,label:body.amount+body.unit,measurement_type:body.measurement_type,quantity:Number(body.amount),unit:body.unit,base_value:['ml','g'].includes(body.unit)?Number(body.amount)/1000:Number(body.amount)};
  stored=[...stored.filter(r=>r.id!==conversion.id),conversion];
  return {ok:true,json:async()=>({ok:true,conversion,conversions:stored})};
}
vm.runInNewContext(fs.readFileSync('assets/js/size-conversions.js','utf8'),{document,fetch,requestAnimationFrame:fn=>fn(),setTimeout:()=>1,clearTimeout(){}});
const get=s=>drawer.children[s],size=get('[data-conversion-size]'),preview=get('[data-conversion-preview]'),save=get('[data-conversion-save]'),error=get('[data-conversion-error]');
await add.fire('click');assert.equal(drawer.attrs['aria-hidden'],'false');assert.equal(document.body.style.overflow,'hidden');
for(const [text,type,value] of [['10ml',volume,'.01'],['50ml',volume,'.05'],['100ml',volume,'.1'],['250ml',volume,'.25'],['500ml',volume,'.5'],['1L',volume,'1'],['5L',volume,'5'],['50g',weight,'.05'],['100g',weight,'.1'],['250g',weight,'.25'],['500g',weight,'.5'],['750g',weight,'.75'],['1kg',weight,'1'],['1.5kg',weight,'1.5']]){
  await type.fire('click');size.value=text;await size.fire('input');assert.equal(Number(preview.value),Number(value));
}
for(const text of ['-1kg','0g','abc','1L','NaNkg']){size.value=text;await form.fire('submit');assert.ok(error.textContent);assert.equal(calls.length,0);}
size.value='1.5kg';await size.fire('input');await backdrop.fire('click');assert.equal(get('[data-conversion-discard]').hidden,false);assert.equal(drawer.attrs['aria-hidden'],'false');
await get('[data-conversion-keep]').fire('click');assert.equal(size.value,'1.5kg');
await drawer.fire('keydown',{key:'Escape'});await get('[data-conversion-discard-confirm]').fire('click');assert.equal(drawer.attrs['aria-hidden'],'true');assert.equal(document.body.style.overflow,'');
await add.fire('click');size.value='333ml';await size.fire('input');await form.fire('submit');assert.equal(drawer.attrs['aria-hidden'],'true');assert.match(tables.volume.innerHTML,/333ml/);assert.equal(calls.at(-1).amount,'333');assert.equal(calls.at(-1).unit,'ml');
await add.fire('click');await weight.fire('click');size.value='333g';await form.fire('submit');assert.match(tables.weight.innerHTML,/333g/);
const edit=new Element();edit.dataset.conversionEdit='1';await root.fire('click',{target:{closest:()=>edit}});assert.equal(size.value,'500ml');assert.equal(form.elements.action.value,'update');assert.equal(save.textContent,'Save Changes');
size.value='501ml';await form.fire('submit');assert.equal(calls.at(-1).id,1);assert.match(tables.volume.innerHTML,/501ml/);
await add.fire('click');size.value='501ml';responseError='This size conversion already exists.';await form.fire('submit');assert.equal(error.textContent,responseError);assert.equal(size.value,'501ml');assert.equal(drawer.attrs['aria-hidden'],'false');assert.equal(save.disabled,false);
responseError='';resolveFetch=true;const pending=form.fire('submit');const before=calls.length;await form.fire('submit');assert.equal(calls.length,before);assert.equal(save.disabled,true);await backdrop.fire('click');assert.equal(drawer.attrs['aria-hidden'],'false');resolveFetch();await pending;
console.log('Size Conversions: 14 conversions, validation, Add/Edit, failure preservation, discard, backdrop/Escape, save locking and returned-table refresh passed');
