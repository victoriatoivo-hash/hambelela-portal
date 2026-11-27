<?php
require __DIR__.'/../apps/hr-portal/includes/payroll-bonus.php';
function must($condition) { if (!$condition) throw new RuntimeException('CSRF test failed'); }
$cookie=str_repeat('a',64);
$token=hrBonusCsrfFromCookie($cookie,1);
must(is_string($token) && strlen($token)===64);
must(hrBonusCsrfValid(1,$token,$cookie));
must(!hrBonusCsrfValid(2,$token,$cookie));
must(!hrBonusCsrfValid(1,$token,str_repeat('b',64)));
must(!hrBonusCsrfValid(1,$token,null));
must(!hrBonusCsrfValid(1,null,$cookie));
must(!hrBonusCsrfValid(1,[$token],$cookie));
must(!hrBonusCsrfValid(1,$token,[$cookie]));
must(!hrBonusCsrfValid(0,$token,$cookie));
must(!hrBonusCsrfValid(1,'',$cookie));
$_COOKIE[HR_BONUS_CSRF_COOKIE]=$cookie;
$_SESSION=[];
$_SERVER['REQUEST_METHOD']='GET';
must(hrBonusCsrf(1)===$token);
$_SERVER['REQUEST_METHOD']='POST';
must(hrBonusCsrf(1)===$token);
unset($_COOKIE[HR_BONUS_CSRF_COOKIE]);
must(!hrBonusCsrfValid(1,hrBonusCsrf(1),null));
echo "13 payroll CSRF checks passed, including GET/POST with no writable PHP session\n";
