<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Read-only Packing List observations. No deadlines, alerts, grades or writes. */
final class PackingWorkSummary
{
    public static function status($s): string {return strtolower(trim(preg_replace('/[^a-z0-9]+/i','_', (string)$s),'_'));}
    public static function finished($s): bool {return self::status($s)==='done';}

    /** Explicit package quantities only; never interpret received bulk weight as output packs. */
    public static function packages(string $text): ?array
    {
        if(trim($text)==='0')return [];
        $p='/\b(\d+(?:\.\d+)?)\s*(kg|kgs|kilograms?|g|grams?|ml|millilitres?|milliliters?|l|lt|litres?|liters?|pcs?|pieces?|units?)\b\s*(?:\(\s*(\d+)\s*\)|[x*]\s*(\d+))?/i';
        preg_match_all($p,$text,$matches,PREG_SET_ORDER);
        if(!$matches||preg_replace('/[\s,;+]+/','',(string)preg_replace($p,'',$text))!=='')return null;
        $out=[];
        foreach($matches as$m){
            $size=(float)$m[1];$u=strtolower($m[2]);$count=(int)(($m[3]??'')!==''?$m[3]:(($m[4]??'')!==''?$m[4]:1));
            if($size<=0||!is_finite($size)||$count<0)return null;
            if(in_array($u,['kg','kgs','kilogram','kilograms'],true)){$size*=1000;$dim='g';}
            elseif(in_array($u,['g','gram','grams'],true))$dim='g';
            elseif(in_array($u,['l','lt','litre','litres','liter','liters'],true)){$size*=1000;$dim='ml';}
            elseif(strpos($u,'ml')===0||strpos($u,'milli')===0)$dim='ml';
            else{$dim='unit';$count*=$size;$size=1;}
            $key=$dim.':'.rtrim(rtrim(sprintf('%.6F',$size),'0'),'.');$out[$key]=($out[$key]??0)+$count;
        }
        ksort($out);return $out;
    }

    public static function accuracy(?array $plan,?array $actual): string
    {
        if($plan===null||!$plan||$actual===null)return 'unknown';
        $over=false;$under=false;
        foreach(array_unique(array_merge(array_keys($plan),array_keys($actual)))as$k){$d=($actual[$k]??0)-($plan[$k]??0);$over=$over||$d>0.00001;$under=$under||$d< -0.00001;}
        return $over?($under?'mixed':'more'):($under?'less':'exact');
    }

    /** Reverse later audited edits to obtain the period-end view, not today's values. */
    public static function snapshot(array $item,array $events,string $at): array
    {
        foreach(array_reverse($events)as$e){
            if($e['at']<=$at)continue;
            if(isset($e['field'])&&array_key_exists('before',$e))$item[$e['field']]=$e['before'];
        }
        return $item;
    }

    public static function ownerAt(array $item,array $assignments,string $at): int
    {
        $id=(int)($item['assigned_employee_id']??0);
        foreach(array_reverse($assignments)as$a)if($a['at']>$at)$id=(int)$a['old'];
        return $id;
    }

    public static function build(array $items,array $events,array $assignments,array $exceptions,int $employee,string $from,string $to,PackerOrdersPreview $clock): array
    {
        $out=['items'=>0,'completed'=>0,'open'=>0,'packages'=>0,'kg'=>0.0,'litres'=>0.0,'quantity_known'=>0,'responsibility_review'=>0,
            'accuracy'=>array_fill_keys(['exact','less','more','mixed','unknown'],0),'states'=>[],'sizes'=>[],'priorities'=>[],
            'reopened'=>0,'corrections'=>0,'notes'=>0,'note_stages'=>[],'note_reasons'=>[],'website_waiting'=>0,'website_updated'=>0,
            'daily'=>[],'timing'=>[],'evidence'=>[],'notes_repeated'=>[],'progress_sizes'=>[],'planned_sizes'=>[],'progress_known'=>0,'quantity_updates'=>[]];
        $durations=[];$queue=[];$stageAges=[];$web=[];$reasons=[];
        foreach($items as$item){
            $id=(int)$item['id'];$history=$events[$id]??[];$owners=$assignments[$id]??[];
            usort($history,static fn($a,$b)=>[$a['at'],$a['id']??0]<=>[$b['at'],$b['id']??0]);
            usort($owners,static fn($a,$b)=>[$a['at'],$a['id']??0]<=>[$b['at'],$b['id']??0]);
            $loaded=(string)($item['date_loaded']??$item['created_at']??'');if(!$loaded||$loaded>$to)continue;
            $snapshot=self::snapshot($item,$history,$to);$state=self::status($snapshot['packing_status']??'');
            $assigned=self::ownerAt($item,$owners,$to);$started=null;$finished=null;$lastStage=null;$attributed=false;$reopened=0;$corrections=0;$wasDone=false;
            $noteEvents=[];$quantityEvents=[];$hasActivity=false;
            foreach($history as$e){
                if($e['at']>$to)break;
                $subject=self::ownerAt($item,$owners,$e['at']);
                if($e['at']>=$from&&$subject===$employee)$hasActivity=true;
                if(($e['field']??'')==='packing_status'){
                    $before=self::status($e['before']??'');$after=self::status($e['after']??'');
                    if($before!==$after)$lastStage=$e['at'];
                    if($after==='packing'&&$started===null)$started=$e['at'];
                    if($after==='done'&&$before!=='done'){$finished=$e['at'];$attributed=$subject===$employee&&($owners||(int)($e['actor']??0)===$employee);$wasDone=true;}
                    if($before==='done'&&$after!=='done'){$wasDone=false;if($e['at']>=$from&&$subject===$employee)$reopened++;}
                    if(in_array($after,['correction_needed','done_needs_correction'],true)&&$after!==$before&&$e['at']>=$from&&$subject===$employee)$corrections++;
                }
                if(($e['field']??'')==='notes'&&$e['at']>=$from&&$subject===$employee&&trim((string)($e['after']??''))!==''&&($e['after']??'')!==($e['before']??''))$noteEvents[]=$e;
                if(($e['field']??'')==='quantity_packed'&&$e['at']>=$from&&$subject===$employee)$quantityEvents[]=$e;
            }
            // Native timestamps still measure turnaround even without the older status actor.
            if(!$started&&!empty($item['date_started'])&&$item['date_started']<=$to)$started=$item['date_started'];
            if(!$finished&&self::finished($state)&&!empty($item['date_completed'])&&$item['date_completed']<=$to)$finished=$item['date_completed'];
            $completed=self::finished($state)&&$finished!==null&&$finished>=$from&&$finished<=$to;
            $open=!self::finished($state)&&$assigned===$employee;
            if(!$open&&!($completed&&self::ownerAt($item,$owners,$finished)===$employee)&&!$hasActivity&&!($assigned===$employee&&$loaded>=$from))continue;
            $out['items']++;$out['reopened']+=$reopened;$out['corrections']+=$corrections;
            foreach($quantityEvents as$e){
                $old=self::packages((string)$e['before']);$new=self::packages((string)$e['after']);$day=substr($e['at'],0,10);
                if(!isset($out['quantity_updates'][$day]))$out['quantity_updates'][$day]=['added'=>0,'reduced'=>0,'unknown'=>0];
                if($old===null||$new===null){$out['quantity_updates'][$day]['unknown']++;continue;}
                foreach(array_unique(array_merge(array_keys($old),array_keys($new)))as$k){$delta=($new[$k]??0)-($old[$k]??0);if($delta>0)$out['quantity_updates'][$day]['added']+=$delta;else$out['quantity_updates'][$day]['reduced']-= $delta;}
            }
            if($open){$out['open']++;$out['states'][$state]=($out['states'][$state]??0)+1;}
            $priority=self::status($snapshot['priority']??'unrecorded');$out['priorities'][$priority]=($out['priorities'][$priority]??0)+1;
            $plan=self::packages((string)($snapshot['quantity_planned']??''));$actual=self::packages((string)($snapshot['quantity_packed']??''));
            $accuracy=$completed?self::accuracy($plan,$actual):'in_progress';
            if($plan!==null)foreach($plan as$key=>$n)$out['planned_sizes'][$key]=($out['planned_sizes'][$key]??0)+$n;
            if($actual!==null){$out['progress_known']++;foreach($actual as$key=>$n)$out['progress_sizes'][$key]=($out['progress_sizes'][$key]??0)+$n;}
            $seconds=null;$deducted=0;
            if($started&&$started>=$loaded&&($finished===null||$finished>=$started)){
                $end=$finished??$to;$intervals=[];
                foreach($exceptions as$ex)if((int)$ex['employee_id']===$employee&&(empty($ex['object_reference'])||$ex['object_reference']==='PACK-'.$id))$intervals[]=[$ex['effective_from'],$ex['effective_to']];
                // Never deduct an employee-wide exception across an unverified reassignment.
                if(!$owners||count(array_unique(array_merge([$assigned],array_column($owners,'old'),array_column($owners,'new'))))===1)$deducted=PackerWorkload::intervalSeconds($clock,$intervals,$started,$end);
                $seconds=max(0,$clock->seconds($started,$end)-$deducted);
                if($completed)$durations[]=$seconds/60;
                if($started>=$from)$queue[]=$clock->seconds($loaded,$started)/60;
            }
            if($open&&$lastStage&&$lastStage>=$loaded)$stageAges[$state][]=round($clock->seconds($lastStage,$to)/60,1);
            if($completed){
                $out['completed']++;if(!$attributed)$out['responsibility_review']++;
                $out['accuracy'][$accuracy]++;
                $day=substr($finished,0,10);$out['daily'][$day]=($out['daily'][$day]??0)+1;
                if($actual!==null){$out['quantity_known']++;foreach($actual as$key=>$n){[$dim,$size]=explode(':',$key);$out['packages']+=$n;$out['sizes'][$key]=($out['sizes'][$key]??0)+$n;if($dim==='g')$out['kg']+=(float)$size*$n/1000;if($dim==='ml')$out['litres']+=(float)$size*$n/1000;}}
            }
            foreach($noteEvents as$e){$out['notes']++;$s=self::status(self::snapshot($item,$history,$e['at'])['packing_status']??'unknown');$out['note_stages'][$s]=($out['note_stages'][$s]??0)+1;$text=strtolower((string)$e['after']);foreach(['stock','label','bottle','pouch','seal','machine','shortage','spill','website']as$word)if(strpos($text,$word)!==false){$reasons[$word][$id]=true;}}
            $ready=$snapshot['packing_website_completed_at']??null;$updated=$snapshot['frontdesk_website_updated_at']??null;$webMinutes=null;
            if($ready&&$ready<=$to&&(int)($snapshot['packing_website_confirmed']??0)===1){
                if($updated&&$updated>=$ready&&$updated<=$to&&(int)($snapshot['frontdesk_website_updated']??0)===1){$out['website_updated']++;$webMinutes=$clock->seconds($ready,$updated)/60;$web[]=$webMinutes;}
                else{$out['website_waiting']++;$webMinutes=$clock->seconds($ready,$to)/60;}
            }
            $out['evidence'][]=['id'=>$id,'name'=>$snapshot['item_name']??'Packing item','priority'=>$priority,'status'=>$state,
                'planned'=>$snapshot['quantity_planned']??'','packed'=>$snapshot['quantity_packed']??'','accuracy'=>$accuracy,
                'started_at'=>$started,'finished_at'=>$finished,'working_minutes'=>$seconds===null?null:round($seconds/60,1),'excluded_minutes'=>round($deducted/60,1),
                'assigned_id'=>$assigned,'responsibility'=>$completed?($attributed?'Historical responsibility supported':'Responsibility needs review'):'Assigned packer',
                'website_ready_at'=>$ready,'website_updated_at'=>$updated&&$updated<=$to?$updated:null,'website_updated_by'=>$snapshot['frontdesk_website_updated_by']??null,'website_wait_minutes'=>$webMinutes];
        }
        foreach($reasons as$word=>$ids)$out['note_reasons'][$word]=count($ids);
        $out['notes_repeated']=array_filter($out['note_reasons'],static fn($n)=>$n>1);
        $out['timing']=['completed_measured'=>count($durations),'median_minutes'=>self::median($durations),'queue_median_minutes'=>self::median($queue),'stage_age_minutes'=>$stageAges,'website_median_minutes'=>self::median($web)];
        $eligible=array_sum($out['accuracy'])-$out['accuracy']['unknown'];$out['accuracy_percent']=$eligible?round(100*$out['accuracy']['exact']/$eligible,1):null;
        $out['kg']=round($out['kg'],3);$out['litres']=round($out['litres'],3);ksort($out['daily']);ksort($out['sizes']);ksort($out['quantity_updates']);
        return $out;
    }
    private static function median(array $v):?float {if(!$v)return null;sort($v,SORT_NUMERIC);$i=intdiv(count($v),2);return round(count($v)%2?$v[$i]:($v[$i-1]+$v[$i])/2,1);}

    /** Only unambiguous, approved whole-day absence records. Partial days need exact intervals. */
    public static function absences(\PDO $db,?\PDO $hr,int $employee,string $from,string $to):array
    {
        $result=['intervals'=>[],'verified'=>false];if(!$hr)return $result;
        try{
            $q=$db->prepare('SELECT hr_employee_id,linked_at FROM employee_user_links WHERE portal_user_id=? AND active=1');$q->execute([$employee]);$links=$q->fetchAll(\PDO::FETCH_ASSOC);
            if(count($links)!==1||empty($links[0]['linked_at'])||$links[0]['linked_at']>$from)return $result;
            $q=$db->prepare('SELECT COUNT(*) FROM employee_user_links WHERE hr_employee_id=? AND active=1');$q->execute([$links[0]['hr_employee_id']]);if((int)$q->fetchColumn()!==1)return $result;
            $q=$hr->prepare("SELECT start_date,end_date,days,approved_at,approved_by FROM leave_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?");$q->execute([$links[0]['hr_employee_id'],substr($to,0,10),substr($from,0,10)]);$result['verified']=true;
            foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$r){
                if(empty($r['approved_at'])||empty($r['approved_by'])||$r['approved_at']>$to){$result['verified']=false;continue;}
                $a=PackerOrdersPreview::time($r['start_date'].' 00:00:00');$b=PackerOrdersPreview::time($r['end_date'].' 00:00:00')->modify('+1 day');$days=0;
                if($b<=$a){$result['verified']=false;continue;}
                for($d=$a;$d<$b;$d=$d->modify('+1 day'))if((int)$d->format('N')!==7)$days++;
                if(!is_numeric($r['days'])||abs((float)$r['days']-$days)>0.001){$result['verified']=false;continue;}
                $result['intervals'][]=['employee_id'=>$employee,'object_reference'=>null,'effective_from'=>$a->format('Y-m-d H:i:s'),'effective_to'=>$b->format('Y-m-d H:i:s')];
            }
        }catch(\Throwable $e){$result['verified']=false;}
        return $result;
    }
}
