<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required.');
    $session=(string)($_SESSION['kpi_settings_csrf']??'');$given=(string)($_POST['csrf_token']??'');
    if($session===''||$given===''||!hash_equals($session,$given))throw new RuntimeException('Your session expired. Refresh and try again.');
    $employeeId=(int)($_POST['employee_id']??0);$periodFrom=trim((string)($_POST['period_from']??''));$periodTo=trim((string)($_POST['period_to']??''));$action=trim((string)($_POST['reward_action']??''));$reason=trim((string)($_POST['reason']??''));
    if($employeeId<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$periodFrom)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$periodTo))throw new RuntimeException('Valid employee and reward period are required.');
    if(!in_array($action,['approve','reject','adjust_score'],true))throw new RuntimeException('Choose a valid reward action.');
    if($reason==='')throw new RuntimeException('A reason is required and will be retained in the audit log.');
    $tier=substr(trim((string)($_POST['tier']??'')),0,40);$reward=substr(trim((string)($_POST['chosen_reward']??'')),0,255);$value=substr(trim((string)($_POST['reward_value']??'')),0,80);$score=($_POST['score']??'')===''?null:max(0,min(100,(float)$_POST['score']));$adjustment=($_POST['score_adjustment']??'')===''?null:max(-100,min(100,(float)$_POST['score_adjustment']));
    db()->exec("CREATE TABLE IF NOT EXISTS epi_reward_decision_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,period_from DATE NOT NULL,period_to DATE NOT NULL,action VARCHAR(32) NOT NULL,recommended_tier VARCHAR(40) NULL,score DECIMAL(6,2) NULL,score_adjustment DECIMAL(6,2) NULL,chosen_reward VARCHAR(255) NULL,reward_value VARCHAR(80) NULL,reason TEXT NOT NULL,actor_employee_id INT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_reward_employee_period(employee_id,period_from,period_to),INDEX idx_reward_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt=db()->prepare('INSERT INTO epi_reward_decision_log(employee_id,period_from,period_to,action,recommended_tier,score,score_adjustment,chosen_reward,reward_value,reason,actor_employee_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$actor=ops_current_employee_id();$stmt->execute([$employeeId,$periodFrom,$periodTo,$action,$tier,$score,$adjustment,$reward,$value,$reason,$actor?:null]);$id=(int)db()->lastInsertId();
    ops_activity_log('performance_reward_'.$action,'performance_reward',$id,['employee_id'=>$employeeId,'period_from'=>$periodFrom,'period_to'=>$periodTo,'recommended_tier'=>$tier,'score'=>$score,'score_adjustment'=>$adjustment,'chosen_reward'=>$reward,'reward_value'=>$value,'reason'=>$reason,'actor_employee_id'=>$actor,'immutable'=>true]);
    echo json_encode(['ok'=>true,'message'=>'Reward decision recorded in the immutable log.','log_id'=>$id],JSON_UNESCAPED_SLASHES);
} catch(Throwable $error){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES);}
