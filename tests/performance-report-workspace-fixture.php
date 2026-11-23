<?php
// Local-only UI fixture. No database, authentication session or live records.
define('BASE_PATH', dirname(__DIR__)); define('BASE_URL', '');
$tab=$argv[1]??'business-health'; $ready=true; $csrf='fixture'; $message='';
$currentKpiTitle=['business-health'=>'Business Health','employees'=>'Team Comparison','settings'=>'Performance Settings','business-activity'=>'Business Activity Timeline'][$tab];
$phaseThreeTabs=['business-activity'=>'Business Activity Timeline'];$visiblePerformanceTabs=$phaseThreeTabs;
$settings=[];$employees=[];$employeeSchedules=[];$employeeScheduleVersions=[];$holidays=[];$recentEvents=[];$recentSessions=[];
$source=file_get_contents(BASE_PATH.'/apps/operations/reports.php');
preg_match('/\$settingFields = \[(.*?)\n\];/s',$source,$match);eval($match[0]);
$template=substr($source,strpos($source,'<main id='));
$template=str_replace("<?php include BASE_PATH . '/shared/footer.php'; ?>",'', $template);
$timing=file_get_contents(BASE_PATH.'/apps/operations/performance-timing-settings.php');
$timing=substr($timing,strpos($timing,'?>')+2);
$timing=str_replace('\\Hambelela\\EPI\\ProvisionalTimingPolicy::FIELDS',"['fixture_minutes'=>'Example working target']",$timing);
$timingValues=['fixture_minutes'=>30];$timingEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$cashControls=null;$packingDeadlines=[];
$timingMarkup=$timing;$timing=null;
ob_start();eval('?>'.$timingMarkup);$timingMarkup=ob_get_clean();
$template=str_replace("<?php require __DIR__.'/performance-timing-settings.php'; ?>",$timingMarkup,$template);
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/portal.css"><link rel="stylesheet" href="/assets/css/portal-responsive.css"><link rel="stylesheet" href="/assets/css/performance-dashboard.css"></head><body><div class="shell"><aside class="portal-sidebar" aria-label="Fixture sidebar"></aside>';
eval('?>'.$template);echo '<link rel="stylesheet" href="/assets/css/portal-responsive.css"></div></body></html>';
