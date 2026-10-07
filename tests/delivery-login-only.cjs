const assert=require('node:assert/strict');
const base='http://127.0.0.1:8822/apps/delivery/';const cookies=new Map();
async function req(path,options={}){const r=await fetch(base+path,{...options,redirect:'manual',headers:{Cookie:[...cookies].map(([k,v])=>`${k}=${v}`).join('; '),...options.headers}});for(const c of r.headers.getSetCookie()){const p=c.split(';')[0],i=p.indexOf('=');cookies.set(p.slice(0,i),p.slice(i+1));}return r;}
(async()=>{
 assert.equal((await req('index.php')).status,503);
 assert.equal((await req('driver/api.php')).status,503);
 let r=await req('driver/login.php');assert.equal(r.status,200);const html=await r.text();const csrf=html.match(/name="csrf" value="([^"]+)"/)[1];
 r=await req('driver/login.php',{method:'POST',body:new URLSearchParams({csrf,mobile:'+264810000002',secret:'Synthetic-Release-Driver-42'})});assert.equal(r.status,302);
 r=await req('driver/index.php');assert.equal(r.status,200);assert.match(await r.text(),/Sign-in verified/);
 assert.equal((await req('driver/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:'{"action":"collect"}'})).status,503);
 console.log('PASS: synthetic login succeeds; Front, Driver data and mutations remain disabled.');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
