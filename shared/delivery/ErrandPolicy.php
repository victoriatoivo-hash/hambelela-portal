<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
final class ErrandPolicy
{
    public static function text($s,string $label,int $min=1,int $max=1500):string
    {
        if(!is_string($s))throw new \DomainException('Invalid '.$label.'.');$s=trim($s);
        if(mb_strlen($s)<$min||mb_strlen($s)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u',$s))throw new \DomainException($label.' must contain '.$min.'–'.$max.' characters.');return $s;
    }
    public static function quantity($v):int
    {
        if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,3}))?$/D',$v,$m))throw new \DomainException('Use a quantity between 0 and 999999, with up to three decimals.');
        return (int)$m[1]*1000+(int)str_pad($m[2]??'',3,'0');
    }
    public static function normalize(array $v):array
    {
        $p=['title'=>self::text($v['title']??null,'Task name',4,110),'type'=>$v['type']??'','proof'=>$v['proof']??'','priority'=>($v['priority']??'')==='1','phone'=>trim((string)($v['phone']??'')),'due_at'=>null,'dispatch_order'=>null];
        if(!in_array($p['type'],['collection','handover','other'],true)||!in_array($p['proof'],['checklist','photo','signature','both'],true))throw new \DomainException('Choose the errand type and proof requirements.');
        if($p['phone']!==''&&!preg_match('/^\+?[0-9 ()-]{7,30}$/D',$p['phone']))throw new \DomainException('Enter a valid contact number.');
        if(!empty($v['due'])){$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)$v['due'],new \DateTimeZone('Africa/Windhoek'));if(!$d||$d->format('Y-m-d\TH:i')!==$v['due'])throw new \DomainException('Choose a valid Windhoek due date/time.');$p['due_at']=$d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
        if(isset($v['dispatch_order'])&&$v['dispatch_order']!==''){if(!ctype_digit((string)$v['dispatch_order'])||(int)$v['dispatch_order']>10000)throw new \DomainException('Invalid dispatch sequence.');$p['dispatch_order']=(int)$v['dispatch_order'];}
        $items=$v['items']??null;$checks=$v['checklist']??null;if(!is_array($items)||!count($items)||count($items)>30||!is_array($checks)||!count($checks)||count($checks)>20)throw new \DomainException('Provide 1–30 item quantities and 1–20 handover checks.');
        $p['items']=[];foreach($items as $i){if(!is_array($i))throw new \DomainException('Invalid item.');$q=self::quantity($i['quantity']??null);if($q<=0)throw new \DomainException('Expected quantity must be positive.');$p['items'][]=['name'=>self::text($i['name']??null,'Item name',1,150),'expected'=>$q,'unit'=>self::text($i['unit']??null,'Quantity unit',1,30)];}
        $p['checklist']=array_map(static fn($s)=>self::text($s,'Handover check',3,200),$checks);return $p;
    }
    public static function completion(array $p,array $v,array $evidence):array
    {
        $notes=self::text($v['notes']??null,'Handover details',12);$recipient=self::text($v['recipient']??null,'Actual handover person',2,120);
        $actual=$v['actual']??null;$checks=$v['checks']??null;
        if(!is_array($actual)||count($actual)!==count($p['items'])||array_keys($actual)!==array_keys($p['items']))throw new \DomainException('Record the actual quantity of every item.');
        $quantities=array_map([self::class,'quantity'],$actual);$mismatch=false;foreach($quantities as $i=>$q)if($q!==$p['items'][$i]['expected'])$mismatch=true;
        // A mismatch must be sent to review even if normal completion proof/checks cannot be supplied.
        if(!$mismatch){
            if(!is_array($checks)||array_values($checks)!==array_map('strval',array_keys($p['checklist'])))throw new \DomainException('Confirm each assigned handover check.');
            $kinds=array_column($evidence,'kind');foreach(['photo','signature'] as $kind)if(in_array($p['proof'],[$kind,'both'],true)&&!in_array($kind,$kinds,true))throw new \DomainException('This errand requires '.$kind.' evidence.');
        }
        return ['notes'=>$notes,'recipient'=>$recipient,'actual'=>$quantities,'checks'=>$checks??[],'evidence'=>$evidence,'discrepancy'=>$mismatch];
    }
}
