<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/cost-workbook.php';
require_once BASE_PATH.'/shared/cost-workbook-page-shell.php';
require_once BASE_PATH.'/shared/cost-workbook-sections.php';
require_role('owner_admin');
$cwPageKey=(string)($cwPageKey??'overview');$period=cw_page_period();$bootError=null;
try{cw_install_schema(db());}catch(Throwable $error){$bootError='setup_failed';error_log('Cost Workbook setup failed: '.get_class($error));}
cw_page_begin($cwPageKey,$period,$bootError);
$scripts=[];
if($cwPageKey==='overview'){cw_render_overview();$scripts[]='cost-workbook-pages.js?v=1';}
elseif($cwPageKey==='purchases'){cw_render_purchases(false);$scripts[]='cost-workbook-pages.js?v=1';}
elseif($cwPageKey==='invoice-review'){cw_render_purchases(true);$scripts[]='cost-workbook-pages.js?v=1';}
elseif(in_array($cwPageKey,['shipments','landed-costs','product-matching'],true)){cw_render_phase2($cwPageKey);$scripts[]='cost-workbook-phase2.js?v=2';}
elseif($cwPageKey==='profitability'){cw_render_profitability();$scripts[]='cost-workbook-pages.js?v=1';}
elseif($cwPageKey==='cogs-publishing'){cw_render_cogs();}
elseif($cwPageKey==='settings'){cw_render_settings();$scripts[]='cost-workbook-pages.js?v=1';}
cw_page_end($scripts);
