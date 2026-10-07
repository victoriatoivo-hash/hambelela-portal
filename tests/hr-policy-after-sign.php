<?php
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$before=json_decode(file_get_contents(__DIR__.'/../verification/synthetic-before.json'),true);
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
check($before['signed']===$db->query('SELECT * FROM hr_policy_acknowledgements WHERE employee_id=1')->fetchAll(),'existing signed acknowledgement byte-for-byte unchanged after new signature');
check($before['versions']===$db->query('SELECT * FROM hr_policy_versions')->fetchAll(),'published policy and all versions unchanged after new signature');
check($db->query('SELECT status FROM hr_policy_assignments WHERE employee_id=2')->fetchColumn()==='acknowledged','new assignment becomes acknowledged');
check((bool)$db->query('SELECT resolved_at FROM hr_policy_notifications WHERE user_id=12')->fetchColumn(),'notification resolves after signing');
check((int)$db->query('SELECT COUNT(*) FROM hr_policy_notifications WHERE user_id=12')->fetchColumn()===1,'no duplicate notification after policy navigation and signature');
check((int)$db->query('SELECT COUNT(*) FROM hr_policy_acknowledgements WHERE employee_id=2 AND signed_at IS NOT NULL AND acknowledgement_reference IS NOT NULL')->fetchColumn()===1,'new signed acknowledgement and receipt reference preserved');
$portal=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_portal_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
check($portal->query('SELECT cleared_at FROM notification_recipients')->fetchColumn()!==null,'Main Portal policy notification resolves after real synthetic signature');
