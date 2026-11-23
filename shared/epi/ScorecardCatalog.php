<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Installation templates, not runtime business policy. Installed versions live in the database.
 * Unspecified internal weights/sample thresholds are proposals requiring owner validation.
 */
final class ScorecardCatalog
{
    public static function templates(): array
    {
        $front = [
            'orders'=>self::category('Orders & Fulfilment',2500,[
                'progression'=>['Order response / progression SLA',3000],
                'completion'=>['Completion / next-stage SLA',3500],
                'stale_prevention'=>['Neglected / stale-order prevention',2000],
                'first_time_right'=>['First-time-right',1500]]),
            'communication'=>self::category('Customer Communication & Follow-up',2000,[
                'response'=>['Response SLA',2500], 'followup'=>['Follow-up compliance',2500],
                'accuracy'=>['Communication accuracy',3000], 'handover'=>['Handover / escalation compliance',2000]]),
            'quality'=>self::category('Quality & Accuracy',1500,[
                'first_time_right'=>['Accurate eligible operational work',10000]],true),
            'bookkeeping'=>self::category('Bookkeeping & Cash Control',1500,[
                'completion'=>['Required-entry completion',3000], 'timeliness'=>['Entry SLA',2500],
                'accuracy'=>['Amount / reconciliation accuracy',3000], 'controls'=>['Opening / closing / deposit controls',1500]]),
            'tasks'=>self::category('Tasks & Admin Execution',1000,[
                'completion'=>['Completion rate',3000], 'start'=>['Start SLA',2000],
                'timeliness'=>['Completion SLA',3000], 'compliance'=>['Checklist / note / proof compliance',2000]]),
            'attendance'=>self::category('Attendance & Reliability',500,[
                'presence'=>['Scheduled attendance',5000], 'punctuality'=>['Scheduled punctuality',5000]],true),
            'courier'=>self::category('Courier / Dispatch',500,[
                'timeliness'=>['Dispatch SLA from document availability',5000],
                'compliance'=>['Dispatch / communication completeness',5000]],true),
            'inventory'=>self::category('Inventory / Website Accuracy',500,[
                'timeliness'=>['Assigned update SLA',5000], 'accuracy'=>['Stock / variation accuracy',5000]],true),
        ];
        $packer = [
            'packing'=>self::category('Packing Productivity & Timeliness',3000,[
                'completion'=>['Assigned workload completion',4000], 'start'=>['Start SLA',3000],
                'timeliness'=>['Completion SLA',3000]],true),
            'quality'=>self::category('Packing Accuracy & First-Time-Right',3000,[
                'first_time_right'=>['Correct first-time packed work',10000]],true),
            'orders'=>self::category('Order Fulfilment / Readiness',1500,[
                'progression'=>['Packer-controlled readiness SLA',10000]],true),
            'packing_list'=>self::category('Packing List / Priority / Stock Compliance',1000,[
                'priority'=>['Priority handling',5000], 'compliance'=>['Status / quantity / assigned stock compliance',5000]],true),
            'tasks'=>self::category('Tasks',500,[
                'completion'=>['Completion rate',3000], 'start'=>['Start SLA',2000],
                'timeliness'=>['Completion SLA',3000], 'compliance'=>['Process compliance',2000]],true),
            'attendance'=>self::category('Attendance & Reliability',500,[
                'presence'=>['Scheduled attendance',5000], 'punctuality'=>['Scheduled punctuality',5000]],true),
            'courier'=>self::category('Courier Readiness / Upload',500,[
                'timeliness'=>['Packed by eligible cutoff',5000], 'upload'=>['Required document availability',5000]],true),
        ];
        return [self::policy('front-desk-final-brief-draft-1','front_desk',$front),
            self::policy('packer-final-brief-draft-1','packer',$packer)];
    }

    public static function communicationTypes(): array
    {
        return ['missed_customer_followup','incorrect_customer_information','incorrect_stock_information',
            'incorrect_price_information','incorrect_payment_information','incorrect_delivery_information',
            'incorrect_courier_information','unrecorded_customer_commitment','incomplete_internal_handover',
            'failure_to_escalate','delayed_customer_response','communication_caused_complaint',
            'repeat_communication_failure'];
    }

    private static function category(string $label,int $weight,array $definitions,bool $proposed=false): array
    {
        $metrics=[];
        foreach($definitions as $key=>$definition) $metrics[$key]=[
            'label'=>$definition[0], 'weight_hundredths'=>$definition[1], 'direction'=>'success',
            'minimum_volume'=>5, 'moderate_volume'=>20, 'high_volume'=>100,
            'formula'=>'successful eligible opportunities / eligible opportunities',
        ];
        return ['label'=>$label,'weight_hundredths'=>$weight,'metrics'=>$metrics,
            'internal_weights_status'=>$proposed?'proposed_for_owner_validation':'specified_in_final_brief'];
    }

    private static function policy(string $version,string $role,array $categories): array
    {
        return ['version'=>$version,'role'=>$role,'status'=>'draft','categories'=>$categories,
            'sample_thresholds_status'=>'proposed_for_owner_validation',
            'negative_modifier_cap_hundredths'=>1000,'positive_modifier_cap_hundredths'=>0,
            'financial_use_allowed'=>false];
    }
}
