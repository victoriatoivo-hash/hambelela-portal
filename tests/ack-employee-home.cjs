// Render the real PHP entry points against a synthetic role. No portal data.
'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom'),dir=fs.mkdtempSync(path.join(os.tmpdir(),'ack-home-'));
const put=(file,body)=>{const p=path.join(dir,file);fs.mkdirSync(path.dirname(p),{recursive:true});fs.writeFileSync(p,body);};
async function run(){
 for(const p of ['apps/acknowledgments/index.php','apps/acknowledgments/messages.php','assets/css/acknowledgments.css','assets/js/acknowledgments.js','assets/js/ack-inbox.js'])put(p,fs.readFileSync(p));
 put('config.php',"<?php define('BASE_PATH',__DIR__);define('BASE_URL','');define('APP_NAME','TEST');");
 put('shared/auth.php',"<?php function require_login(){} function db(){return null;} function current_user(){return ['id'=>7,'role_key'=>getenv('TEST_ROLE')];}");
 put('shared/acknowledgments/integration.php',"<?php namespace Hambelela\\Acknowledgments; class Service{public function actor($id){return \\current_user();}}");
 put('shared/acknowledgments/AnonymousAccess.php',"<?php namespace Hambelela\\Acknowledgments; class AnonymousAccess{public static function url($host){return '';}}");
 for(const f of ['shared/header.php','shared/sidebar.php','shared/footer.php'])put(f,'');
 const render=(role,record='',entry='index')=>cp.execFileSync('php',['-r',"$_GET=['record'=>getenv('TEST_RECORD')];require 'apps/acknowledgments/"+entry+".php';"],{cwd:dir,encoding:'utf8',env:{...process.env,TEST_ROLE:role,TEST_RECORD:record}});
 for(const role of ['packer','delivery_driver','marketing_sales_assistant']){
  for(const entry of ['index','messages']){
   const d=new JSDOM(render(role,'',entry)).window.document;
   assert.deepEqual([...d.querySelectorAll('main section h2')].map(h=>h.textContent),['Private assistance','Send without my name']);
   assert.match(d.querySelector('#anonymous').textContent,/Hosting logs may (?:also )?record connection details/);
   assert.equal(d.querySelector('#ack-new,#ack-table,#ack-tabs,#ack-drawer,.ack-contact-back'),null);
  }
 }
 const manager=new JSDOM(render('owner_admin')).window.document;assert.ok(manager.querySelector('#ack-tabs'));assert.ok(manager.querySelector('#ack-new'));
 assert.ok(manager.querySelector('#ack-type-filter'));assert.equal(manager.querySelector('#ack-private-open'),null);
 assert.equal(render('owner_admin','','messages'),'','Old Admin route does not render a second inbox');
 const inboxDom=new JSDOM(render('packer'),{url:'https://portal.test/apps/acknowledgments/messages.php',runScripts:'outside-only'});
 try{const w=inboxDom.window,d=w.document,messages=[{id:11,topic_id:11,sender_id:7,body:'First request'},{id:20,topic_id:20,sender_id:7,body:'Second request'}];let posted;
 w.fetch=async(url,options)=>{if(options?.method==='POST')posted=JSON.parse(options.body);return {ok:true,json:async()=>options?.method==='POST'?{ok:true}:{owner:false,employee:7,messages,csrf:'test'}};};
 w.eval(fs.readFileSync('assets/js/ack-inbox.js','utf8'));d.querySelector('#ack-private-open').click();await new Promise(r=>setImmediate(r));
 [...d.querySelectorAll('#ack-private button')].find(b=>b.textContent==='Reply to this conversation').click();const form=d.querySelector('#ack-private form');form.querySelector('textarea').value='Answer to first request';form.dispatchEvent(new w.Event('submit',{cancelable:true}));await new Promise(r=>setImmediate(r));assert.equal(posted.topic,11);assert.equal(posted.new_topic,false);
 }finally{inboxDom.window.close();}
 const bad=new JSDOM(render('packer','oops')).window.document;assert.equal(bad.querySelector('#ack-drawer'),null);
 for(const allowed of [true,false]){
 const dom=new JSDOM(render('packer','47'),{url:'https://portal.test/apps/acknowledgments/index.php?record=47',runScripts:'outside-only'});
 try{const w=dom.window,d=w.document,urls=[];w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','')};w.HTMLDialogElement.prototype.close=function(){this.removeAttribute('open')};
 w.AckCommunication={controls(){}};
 w.fetch=async url=>{urls.push(url);return {ok:allowed,headers:{get:()=>'application/json'},json:async()=>allowed?{id:47,employee_id:7,title:'Assigned notice',release_state:'released',version:1,events:[],message_html:'Read me'}:{error:'This instruction is not assigned to you.'}};};
 w.eval(fs.readFileSync('assets/js/acknowledgments.js','utf8'));await new Promise(r=>setImmediate(r));
 assert.deepEqual(urls,['api.php?action=detail&id=47']);assert.equal(d.querySelector('#ack-tabs,#ack-table'),null);
 if(allowed){assert.ok(d.querySelector('dialog[open] #ack-understood'));d.querySelector('[data-ack-close]').click();assert.equal(d.querySelector('dialog[open]'),null);assert.ok(d.querySelector('#ack-private-open'));}
 else{assert.equal(d.querySelector('dialog[open]'),null);assert.match(d.querySelector('#ack-message').textContent,/not assigned/);}
 }finally{dom.window.close();}
 }
 console.log('PASS employee messaging-only home, direct inbox, Owner register, authorized notice detail and denied link.');
}
run().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>fs.rmSync(dir,{recursive:true,force:true}));
