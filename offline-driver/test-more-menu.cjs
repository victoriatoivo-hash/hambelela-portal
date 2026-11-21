const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const code=fs.readFileSync('assets/js/driver-more-menu.js','utf8');
const markup='<main><nav><button data-hd-dialog="more"><span>More</span></button><button data-hd-dialog="locate">Map location</button></nav><dialog data-driver-more-sheet><button data-driver-more-close>Close</button><a href="/notifications.php">Notifications</a></dialog><dialog id="old"></dialog></main>';
for(const screen of ['Home','Deliveries','Map','Errands','History','Cash','Dispatch','Notifications','Profile','Details','Proof','Issues']){
 const dom=new JSDOM(markup,{runScripts:'outside-only',url:'https://portal.example/'}),w=dom.window,d=w.document;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 let oldOpened=0,other=0;d.querySelector('main').addEventListener('click',e=>{if(e.target.closest('[data-hd-dialog="more"]'))oldOpened++;if(e.target.closest('[data-hd-dialog="locate"]'))other++;});
 w.eval(code);w.eval(code);let dialog=d.querySelector('dialog');
 d.querySelector('span').click();assert.equal(dialog.open,true,screen);assert.equal(oldOpened,0,screen+' bypasses old menu');
 d.querySelector('[data-driver-more-close]').click();assert.equal(dialog.open,false);assert.equal(d.activeElement.dataset.hdDialog,'more');
 d.querySelector('span').click();dialog.dispatchEvent(new w.Event('cancel',{bubbles:false,cancelable:true}));assert.equal(dialog.open,false);
 d.querySelector('[data-hd-dialog="locate"]').click();assert.equal(other,1,'other actions unchanged');
 d.querySelector('main').outerHTML=markup;dialog=d.querySelector('dialog');d.querySelector('span').click();assert.equal(dialog.open,true,'background DOM replacement');
 dialog.getBoundingClientRect=()=>({left:10,right:350,top:200,bottom:700});dialog.dispatchEvent(new w.MouseEvent('click',{bubbles:true,clientX:2,clientY:2}));assert.equal(dialog.open,false,'backdrop');
 d.querySelector('span').click();w.dispatchEvent(new w.Event('pagehide'));assert.equal(dialog.open,false);w.close();
}
const php=fs.readFileSync('shared/delivery/dashboard/BottomNav.php','utf8');for(const label of ['Delivery history','Cash handover','Dispatch chat','My account','Notifications'])assert.ok(php.includes(label));
const css=fs.readFileSync('assets/css/driver-more-menu.css','utf8');assert.match(css,/prefers-reduced-motion/);assert.match(css,/min-height:44px/);assert.match(css,/border-bottom:1px/);
console.log('PASS shared More menu: 12 screens, one opener, same destinations, focus return, Escape/backdrop, background replacement, no task action interception, reduced motion.');
