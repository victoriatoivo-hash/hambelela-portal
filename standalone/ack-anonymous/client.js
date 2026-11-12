(()=>{
 const el=id=>document.getElementById(id),ticket=location.hash.slice(1);history.replaceState(null,'',location.pathname);
 const code=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('').toUpperCase().match(/.{4}/g).join('-');
 async function run(id,out,payload,done){const button=el(id);button.disabled=true;el(out).textContent='Working…';
  try{const r=await fetch('api.php',{method:'POST',credentials:'omit',referrerPolicy:'no-referrer',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const d=await r.json();if(!r.ok)throw Error(d.error||'Please retry.');done(d);}
  catch(e){el(out).textContent=e.message;button.disabled=false;}}
 el('send').onclick=()=>{if(!el('body').value.trim()){el('sent').textContent='Write a report first.';return;}
  el('receipt').textContent='Your private code: '+code+'. Save it now; it cannot be recovered.';
  run('send','sent',{action:'submit',ticket,code,body:el('body').value},()=>{el('body').value='';el('sent').textContent='Report received. Anyone with the code can check the reply. Keep it private.';});};
 el('check').onclick=()=>run('check','reply',{action:'check',code:el('code').value},d=>{el('reply').textContent=d.reply??'No reply yet.';el('check').disabled=false;});
})();
