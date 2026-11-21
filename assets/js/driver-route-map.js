/* Foreground, opt-in routing only. No location/customer data in persistent storage. */
(() => {
  'use strict';
  const root=document.querySelector('[data-live-route]');if(!root)return;
  const $=selector=>root.querySelector(selector), actor=Number(root.dataset.driverId);
  let snapshot=null, selected='', epoch=0, busy=false, map=null, estimate=null, loading=null, canvas=null;
  const notice=text=>{const n=$('[data-route-notice]');n.textContent=text;n.hidden=!text;};
  function resetMap(){
    map=null;canvas=null;estimate=null;
    const surface=$('[data-google-canvas]');
    if(surface){surface.replaceChildren();const msg=document.createElement('p');msg.className='ho-driver-route__map-placeholder';msg.textContent='Tap Locate for a fresh route to your selected delivery.';surface.append(msg);}
    const labels={'[data-map-duration]':'Time unavailable','[data-map-distance]':'Distance unavailable','[data-map-estimate-note]':'No current road estimate.','[data-map-label]':'Selected delivery'};
    for(const [selector,text] of Object.entries(labels)){const element=$(selector);if(element)element.textContent=text;}
  }
  function clear(){epoch++;resetMap();snapshot=null;busy=false;}
  window.hambelelaRouteMap={
    clear,
    snapshot(data){
      if(snapshot&&snapshot.revision_token!==data.revision_token){epoch++;resetMap();notice('Assignments changed. Locate again for a fresh route.');}
      snapshot=data;
      const select=$('[data-map-stop]');if(!select)return;
      if(!Array.from(select.options).some(o=>o.value===selected))selected='';
      if(!selected&&select.options.length===2)selected=select.options[1].value;
      select.value=selected;updateNavigation();
    }
  };
  function current(){return snapshot?.stops.find(s=>String(s.assignment_id)===selected&&s.status==='on_route'&&s.address_display);}
  function updateNavigation(){
    const link=$('[data-map-external]'), stop=current();if(!link)return;
    link.hidden=!stop;
    if(stop){const target=new URL(root.dataset.endpoint,location.href);target.search='';target.searchParams.set('delivery',stop.assignment_id);target.searchParams.set('contact','navigate');link.href=target.href;}
    else link.removeAttribute('href');
    $('[data-map-navigation-note]').textContent=stop?'Selected: '+stop.order_reference:'Choose a delivery you have started. No visit order is guessed.';
  }
  async function api(action,extra={}){
    const body=new URLSearchParams({action,actor:String(actor),csrf:root.dataset.csrf,...extra});
    const response=await fetch(root.dataset.endpoint,{method:'POST',body,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    if(response.redirected||[401,403].includes(response.status)){clear();root.querySelector('[data-route-content]').replaceChildren();notice('Your driver session has ended. Sign in again.');throw Error('Your driver session has ended. Sign in again.');}
    const result=await response.json();
    if(!response.ok||!result.ok)throw Error(result.error||'Maps are temporarily unavailable.');
    if(result.driver_id!==actor){clear();root.querySelector('[data-route-content]').replaceChildren();notice('Your account changed. Reload.');throw Error('Your account changed. Reload.');}
    return result.result;
  }
  async function googleMaps(){
    if(window.google?.maps?.Map)return window.google.maps;
    if(!loading)loading=(async()=>{
      const config=await api('maps_bootstrap');
      return new Promise((resolve,reject)=>{
        const script=document.createElement('script');script.async=true;script.referrerPolicy='origin';
        const timeout=setTimeout(()=>{delete window.hambelelaDriverMapsReady;script.remove();reject(Error('Google Maps took too long. Retry or use external directions.'));},15000);
        window.hambelelaDriverMapsReady=()=>{clearTimeout(timeout);delete window.hambelelaDriverMapsReady;resolve(window.google.maps);};
        script.onerror=()=>{clearTimeout(timeout);delete window.hambelelaDriverMapsReady;reject(Error('Google Maps could not load. Check your connection.'));};
        const params=new URLSearchParams({key:config.browser_key,loading:'async',callback:'hambelelaDriverMapsReady',libraries:'geometry',v:'quarterly',auth_referrer_policy:'origin'});
        script.src='https://maps.googleapis.com/maps/api/js?'+params;document.head.appendChild(script);
      });
    })().catch(error=>{loading=null;throw error;});
    return loading;
  }
  const locate=()=>new Promise((resolve,reject)=>{
    if(!navigator.geolocation){reject(Error('Location is unavailable. Use external directions.'));return;}
    navigator.geolocation.getCurrentPosition(resolve,error=>reject(Error(error.code===1?'Location was declined. You can still use external directions.':'Could not get a fresh location. Try outdoors or use external directions.')),{enableHighAccuracy:true,timeout:12000,maximumAge:0});
  });
  async function showRoute(){
    if(busy||document.hidden)return;
    const stop=current();if(!stop){$('[data-map-dialog-status]').textContent='Choose a started delivery first.';return;}
    if($('[data-route-state]')?.dataset.mapEnabled!=='1'){notice('Embedded maps are unavailable here. Use the delivery’s external directions.');return;}
    busy=true;const token=++epoch, revision=snapshot.revision_token, control=$('[data-map-locate]');control.disabled=true;
    $('[data-map-dialog-status]').textContent='Locating for this delivery only…';
    try{
      const position=await locate();if(token!==epoch||document.hidden)return;
      const point={latitude:position.coords.latitude,longitude:position.coords.longitude,accuracy:position.coords.accuracy,recorded_at:Math.floor(position.timestamp/1000),event_id:crypto.randomUUID()};
      const g=await googleMaps();if(token!==epoch||document.hidden)return;
      const route=await api('estimate',{location_consent:'1',delivery:String(stop.assignment_id),position:JSON.stringify(point),revision});
      if(token!==epoch||document.hidden||snapshot?.revision_token!==revision||selected!==String(stop.assignment_id))return;
      if(!route.available)throw Error(route.reason||'Road estimate unavailable.');
      if(route.driver_id!==actor||route.assignment_id!==stop.assignment_id||route.route_revision!==revision)throw Error('Assignments changed. Refresh before navigating.');
      canvas=$('[data-google-canvas]');canvas.replaceChildren();
      map=new g.Map(canvas,{center:route.origin,zoom:14,mapTypeControl:false,streetViewControl:false,fullscreenControl:false,gestureHandling:'cooperative'});
      const path=g.geometry.encoding.decodePath(route.polyline), bounds=new g.LatLngBounds();
      path.forEach(p=>bounds.extend(p));map.fitBounds(bounds,40);
      new g.Polyline({path,map,strokeColor:'#4d684b',strokeWeight:5});
      new g.Circle({map,center:route.origin,radius:Math.max(4,Math.min(point.accuracy,250)),strokeColor:'#4d684b',fillColor:'#6a8e61',fillOpacity:.25,strokeWeight:2});
      new g.Circle({map,center:route.destination,radius:12,strokeColor:'#fff',fillColor:'#c98e45',fillOpacity:1,strokeWeight:3});
      estimate=route;
      $('[data-map-duration]').textContent=Math.max(1,Math.ceil(route.duration_seconds/60))+' min · selected stop';
      $('[data-map-distance]').textContent=(route.distance_metres/1000).toFixed(1)+' km';
      const label=$('[data-map-label]');label.replaceChildren(document.createTextNode(stop.order_reference));const sub=document.createElement('strong');sub.textContent='Selected delivery · Google road estimate';label.append(sub);
      $('[data-map-estimate-note]').textContent='Google estimate updated '+new Date(route.calculated_at*1000).toLocaleTimeString('en-GB',{timeZone:'Africa/Windhoek',hour:'2-digit',minute:'2-digit'})+' · excludes live traffic. Selected stop only.';
      document.getElementById('hd-locate').close();notice('');
    }catch(error){if(token===epoch&&!document.hidden){notice(error.message);$('[data-map-dialog-status]').textContent=error.message;}}
    finally{busy=false;control.disabled=false;}
  }
  root.addEventListener('click',event=>{if(event.target.closest('[data-map-locate]'))showRoute();if(event.target.closest('[data-hd-dialog="navigate"]'))updateNavigation();});
  root.addEventListener('change',event=>{if(event.target.matches('[data-map-stop]')){selected=event.target.value;epoch++;resetMap();updateNavigation();}});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)clear();});window.addEventListener('pagehide',clear);
  setInterval(()=>{if(estimate&&Date.now()/1000-estimate.calculated_at>300){estimate=null;$('[data-map-estimate-note]').textContent='Estimate is stale. Tap Locate to request a fresh road estimate.';}},30000);
})();
