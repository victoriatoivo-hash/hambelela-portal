<?php
if(!defined('OWNER_VIEW_LAB')||!OWNER_VIEW_LAB)exit;
if(!function_exists('current_role_key')){function current_role_key(){return 'owner_admin';}}
require_once BASE_PATH.'/shared/ess-navigation.php';
$essActiveModule='Driver Operations';$essShellApps=[['name'=>'Operations','href'=>'/apps/operations/index.php','icon'=>'clipboard-check'],['name'=>'Delivery','href'=>'/apps/delivery/workspace.php','icon'=>'bike'],['name'=>'Bookkeeping','href'=>'/apps/operations/bookkeeping.php','icon'=>'book-open']];
$essHeadingPartial=BASE_PATH.'/shared/delivery/PortalHeading.php';$essOwnerOperationsSearch=false;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Local staging · Driver Operations</title><link rel="stylesheet" href="/assets/css/ess-dashboard.css"><link rel="stylesheet" href="/assets/css/driver-admin-view-as-driver.css"><style>
@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}
.operations-lab{padding:6px 12px;background:#fff1cb;color:#685119;font-size:11px;border-radius:10px;margin:0}
.ess-topbar{min-height:65px}.ess-account-slot:after{content:'Synthetic Owner · Local staging';font-size:12px}.ess-topbar-actions{flex:1}.ess-search{flex:1}
@media(max-width:680px){.ess-dashboard-main{padding:14px!important}.ess-topbar{gap:8px;flex-wrap:wrap}.ess-topbar-actions{flex-basis:100%}.ess-account-slot{display:none}}
</style></head><body class="ess-dashboard"><div class="shell ess-dashboard-shell"><?php require BASE_PATH.'/shared/ess-sidebar.php'; ?><main id="ess-main" class="workspace ess-dashboard-main"><?php require BASE_PATH.'/shared/ess-topbar.php'; ?><p class="operations-lab">LOCAL STAGING · Synthetic records only · Production unchanged. Unrelated live workflows are not connected.</p>
