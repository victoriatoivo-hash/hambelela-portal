<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Bounded presentation of an immutable published result. No recalculation or writes. */
final class PerformanceEvidenceView
{
    public static function page(array $result,array $filter=[]):array
    {
        $rows=[];$search=trim((string)($filter['search']??''));$category=(string)($filter['category']??'');
        $state=(string)($filter['status']??'');$date=(string)($filter['date']??'');
        foreach($result['evidence']??[] as $row){
            if($category!==''&&($row['category']??'')!==$category)continue;
            if($state!==''&&($row['outcome']??'')!==$state)continue;
            $at=$row['observed_at']??$row['fulfilled_at']??$row['due_at']??'';
            if($date!==''&&substr($at,0,10)!==$date)continue;
            if($search!==''&&stripos(implode(' ',array_map('strval',[$row['source_reference']??'',$row['module']??'',$row['expected']??'',$row['actual']??''])),$search)===false)continue;
            $rows[]=$row;
        }
        usort($rows,static function(array $a,array $b):int{
            return strcmp((string)($b['observed_at']??$b['fulfilled_at']??$b['due_at']??''),(string)($a['observed_at']??$a['fulfilled_at']??$a['due_at']??''));
        });
        if(!empty($filter['unique_work'])){$seen=[];$rows=array_values(array_filter($rows,static function(array $row)use(&$seen):bool{$key=(string)($row['opportunity_key']??$row['source_reference']);if(isset($seen[$key]))return false;$seen[$key]=true;return true;}));}
        $size=max(1,min(50,(int)($filter['page_size']??25)));$total=count($rows);
        $pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($filter['page']??1)));
        return ['records'=>array_slice($rows,($page-1)*$size,$size),'total'=>$total,'page'=>$page,'page_size'=>$size,'pages'=>$pages,'result_id'=>$result['result_id']??null,
            'scope'=>'Published eligible KPI observations; one work unit can support distinct measures. These rows are not additional deductions.'];
    }
}
