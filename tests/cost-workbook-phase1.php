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

$line=cw_calculate_invoice_line(2,'10.005','1.25','2.81','exclusive');
check('decimal discount is preserved', $line['discount']==='1.25');
check('exclusive subtotal is gross less discount', $line['line_subtotal']==='18.76');
check('exclusive VAT is added after discount', $line['line_total']==='21.57');
$zero=cw_calculate_invoice_line(1,'100','0','15','exclusive');check('zero discount saves', $zero['discount']==='0.00'&&$zero['line_total']==='115.00');
$inclusive=cw_calculate_invoice_line(1,'115','15','15','inclusive');check('inclusive VAT is not added twice', $inclusive['line_subtotal']==='85.00'&&$inclusive['line_total']==='100.00');
foreach([['-1','Discount must reject negatives'],['oops','Discount must reject malformed values'],['101','Discount must not exceed gross']] as [$discount,$name]){try{cw_calculate_invoice_line(1,'100',$discount,'0','exclusive');check($name,false);}catch(InvalidArgumentException $e){check($name,true);}}

exit($failures ? 1 : 0);
