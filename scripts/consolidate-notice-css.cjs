// Mechanical removal of superseded popup rules; page styles remain in place.
const fs=require('node:fs');
function selectors(text){let depth=0,start=0,out=[];for(let i=0;i<text.length;i++){if('(['.includes(text[i]))depth++;if(')]'.includes(text[i]))depth--;if(text[i]===','&&!depth){out.push(text.slice(start,i));start=i+1;}}out.push(text.slice(start));return out;}
function filter(css,remove){let out='',pos=0;while(pos<css.length){const open=css.indexOf('{',pos);if(open<0){out+=css.slice(pos);break;}let level=1,i=open+1,quote='';for(;i<css.length&&level;i++){const c=css[i];if(quote){if(c===quote&&css[i-1]!=='\\')quote='';continue;}if(c==='"'||c==="'"){quote=c;continue;}if(c==='{')level++;if(c==='}')level--;}
 const head=css.slice(pos,open),body=css.slice(open+1,i-1);if(head.trim().startsWith('@media')||head.trim().startsWith('@supports'))out+=head+'{'+filter(body,remove)+'}';else if(head.trim().startsWith('@'))out+=head+'{'+body+'}';else{const kept=selectors(head).filter(s=>!remove(s));if(kept.length)out+=kept.join(',')+'{'+body+'}';}pos=i;}return out;}
for(const path of ['assets/css/portal.css','assets/css/notifications-ui.css']){
 const css=fs.readFileSync(path,'utf8');fs.writeFileSync(path,filter(css,s=>s.includes('.portal-toast')&&!s.includes(':is(')));
}
const path='assets/css/marketing-workspace.css';
fs.writeFileSync(path,filter(fs.readFileSync(path,'utf8'),s=>s.includes('.mk-marketing-popup')));
const ack='assets/css/acknowledgments.css';
fs.writeFileSync(ack,filter(fs.readFileSync(ack,'utf8').replaceAll(':is(.hb-ack-page,.hb-ack-popup)',':is(.hb-ack-page)'),s=>s.includes('.hb-ack-popup')));

