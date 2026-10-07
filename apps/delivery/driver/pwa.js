(()=>{'use strict';
if('serviceWorker' in navigator&&window.isSecureContext){navigator.serviceWorker.register('sw.js',{scope:'./'}).catch(()=>{});}
function connection(){
 let notice=document.getElementById('delivery-connection');
 if(!notice){notice=document.createElement('p');notice.id='delivery-connection';notice.className='notice';notice.setAttribute('role','status');document.querySelector('main').prepend(notice);}
 notice.hidden=navigator.onLine;
 notice.textContent='You are offline. Delivery changes are not saved or queued. Reconnect, refresh, and confirm the current balance before collecting money.';
}
window.addEventListener('online',connection);window.addEventListener('offline',connection);connection();
})();
