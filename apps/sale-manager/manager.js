'use strict';
const el = id => document.getElementById(id);
const state = {rules:{}, rows:[], include:new Set(), exclude:new Set(), id:'', revision:0, ready:false};
const money = cents => 'N$'+(cents/100).toFixed(2);
const node = (tag,txt) => { const n=document.createElement(tag); n.textContent=txt; return n; };
function button(label,action) {
    const b=node('button',label); b.type='button'; b.onclick=action; return b;
}
async function api(action, data) {
    const options = data ? {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf"]').content},body:JSON.stringify(data)} : {};
    const response=await fetch('?action='+action,options);
    let result;
    try { result=await response.json(); } catch { throw new Error('The portal session or shop connection expired. Reload to reconnect.'); }
    if (!response.ok) throw new Error(result.error || 'Shop request failed.');
    return result;
}
function localDate(stamp) {
    if (!stamp) return '';
    const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(stamp*1000);
    const read=type=>parts.find(x=>x.type===type).value;
    return read('year')+'-'+read('month')+'-'+read('day')+'T'+read('hour')+':'+read('minute');
}
function status(r) {
    const now=Date.now()/1000;
    if (!r.enabled) return 'Draft / paused';
    if (r.until && r.until<=now) return 'Ended';
    return r.from>now ? 'Scheduled' : 'Active';
}
function list() {
    const out=el('campaigns'); out.replaceChildren();
    for (const [id,r] of Object.entries(state.rules)) out.append(button(r.name+' · '+status(r),()=>openRule(id)));
    if (!out.children.length) out.textContent='No saved campaigns.';
}
function openRule(id) {
    const r=state.rules[id];
    state.id=id || Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
    state.revision=r?.revision || 0;
    state.include=new Set(r?.include); state.exclude=new Set(r?.exclude);
    el('name').value=r?.name || '';
    el('kind').value=r?.kind || 'percent'; el('amount').value=r ? r.value/100 : '';
    el('quantity').value=r?.kind==='bundle' ? r.quantity : 2;
    el('scope').value=r?.scope || 'selected'; el('stack').checked=!!r?.stack;
    el('start').value=localDate(r?.from); el('end').value=localDate(r?.until);
    for(const o of el('categories').options) o.selected=!!r?.categories.includes(Number(o.value));
    el('draft').textContent=r?.enabled ? 'Pause sale' : 'Save draft';
    el('publish').textContent=r?.enabled ? 'Update published sale' : 'Publish sale';
    el('search').value=''; render();
}
function selection() {
    return Array.from(el('categories').selectedOptions,o=>Number(o.value));
}
function toggle(id,mode) {
    const selected=state[mode], other=state[mode==='include'?'exclude':'include'];
    if(selected.has(id)) selected.delete(id);
    else { other.delete(id); selected.add(id); }
    render();
}
function offerCents(kind, regular, value) {
    const cents = kind === 'percent' ? Math.round(regular * (10000 - value) / 10000) : value;
    return Math.ceil(cents / 100) * 100;
}
function render(){
    const scope=el('scope').value, kind=el('kind').value;
    el('categoryBox').hidden=scope!=='category'; el('bundle').hidden=kind!=='bundle';
    el('unit').textContent=kind==='percent'?'Percentage off':kind==='bundle'?'Bundle total (N$)':'Sale price (N$)';
    el('amount').max=kind==='percent'?100:9999999;
    const choices=el('choices'); choices.replaceChildren();
    const search=el('search').value.toLowerCase().trim();
    if(search) for(const row of state.rows.filter(r=>r.name.toLowerCase().includes(search)||String(r.id)===search)){
        const line=node('div',''); line.className='choice'; line.append(node('span',row.name+(row.group?' · all sizes':'')));
        for(const mode of ['include','exclude']) line.append(button(state[mode].has(row.id)?'Undo '+mode:mode==='include'?'Include':'Exclude',()=>toggle(row.id,mode)));
        choices.append(line);
    }
    const picks=el('picks'); picks.replaceChildren();
    for(const mode of ['include','exclude']) for(const id of state[mode]){
        const name=state.rows.find(r=>r.id===id)?.name || '#'+id;
        picks.append(button((mode==='include'?'Included · ':'Excluded · ')+name+' ×',()=>toggle(id,mode)));
    }
    const cats=selection(), value=Math.round(Number(el('amount').value)*100), qty=kind==='bundle'?Number(el('quantity').value):1;
    const tbody=el('preview'); tbody.replaceChildren();
    let eligible=0, reduced=0;
    for(const row of state.rows){
        if(row.group || [row.id,row.parent].some(id=>state.exclude.has(id))) continue;
        if(!(scope==='all' || [row.id,row.parent].some(id=>state.include.has(id)) || (scope==='category' && cats.some(c=>row.categories.includes(c))))) continue;
        eligible++;
        const current=row.base*qty;
        const total=value>0 && qty>=1 && row.regular>0 ? Math.min(current, offerCents(kind, row.regular, value)) : current;
        if(total>=0 && total<current) reduced++;
        const tr=node('tr','');
        tr.append(node('td',row.name),node('td',money(current)),node('td',value>0 && total>=0 ? money(total):'—')); tbody.append(tr);
    }
    el('summary').textContent=eligible+' matched · '+reduced+' reduced by this offer'+(kind==='bundle'?' · totals for '+qty+' of each size':'')+'. A better existing basket offer takes priority.';
    return reduced;
}
el('editor').addEventListener('input',render);
el('new').onclick=()=>{if(state.ready) openRule(null);};
el('editor').onsubmit=async event=>{
    event.preventDefault(); if(!state.ready) return;
    const enabled=event.submitter?.value==='publish';
    if(enabled && !render()){el('message').textContent='Choose an offer that reduces at least one product price.'; return;}
    const payload={id:state.id,revision:state.revision,name:el('name').value,kind:el('kind').value,amount:el('amount').value,quantity:el('quantity').value,
        scope:el('scope').value,categories:selection(),include:[...state.include],exclude:[...state.exclude],start:el('start').value,end:el('end').value,stack:el('stack').checked,enabled};
    el('fields').disabled=true; state.ready=false; el('message').textContent='Saving campaign…';
    try{
        const result=await api('save',payload);
        state.revision=result.revision;
        state.rules=(await api('list')).campaigns;
        list();openRule(result.id);el('message').textContent='Saved · '+status(state.rules[result.id]);
    }catch(err){el('message').textContent=err.message;}
    finally {el('fields').disabled=false;state.ready=true;}
};
(async()=>{
    try{
        state.rules=(await api('list')).campaigns;
        let cursor=0;
        do {
            const page=await api('catalog&after='+cursor);
            if(page.categories) for(const cat of page.categories){const o=node('option',cat.name);o.value=cat.id;el('categories').append(o);}
            state.rows.push(...page.items);cursor=page.after;
            el('message').textContent='Loading catalogue · '+state.rows.length;
        }while(cursor);
        el('fields').disabled=false; state.ready=true;list();openRule(null);
        el('message').textContent='Connected to WooCommerce · '+state.rows.filter(r=>!r.group).length+' products and sizes';
    }catch(err){el('message').textContent='Sale Manager could not connect. '+err.message;}
})();
