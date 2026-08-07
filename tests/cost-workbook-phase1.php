<?php
declare(strict_types=1);

// Run with: php tests/cost-workbook-phase1.php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/shared/cost-workbook.php';

$failures = [];
function check(string $name, bool $ok): void { global $failures; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failures[]=$name; }

[$q,$u,$e]=cw_normalize_quantity(1,'kg'); check('1kg becomes 1000g', (float)$q===1000.0 && $u==='g' && $e===null);
[$q,$u,$e]=cw_normalize_quantity(500,'g'); check('500g remains 500g', (float)$q===500.0 && $u==='g');
[$q,$u,$e]=cw_normalize_quantity(1,'L'); check('1L becomes 1000ml', (float)$q===1000.0 && $u==='ml');
[$q,$u,$e]=cw_normalize_quantity(25,'L'); check('25L becomes 25000ml', (float)$q===25000.0 && $u==='ml');
[$q,$u,$e]=cw_normalize_quantity(2,'pack'); check('unknown pack remains unresolved', $q===null && $u==='pack' && $e!==null);

$c=cw_calculate(115,60,15);
check('selling price excluding VAT', $c['selling_ex_vat']===100.0);
check('output VAT', $c['output_vat']===15.0);
check('gross profit', $c['gross_profit']===40.0);
check('gross margin is 40%', $c['gross_margin']===40.0);
check('markup is 66.67%', $c['markup']===66.67);
check('margin and markup differ', $c['gross_margin']!==$c['markup']);
$missing=cw_calculate(null,null,15); check('missing values do not become Infinity or NaN', !in_array(INF,$missing,true) && !in_array(NAN,$missing,true));

exit($failures ? 1 : 0);
