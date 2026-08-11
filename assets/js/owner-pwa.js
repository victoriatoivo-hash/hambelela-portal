(() => {
  'use strict';
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  document.documentElement.classList.toggle('owner-pwa-standalone', standalone);
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js', {scope:'/'}).catch(error => console.warn('Owner app support unavailable:', error.message));
  const isiOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const mobile = isiOS || /android|mobile|tablet/i.test(navigator.userAgent);
  if (isiOS && mobile && !standalone && localStorage.getItem('hambelela_owner_install_hidden') !== '1') {
    const helper=document.createElement('aside');helper.className='owner-install-helper';helper.setAttribute('role','dialog');helper.setAttribute('aria-label','Install Hambelela Portal');
    helper.innerHTML='<button class="owner-install-close" type="button" aria-label="Close">×</button><strong>Add Hambelela Portal to your iPhone</strong><ol><li>Open this portal in Safari.</li><li>Tap Share.</li><li>Select Add to Home Screen.</li><li>Tap Add.</li></ol><button class="owner-install-dismiss" type="button">Don\'t show again</button>';
    document.body.appendChild(helper);helper.querySelector('.owner-install-close').onclick=()=>helper.remove();helper.querySelector('.owner-install-dismiss').onclick=()=>{localStorage.setItem('hambelela_owner_install_hidden','1');helper.remove()};
  }
  let reconnectTimer;
  const updateConnection=()=>{document.documentElement.classList.toggle('owner-pwa-offline',!navigator.onLine);let notice=document.querySelector('.owner-connection-notice');if(!navigator.onLine&&!notice){notice=document.createElement('div');notice.className='owner-connection-notice';notice.setAttribute('role','status');notice.textContent="You're offline — current information may be unavailable.";document.body.appendChild(notice)}if(navigator.onLine&&notice){notice.textContent='Connection restored — refreshing current information…';clearTimeout(reconnectTimer);reconnectTimer=setTimeout(()=>location.reload(),700)}};
  window.addEventListener('offline',updateConnection);window.addEventListener('online',updateConnection);updateConnection();
})();
