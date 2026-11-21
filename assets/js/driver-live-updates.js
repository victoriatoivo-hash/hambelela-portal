/* In-place updates for authenticated, server-escaped driver fragments only.
 * No polling, storage, identity decisions or business writes live in this helper. */
(() => {
  'use strict';
  const key = node => node.nodeType === 1 ? ['id','data-card','data-delivery-card','data-group','data-live-key','href'].map(name => {
    const value = node.getAttribute(name); return value ? name + ':' + value : '';
  }).find(Boolean) || '' : '';
  const sameType = (a,b) => a.nodeType === b.nodeType && a.nodeName === b.nodeName;
  function sync(current, fresh) {
    if (!sameType(current,fresh)) { current.replaceWith(document.importNode(fresh,true)); return; }
    if (current.nodeType !== 1) { if(current.nodeValue !== fresh.nodeValue) current.nodeValue = fresh.nodeValue; return; }
    // The map owns its canvas/estimates; the route revision handler invalidates them.
    if (current.matches('[data-google-canvas],[data-map-duration],[data-map-distance],[data-map-label],[data-map-estimate-note],canvas')) return;
    if (current.isEqualNode(fresh)) return;
    const interactive = current.matches('input:not([type=hidden]),textarea,select');
    const selection = current.matches('select') ? current.value : null;
    const keep = name => (name === 'open' && current.matches('details,dialog')) || (interactive && ['value','checked','selected'].includes(name));
    for (const attr of Array.from(current.attributes)) if (!fresh.hasAttribute(attr.name) && !keep(attr.name)) current.removeAttribute(attr.name);
    for (const attr of Array.from(fresh.attributes)) if (!keep(attr.name) && current.getAttribute(attr.name) !== attr.value) current.setAttribute(attr.name,attr.value);
    if (!current.matches('input,textarea')) children(current,fresh);
    if (selection !== null && Array.from(current.options).some(o=>o.value===selection)) current.value=selection;
  }
  function children(current, fresh) {
    const existing=Array.from(current.childNodes), used=new Set();
    let cursor=current.firstChild;
    for (const desired of Array.from(fresh.childNodes)) {
      const wanted=key(desired);
      let node=existing.find(n=>!used.has(n) && sameType(n,desired) && (wanted ? key(n)===wanted : !key(n)));
      if (node) { used.add(node); if(node!==cursor) current.insertBefore(node,cursor); sync(node,desired); }
      else { node=document.importNode(desired,true); current.insertBefore(node,cursor); }
      cursor=node.nextSibling;
    }
    for (const node of existing) if(!used.has(node)) node.remove();
  }
  function html(current, markup) {
    const template=document.createElement('template'); template.innerHTML=markup;
    const scroller=current.closest('.ho-driver-dashboard__scroll');
    const top=scroller?.scrollTop, left=scroller?.scrollLeft;
    children(current,template.content);
    if(scroller){scroller.scrollTop=top;scroller.scrollLeft=left;}
  }
  window.hambelelaLiveUpdates=Object.freeze({sync,html});
})();
