<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/shared/delivery/DispatchChat.php';
use Hambelela\Delivery\{DispatchChat,DispatchFiles};
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='driver_review')throw new RuntimeException('Local review database only.');
// Reviewed additive migration; no production credentials or config included.
$db->exec(file_get_contents(BASE_PATH.'/database/delivery/008-dispatch-messages-proposed.sql'));
$root=dirname(BASE_PATH).'/driver-proof-private-review';putenv('HO_DRIVER_PROOF_DIR='.$root);
if(!is_file($root.'/dispatch-audit.key')){file_put_contents($root.'/dispatch-audit.key',bin2hex(random_bytes(32)),LOCK_EX);chmod($root.'/dispatch-audit.key',0600);}
if(!is_file($root.'/dispatch-policy.json'))file_put_contents($root.'/dispatch-policy.json',json_encode(['dispatchPhone'=>null,'phoneVerified'=>false,'attachmentRetentionDays'=>null,'pdfInfoBinary'=>getenv('LOCAL_PDFINFO')?:null,'virusScanner'=>null],JSON_PRETTY_PRINT));
$s=new DispatchChat($db);
$texts=['Good morning. Your first 3 practice parcels are ready for collection.','Morning. I have collected the practice parcels and confirmed the quantities.','Great. Please deliver Pioneers Park first, then Olympia. The customer at Olympia requested a call before arrival.','Understood. I will call before I arrive.'];
foreach($texts as $i=>$body){$actor=$i%2?3:2;$s->send($actor,3,['key'=>'08000000-0000-4000-8000-00000000000'.($i+1),'body'=>$body,'urgent'=>false]);}
echo "Local Phase08 tables and four synthetic messages ready. No read receipts were fabricated.\n";
