<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
require_once __DIR__.'/PerformanceRefreshRuntime.php';

/** Manual evidence uses the existing conversation/message/follow-up sources.
 * No API connection is invented and no quality judgement is inferred from notes.
 */
final class OrderCommunicationCapture
{
    public static function install(PDO $db):void
    {
        // Explicit CLI migration only, never a constructor or GET path.
        if(PHP_SAPI!=='cli')throw new RuntimeException('Explicit migration required');
        foreach(['ops_whatsapp_conversations','ops_whatsapp_messages'] as $table){
            $q=$db->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'platform\'');$q->execute([$table]);$type=$q->fetchColumn();$q->closeCursor();
            if(!is_string($type)||!preg_match("/^enum\\('[a-z_]+'(?:,'[a-z_]+')*\\)$/D",$type))throw new RuntimeException('Inspect communication platform schema before migration');
            $original=$type;foreach(['call','email'] as $channel)if(strpos($type,"'".$channel."'")===false)$type=substr($type,0,-1).",'".$channel."')";
            if($type!==$original)$db->exec("ALTER TABLE $table MODIFY platform $type NOT NULL DEFAULT 'whatsapp'");
        }
    }
    public static function enabled(PDO $db):?string
    {return V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_manual_communication_from'")['setting_value']??null;}
    public static function save(PDO $db,int $order,int $actor,array $in,bool $owner=false,?string $now=null):array
    {
        V2Store::employee($db,$actor);$at=Support::timestamp($now)->format('Y-m-d H:i:s');
        if($owner&&$actor!==(int)(V2Store::activation($db)['approved_by']??0))throw new RuntimeException('Approved owner identity required');
        if(!self::enabled($db))throw new RuntimeException('Communication evidence capture is not activated');
        $uuid=(string)($in['event_uuid']??'');$kind=(string)($in['contact_type']??'');$channel=(string)($in['channel']??'');
        $outcome=trim((string)($in['outcome']??''));$proof=trim((string)($in['evidence_reference']??''));
        $employee=(int)($in['responsible_employee_id']??$actor);V2Store::employee($db,$employee);
        if(!preg_match('/^[a-f0-9-]{36}$/Di',$uuid)||!in_array($kind,['inquiry','response','followup_completed','handover_requested','handover_accepted','escalation'],true)
            ||!in_array($channel,['whatsapp','call','email'],true)||$outcome===''||strlen($outcome)>1500||$proof===''||strlen($proof)>500)throw new RuntimeException('Contact type, channel, outcome and evidence reference are required');
        $contact=self::date((string)($in['contact_at']??''));if($contact>$at)throw new RuntimeException('A contact cannot occur in the future');
        $follow=(string)($in['follow_up_required']??'');if(!in_array($follow,['yes','no'],true))throw new RuntimeException('Choose whether a follow-up is required');
        $due=$follow==='yes'?self::date((string)($in['promised_follow_up_at']??'')):null;
        if($due&&$due<=$at)throw new RuntimeException('A new promise must have a future deadline. Record an earlier missed promise through reviewed incident evidence; do not backdate ownership');
        return V2Store::transaction($db,static function()use($db,$order,$actor,$in,$owner,$at,$uuid,$kind,$channel,$outcome,$proof,$employee,$contact,$follow,$due){
            V2Store::lock($db,'order-communication|'.$order);
            $o=V2Store::one($db,'SELECT id,customer_name,customer_contact FROM ops_orders WHERE id=? AND deleted_at IS NULL',[$order]);if(!$o)throw new RuntimeException('Order not found');
            $existing=V2Store::one($db,'SELECT m.id,m.conversation_id,c.order_id FROM ops_whatsapp_messages m JOIN ops_whatsapp_conversations c ON c.id=m.conversation_id WHERE m.wa_message_id=?',['manual-contact:'.$uuid]);
            if($existing){if((int)$existing['order_id']!==$order)throw new RuntimeException('Contact request belongs to another order');return ['conversation_id'=>(int)$existing['conversation_id'],'message_id'=>(int)$existing['id'],'duplicate'=>true];}
            $id=(int)($in['conversation_id']??0);$c=$id?V2Store::one($db,'SELECT * FROM ops_whatsapp_conversations WHERE id=? AND order_id=? FOR UPDATE',[$id,$order]):null;
            if($id&&!$c)throw new RuntimeException('Conversation does not belong to this order');
            if(!$c){
                if($employee!==$actor&&!$owner)throw new RuntimeException('Accept your own communication responsibility; transfer other work through handover');
                if($kind!=='inquiry')throw new RuntimeException('Record the customer inquiry first so response timing has a real start');
                $db->prepare("INSERT INTO ops_whatsapp_conversations(platform,source,customer_name,customer_phone,order_id,assigned_employee_id,status,created_by,created_at) VALUES(?,'manual',?,?,?,?,'awaiting_response',?,?)")->execute([$channel,$o['customer_name'],$o['customer_contact'],$order,$employee,$actor,$at]);
                $id=(int)$db->lastInsertId();
                V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$id,['employee_id'=>$actor,'occurred_at'=>$at,'event_uuid'=>$uuid.':assignment']);
                $c=V2Store::one($db,'SELECT * FROM ops_whatsapp_conversations WHERE id=?',[$id]);
            }
            $responsible=(int)$c['assigned_employee_id'];
            if(in_array($kind,['response','followup_completed'],true)&&!empty($c['first_customer_message_at'])&&$contact<$c['first_customer_message_at'])throw new RuntimeException('A response cannot precede its customer inquiry');
            if($kind==='handover_accepted'){
                $request=V2Store::one($db,'SELECT raw_payload FROM ops_whatsapp_messages WHERE id=? AND conversation_id=?',[(int)($in['handover_message_id']??0),$id]);$request=json_decode($request['raw_payload']??'{}',true)?:[];
                if(($request['contact_type']??'')!=='handover_requested'||(int)($request['transfer_to']??0)!==$actor||(int)($request['responsible_employee_id']??0)!==$responsible)throw new RuntimeException('Only the requested incoming employee can accept this unchanged handover');
                $db->prepare('UPDATE ops_whatsapp_conversations SET assigned_employee_id=? WHERE id=?')->execute([$actor,$id]);$responsible=$actor;
                V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$id,['employee_id'=>$actor,'occurred_at'=>$at,'event_uuid'=>$uuid.':accepted']);
            }elseif($responsible!==$actor&&!$owner&&$kind!=='response'&&$kind!=='followup_completed')throw new RuntimeException('Only the responsible employee or owner can change this communication assignment');
            $transfer=null;if($kind==='handover_requested'){$transfer=(int)($in['transfer_to']??0);V2Store::employee($db,$transfer);if($transfer===$responsible)throw new RuntimeException('Choose another employee for handover');}
            $body=['contact_type'=>$kind,'channel'=>$channel,'contact_at'=>$contact,'recorded_at'=>$at,'outcome'=>$outcome,'evidence_reference'=>$proof,
                'responsible_employee_id'=>$responsible,'actor_employee_id'=>$actor,'follow_up_required'=>$follow,'promised_follow_up_at'=>$due,
                'follow_up_completed_at'=>$kind==='followup_completed'?$contact:null,'transfer_to'=>$transfer,'handover_message_id'=>(int)($in['handover_message_id']??0),
                'quality_state'=>'not_reviewed','manual_evidence'=>true];
            $direction=$kind==='inquiry'?'inbound':'outbound';
            $db->prepare("INSERT INTO ops_whatsapp_messages(conversation_id,platform,wa_message_id,direction,message_type,message_text,message_at,employee_id,raw_payload) VALUES(?,?,?,?,'manual_contact',?,?,?,?)")->execute([$id,$channel,'manual-contact:'.$uuid,$direction,$outcome,$contact,$actor,Support::json($body)]);
            $message=(int)$db->lastInsertId();
            if($kind==='inquiry'){
                $db->prepare('UPDATE ops_whatsapp_conversations SET first_customer_message_at=COALESCE(first_customer_message_at,?),last_customer_message_at=?,last_message_at=? WHERE id=?')->execute([$contact,$contact,$contact,$id]);
                V2OperationalBridge::record($db,'ops_whatsapp_conversation','whatsapp_message_received',$id,['employee_id'=>0,'direction'=>'inbound','occurred_at'=>$contact,'event_uuid'=>$uuid]);
            }elseif(in_array($kind,['response','followup_completed'],true)){
                $db->prepare('UPDATE ops_whatsapp_conversations SET first_response_at=COALESCE(first_response_at,?),last_staff_response_at=?,last_message_at=? WHERE id=?')->execute([$contact,$contact,$contact,$id]);
                V2OperationalBridge::record($db,'ops_whatsapp_conversation','whatsapp_message_saved',$id,['employee_id'=>$actor,'direction'=>'outbound','occurred_at'=>$contact,'event_uuid'=>$uuid,'prevent_assignment'=>true]);
                $interval=(new OwnershipPeriodEngine($db))->ownerAt('Orders','CONVERSATION-'.$id,$contact);
                $start=(string)($c['first_customer_message_at']??$c['created_at']);
                $mode=$interval?PerformanceCapturePolicy::captureMode($db,(int)$interval['employee_id'],$start):0;
                $db->prepare('INSERT IGNORE INTO epi_v2_completed_work_units(opportunity_key,module,object_reference,employee_id,fulfiller_id,starts_at,completed_at,ownership_json,source_snapshot_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
                    'ops_whatsapp_conversation:'.$id.':contact:'.$message,'Orders','CONVERSATION-'.$id,$interval['employee_id']??null,$actor,$start,$contact,Support::json($interval),
                    Support::json(['capture_mode'=>$mode,'manual_contact'=>$body,'message_id'=>$message,'native_measurement'=>[
                        'measured'=>false,'outcome'=>'success','expected'=>'Provide accurate customer communication supported by reviewed evidence',
                        'actual'=>'Communication quality is not reviewed; no accuracy success/failure is inferred']])]);
                if($kind==='followup_completed'){
                    $pending=V2Store::one($db,"SELECT id,due_at FROM ops_whatsapp_followups WHERE conversation_id=? AND status='pending' ORDER BY id LIMIT 1 FOR UPDATE",[$id]);if(!$pending)throw new RuntimeException('There is no open promised follow-up to complete');
                    $db->prepare("UPDATE ops_whatsapp_followups SET status='done',completed_at=? WHERE id=?")->execute([$contact,$pending['id']]);
                    (new DeadlineEngine($db))->fulfilObject('Orders','CONVERSATION-'.$id,'customer_followup',$actor,$contact);
                    $db->prepare('UPDATE ops_whatsapp_conversations SET follow_up_at=NULL WHERE id=?')->execute([$id]);
                }
            }
            if($due){
                if(V2Store::one($db,"SELECT id FROM ops_whatsapp_followups WHERE conversation_id=? AND status='pending' LIMIT 1",[$id]))throw new RuntimeException('Complete the existing promise before adding another; do not silently extend its deadline');
                $db->prepare('INSERT INTO ops_whatsapp_followups(conversation_id,due_at,reason,created_by) VALUES(?,?,?,?)')->execute([$id,$due,function_exists('mb_substr')?mb_substr($outcome,0,255):substr($outcome,0,255),$actor]);
                $db->prepare("UPDATE ops_whatsapp_conversations SET follow_up_at=?,status='follow_up' WHERE id=?")->execute([$due,$id]);
                V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$id,['employee_id'=>$actor,'occurred_at'=>$at,'event_uuid'=>$uuid.':promise']);
            }
            V2Store::audit($db,'communication-contact|'.$message,$actor,'Recorded real customer contact / accepted responsibility',null,$body+['order_id'=>$order,'conversation_id'=>$id]);
            PerformanceRefreshRuntime::invalidate($db,'order communication evidence');
            return ['conversation_id'=>$id,'message_id'=>$message,'duplicate'=>false];
        });
    }
    public static function read(PDO $db,int $order):array
    {
        $q=$db->prepare("SELECT m.id,m.conversation_id,m.platform,m.message_at,m.raw_payload,e.full_name actor,c.assigned_employee_id,c.follow_up_at,u.source_snapshot_json FROM ops_whatsapp_messages m JOIN ops_whatsapp_conversations c ON c.id=m.conversation_id LEFT JOIN ops_employees e ON e.id=m.employee_id LEFT JOIN epi_v2_completed_work_units u ON u.opportunity_key=CONCAT('ops_whatsapp_conversation:',c.id,':contact:',m.id) WHERE c.order_id=? AND m.message_type='manual_contact' ORDER BY m.id DESC LIMIT 100");$q->execute([$order]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($rows as &$r){$r['contact']=json_decode($r['raw_payload']??'{}',true)?:[];$r['quality_review']=json_decode($r['source_snapshot_json']??'{}',true)['communication_quality_review']??null;unset($r['raw_payload'],$r['source_snapshot_json']);}unset($r);
        return ['enabled'=>(bool)self::enabled($db),'records'=>$rows];
    }
    public static function reviewQuality(PDO $db,int $message,int $owner,string $decision,string $proof,string $reason,?string $now=null):void
    {
        $m=V2Store::one($db,"SELECT conversation_id,message_at FROM ops_whatsapp_messages WHERE id=? AND message_type='manual_contact'",[$message]);if(!$m)throw new RuntimeException('Manual contact not found');
        $key='ops_whatsapp_conversation:'.$m['conversation_id'].':contact:'.$message;
        self::reviewUnit($db,$key,$owner,$decision,$proof,$reason,$now);
    }
    public static function reviewUnit(PDO $db,string $key,int $owner,string $decision,string $proof,string $reason,?string $now=null):void
    {
        if(!preg_match('/^ops_whatsapp_conversation:[1-9][0-9]*:(?:0|contact:[1-9][0-9]*)$/D',$key)||$owner!==(int)(V2Store::activation($db)['approved_by']??0)||!in_array($decision,['accurate','system','business','supplier','courier','customer'],true)||trim($proof)===''||trim($reason)===''||strlen($proof)>500||strlen($reason)>1500)throw new RuntimeException('Owner, explicit quality decision and supporting evidence/reason required');
        V2Store::transaction($db,static function()use($db,$key,$owner,$decision,$proof,$reason,$now){
            V2Store::lock($db,'communication-quality|'.$key);$u=V2Store::one($db,'SELECT * FROM epi_v2_completed_work_units WHERE opportunity_key=? FOR UPDATE',[$key]);
            if(!$u||!$u['employee_id'])throw new RuntimeException('Resolve original communication responsibility before quality review');
            $s=json_decode($u['source_snapshot_json'],true)?:[];
            if(!in_array((int)($s['capture_mode']??0),[1,2],true))throw new RuntimeException('Only previously approved/provisional captured work can be reviewed; historical shadow evidence cannot be promoted');
            $h=V2Store::one($db,'SELECT locked_at FROM epi_v2_employee_result_heads WHERE employee_id=? AND period_start=?',[$u['employee_id'],substr($u['completed_at'],0,7).'-01']);
            if(!empty($h['locked_at']))throw new RuntimeException('Locked period: propose an audited correction instead');
            if(isset($s['communication_quality_review']))throw new RuntimeException('Original audit already recorded; corrections require the existing incident review workflow');
            $s['communication_quality_review']=['reviewer'=>$owner,'at'=>Support::timestamp($now)->format('Y-m-d H:i:s'),'decision'=>$decision,'proof'=>$proof,'reason'=>$reason];
            $s['native_measurement']=['measured'=>$decision==='accurate','outcome'=>'success','responsibility_type'=>$decision==='accurate'?'employee':$decision,
                'expected'=>'Provide accurate customer communication supported by reviewed evidence','actual'=>$decision==='accurate'?'Owner verified accurate communication: '.$reason:'Owner reviewed non-employee cause; excluded: '.$reason,
                'evidence_reference'=>$proof,'reviewer_id'=>$owner];
            V2Store::audit($db,'communication-quality|'.$key,$owner,$reason,json_decode($u['source_snapshot_json'],true),$s);
            $db->prepare('UPDATE epi_v2_completed_work_units SET source_snapshot_json=? WHERE opportunity_key=?')->execute([Support::json($s),$key]);
            PerformanceRefreshRuntime::invalidate($db,'communication quality reviewed');
        });
    }
    public static function qualityCandidates(PDO $db,int $employee,string $period):array
    {
        $q=$db->prepare("SELECT opportunity_key,object_reference,completed_at,source_snapshot_json FROM epi_v2_completed_work_units WHERE employee_id=? AND completed_at>=? AND completed_at<? AND opportunity_key LIKE 'ops_whatsapp_conversation:%' ORDER BY completed_at DESC LIMIT 200");$q->execute([$employee,$period,Support::timestamp($period)->modify('+1 month')->format('Y-m-d')]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$out=[];
        foreach($rows as $r){$s=json_decode($r['source_snapshot_json'],true)?:[];if((int)($s['capture_mode']??0)!==2||!isset($s['native_measurement'])||isset($s['communication_quality_review']))continue;unset($r['source_snapshot_json']);$out[]=$r;}return $out;
    }
    public static function requireCompletion(PDO $db,array $ids):void
    {
        $from=self::enabled($db);if(!$from)return;
        foreach($ids as $id){$o=V2Store::one($db,'SELECT id,created_at,status,fulfilment_mode,order_type FROM ops_orders WHERE id=?',[(int)$id]);
            if(!$o||in_array($o['status'],['completed','cancelled'],true))continue;
            // Database TIMESTAMP values may be returned in UTC. Use the durable
            // Namibia release event, not a mixed-timezone created_at comparison.
            $release=V2Store::one($db,"SELECT payload_json FROM epi_v2_outbox WHERE entity_type='order' AND entity_id=? AND action='order_created' ORDER BY id LIMIT 1",[$id]);
            $released=json_decode($release['payload_json']??'{}',true)['metadata']['occurred_at']??null;
            if(!$released||$released<$from)continue;
            $tracking=V2Store::one($db,'SELECT classification FROM epi_v2_orders_tracking WHERE order_id=?',[$id]);
            $mode=$tracking['classification']??(($o['fulfilment_mode']??'')?:$o['order_type']);if(!in_array($mode,['collection','courier','windhoek_delivery','delivery'],true))continue;
            if(!V2Store::one($db,"SELECT m.id FROM ops_whatsapp_messages m JOIN ops_whatsapp_conversations c ON c.id=m.conversation_id WHERE c.order_id=? AND ((m.message_type='manual_contact' AND JSON_UNQUOTE(JSON_EXTRACT(m.raw_payload,'$.contact_type')) IN('response','followup_completed')) OR (m.message_type<>'manual_contact' AND m.direction='outbound' AND m.employee_id>0 AND c.first_customer_message_at IS NOT NULL AND m.message_at>=c.first_customer_message_at)) LIMIT 1",[$id]))throw new RuntimeException('Record the customer communication outcome in this order’s Communication tab before completing it. An open customer promise remains tracked separately.');
        }
    }
    private static function date(string $value):string
    {$value=str_replace('T',' ',$value);if(strlen($value)===16)$value.=':00';$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('Africa/Windhoek'));if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('Provide a valid contact/promise date and time');return $value;}
}
