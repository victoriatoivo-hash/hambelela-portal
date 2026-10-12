<?php
require __DIR__.'/../shared/marketing-workspace.php';
foreach(['UTC','America/New_York','Asia/Tokyo'] as $zone){
 date_default_timezone_set($zone);
 if(marketing_local_datetime('2026-10-12T17:00')!=='2026-10-12 17:00:00')throw new RuntimeException('Storage changed with host timezone');
 if(marketing_time_iso('2026-10-12 17:00:00')!=='2026-10-12T17:00:00+02:00')throw new RuntimeException('Wrong offset');
 if(marketing_time_iso('2026-10-12T15:00:00Z')!=='2026-10-12T17:00:00+02:00')throw new RuntimeException('UTC normalization failed');
}
try{marketing_local_datetime('2026-02-31T17:00');throw new LogicException('Invalid date accepted');}catch(RuntimeException $e){}
echo "PASS Namibia storage and display independent of host timezone\n";
