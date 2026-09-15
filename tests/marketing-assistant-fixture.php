<?php
declare(strict_types=1);
// Test-only in-memory adapter. Never loads config.php or production credentials.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '');
define('APP_NAME', 'Essentials — local fixture');
date_default_timezone_set('Africa/Windhoek');
$fixtureRole = 'marketing_sales';
$fixtureUserId = 20;
class MarketingFixturePDO extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace(' FOR UPDATE','',$query);
        $query = str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(item_id,channel_key) DO UPDATE SET',$query);
        $query = preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$query);
        $query = str_replace('IF(?="published",COALESCE(published_at,NOW()),published_at)','CASE WHEN ?="published" THEN COALESCE(published_at,CURRENT_TIMESTAMP) ELSE published_at END',$query);
        return parent::prepare($query,$options);
    }
    public function exec(string $statement): int|false {
        $statement = str_replace(' ON UPDATE CURRENT_TIMESTAMP','',$statement);
        $statement = preg_replace('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/','',$statement);
        return parent::exec($statement);
    }
}
$fixtureDb = new MarketingFixturePDO();
function db(): PDO { return $GLOBALS['fixtureDb']; }
function current_user(): array { return ['id'=>$GLOBALS['fixtureUserId'],'name'=>'Demo Marketing Assistant','role_key'=>$GLOBALS['fixtureRole']]; }
function current_role_key(): string { return $GLOBALS['fixtureRole']; }
function user_has_role(string ...$roles): bool { return in_array(current_role_key(),$roles,true); }
function marketing_is_owner(): bool { return current_role_key()==='owner_admin'; }
function marketing_employee_id(): int { return $GLOBALS['fixtureUserId']; }
function marketing_e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function marketing_csrf(): string { return 'fixture-only-token'; }
function marketing_verify(): void { if (($_POST['csrf']??'')!==marketing_csrf()) throw new RuntimeException('Invalid request token.'); }
function marketing_require_access(): void { if (!portal_user_can_access_feature('marketing')) { http_response_code(403); exit('Access denied.'); } }
function marketing_statuses(): array { return ['brief','to_create','in_progress','ready_for_review','changes_requested','approved','scheduled','published','cancelled']; }
function marketing_notify(...$args): void { if (!empty($GLOBALS['fixtureNoticeFail'])) throw new RuntimeException('Fixture notification outage'); }
function marketing_column_exists(string $table,string $column): bool { foreach(db()->query('PRAGMA table_info('.$table.')')->fetchAll() as $row)if($row['name']===$column)return true;return false; }
function marketing_scope_sql(array &$params): string { if(marketing_is_owner())return '1=1';$params[]=marketing_employee_id();return 'assigned_employee_id=?'; }
function notifications_summary_for_current_user(int $limit): array { return ['unread_count'=>0,'latest'=>[],'preferences'=>['sound_enabled'=>1,'desktop_enabled'=>0,'sound_volume'=>65]]; }
require_once BASE_PATH.'/shared/employee-features.php';
require_once BASE_PATH.'/shared/marketing-execution.php';
db()->exec('CREATE TABLE marketing_work_items(id INTEGER PRIMARY KEY,title TEXT,content_type TEXT,platform TEXT,priority TEXT DEFAULT "normal",status TEXT,approval_required INTEGER DEFAULT 1,assigned_employee_id INTEGER,created_by INTEGER DEFAULT 1,cancelled_at TEXT,caption TEXT,due_at TEXT,publish_at TEXT,published_at TEXT,published_url TEXT,brief TEXT,campaign TEXT,product_reference TEXT,required_dimensions TEXT,cta TEXT)');
db()->exec('CREATE TABLE marketing_item_versions(id INTEGER PRIMARY KEY,item_id INTEGER,file_name TEXT,version_label TEXT)');
db()->exec('CREATE TABLE marketing_item_history(id INTEGER PRIMARY KEY,item_id INTEGER,old_status TEXT,new_status TEXT,note TEXT,actor_id INTEGER,actor_name TEXT)');
db()->exec('CREATE TABLE marketing_product_changes(id INTEGER PRIMARY KEY,product_name TEXT,status TEXT,change_notes TEXT,assigned_employee_id INTEGER,updated_at TEXT,proposed_name TEXT,proposed_short_description TEXT,proposed_description TEXT,proposed_seo_title TEXT,proposed_meta_description TEXT,submitted_at TEXT)');
db()->exec("INSERT INTO marketing_product_changes(id,product_name,status,change_notes,assigned_employee_id) VALUES(1,'Assigned product copy','draft','Prepare updated website copy.',20),(2,'Other employee product','draft','Private brief.',21)");
marketing_execution_schema_ready();
foreach ([[1,'Assigned promotion','social_post','instagram','approved',20],[2,'Other employee private brief','social_post','facebook','approved',21],[3,'Awaiting owner review','newsletter','newsletter','in_progress',20]] as $row) {
 db()->prepare('INSERT INTO marketing_work_items(id,title,content_type,platform,status,assigned_employee_id,brief,caption,due_at,publish_at) VALUES(?,?,?,?,?,?,"Prepare the supplied copy for each required channel.","Prepared caption",?,?)')->execute([...$row,date('Y-m-d').' 16:00:00',date('Y-m-d').' 17:00:00']);
}
foreach (['instagram','whatsapp','website'] as $key) db()->prepare('INSERT INTO marketing_channel_execution(item_id,channel_key,instructions) VALUES(1,?,?)')->execute([$key,'Complete the '.$key.' instructions.']);
