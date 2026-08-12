<?php
declare(strict_types=1);

// Run with: php tests/cost-workbook-phase1.php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/shared/cost-workbook.php';

$failures = [];
$assertions = 0;
function check(string $name, bool $ok): void { global $failures,$assertions; $assertions++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failures[]=$name; }
function numeric_close($actual,float $expected,float $epsilon=0.000001):bool{return !is_bool($actual)&&$actual!==null&&is_numeric($actual)&&is_finite((float)$actual)&&abs((float)$actual-$expected)<=$epsilon;}
function check_close(string $name,$actual,float $expected,float $epsilon=0.000001):void{check($name,numeric_close($actual,$expected,$epsilon));}

check('numeric comparison accepts integer for float expectation',numeric_close(100,100.0));
check('numeric comparison accepts epsilon-equivalent result',numeric_close(100.0000005,100.0));
check('numeric comparison rejects material difference',!numeric_close(100.000002,100.0));
check('numeric comparison rejects non-numeric string',!numeric_close('not-a-number',100.0));
check('numeric comparison rejects null',!numeric_close(null,100.0));
check('numeric comparison rejects booleans',!numeric_close(true,1.0)&&!numeric_close(false,0.0));
check('numeric comparison rejects non-finite values',!numeric_close(INF,100.0)&&!numeric_close(NAN,100.0));

[$q,$u,$e]=cw_normalize_quantity(1,'kg'); check('1kg becomes 1000g', (float)$q===1000.0 && $u==='g' && $e===null);
[$q,$u,$e]=cw_normalize_quantity(500,'g'); check('500g remains 500g', (float)$q===500.0 && $u==='g');
[$q,$u,$e]=cw_normalize_quantity(1,'L'); check('1L becomes 1000ml', (float)$q===1000.0 && $u==='ml');
[$q,$u,$e]=cw_normalize_quantity(25,'L'); check('25L becomes 25000ml', (float)$q===25000.0 && $u==='ml');
[$q,$u,$e]=cw_normalize_quantity(2,'pack'); check('unknown pack remains unresolved', $q===null && $u==='pack' && $e!==null);

$c=cw_calculate(115,60,15);
check_close('selling price excluding VAT',$c['selling_ex_vat'],100.0);
check_close('output VAT',$c['output_vat'],15.0);
check_close('gross profit',$c['gross_profit'],40.0);
check_close('gross margin is 40%',$c['gross_margin'],40.0);
check_close('markup is 66.67%',$c['markup'],66.67);
check('margin and markup differ', $c['gross_margin']!==$c['markup']);
$fractional=cw_calculate(99.99,42.37,15);
check_close('fractional selling price excluding VAT',$fractional['selling_ex_vat'],86.95);
check_close('fractional output VAT cross-check',$fractional['output_vat'],round(99.99*15/115,2));
check_close('fractional gross profit uses VAT-exclusive selling price',$fractional['gross_profit'],86.95-42.37);
check_close('fractional markup uses landed cost denominator',$fractional['markup'],round((86.95-42.37)/42.37*100,2));
check_close('fractional margin uses VAT-exclusive denominator',$fractional['gross_margin'],round((86.95-42.37)/86.95*100,2));
check('incorrect VAT result is rejected',!numeric_close($fractional['output_vat'],99.99-42.37));
check('incorrect margin denominator is rejected',!numeric_close($fractional['gross_margin'],round((86.95-42.37)/42.37*100,2)));
$missing=cw_calculate(null,null,15); check('missing values do not become Infinity or NaN', !in_array(INF,$missing,true) && !in_array(NAN,$missing,true));

$line=cw_calculate_invoice_line(2,'10.005','1.25','2.81','exclusive');
check('decimal discount is preserved', $line['discount']==='1.25');
check('exclusive subtotal is gross less discount', $line['line_subtotal']==='18.76');
check('exclusive VAT is added after discount', $line['line_total']==='21.57');
$zero=cw_calculate_invoice_line(1,'100','0','15','exclusive');check('zero discount saves', $zero['discount']==='0.00'&&$zero['line_total']==='115.00');
$inclusive=cw_calculate_invoice_line(1,'115','15','15','inclusive');check('inclusive VAT is not added twice', $inclusive['line_subtotal']==='85.00'&&$inclusive['line_total']==='100.00');
foreach([['-1','Discount must reject negatives'],['oops','Discount must reject malformed values'],['101','Discount must not exceed gross']] as [$discount,$name]){try{cw_calculate_invoice_line(1,'100',$discount,'0','exclusive');check($name,false);}catch(InvalidArgumentException $e){check($name,true);}}

echo sprintf("Phase 1 assertions: %d/%d passed.%s",$assertions-count($failures),$assertions,PHP_EOL);
exit($failures ? 1 : 0);
