<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);ob_start();define('BASE_URL','');
// Use the actual shared auth routine, not a copy or the private router's synthetic identity.
require dirname(__DIR__).'/shared/auth.php';require dirname(__DIR__).'/shared/delivery/EmployeeDriverSession.php';
$checks=0;function session12($v,$label){global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";}
$_SESSION['user']=['id'=>3,'name'=>'Synthetic driver','role_key'=>'delivery_driver'];portal_initialize_authenticated_session($_SESSION['user']);$_SESSION['csrf']='test';$_SESSION['driver_profile_csrf']='test-profile';
session12(portal_validate_authenticated_session(),'real session is valid before logout');
try{\Hambelela\Delivery\EmployeeDriverSession::csrf('invalid');throw new RuntimeException('CSRF accepted');}catch(DomainException $e){session12(portal_validate_authenticated_session(),'bad logout CSRF preserves session');}
\Hambelela\Delivery\EmployeeDriverSession::csrf('test');logout_user();session12($_SESSION===[]&&session_status()!==PHP_SESSION_ACTIVE,'real logout destroys server session and tokens');session12(!portal_validate_authenticated_session(),'stale back-page cannot validate logged-out session');
session_start();$_SESSION['user']=['id'=>3];portal_initialize_authenticated_session($_SESSION['user']);$_SESSION['absolute_expires_at']=time()-1;session12(!portal_validate_authenticated_session()&&$_SESSION===[],'expired session invalidates and clears identity');
echo "TOTAL $checks real-session checks passed\n";
