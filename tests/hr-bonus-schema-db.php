<?php
require __DIR__.'/../apps/hr-portal/includes/payroll-bonus.php';
$db=new PDO('mysql:host=127.0.0.1;port=33329;dbname=bonus_fixture;charset=utf8mb4','root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TABLE payslips (id INT PRIMARY KEY, net_salary DECIMAL(10,2) NOT NULL) ENGINE=InnoDB');
$db->exec('INSERT INTO payslips VALUES (1,5000.00)');
if(!hrBonusEnsureSchema($db) || !hrBonusEnsureSchema($db)) throw new RuntimeException('Migration or repeat migration failed');
$row=$db->query('SELECT net_salary,bonus_amount,bonus_paye FROM payslips WHERE id=1')->fetch(PDO::FETCH_NUM);
if(array_map('floatval',$row)!==[5000.0,0.0,0.0]) throw new RuntimeException('Existing payroll changed');
$db->exec('UPDATE payslips SET bonus_amount=2500,bonus_paye=250 WHERE id=1');
if(!hrBonusEnsureSchema($db)) throw new RuntimeException('Repeat migration failed');
$row=$db->query('SELECT net_salary,bonus_amount,bonus_paye FROM payslips WHERE id=1')->fetch(PDO::FETCH_NUM);
if(array_map('floatval',$row)!==[5000.0,2500.0,250.0]) throw new RuntimeException('Existing bonus changed');
echo "MariaDB schema migration, idempotence and existing payroll preservation passed\n";
