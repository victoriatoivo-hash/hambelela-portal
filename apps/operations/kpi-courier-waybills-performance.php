<?php

declare(strict_types=1);

/*
 * Courier KPI reporting only. This service never writes operational waybills.
 * `sent_date` is the upload form's batch/service date; its evidence label is
 * preserved so older records are not presented as having a stronger source.
 */

function kpi_courier_next_applicable_day(DateTimeImmutable $serviceDate, string $rule, array $holidays): ?DateTimeImmutable
{
    if (!in_array($rule, ['calendar_day', 'business_day', 'courier_service_day'], true)) return null;
    $cursor = $serviceDate->modify('+1 day');
    if ($rule === 'calendar_day') return $cursor;
    for ($i = 0; $i < 14; $i++) {
        $isWeekend = (int) $cursor->format('N') >= 6;
        $isHoliday = in_array($cursor->format('Y-m-d'), $holidays, true);
        if (!$isWeekend && !$isHoliday) return $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    return null;
}

function kpi_courier_percentile(array $values, float $percentile): ?float
{
    if (!$values) return null;
    sort($values, SORT_NUMERIC);
    $position = ($percentile / 100) * (count($values) - 1);
    $lower = (int) floor($position); $upper = (int) ceil($position);
    return $values[$lower] + (($values[$upper] - $values[$lower]) * ($position - $lower));
}

function kpi_courier_time_label(?float $seconds): ?string
{
    if ($seconds === null) return null;
    $seconds = (int) round($seconds);
    return sprintf('%02d:%02d', intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60));
}

function kpi_courier_waybills_performance(?array $employee, string $fromSql, string $toSql, array $settings, array $holidays): array
{
    $zone = new DateTimeZone('Africa/Windhoek');
    $followingRule = (string) ($settings['courier_following_applicable_day_rule'] ?? 'business_day');
    if ($followingRule === '' || $followingRule === 'not_configured') $followingRule = 'business_day';
    $uploadCutoff = preg_match('/^\d{2}:\d{2}$/',(string)($settings['courier_sameday_cutoff']??''))?(string)$settings['courier_sameday_cutoff']:'17:00';
    $sendCutoff = preg_match('/^\d{2}:\d{2}$/',(string)($settings['courier_nextday_cutoff']??''))?(string)$settings['courier_nextday_cutoff']:'09:00';
    $morningInference = (string) ($settings['courier_morning_inference_enabled'] ?? '0') === '1';
    $lateTarget = max(0, (int) ($settings['courier_late_response_target_minutes'] ?? 0));
    $employeeId = $employee ? (int) ($employee['id'] ?? 0) : 0;
    $roleKey = strtolower((string) ($employee['role_key'] ?? ''));
    $roleView = strpos($roleKey, 'packer') !== false ? 'packer' : (strpos($roleKey, 'front') !== false ? 'front' : 'all');

    $raw = ops_rows(
        "SELECT w.id,w.batch_id,w.sent_date,w.courier_names,w.number_of_waybills,w.uploaded_at,w.uploaded_by,
                w.sent_at,w.sent_by,w.status,w.notes,w.waybill_reference,w.order_id,w.customer_name,w.due_by,
                up.full_name uploaded_by_name,ur.role_key uploaded_role_key,
                sp.full_name sent_by_name,sr.role_key sent_role_key
         FROM hambelela_waybills w
         LEFT JOIN ops_employees up ON up.id=w.uploaded_by LEFT JOIN ops_roles ur ON ur.id=up.role_id
         LEFT JOIN ops_employees sp ON sp.id=w.sent_by LEFT JOIN ops_roles sr ON sr.id=sp.role_id
         WHERE w.deleted_at IS NULL
           AND ((w.sent_date BETWEEN DATE(?) AND DATE(?)) OR (w.sent_date IS NULL AND w.uploaded_at BETWEEN ? AND ?))
         ORDER BY w.uploaded_at,w.id",
        [$fromSql, $toSql, $fromSql, $toSql]
    );

    $batches = [];
    foreach ($raw as $record) {
        $batchKey = (string) ($record['batch_id'] ?: 'row-' . $record['id']);
        if (!isset($batches[$batchKey])) $batches[$batchKey] = $record + ['record_ids'=>[], 'files'=>0, 'declared_waybills'=>max(1,(int)($record['number_of_waybills']??1))];
        $batches[$batchKey]['record_ids'][] = (int) $record['id'];
        $batches[$batchKey]['files']++;
        $batches[$batchKey]['declared_waybills']=max((int)$batches[$batchKey]['declared_waybills'],max(1,(int)($record['number_of_waybills']??1)));
        if (!empty($record['sent_at']) && (empty($batches[$batchKey]['sent_at']) || $record['sent_at'] < $batches[$batchKey]['sent_at'])) {
            $batches[$batchKey]['sent_at'] = $record['sent_at'];
            $batches[$batchKey]['sent_by'] = $record['sent_by'];
            $batches[$batchKey]['sent_by_name'] = $record['sent_by_name'];
        }
    }

    $rows=[]; $turnaround=[]; $uploadTimes=[];
    $counts=['batches'=>0,'waybills'=>0,'sent_waybills'=>0,'combined'=>0,'review'=>0,'packer_eligible'=>0,'packer_on_time'=>0,'packer_late'=>0,'within_window'=>0,'next_morning'=>0,'front_eligible'=>0,'front_on_time'=>0,'front_late'=>0,'blocked_late_upload'=>0,'customer_eligible'=>0,'customer_on_time'=>0,'customer_late'=>0,'pending'=>0];
    foreach ($batches as $batch) {
        $uploadedAt = !empty($batch['uploaded_at']) ? new DateTimeImmutable((string) $batch['uploaded_at'], $zone) : null;
        $sentAt = !empty($batch['sent_at']) ? new DateTimeImmutable((string) $batch['sent_at'], $zone) : null;
        $serviceDate = null; $serviceSource = 'service_date_unavailable'; $review=false;
        if (!empty($batch['sent_date'])) {
            $serviceDate = new DateTimeImmutable((string) $batch['sent_date'] . ' 00:00:00', $zone);
            $serviceSource = 'current_record_batch_date_sent_date_field';
        } elseif ($uploadedAt && (int)$uploadedAt->format('H') < 12) {
            $serviceDate = $uploadedAt->modify('-1 day')->setTime(0,0);
            $serviceSource = 'inferred_previous_day_from_morning_upload';
            $review = !$morningInference;
        } else $review = true;

        $waybillCount=max(1,(int)($batch['declared_waybills']??$batch['number_of_waybills']??1));
        $combined = $waybillCount > 1 || (int)$batch['files'] > 1;
        if ($combined) $counts['combined']++;
        $serviceReview = $review;
        $uploadDeadline = $serviceDate ? $serviceDate->setTime((int)substr($uploadCutoff,0,2),(int)substr($uploadCutoff,3,2)) : null;
        $windowStart = $serviceDate ? $serviceDate->setTime(14,0) : null;
        $dueDate = $serviceDate ? kpi_courier_next_applicable_day($serviceDate, $followingRule, $holidays) : null;
        $customerDeadline = $dueDate ? $dueDate->setTime((int)substr($sendCutoff,0,2),(int)substr($sendCutoff,3,2)) : null;
        if (!$customerDeadline) $review=true;

        $packerResult='Service date unavailable'; $uploadDelay=null;
        if ($uploadedAt && $uploadDeadline && !$serviceReview) {
            $counts['packer_eligible']++;
            if ($uploadedAt <= $uploadDeadline) {
                $counts['packer_on_time']++;
                if ($uploadedAt < $windowStart) $packerResult='Uploaded early';
                else { $packerResult='Uploaded within window'; $counts['within_window']++; }
            } else {
                $counts['packer_late']++; $uploadDelay=(int)(($uploadedAt->getTimestamp()-$uploadDeadline->getTimestamp())/60);
                if ($uploadedAt->format('Y-m-d') === $serviceDate->format('Y-m-d')) $packerResult='Uploaded late - same day';
                elseif ($uploadedAt->format('Y-m-d') === $serviceDate->modify('+1 day')->format('Y-m-d') && (int)$uploadedAt->format('H') < 12) { $packerResult='Uploaded late - next morning'; $counts['next_morning']++; }
                else $packerResult='Uploaded late - later day';
            }
        } elseif ($uploadedAt && $serviceDate) $packerResult='Requires review';

        $availableBeforeDeadline = $uploadedAt && $customerDeadline && $uploadedAt <= $customerDeadline;
        $frontResult='Requires review'; $lateResponse=null;
        if ($customerDeadline && $uploadedAt) {
            if (!$availableBeforeDeadline) {
                $counts['blocked_late_upload']++;
                if ($sentAt && $sentAt >= $uploadedAt) { $frontResult='Sent after late availability'; $lateResponse=(int)(($sentAt->getTimestamp()-$uploadedAt->getTimestamp())/60); }
                else $frontResult='Blocked by late packer upload';
            } elseif (!$serviceReview) {
                $counts['front_eligible']++;
                if ($sentAt && $sentAt <= $customerDeadline) { $frontResult=$sentAt==$customerDeadline?'Sent at deadline':'Sent before deadline'; $counts['front_on_time']++; }
                elseif ($sentAt) { $frontResult='Sent late'; $counts['front_late']++; }
                else { $frontResult=(new DateTimeImmutable('now',$zone)) <= $customerDeadline?'Pending - deadline upcoming':'Pending - overdue'; }
            }
        }

        $customerResult='Requires review'; $responsibility='Requires review';
        if ($customerDeadline && !$serviceReview) {
            $counts['customer_eligible']++;
            if ($sentAt && $sentAt <= $customerDeadline) { $customerResult='On time'; $counts['customer_on_time']++; $responsibility='None'; }
            else {
                $customerResult=$sentAt?'Late':'Pending overdue'; $counts['customer_late']++;
                if (!$availableBeforeDeadline) $responsibility='Packer';
                elseif ($packerResult==='Uploaded late - next morning' && $sentAt && $sentAt>$customerDeadline) $responsibility='Shared';
                else $responsibility='Front person';
            }
        }
        if (!$sentAt) $counts['pending']++;
        $duration = ($uploadedAt && $sentAt && $sentAt >= $uploadedAt) ? (int)(($sentAt->getTimestamp()-$uploadedAt->getTimestamp())/60) : null;
        $businessDuration = ($uploadedAt && $sentAt && $sentAt >= $uploadedAt && function_exists('kpi_business_minutes')) ? kpi_business_minutes($uploadedAt,$sentAt,$holidays) : null;
        if ($duration !== null) $turnaround[]=$duration;
        if ($uploadedAt) $uploadTimes[]=(int)$uploadedAt->format('H')*3600+(int)$uploadedAt->format('i')*60+(int)$uploadedAt->format('s');

        $row=[
            'id'=>(int)$batch['id'],'record_id'=>(int)$batch['id'],'timeline_module'=>'waybill','batch_id'=>$batch['batch_id'],
            'waybill_reference'=>$batch['waybill_reference'],'order_id'=>$batch['order_id'],'customer_name'=>$batch['customer_name'],
            'courier'=>$batch['courier_names'] ?: 'Unclassified courier','service_date'=>$serviceDate?$serviceDate->format('Y-m-d'):null,
            'service_date_source'=>$serviceSource,'uploaded_at'=>$batch['uploaded_at'],'uploaded_by'=>(int)$batch['uploaded_by'],
            'uploaded_by_name'=>$batch['uploaded_by_name'] ?: 'Uploader unavailable','upload_deadline'=>$uploadDeadline?$uploadDeadline->format('Y-m-d H:i:s'):null,
            'packer_result'=>$packerResult,'upload_delay_minutes'=>$uploadDelay,'sent_at'=>$batch['sent_at'],'sent_by'=>(int)($batch['sent_by']??0),
            'sent_by_name'=>$batch['sent_by_name'] ?: 'Sending actor unavailable','customer_deadline'=>$customerDeadline?$customerDeadline->format('Y-m-d H:i:s'):null,
            'front_result'=>$frontResult,'customer_result'=>$customerResult,'delay_responsibility'=>$responsibility,
            'packer_eligible'=>!$serviceReview&&$uploadDeadline!==null,'front_eligible'=>!$serviceReview&&$availableBeforeDeadline&&$customerDeadline!==null,
            'late_availability_response_minutes'=>$lateResponse,'late_response_target_minutes'=>$lateTarget?:null,
            'upload_to_send_minutes'=>$duration,'business_minutes'=>$businessDuration,'status'=>$batch['status'],'notes'=>$batch['notes'],'combined_batch'=>$combined,
            'waybill_count'=>$waybillCount,'file_count'=>(int)$batch['files'],'evidence_quality'=>$review?'requires_review':'exact_and_current_record_evidence',
            'review_reason'=>$serviceSource==='service_date_unavailable'?'Service date unavailable':($review?'Inferred service date needs confirmation':null)
        ];
        $include = !$employeeId || ($roleView==='packer' ? (int)$row['uploaded_by']===$employeeId : ($roleView==='front' ? ((int)$row['sent_by']===$employeeId||($row['front_eligible']&&!$row['sent_at'])) : ((int)$row['uploaded_by']===$employeeId||(int)$row['sent_by']===$employeeId)));
        if ($include) { $rows[]=$row; $counts['batches']++;$counts['waybills']+=$waybillCount;if($sentAt)$counts['sent_waybills']+=$waybillCount;if($review)$counts['review']++; }
    }

    if ($employeeId) {
        foreach (array_keys($counts) as $key) $counts[$key]=0;
        $counts['batches']=count($rows);
        foreach ($rows as $row) {
            $counts['waybills']+=(int)$row['waybill_count'];if(!empty($row['sent_at']))$counts['sent_waybills']+=(int)$row['waybill_count'];
            $review=(string)$row['evidence_quality']==='requires_review'; if($review)$counts['review']++;
            if($row['combined_batch'])$counts['combined']++;
            if(!empty($row['packer_eligible'])) { $counts['packer_eligible']++; if(strpos((string)$row['packer_result'],'Uploaded late')===0)$counts['packer_late']++; else $counts['packer_on_time']++; }
            if($row['customer_deadline']&&$row['service_date_source']!=='service_date_unavailable') { $counts['customer_eligible']++; if($row['customer_result']==='On time')$counts['customer_on_time']++; else $counts['customer_late']++; }
            if($row['front_result']==='Sent after late availability'||$row['front_result']==='Blocked by late packer upload')$counts['blocked_late_upload']++;
            elseif(!empty($row['front_eligible'])) { $counts['front_eligible']++; if(in_array($row['front_result'],['Sent before deadline','Sent at deadline'],true))$counts['front_on_time']++; elseif($row['front_result']==='Sent late')$counts['front_late']++; }
            if(empty($row['sent_at']))$counts['pending']++;
            if($row['packer_result']==='Uploaded within window')$counts['within_window']++;
            if($row['packer_result']==='Uploaded late - next morning')$counts['next_morning']++;
        }
        $turnaround=array_values(array_filter(array_column($rows,'business_minutes'),static function($v){return $v!==null;}));
        $uploadTimes=[]; foreach($rows as$row)if(!empty($row['uploaded_at'])){$dt=new DateTimeImmutable((string)$row['uploaded_at'],$zone);$uploadTimes[]=(int)$dt->format('H')*3600+(int)$dt->format('i')*60;}
    }
    $packerRate=$counts['packer_eligible']?round(100*$counts['packer_on_time']/$counts['packer_eligible'],1):null;
    $frontRate=$counts['front_eligible']?round(100*$counts['front_on_time']/$counts['front_eligible'],1):null;
    $customerRate=$counts['customer_eligible']?round(100*$counts['customer_on_time']/$counts['customer_eligible'],1):null;
    $courierBreakdown=[];foreach($rows as$row){$courier=trim((string)$row['courier'])?:'Unclassified courier';if(!isset($courierBreakdown[$courier]))$courierBreakdown[$courier]=['courier'=>$courier,'upload_batches'=>0,'waybills_uploaded'=>0,'waybills_sent'=>0,'pending_waybills'=>0];$qty=max(1,(int)$row['waybill_count']);$courierBreakdown[$courier]['upload_batches']++;$courierBreakdown[$courier]['waybills_uploaded']+=$qty;if(!empty($row['sent_at']))$courierBreakdown[$courier]['waybills_sent']+=$qty;else$courierBreakdown[$courier]['pending_waybills']+=$qty;}usort($courierBreakdown,static fn($a,$b)=>$b['waybills_uploaded']<=>$a['waybills_uploaded']);
    return [
        'title'=>'Courier Waybills Performance','role_view'=>$roleView,'settings'=>['following_applicable_day_rule'=>$followingRule,'morning_inference_enabled'=>$morningInference,'late_response_target_minutes'=>$lateTarget?:null,'timezone'=>'Africa/Windhoek'],
        'counts'=>$counts,'packer_on_time_rate'=>$packerRate,'front_on_time_rate'=>$frontRate,'customer_on_time_rate'=>$customerRate,
        'metrics'=>[
            ['label'=>'Upload Batches','value'=>$counts['batches'],'explanation'=>'Number of upload actions/batches attributed to this employee. A batch may contain more than one waybill.'],
            ['label'=>'Waybills Uploaded','value'=>$counts['waybills'],'explanation'=>'Physical waybill quantity from number_of_waybills, not merely the number of upload actions.'],
            ['label'=>'Waybills Sent','value'=>$counts['sent_waybills'],'explanation'=>'Physical waybill quantity in batches with a recorded Sent timestamp.'],
            ['label'=>'Packer Uploads by 17:00','value'=>$packerRate,'format'=>'percent','numerator'=>$counts['packer_on_time'],'denominator'=>$counts['packer_eligible'],'explanation'=>'Upload batches available by 17:00 on the courier service date.'],
            ['label'=>'Front-Person Sends by 09:00','value'=>$frontRate,'format'=>'percent','numerator'=>$counts['front_on_time'],'denominator'=>$counts['front_eligible'],'explanation'=>'Eligible waybill batches sent by 09:00 on the next working day. Batches uploaded after their availability deadline are excluded from this employee denominator.'],
            ['label'=>'Customer Deadline Met','value'=>$customerRate,'format'=>'percent','numerator'=>$counts['customer_on_time'],'denominator'=>$counts['customer_eligible']],
            ['label'=>'Blocked by late upload','value'=>$counts['blocked_late_upload']],
            ['label'=>'Pending','value'=>$counts['pending']],
            ['label'=>'Median upload time','value'=>kpi_courier_time_label(kpi_courier_percentile($uploadTimes,50)),'format'=>'time'],
            ['label'=>'Median upload-to-send','value'=>kpi_courier_percentile($turnaround,50),'format'=>'minutes','sample'=>count($turnaround)],
            ['label'=>'Average upload-to-send','value'=>$turnaround?round(array_sum($turnaround)/count($turnaround),1):null,'format'=>'minutes','sample'=>count($turnaround)],
            ['label'=>'Slow-End Handling Time (90th Percentile)','value'=>count($turnaround)>=10?kpi_courier_percentile($turnaround,90):null,'format'=>'minutes','sample'=>count($turnaround),'explanation'=>'Business handling time from upload to Sent. Ninety percent of measured batches were sent within this duration; the slowest ten percent took longer.'],
            ['label'=>'Incomplete Source Evidence','value'=>$counts['review'],'status'=>$counts['review']?'warning':'ok','explanation'=>'Only missing or inferred service-date evidence is listed here. Valid multi-waybill uploads do not require owner approval.'],
        ],'rows'=>$rows,'courier_breakdown'=>array_values($courierBreakdown),
        'reference_only'=>false,'courier_waybills_performance'=>true,
        'methodology'=>'Customer, packer and front-person results are separate. Records unavailable after 08:00 never enter the front-person on-time denominator.'
    ];
}
