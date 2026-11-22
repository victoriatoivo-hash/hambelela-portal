<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/OwnerProofViewer.php';
require_once __DIR__.'/dashboard/Data.php';
require_once __DIR__.'/DriverProfileData.php';
require_once __DIR__.'/DriverNotificationsData.php';
require_once __DIR__.'/DriverLiveRouteData.php';
require_once __DIR__.'/ProofContext.php';
require_once __DIR__.'/IssueContext.php';

/** Owner observation only. Never changes session identity or invokes driver commands. */
final class OwnerDriverView
{
    private \PDO $db;
    private int $owner;
    private OwnerProofViewer $proof;
    public const SCREENS=['home','deliveries','details','map','proof','issues','errands','messages','notifications','history','cash','profile'];
    public function __construct(\PDO $db,int $owner){$this->db=$db;$this->owner=$owner;$this->proof=new OwnerProofViewer($db,$owner);}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    public function authorize(int $driver):array
    {
        $this->proof->authorize();
        try{return EmployeeDriverSession::actor($this->db,$driver);}catch(\DomainException $e){throw new \RuntimeException('Active driver unavailable.',404);}
    }
    public function drivers():array
    {
        $this->proof->authorize();
        return $this->rows("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.status='active' AND r.role_key='delivery_driver' AND p.active=1 ORDER BY e.full_name,e.id");
    }
    public static function id($value):int
    {
        if(!is_scalar($value)||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value))throw new \RuntimeException('Invalid record.',422);
        return (int)$value;
    }
    public function job(int $driver,int $id):array
    {
        $this->authorize($driver);$r=$this->rows('SELECT * FROM delivery_jobs WHERE id=? AND driver_employee_id=?',[$id,$driver])[0]??null;
        if(!$r)throw new \RuntimeException('This record is not assigned to the selected driver.',404);return $r;
    }
    public function audit(int $driver,string $category,?int $record=null):void
    {
        $this->authorize($driver);
        // Reuse the durable journal with a separate owner-only namespace. Not a driver activity event.
        $stamp=new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek'));
        $data=json_encode(['actor'=>$this->owner,'selected_driver'=>$driver,'category'=>$category,'record'=>$record,'at'=>$stamp->format('c'),'event'=>'owner_read_only_access'],JSON_THROW_ON_ERROR);
        $bytes=bin2hex(random_bytes(16));$uuid=substr($bytes,0,8).'-'.substr($bytes,8,4).'-4'.substr($bytes,13,3).'-a'.substr($bytes,17,3).'-'.substr($bytes,20,12);
        $this->db->prepare("INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json,created_at) VALUES(?,?,'owner_view_access',?,?,?)")->execute(['owner-view:'.$this->owner,$uuid,hash('sha256',$data),$data,$stamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    }
    public function activity(int $driver):array
    {
        $this->authorize($driver);
        return $this->rows("SELECT id,event_type,created_at FROM (SELECT id,CONCAT('delivery: ',event_type) event_type,created_at FROM delivery_events WHERE employee_id=? UNION ALL SELECT id,CONCAT('errand: ',action) event_type,created_at FROM delivery_errand_events WHERE actor_id=?) activity ORDER BY created_at DESC,id DESC LIMIT 12",[$driver,$driver]);
    }
    public function notifications(int $driver,array $p=[]):array
    {
        $filter=(string)($p['filter']??'all');$before=(string)($p['cursor']??'');
        if(!in_array($filter,['all','unread','deliveries','errands'],true)||($before!==''&&!ctype_digit($before)))throw new \RuntimeException('Invalid inbox filter.',422);
        $feed=DriverInbox::feed($this->db,$this->authorize($driver),(int)$before,$filter);$items=$feed['items'];
        return ['items'=>array_map(static fn($r)=>DriverNotificationsData::display($r,time()),$items),'unread'=>$feed['counts']['all'],'server_time'=>time(),'filter'=>$filter,'next'=>$feed['more']&&$items?(string)end($items)['id']:null,'read_all_token'=>'','preferences'=>['sound_enabled'=>false,'desktop_enabled'=>false,'state'=>'read_only']];
    }
    private function notificationTarget(int $driver,int $notice):array
    {
        $feed=DriverInbox::feed($this->db,$this->authorize($driver),$notice+1);$item=null;
        foreach($feed['items'] as $row)if((int)$row['id']===$notice)$item=$row;
        if(!$item)throw new \RuntimeException('Notification unavailable for this driver.',404);
        $source=$this->rows('SELECT related_type FROM notifications WHERE id=?',[$notice])[0]??[];
        $targets=['delivery_errand'=>'errands','delivery_errand_request'=>'errands','delivery_chat'=>'messages','delivery_cash_handover'=>'cash'];
        if(($source['related_type']??'')==='delivery_job')return ['screen'=>in_array($item['kind'],['issue_reported','issue_decision'],true)?'issues':'details','id'=>(int)$item['job']];
        $screen=$targets[$source['related_type']??'']??null;
        if(!$screen)throw new \RuntimeException('Notification destination unavailable.',404);
        return ['screen'=>$screen,'id'=>null];
    }
    public function summary(int $driver):array
    {
        $this->authorize($driver);$home=Dashboard\Data::read($this->db,$driver);$profile=(new DriverProfileData($this->db))->read($driver);
        $activity=$this->activity($driver);$opening=$this->rows("SELECT response_json,created_at FROM delivery_requests WHERE actor_key=? AND action='driver_app_open' ORDER BY created_at DESC LIMIT 1",['driver-app:'.$driver])[0]??null;
        $latest=null;
        foreach($this->rows("SELECT j.id FROM delivery_jobs j JOIN delivery_events e ON e.delivery_id=j.id AND e.event_type='delivered' WHERE j.driver_employee_id=? GROUP BY j.id ORDER BY MAX(e.created_at) DESC LIMIT 20",[$driver]) as $r){
            $d=$this->proof->read((int)$r['id']);$files=array_values(array_filter($d['proof']['files'],static fn($f)=>$f['driver_id']===$driver));
            if(!$files)continue;$f=$files[count($files)-1];
            if(!$latest||$f['recorded_at']>$latest['at'])$latest=['id'=>(int)$r['id'],'reference'=>$d['reference'],'customer'=>$d['job']['customer_name'],'at'=>$f['recorded_at'],'kind'=>$f['kind'],'key'=>$f['id'],'recipient'=>$d['proof']['receipts'][count($d['proof']['receipts'])-1]['recipient']['recipient_name']??null,'reviewed'=>!empty($d['review'])&&$d['review']['evidence_version']===$d['version']&&empty($d['open'])];
        }
        $this->authorize($driver);$this->audit($driver,'summary');
        return ['driver'=>['id'=>$driver,'name'=>$profile['driver']['display_name'],'initials'=>$profile['driver']['initials'],'duty'=>null,'contact'=>null,'app_open'=>$opening?json_decode($opening['response_json'],true):null],
            'assignment'=>$home['progress'],'next'=>$home['nextDelivery'],'cash'=>['amount'=>$home['quickActions']['cashDueNad'],'meaning'=>'Driver-recorded custody remaining; not independently reconciled Cash Book income.'],
            'proof'=>$latest,'notifications'=>array_slice($this->notifications($driver)['items'],0,3),'activities'=>array_slice($activity,0,5),'as_of'=>(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('c')];
    }
    /** Strict read operation allow-list. No action name supplied by a driver script is executed. */
    public function read(int $driver,string $op,array $p=[]):array
    {
        $actor=$this->authorize($driver);$allowed=['home'=>[],'deliveries'=>[],'profile'=>[],'cash'=>[],'map'=>[],'details'=>['id'],'errands'=>['id'],'messages'=>['after','before'],'notifications'=>['filter','cursor'],'notification_target'=>['id'],'history'=>['period','status','query','search','from','to','cursor'],'history_detail'=>['event_id']];
        if(!isset($allowed[$op]))throw new \RuntimeException('Owner preview is read-only. Action not permitted.',405);
        if(array_diff(array_keys($p),$allowed[$op]))throw new \RuntimeException('Unsupported read parameters.',422);
        foreach($p as $v)if($v!==null&&(!is_scalar($v)||strlen((string)$v)>700))throw new \RuntimeException('Invalid read parameters.',422);
        $this->audit($driver,$op,isset($p['id'])?self::id($p['id']):null);
        switch($op){
            case 'home':$out=Dashboard\Data::read($this->db,$driver);break;
            case 'deliveries':$out=DriverAssignments::read($this->db,$driver);$out['unreadNotifications']=DriverInbox::feed($this->db,$actor)['counts']['all'];break;
            case 'profile':$out=(new DriverProfileData($this->db))->read($driver);break;
            case 'cash':$out=(new DriverRecordedHandover($this->db))->summary($driver);$out['unread_notifications']=DriverInbox::feed($this->db,$actor)['counts']['all'];break;
            case 'map':$out=DriverLiveRouteData::read($this->db,$driver);break;
            case 'details':
                $id=self::id($p['id']??null);$job=$this->job($driver,$id);
                // The owner may inspect historical assignments already authorized above.
                // Select the saved scheduling day, not today's driver queue. This is a
                // read-only projection of current records, not a change to driver eligibility.
                $day=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$job['scheduled_date'],new \DateTimeZone('Africa/Windhoek'));
                if(!$day||$day->format('Y-m-d')!==$job['scheduled_date'])throw new \RuntimeException('Assignment date unavailable.',404);
                $out=DriverOrderDetailsData::read($this->db,$driver,$id,$day);break;
            case 'errands':$out=ErrandData::board($this->db,$driver);if(!empty($p['id']))$out['focus']=ErrandData::detail($this->db,$driver,self::id($p['id']));break;
            case 'messages':$after=isset($p['after'])?max(0,(int)$p['after']):0;$before=isset($p['before'])?max(0,(int)$p['before']):0;$out=(new DispatchChat($this->db))->feed($driver,0,$after,$before);break;
            case 'notifications':$out=$this->notifications($driver,$p);break;
            case 'notification_target':$out=$this->notificationTarget($driver,self::id($p['id']??null));break;
            case 'history':$out=(new DeliveryHistory($this->db))->page($driver,$p);break;
            case 'history_detail':$out=(new DeliveryHistory($this->db))->detail($driver,self::id($p['event_id']??null));break;
        }
        $this->authorize($driver);return $out;
    }
    public function evidence(int $driver,int $id,string $key):array
    {
        $this->job($driver,$id);$d=$this->proof->read($id);$valid=false;
        foreach($d['proof']['files'] as $f)if($f['driver_id']===$driver&&hash_equals($f['id'],$key))$valid=true;
        if(!$valid)throw new \RuntimeException('Evidence unavailable for the selected driver.',404);
        $f=$this->proof->file($id,$key);$this->audit($driver,'proof_evidence',$id);return $f;
    }
    public function render(int $driver,string $screen,?int $id=null):string
    {
        $actor=$this->authorize($driver);if(!in_array($screen,self::SCREENS,true))throw new \RuntimeException('Screen unavailable.',404);
        $this->audit($driver,'screen_'.$screen,$id);
        $local=false;$base=rtrim(BASE_URL,'/');$error=null;$selected=null;$unavailable=false;$assignmentId=$id??0;$driverId=$driver;$requested=0;$phone=null;$mapEnabled=false;$reference=false;
        switch($screen){
            case 'home':$dashboard=$this->read($driver,'home');$view='dashboard';break;
            case 'deliveries':$data=$this->read($driver,'deliveries');$view='deliveries';break;
            case 'details':$data=$this->read($driver,'details',['id'=>$id]);$view='order-details';break;
            case 'map':$data=$this->read($driver,'map');$view='live-route';break;
            case 'proof':$this->job($driver,(int)$id);$ctx=ProofContext::read($this->db,$driver,(int)$id);$view='proof';break;
            case 'issues':$job=$this->job($driver,(int)$id);$c=IssueContext::read($this->db,$actor,$job);$view='issues';break;
            case 'errands':$view='errands';break;
            case 'messages':$view='dispatch';$driver=true;break;
            case 'notifications':$view='notifications';break;
            case 'history':$view='history';break;
            case 'cash':$view='cash';$driver=true;break;
            case 'profile':$view='profile';break;
        }
        // Render only shared presentation partials, never driver controllers. Session identity is untouched.
        $bufferLevel=ob_get_level();ob_start();try{if(in_array($view,['order-details','live-route','proof','issues'],true))require __DIR__.'/'.$view.'/Helpers.php';require __DIR__.'/'.$view.'/View.php';$html=(string)ob_get_clean();
            $html=preg_replace('/\sdata-csrf="[^"]*"/i',' data-csrf=""',$html);
            $this->authorize((int)$actor['id']);return preg_replace('/<input\b[^>]*type="hidden"[^>]*>/i','',$html);
        }catch(\Throwable $e){if(ob_get_level()>$bufferLevel)ob_end_clean();throw $e;}
    }
}
