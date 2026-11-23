<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', 'https://portal.example');
$_SESSION=[];
$actor=['id'=>123];
ob_start();require BASE_PATH.'/shared/delivery/profile/View.php';$html=ob_get_clean();
$url='https://github.com/victoriatoivo-hash/hambelela-portal/releases/download/driver-android-v1.0.0/Hambelela-Driver-1.0.0.apk';
function check($value,$label){if(!$value)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
check(substr_count($html,'href="'.$url.'"')===2,'download available in profile and shared More menu');
check(substr_count($html,'rel="noopener noreferrer" referrerpolicy="no-referrer"')===2,'no portal referrer sent with downloads');
check(strpos($html,'Android 8+ · 3.6 MB')!==false,'phone requirements shown');
check(strpos($html,'Internet is still required.')!==false,'no false offline claim');
check(strpos($html,'data-driver-more-sheet')!==false,'existing More sheet retained');
check(strpos($html,'id="hp-logout"')!==false,'existing server sign-out retained');
check(strpos($html,'data-csrf=""')!==false,'synthetic render requires no real session');
check(substr_count($html,'class="ho-driver-dashboard__tab"')===5,'five bottom tabs unchanged');
