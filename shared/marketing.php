<?php
declare(strict_types=1);
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/employee-features.php';

function marketing_schema_ready():void{
 static $ready=false;if($ready)return;
 db()->exec("INSERT IGNORE INTO ops_roles(role_key,name,description)VALUES('marketing_sales','Marketing & Sales Assistant','Marketing production, website content and limited sales cover')");
 db()->exec("CREATE TABLE IF NOT EXISTS marketing_work_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190) NOT NULL,content_type VARCHAR(50) NOT NULL,platform VARCHAR(50) NULL,campaign VARCHAR(190) NULL,product_reference VARCHAR(190) NULL,brief TEXT NULL,objective TEXT NULL,target_audience VARCHAR(190) NULL,owner_notes TEXT NULL,required_dimensions VARCHAR(80) NULL,due_at DATETIME NULL,publish_at DATETIME NULL,priority VARCHAR(20) NOT NULL DEFAULT 'normal',approval_required TINYINT(1) NOT NULL DEFAULT 1,assigned_employee_id INT NULL,status VARCHAR(40) NOT NULL DEFAULT 'brief',caption TEXT NULL,cta VARCHAR(255) NULL,hashtags TEXT NULL,published_url VARCHAR(500) NULL,published_at DATETIME NULL,proof_file VARCHAR(255) NULL,revision_count INT NOT NULL DEFAULT 0,quality_revision_count INT NOT NULL DEFAULT 0,created_by INT NOT NULL,created_by_name VARCHAR(190) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,cancelled_at DATETIME NULL,KEY idx_marketing_status(status,due_at),KEY idx_marketing_assignee(assigned_employee_id,status),KEY idx_marketing_publish(publish_at,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 db()->exec("CREATE TABLE IF NOT EXISTS marketing_item_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,version_label VARCHAR(40) NOT NULL,file_name VARCHAR(255) NOT NULL,stored_name VARCHAR(255) NOT NULL,mime_type VARCHAR(120) NOT NULL,file_size BIGINT UNSIGNED NOT NULL,asset_status VARCHAR(30) NOT NULL DEFAULT 'draft',uploaded_by INT NOT NULL,uploaded_by_name VARCHAR(190) NOT NULL,uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_marketing_versions(item_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 db()->exec("CREATE TABLE IF NOT EXISTS marketing_item_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,old_status VARCHAR(40) NULL,new_status VARCHAR(40) NOT NULL,note TEXT NULL,actor_id INT NOT NULL,actor_name VARCHAR(190) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_marketing_history(item_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $ready=true;
}
function marketing_is_owner():bool{return current_role_key()==='owner_admin';}
function marketing_require_access():void{require_login();if(!portal_user_can_access_feature('marketing')){http_response_code(403);exit('You do not have access to Marketing.');}marketing_schema_ready();}
function marketing_employee_id():int{return(int)(current_user()['id']??0);}
function marketing_scope_sql(array&$params):string{if(marketing_is_owner())return'1=1';$params[]=marketing_employee_id();return'assigned_employee_id=?';}
function marketing_e(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function marketing_csrf():string{if(empty($_SESSION['marketing_csrf']))$_SESSION['marketing_csrf']=bin2hex(random_bytes(24));return(string)$_SESSION['marketing_csrf'];}
function marketing_verify():void{if(!hash_equals(marketing_csrf(),(string)($_POST['csrf']??'')))throw new RuntimeException('Your session expired. Refresh and try again.');}
function marketing_statuses():array{return['brief','to_create','in_progress','ready_for_review','changes_requested','approved','scheduled','published','cancelled'];}
function marketing_notify(int$itemId,int$recipient,string$title,string$message):void{if($recipient<=0)return;try{require_once BASE_PATH.'/shared/notifications.php';notifications_create(['title'=>$title,'message'=>$message,'module'=>'marketing','related_type'=>'marketing_item','related_id'=>$itemId,'action_link'=>BASE_URL.'/apps/marketing/index.php?item='.$itemId,'required_delivery'=>1],[$recipient]);}catch(Throwable $e){error_log('Marketing notification failed: '.$e->getMessage());}}
