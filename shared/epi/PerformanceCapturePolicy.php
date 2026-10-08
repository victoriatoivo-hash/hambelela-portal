<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Prospective eligibility only. Never promotes/re-writes historical shadow evidence. */
final class PerformanceCapturePolicy
{
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
