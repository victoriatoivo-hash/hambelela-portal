<?php
$root=dirname(__DIR__);
if(($argv[1]??'')==='auth'){
    require $root.'/apps/hr-portal/config.php';
    startSession();$_SESSION['user_id']=123;
    $_SESSION['user']=['id'=>123,'name'=>'Synthetic user','role'=>$argv[2],'emp_id'=>1];
    requireAdmin();echo 'ADMIN_ALLOWED';exit;
}
foreach(['employee'=>false,'admin'=>true] as $role=>$allowed){
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'auth',$role],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0||($output==='ADMIN_ALLOWED')!==$allowed)throw new RuntimeException('Role gate failed: '.$role.' '.$errors);
    echo 'PASS: actual admin gate for '.$role."\n";
}
// Omit a user ID so this presentation-only check cannot assign a policy.
$user=['name'=>'Synthetic Employee','role'=>'employee','emp_id'=>1];
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/apps/hr-portal/self-service.php';
ob_start();require $root.'/apps/hr-portal/includes/emp-sidebar.php';$html=ob_get_clean();
preg_match_all('/class=\'nav-item[^\']*\'.*?<\/a>/',$html,$matches);
$expected=['Back to Portal','My Dashboard','Notifications','My Leave','My Overtime','My Payslips','My Documents','My Loans','Company Policies'];
if(count($matches[0])!==count($expected))throw new RuntimeException('Employee navigation count changed');
foreach($expected as $i=>$label)if(strpos($matches[0][$i],$label)===false)throw new RuntimeException('Employee navigation changed: '.$label);
foreach(['employees.php','payroll.php','settings.php'] as $adminPath)if(strpos($html,"href='$adminPath'")!==false)throw new RuntimeException('Admin navigation exposed');
echo "PASS: employee navigation contains only authorised sections\n";
