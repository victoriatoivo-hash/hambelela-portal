<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
require_once __DIR__.'/OperationalMeasurementPolicy.php';
require_once __DIR__.'/PerformanceRefreshRuntime.php';

/** Evidence entry for facts which a portal login or tick cannot prove. Existing
 * profile only; normal scheduled publication calculates the result automatically. */
final class PhysicalEvidenceCapture
{
    public static function schedule(PDO $db,int $employee,int $owner,string $from,string $reason):array
    {
        self::owner($db,$owner,$reason);V2Store::employee($db,$employee);
        if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$from)||Support::timestamp($from)->format('Y-m-d')!==$from||$from<=Support::timestamp()->format('Y-m-d'))throw new RuntimeException('Choose a future schedule start; do not backdate attendance expectations.');
        return V2Store::transaction($db,function()use($db,$employee,$owner,$from,$reason){
            V2Store::lock($db,'attendance-schedule|'.$employee);
            if(V2Store::one($db,'SELECT id FROM epi_attendance_schedules WHERE employee_id=? AND (effective_to IS NULL OR effective_to>=?)',[$employee,$from]))throw new RuntimeException('An attendance schedule already covers this period. Review it instead of creating overlap.');
            foreach(range(1,6) as $day)$db->prepare('INSERT INTO epi_attendance_schedules(employee_id,day_of_week,scheduled_start,scheduled_end,effective_from,approved_by,created_at) VALUES(?,?,?,?,?,?,?)')->execute([
                $employee,$day,$day===6?'09:00:00':'08:00:00',$day===6?'13:00:00':'17:00:00',$from,$owner,Support::timestamp()->format('Y-m-d H:i:s')]);
            V2Store::audit($db,'attendance-schedule|'.$employee,$owner,$reason,null,['from'=>$from,'weekday'=>'08:00–17:00','Saturday'=>'09:00–13:00','Sunday'=>'not scheduled','provisional'=>true]);
            PerformanceRefreshRuntime::invalidate($db,'attendance schedule approved');return ['from'=>$from,'scheduled_weekdays'=>6];
        });
    }
    public static function website(PDO $db,int $employee,int $owner,array $input,?string $now=null):void
    {
        $reason=trim((string)($input['reason']??''));self::owner($db,$owner,$reason);
        $id=(int)($input['packing_task_id']??0);$ref='PACK-'.$id;
        $proof=trim((string)($input['evidence_reference']??''));$product=trim((string)($input['product_reference']??''));
        $expected=self::quantities($input['expected_stock']??'');$actual=self::quantities($input['observed_stock']??'');
        if($id<1||$proof===''||strlen($proof)>500||$product===''||strlen($product)>190||array_keys($expected)!==array_keys($actual))throw new RuntimeException('Provide the packing item, exact product/variation references, comparable stock quantities and physical/website audit proof.');
        $at=Support::timestamp($now)->format('Y-m-d H:i:s');$policy=OperationalMeasurementPolicy::current($db,$at);
        if(!$policy)throw new RuntimeException('Prospective native measurements must be activated first.');
        $d=V2Store::one($db,"SELECT * FROM epi_v2_operational_deadlines WHERE module='Inventory' AND object_reference=? AND obligation_key='update_website_stock' ORDER BY id LIMIT 1",[$ref]);
        if(!$d||!$d['fulfilled_at']||$d['starts_at']<$policy['effective_from'])throw new RuntimeException('Choose a prospectively tracked, completed website update. Historical ticks cannot be re-scored.');
        $evidenceAt=Support::timestamp($input['evidence_at']??'1900-01-01')->format('Y-m-d H:i:s');
        if($evidenceAt!==$d['fulfilled_at'])throw new RuntimeException('The audit must prove stock at this update timestamp, not unrelated current stock.');
        if($evidenceAt>$at)throw new RuntimeException('Future evidence cannot be verified.');
        $responsibilityType=(string)($input['responsibility_type']??'unconfirmed');
        if(!in_array($responsibilityType,['employee','business','system','supplier','courier','customer','unconfirmed'],true))throw new RuntimeException('Choose the confirmed cause of any mismatch.');
        $responsibility=(new OwnershipPeriodEngine($db))->ownerAt('Inventory',$ref,$d['fulfilled_at']);
        if(!$responsibility||(int)$responsibility['employee_id']!==$employee)throw new RuntimeException('The verified Inventory responsibility does not belong to this employee.');
        if(FrontDeskRoster::enabled($db)){
            $availability=HrAbsenceEvidence::inspect($db,HrConnection::connect(),$employee,$d['fulfilled_at']);
            if($availability['state']!=='no_approved_absence')throw new RuntimeException('Resolve approved absence/coverage attribution before recording a website outcome.');
        }
        $mode=PerformanceCapturePolicy::captureMode($db,$employee,$d['starts_at']);
        if($mode!==2)throw new RuntimeException('This capture is provisional only; a shadow or official period cannot be promoted.');
        $matched=$expected===$actual;$key='website_accuracy:'.$id;
        V2Store::transaction($db,function()use($db,$employee,$owner,$reason,$proof,$product,$expected,$actual,$at,$policy,$d,$responsibility,$mode,$matched,$key,$ref,$responsibilityType,$evidenceAt){
            V2Store::lock($db,'physical-evidence|'.$key);
            if(V2Store::one($db,'SELECT opportunity_key FROM epi_v2_completed_work_units WHERE opportunity_key=?',[$key]))throw new RuntimeException('This original website audit is already recorded. Correct responsibility/root evidence through the existing Quality review; do not overwrite the first result.');
            $observation=['category'=>'inventory','metric'=>'accuracy','measured'=>$matched||$responsibilityType==='employee','outcome'=>$matched?'success':'failure',
                'expected'=>'Match the audited expected product/variation stock quantities','actual'=>$matched?'The verified website quantities match the stock audit':'The verified website quantities differ from the stock audit',
                'expected_stock'=>$expected,'observed_stock'=>$actual,'product_reference'=>$product,'evidence_reference'=>$proof,'evidence_at'=>$evidenceAt,'responsibility_type'=>$responsibilityType,'reviewer_id'=>$owner,'reviewed_at'=>$at,'reason'=>$reason];
            $db->prepare('INSERT INTO epi_v2_completed_work_units(opportunity_key,module,object_reference,employee_id,fulfiller_id,starts_at,completed_at,ownership_json,source_snapshot_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
                $key,'Inventory',$ref,$employee,$d['fulfilled_by'],$d['starts_at'],$d['fulfilled_at'],Support::json($responsibility),Support::json([
                    'capture_mode'=>$mode,'score_eligible_at_capture'=>false,'native_measurement'=>$observation,'measurement_policy'=>$policy])]);
            V2Store::audit($db,'website-stock-audit|'.$key,$owner,$reason,null,$observation);
            PerformanceRefreshRuntime::invalidate($db,'verified website stock audit');
        });
    }
    public static function read(PDO $db,int $employee,string $period):array
    {
        $q=$db->prepare('SELECT day_of_week,scheduled_start,scheduled_end,effective_from,effective_to,approved_by FROM epi_attendance_schedules WHERE employee_id=? ORDER BY effective_from,day_of_week');$q->execute([$employee]);$schedules=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $q=$db->prepare('SELECT evidence_date,classification,owner_note,reviewed_at FROM kpi_portal_presence_reviews WHERE employee_id=? AND evidence_date>=? AND evidence_date<? ORDER BY evidence_date,id DESC');$q->execute([$employee,$period,Support::timestamp($period)->modify('+1 month')->format('Y-m-d')]);$attendance=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $policy=OperationalMeasurementPolicy::current($db);
        $q=$db->prepare("SELECT d.object_reference,d.fulfilled_at FROM epi_v2_operational_deadlines d LEFT JOIN epi_v2_completed_work_units u ON u.opportunity_key=CONCAT('website_accuracy:',SUBSTRING(d.object_reference,6)) WHERE d.module='Inventory' AND d.obligation_key='update_website_stock' AND d.fulfilled_at>=? AND d.fulfilled_at<? AND d.starts_at>=? AND u.opportunity_key IS NULL ORDER BY d.fulfilled_at LIMIT 200");
        $q->execute([$period,Support::timestamp($period)->modify('+1 month')->format('Y-m-d'),$policy['effective_from']??'9999-01-01']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$website=[];$owners=new OwnershipPeriodEngine($db);
        foreach($rows as $r){$o=$owners->ownerAt('Inventory',$r['object_reference'],$r['fulfilled_at']);if($o&&(int)$o['employee_id']===$employee)$website[]=$r;}
        return ['schedules'=>$schedules,'attendance_reviews'=>$attendance,'website_updates'=>$website,'measurement_policy'=>OperationalMeasurementPolicy::current($db),'today'=>Support::timestamp()->format('Y-m-d')];
    }
    private static function owner(PDO $db,int $owner,string $reason):void
    {if($owner!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)===''||strlen($reason)>1500)throw new RuntimeException('Owner review and a meaningful evidence reason required.');}
    private static function quantities($json):array
    {
        $data=json_decode((string)$json,true);if(!is_array($data)||!$data||count($data)>100)throw new RuntimeException('Enter quantities as a JSON map of exact product/variation IDs to non-negative stock quantities.');
        $out=[];foreach($data as $key=>$value){if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',(string)$key)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<0)throw new RuntimeException('Invalid product/variation quantity.');$out[(string)$key]=(float)$value;}ksort($out);return $out;
    }
}
