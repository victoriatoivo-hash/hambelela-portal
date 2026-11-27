<?php
require __DIR__.'/../apps/hr-portal/includes/payroll-bonus.php';
function check($condition) { if (!$condition) throw new RuntimeException('Assertion failed'); }
function rejects($a,$t,$e) { try { hrBonusValidate($a,$t,$e); } catch (InvalidArgumentException $x) { return; } throw new RuntimeException('Expected rejection'); }
$employees=[['id'=>1],['id'=>2]];
check(hrBonusValidate([], [], $employees) === []);
check(hrBonusValidate([1=>'1000.50',2=>''],[1=>'180',2=>''],$employees)===[1=>['amount'=>1000.50,'paye'=>180.0]]);
check(hrBonusValidate([1=>'10'],[1=>'0'],$employees)[1]['paye']===0.0);
rejects([3=>'10'],[3=>'0'],$employees);
rejects([1=>'10'],[],$employees);
rejects([1=>'-10'],[1=>'0'],$employees);
rejects([1=>'1.001'],[1=>'0'],$employees);
rejects([1=>'10'],[1=>'11'],$employees);
rejects([1=>''],[1=>'1'],$employees);
echo "9 bonus validation checks passed\n";
