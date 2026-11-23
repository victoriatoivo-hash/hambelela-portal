const fs=require('fs'),assert=require('assert');
const read=p=>fs.readFileSync(p,'utf8');let n=0;
const ok=(b,s)=>{assert(b,s);n++;console.log('PASS '+s)};
for(const [route,controller] of [['driver-operations','OwnerOperationsController'],['delivery-details','OwnerProofController'],['view-as-driver','OwnerDriverViewController']]){
 const source=read('apps/delivery/'+route+'.php');
 ok(source.includes('/shared/auth.php')&&source.includes('/shared/delivery/'+controller+'.php'),'existing authentication/controller '+route);
 const c=read('shared/delivery/'+controller+'.php');
 ok(c.indexOf('refresh_logged_in_user()')<c.indexOf('redirectLegacy('),'authorize before redirect '+route);
 ok(c.includes("$essActiveModule='Delivery'")&&c.includes("OwnerNavigation::styles("),'shared Delivery shell '+route);
}
const landing=read('apps/delivery/index.php');
ok(landing.indexOf('DriverDeliveries.php')<landing.indexOf("'owner_admin'"),'driver application exits before owner redirect');
ok(landing.indexOf("isset($_GET['errands'])")<landing.indexOf("'owner_admin'"),'errands and driver feature routes retained');
ok(landing.includes('ErrandLanding::enforce($deliveryActor)'),'front desk and marketing landing unchanged');
const old=read('apps/delivery/driver-view.php');
ok(old.indexOf("$_GET['view']==='board'")<old.indexOf('$destination='),'legacy read API retained before page redirect');
const proof=read('shared/delivery/OwnerProofController.php');
ok(proof.indexOf("REQUEST_METHOD")<proof.indexOf('redirectLegacy(')&&proof.indexOf('readfile($download)')<proof.indexOf('redirectLegacy('),'proof action/download handlers preserved');
for(const file of ['operations','proof-viewer','view-as-driver']){
 const css=read('assets/css/driver-admin-'+file+'.css');
 ok(!/font-weight\s*:\s*(?:7\d\d|8\d\d|900)/.test(css),'no heavy heading overrides '+file);
}
ok(read('assets/css/delivery-admin.css').includes("../fonts/jost-variable.woff2"),'local Jost asset');
ok(!read('assets/js/delivery-core.js').includes('/apps/operations/driver-delivery-details.php'),'front proof links canonical');
console.log('TOTAL '+n+' consolidation contracts passed');
