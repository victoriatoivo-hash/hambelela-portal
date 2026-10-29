<?php
declare(strict_types=1);
// Private CLI packaging. Compare against the approved base, preserve live Orders edits.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);$package=realpath($argv[1]??'');$root=realpath($argv[2]??'');
if(!$package||!$root||strpos($package,$root)===0||!is_file($root.'/shared/database.php')||!is_dir($package.'/source')||!is_dir($package.'/base'))throw new RuntimeException('Explicit private package, baseline and portal root required');
$hash=static function(string $file):?string{return is_file($file)?hash('sha256',str_replace("\r\n","\n",file_get_contents($file))):null;};
$files=['apps/operations/kpi-employee-data.php','apps/operations/kpi-employee.php','apps/operations/order-communication.php',
 'apps/operations/orders-board-action.php','apps/operations/orders-board.php','apps/operations/whatsapp-service.php',
 'assets/css/employee-performance.css','assets/css/order-communication.css','assets/js/employee-performance.js','assets/js/order-communication.js','assets/js/orders-board.js',
 'shared/epi/CashDeadlineBridge.php','shared/epi/CommunicationDeadlineBridge.php','shared/epi/CompletedWorkCapture.php',
 'shared/epi/EmployeePerformanceService.php','shared/epi/EvidenceCaptureInbox.php','shared/epi/EvidenceStatus.php',
 'shared/epi/OperationalPerformanceProjector.php','shared/epi/OrderCommunicationCapture.php','shared/epi/PerformanceEvidenceView.php'];
$merge=['apps/operations/orders-board.php','apps/operations/orders-board-action.php','assets/js/orders-board.js'];
$run=static function(string $command,string $output)use($package):int{
 $p=proc_open($command,[0=>['pipe','r'],1=>['file',$output,'w'],2=>['file',$package.'/stage-errors.log','a']],$pipes);
 if(!is_resource($p))throw new RuntimeException('Cannot start staging command');fclose($pipes[0]);return proc_close($p);
};
$manifest=['files'=>[],'dependencies'=>[]];$preserved=[];
foreach($files as $path){
 $source=$package.'/source/'.$path;$base=$package.'/base/'.$path;$live=$root.'/'.$path;$target=$package.'/runtime/'.$path;
 if(!is_file($source))throw new RuntimeException('Missing release source: '.$path);
 if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
 $before=$hash($live);$expected=$hash($base);
 if($before!==$expected&&$before!==$hash($source)){
  if(!in_array($path,$merge,true)||!is_file($base)||!is_file($live))throw new RuntimeException('Unexpected live drift: '.$path);
  copy($live,$target);$preserve=$package.'/preserved-live/'.$path;if(!is_dir(dirname($preserve)))mkdir(dirname($preserve),0700,true);copy($live,$preserve);
  $patch=$package.'/orders-'.count($preserved).'.patch';
  $code=$run('diff -u --label '.escapeshellarg('a/'.$path).' --label '.escapeshellarg('b/'.$path).' '.escapeshellarg($base).' '.escapeshellarg($source),$patch);
  if($code!==1)throw new RuntimeException('Expected a precise nonempty Orders delta');
  $command='patch --batch --fuzz=0 -p1 --directory='.escapeshellarg($package.'/runtime').' --input='.escapeshellarg($patch);
  if($run($command.' --dry-run',$package.'/dry-run-'.count($preserved).'.log')!==0)throw new RuntimeException('Orders delta conflicts with newer live edits: '.$path);
  if($run($command,$package.'/merge-'.count($preserved).'.log')!==0)throw new RuntimeException('Orders delta application failed');
  $preserved[]=$path;
 }else copy($source,$target);
 if(substr($path,-4)==='.php'&&$run(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($target),$package.'/lint-'.md5($path).'.log')!==0)throw new RuntimeException('Production PHP syntax failure: '.$path);
 $manifest['files'][$path]=['before'=>[$before],'after'=>$hash($target)];
}
foreach(['PhysicalEvidenceCapture','PerformanceIncidentReview','PerformanceRefreshRuntime','ProvisionalTimingPolicy','PerformanceCapturePolicy','V2OperationalBridge','OwnershipPeriodEngine','DeadlineEngine','BusinessTimeEngine','RateScoreCalculator'] as $name){
 $path='shared/epi/'.$name.'.php';if($hash($root.'/'.$path)!==$hash($package.'/base/'.$path))throw new RuntimeException('Dependency differs from approved base: '.$path);
 $manifest['dependencies'][$path]=$hash($root.'/'.$path);
}
foreach(['epi-data-link-release.php','epi-evidence-capture-activation.php'] as $name)copy($package.'/source/scripts/'.$name,$package.'/'.$name);
copy($package.'/source/operations-bookkeeping-kpi-migration.sql',$package.'/operations-bookkeeping-kpi-migration.sql');
file_put_contents($package.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT));
file_put_contents($package.'/preserved-live.json',json_encode($preserved,JSON_PRETTY_PRINT));
echo json_encode(['state'=>'staged_compared','files'=>count($files),'newer_live_orders_preserved'=>$preserved,'production_writes'=>false]),PHP_EOL;
