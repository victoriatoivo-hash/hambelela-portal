(()=>{
 const el=id=>document.getElementById(id),ticket=location.hash.slice(1);history.replaceState(null,'',location.pathname);
 const code=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('').toUpperCase().match(/.{4}/g).join('-');
 async function run(id,out,payload,done){const button=el(id);button.disabled=true;el(out).textContent='Working…';
  try{const r=await fetch('api.php',{method:'POST',credentials:'omit',referrerPolicy:'no-referrer',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const d=await r.json();if(!r.ok)throw Error(d.error||'Please retry.');done(d);}
  catch(e){el(out).textContent=e.message;button.disabled=false;}}
 el('send').onclick=()=>{if(!el('body').value.trim()){el('sent').textContent='Write a report first.';return;}
  el('receipt').textContent='Your private code: '+code+'. Save it now; it cannot be recovered.';
  run('send','sent',{action:'submit',ticket,code,body:el('body').value},()=>{el('body').value='';el('sent').textContent='Report received. Anyone with the code can check the reply. Keep it private.';});};
 let checked='',previous='',key='';
 el('code').oninput=()=>{el('continue').hidden=true;el('reply').textContent='';};
 el('check').onclick=()=>{const lookup=el('code').value;el('continue').hidden=true;run('check','reply',{action:'thread',code:lookup},d=>{el('check').disabled=false;if(lookup!==el('code').value)return;checked=lookup;el('reply').replaceChildren();for(const m of d.messages){const p=document.createElement('span');p.textContent=(m.side==='owner'?'Owner':'You')+' · '+(m.submitted_on||'Date not recorded')+' — '+m.body;el('reply').append(p,document.createElement('br'));}el('continue').hidden=false;});};
 el('add').onclick=()=>{const text=el('followup').value.trim();if(!text||checked!==el('code').value)return;if(text!==previous){key=crypto.randomUUID();previous=text;}run('add','reply',{action:'followup',code:checked,body:text,request_key:key},()=>{key='';previous='';el('followup').value='';el('add').disabled=false;el('check').click();});};
})();
