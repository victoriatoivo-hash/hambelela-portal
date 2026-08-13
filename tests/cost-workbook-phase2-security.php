<?php
declare(strict_types=1);
$root=dirname(__DIR__);$api=file_get_contents($root.'/apps/cost-manager/cw-phase2-api.php');$invoiceApi=file_get_contents($root.'/apps/cost-manager/cw-api.php');$migration=file_get_contents($root.'/apps/cost-manager/cost-workbook-phase2-migration.sql');
function require_pattern(string $pattern,string $text,string $label):void{if(!preg_match($pattern,$text))throw new RuntimeException($label);}
function reject_pattern(string $pattern,string $text,string $label):void{if(preg_match($pattern,$text))throw new RuntimeException($label);}
require_pattern("/require_role\('owner_admin'\)/",$api,'Phase 2 API is not owner/admin-only.');
require_pattern('/REQUEST_METHOD.*GET.*cw_require_csrf/s',$api,'Phase 2 writes do not require CSRF.');
require_pattern('/download-expense-file/',$api,'Authenticated expense-file download route is missing.');
require_pattern('/random_bytes\(8\)/',$api,'Expense files lack randomized stored names.');
require_pattern('/move_uploaded_file/',$api,'Secure upload handling is missing.');
require_pattern('/Content-Disposition: attachment/',$api,'Private files are not forced to download.');
require_pattern('/Confirmed shipments are immutable/',$api,'Confirmed shipment mutation guard is missing.');
require_pattern('/Only a confirmed calculation can be revised/',$api,'Revision source guard is missing.');
require_pattern('/status=\'confirmed\'/',$api,'Calculation confirmation state is missing.');
require_pattern('/unallocated_difference/',$api,'Confirmation reconciliation guard is missing.');
require_pattern('/require_role\(\'owner_admin\'\)/',$invoiceApi,'Invoice API is not owner/admin-only.');
reject_pattern('/require_role\(\'owner_admin\', \'supervisor_manager\'\)/',$invoiceApi,'Invoice API still permits supervisors.');
reject_pattern('/wc_(?:post|put|delete)\s*\(/i',$api,'WooCommerce write helper found in Phase 2 API.');
reject_pattern('/CURLOPT_CUSTOMREQUEST.{0,40}(?:POST|PUT|PATCH|DELETE)/is',$api,'HTTP write request found in Phase 2 API.');
reject_pattern('/(?:UPDATE|INSERT INTO|DELETE FROM)\s+cw_product_snapshots/i',$api,'Snapshot mutation found in Phase 2 API.');
reject_pattern('/(?:DROP|TRUNCATE)\s+/i',$migration,'Destructive migration operation found.');
echo "Cost Workbook Phase 2 server-boundary security tests passed.\n";
echo "NOTE: genuine non-privileged HTTP requests remain deferred to authenticated staging.\n";
