// Loopback-only UI fixtures. Never deploy; no database or live requests.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
const root=process.cwd();let response=null;
const record={id:101,employee_id:2,employee_name:'Test Employee',title:'Packing instructions',message_html:'<p>Please check each label before packing.</p><ol><li>Confirm the item and quantity.</li><li>Ask for clarification if anything is unclear.</li></ol>',required:1,version:1,created_at:'2026-10-12 09:00:00',deadline:'2026-10-14 16:30:00',status:'pending',events:[{actor_name:'Test Employee',event_type:'clarify',created_at:'2026-10-12 09:10:00',message:'Which label should I use?'},{actor_name:'Owner',event_type:'reply',created_at:'2026-10-12 09:12:00',message:'Please use the approved product label.'}]};
http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname.endsWith('/api.php')){res.setHeader('Content-Type','application/json');let body='';for await(const chunk of req)body+=chunk;
 if(req.method==='POST'){response=JSON.parse(body);if(response.action==='acknowledge'&&!response.understood){res.statusCode=403;res.end(JSON.stringify({error:'Read and understood is required.'}));return;}res.end(JSON.stringify({ok:true}));return;}
 res.end(JSON.stringify(url.searchParams.get('action')==='popup'?{notice:record,pending:1,session:'fixture'}:url.searchParams.get('action')==='detail'?record:{owner:true,employee_id:2,csrf:'fixture',records:[record],employees:[{id:2,full_name:'Test Employee'},{id:3,full_name:'Test Driver'}]}));return;}
 if(url.pathname==='/result'){res.end(JSON.stringify(response));return;}
 if(url.pathname.startsWith('/assets/')){const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(path.join(root,'assets')+path.sep)||!fs.existsSync(file)){res.statusCode=404;res.end();return;}res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'font/woff2');res.end(fs.readFileSync(file));return;}
 const source=fs.readFileSync('apps/acknowledgments/index.php','utf8');const main=source.slice(source.indexOf('?'+'>')+2,source.indexOf('<script src='));
 res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/portal.css"><link rel="stylesheet" href="/assets/css/portal-date-picker.css"><link rel="stylesheet" href="/assets/css/acknowledgments.css"></head><body>'+(url.pathname==='/popup'?'<h1>Test employee portal</h1><script src="/assets/js/acknowledgments-popup.js"></script>':main+'<script src="/assets/js/acknowledgments.js"></script><script src="/assets/js/portal-date-picker.js"></script>')+'</body></html>');
}).listen(8834,'127.0.0.1',()=>console.log('Acknowledgments fixture on 8834'));
