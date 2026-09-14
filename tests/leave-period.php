<?php
require __DIR__.'/../apps/operations/kpi-leave-period.php';
$rows=kpi_leave_period_rows([['start_date'=>'2026-07-30','end_date'=>'2026-08-03','days'=>5],['start_date'=>'2026-07-30','end_date'=>'2026-08-03','days'=>3],['start_date'=>'2026-08-04','end_date'=>'2026-08-04','days'=>0.5]],'2026-08-01','2026-08-31');
if($rows[0]['days']!==3||$rows[1]['days']!==null||$rows[2]['days']!==0.5)throw new RuntimeException('Period allocation failed');
echo "Leave period checks passed.\n";
