<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Prospective eligibility only. Never promotes/re-writes historical shadow evidence. */
final class PerformanceCapturePolicy
{
    /** 0 excluded, 1 approved prospective, 2 provisional prospective.
     * This is evaluated at capture, never used to promote existing shadow rows.
     */
    public static function captureMode(PDO $db,int $employee,string $at):int
    {
        if(self::approved($db,$employee,$at))return 1;
        if($employee<1)return 0;
        $flag=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_capture_enabled'");
        if(($flag['setting_value']??'0')!=='1')return 0;
        $activation=V2Store::activation($db);
        if(!$activation || $at<$activation['enforcement_start_at'])return 0;
        $row=V2Store::one($db,'SELECT a.assigned_by,s.policy_json,s.policy_hash FROM epi_v2_scorecard_assignments a
            JOIN epi_v2_scorecard_documents s ON s.version=a.scorecard_version
            WHERE a.employee_id=? AND a.period_start=?',[$employee,substr($at,0,7).'-01']);
        if(!$row || (int)$row['assigned_by']!==(int)$activation['approved_by'] ||
            !hash_equals($row['policy_hash'],hash('sha256',$row['policy_json'])))return 0;
        $policy=json_decode($row['policy_json'],true)?:[];$window=$policy['provisional_capture']??[];
        if(($policy['status']??'')!=='provisional' || ($window['enabled']??false)!==true ||
            ($policy['financial_use_allowed']??true)!==false ||
            (int)($window['authorised_by']??0)!==(int)$activation['approved_by'])return 0;
        foreach(['from','authorised_at'] as $key){
            $value=$window[$key]??'';$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
            if(!$date || $date->format('Y-m-d H:i:s')!==$value || $value>$at)return 0;
        }
        return 2;
    }

    public static function metadata(int $mode):array
    {
        return ['excluded_from_scoring'=>!in_array($mode,[1,2],true),
            'mode'=>$mode===1?'approved_prospective':($mode===2?'provisional_prospective':'shadow')];
    }

    public static function approved(PDO $db,int $employee,string $at):bool
    {
        if($employee<1)return false;
        $flag=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_official_capture_enabled'");
        if(($flag['setting_value']??'0')!=='1')return false;
        $period=substr($at,0,7).'-01';$activation=V2Store::activation($db);
        if(!$activation || $period.' 00:00:00'<$activation['enforcement_start_at'])return false;
        $row=V2Store::one($db,'SELECT a.*,s.policy_json,s.policy_hash FROM epi_v2_scorecard_assignments a
            JOIN epi_v2_scorecard_documents s ON s.version=a.scorecard_version
            WHERE a.employee_id=? AND a.period_start=?',[$employee,$period]);
        if(!$row || !$row['official_from'] || $row['official_from']>$period ||
            !$row['validation_approved_at'] || $row['validation_approved_at']>$at ||
            (int)$row['validation_approved_by']!==(int)$activation['approved_by'])return false;
        if(!hash_equals($row['policy_hash'],hash('sha256',$row['policy_json'])))return false;
        $policy=json_decode($row['policy_json'],true);
        return ($policy['status']??'')==='approved';
    }
}
