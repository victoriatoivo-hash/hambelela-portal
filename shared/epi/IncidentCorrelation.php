<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Explicit reviewed identity links, never fuzzy matching customer names or error text. */
final class IncidentCorrelation
{
    /** Correct all evidence for one root atomically; partial reassignment is forbidden. */
    public static function correctRoot(PDO $db,string $root,array $destination,int $reviewer,string $reason,string $at):void
    {
        $activation=V2Store::activation($db);
        if(!$activation || $reviewer!==(int)$activation['approved_by'] || trim($reason)==='')
            throw new RuntimeException('Owner review and correction reason required.');
        $at=Support::timestamp($at)->format('Y-m-d H:i:s');
        V2Store::transaction($db,function()use($db,$root,$destination,$reviewer,$reason,$at){
            V2Store::lock($db,'incident-correlation');
            $q=$db->prepare('SELECT * FROM epi_v2_incident_correlations WHERE root_incident_id=? AND superseded_at IS NULL FOR UPDATE');
            $q->execute([$root]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$rows)throw new RuntimeException('No active root to correct.');
            $changed=false;
            foreach($rows as $row){
                if($at<$row['reviewed_at'])throw new RuntimeException('Correction precedes existing review.');
                foreach(['employee_id','opportunity_key','category_key','metric_key'] as $key)
                    if(!array_key_exists($key,$destination))throw new RuntimeException('Complete correction destination required.');
                    elseif((string)$destination[$key]!== (string)$row[$key])$changed=true;
            }
            if(!$changed)return;
            $db->prepare('UPDATE epi_v2_incident_correlations SET superseded_at=? WHERE root_incident_id=? AND superseded_at IS NULL')->execute([$at,$root]);
            foreach($rows as $row)self::link($db,array_merge($destination,[
                'source_key'=>$row['source_key'],'root_incident_id'=>$root]),$reviewer,$reason,$at);
            V2Store::audit($db,'incident-root|'.$root,$reviewer,$reason,$rows,$destination);
        });
    }

    public static function link(PDO $db,array $input,int $reviewer,string $reason,string $at):void
    {
        $activation=V2Store::activation($db);
        if(!$activation || $reviewer!==(int)$activation['approved_by'])throw new RuntimeException('Owner review required.');
        foreach(['source_key','root_incident_id','opportunity_key','category_key','metric_key'] as $field)
            if(empty($input[$field]) || strlen((string)$input[$field])>190)throw new RuntimeException('Complete correlation identity required.');
        if(trim($reason)==='')throw new RuntimeException('An auditable correlation reason is required.');
        V2Store::employee($db,(int)($input['employee_id']??0));
        $at=Support::timestamp($at)->format('Y-m-d H:i:s');
        V2Store::transaction($db,function()use($db,$input,$reviewer,$reason,$at){
            V2Store::lock($db,'incident-correlation');
            $other=V2Store::one($db,'SELECT * FROM epi_v2_incident_correlations WHERE root_incident_id=?
                AND source_key<>? AND superseded_at IS NULL LIMIT 1',[$input['root_incident_id'],$input['source_key']]);
            if($other && ((int)$other['employee_id']!==(int)$input['employee_id'] ||
                $other['category_key']!==$input['category_key'] || $other['metric_key']!==$input['metric_key'] ||
                $other['opportunity_key']!==$input['opportunity_key']))throw new RuntimeException('One root must have one confirmed employee, work opportunity and KPI.');
            $current=V2Store::one($db,'SELECT * FROM epi_v2_incident_correlations WHERE source_key=? AND superseded_at IS NULL',[$input['source_key']]);
            if($current){
                $same=true;foreach(['root_incident_id','opportunity_key','category_key','metric_key','employee_id'] as $field)
                    if((string)$current[$field]!==(string)$input[$field])$same=false;
                if($same)return;
                if($at<$current['reviewed_at'])throw new RuntimeException('Correlation correction cannot precede its original review.');
                $db->prepare('UPDATE epi_v2_incident_correlations SET superseded_at=? WHERE id=?')->execute([$at,$current['id']]);
            }
            $db->prepare('INSERT INTO epi_v2_incident_correlations(source_key,root_incident_id,opportunity_key,
                category_key,metric_key,employee_id,reviewed_by,reviewed_at,reason) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
                $input['source_key'],$input['root_incident_id'],$input['opportunity_key'],$input['category_key'],
                $input['metric_key'],$input['employee_id'],$reviewer,$at,$reason]);
            V2Store::audit($db,'incident-correlation|'.$input['source_key'],$reviewer,$reason,$current,$input);
            require_once __DIR__.'/PerformanceRefreshRuntime.php';
            PerformanceRefreshRuntime::invalidate($db,'incident correlation corrected');
        });
    }

    public static function current(PDO $db,string $source):?array
    {
        return V2Store::one($db,'SELECT * FROM epi_v2_incident_correlations WHERE source_key=? AND superseded_at IS NULL',[$source]);
    }
}
