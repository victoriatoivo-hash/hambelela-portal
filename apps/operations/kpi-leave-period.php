<?php
declare(strict_types=1);
function kpi_leave_period_rows(array $rows,string $from,string $to): array {
    foreach($rows as &$row){
        $start=substr($row['start_date'],0,10);$end=substr($row['end_date'],0,10);
        $row['request_days']=$row['days'];
        if($start<$from||$end>$to){
            $span=(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days+1;
            $overlap=max(0,(new DateTimeImmutable(max($start,$from)))->diff(new DateTimeImmutable(min($end,$to)))->days+1);
            $row['days']=(float)$row['request_days']===$span*1.0?$overlap:null;
            $row['period_note']=$row['days']===null?'Cross-period working/partial-day allocation requires HR detail':'Calendar days within selected period';
        }else{$row['period_note']='Entire approved request falls within selected period';}
    }unset($row);return $rows;
}
function kpi_leave_period(int $employeeId,string $from,string $to): array {
    try{
        $links=ops_rows('SELECT hr_employee_id FROM employee_user_links WHERE portal_user_id=? AND active=1',[$employeeId]);
        if(count($links)!==1)throw new RuntimeException('HR account link is missing or ambiguous.');
        $pdo=ops_hr_db();if(!$pdo instanceof PDO)throw new RuntimeException('HR data source unavailable.');
        $stmt=$pdo->prepare("SELECT leave_type,start_date,end_date,days,status FROM leave_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=? ORDER BY start_date");
        $stmt->execute([(int)$links[0]['hr_employee_id'],$to,$from]);
        $rows=kpi_leave_period_rows($stmt->fetchAll(PDO::FETCH_ASSOC),$from,$to);
        $unknown=count(array_filter($rows,static fn($r)=>$r['days']===null));
        return ['available'=>true,'rows'=>$rows,'total'=>$unknown?null:array_sum(array_column($rows,'days')),'message'=>$unknown?'Some cross-period leave needs daily HR allocation; no total is estimated.':'Approved days within the selected period.'];
    }catch(Throwable $error){return ['available'=>false,'rows'=>[],'total'=>null,'message'=>'HR data or a unique employee link is unavailable; this is not zero leave.'];}
}
