<?php
declare(strict_types=1);
/** Read-only timing version selection. Existing work keeps its original allowance. */
function kpi_command_target_minutes(array $settings,string $key,?string $started):float
{
    $packing=$key==='packing';
    $current=$packing?(float)($settings['target_fulfilment_hours']??6)*60:(float)($settings['frontdesk_website_target_minutes']??480);
    $effective=(string)($settings['command02_timing_effective_from']??'');
    if($effective!=='' && ($started===null || $started==='' || $started<$effective)) {
        $current=$packing?(float)($settings['command02_previous_packing_hours']??6)*60:(float)($settings['command02_previous_website_minutes']??480);
    }
    return max(1,$current);
}
