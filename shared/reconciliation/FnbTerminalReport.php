<?php
declare(strict_types=1);
namespace Hambelela\Accounts;

/** Parses extracted text only. Does not confirm receipt or write financial data. */
final class FnbTerminalReport
{
    public static function parse(string $text): array
    {
        $text=str_replace(["\xef\xbf\xbd","\xc2\xa0","\r"],[' ',' ',''],$text);
        $text=preg_replace('/Page\s+\d+\s+of\s+\d+/i','',$text);
        foreach(['merchant'=>'/Merchant:\s*(\d+)/','terminal'=>'/Terminal:\s*(\d+)/','batch'=>'/Batch:\s*(\d+)/','count'=>'/Items:\s*(\d+)/'] as $key=>$pattern){
            if(!preg_match($pattern,$text,$m))throw new \DomainException('Missing terminal report '.$key.'.');
            $header[$key]=$m[1];
        }
        if(!preg_match('/APPROVED\s+TRANSACTIONS/',$text)||!preg_match('/TOTALS\s+SUMMARY\s*[_\s]*Purchase\s+NAD\s+([\d,]+\.\d{2})/',$text,$m))throw new \DomainException('Unsupported or incomplete terminal report.');
        $total=self::cents($m[1]);$rows=[];$seen=[];
        preg_match_all('/(\d{2}-\d{2}-\d{4})\s+(\d{2}:\d{2}:\d{2})\s+UTI:\s*([\s\S]*?)(?=\d{2}-\d{2}-\d{4}\s+\d{2}:\d{2}:\d{2}|TOTALS\s+SUMMARY|$)/',$text,$blocks,PREG_SET_ORDER);
        foreach($blocks as $block){
            if(!preg_match('/^([a-f0-9\s-]+)\s*RRN:\s*([a-z0-9]+)\s+Auth\s+Code:\s*(\d+)\s+TSN:\s*(\d+)\s+Batch:\s*(\d+)/i',$block[3],$ids))throw new \DomainException('Unreadable terminal transaction identifiers.');
            $uti=strtolower(preg_replace('/\s+/','',$ids[1]));
            if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/',$uti)||isset($seen[$uti]))throw new \DomainException('Invalid or duplicate transaction identifier.');
            if($ids[5]!==$header['batch'])throw new \DomainException('Transaction batch mismatch.');
            if(!preg_match('/Total:\s*NAD\s+([\d,]+\.\d{2})\s+Purchase\s+NAD\s+([\d,]+\.\d{2})/',$block[3],$amount))throw new \DomainException('Unsupported transaction amount/type.');
            $cents=self::cents($amount[1]);if($cents!==self::cents($amount[2]))throw new \DomainException('Purchase and transaction total disagree.');
            $date=\DateTimeImmutable::createFromFormat('!d-m-Y H:i:s',$block[1].' '.$block[2],new \DateTimeZone('Africa/Windhoek'));
            if(!$date||$date->format('d-m-Y H:i:s')!==$block[1].' '.$block[2])throw new \DomainException('Invalid transaction date.');
            $seen[$uti]=true;
            $rows[]=['source_key'=>hash('sha256','fnb-terminal|'.$header['merchant'].'|'.$uti),'uti'=>$uti,'rrn'=>$ids[2],'authorization_code'=>$ids[3],'sequence'=>$ids[4],'transaction_at'=>$date->format('Y-m-d H:i:s'),'timezone'=>'Africa/Windhoek','amount_cents'=>$cents,'currency'=>'NAD','settlement_state'=>'terminal_approved','receipt_verified'=>false];
        }
        if(count($rows)!==(int)$header['count']||array_sum(array_column($rows,'amount_cents'))!==$total)throw new \DomainException('Report count or total does not reconcile. Import stopped.');
        return ['type'=>'fnb_terminal_batch','merchant'=>$header['merchant'],'terminal'=>$header['terminal'],'batch'=>$header['batch'],'total_cents'=>$total,'transactions'=>$rows];
    }
    private static function cents(string $value): int
    {
        $value=str_replace(',','',$value);if(!preg_match('/^\d{1,10}\.\d{2}$/',$value))throw new \DomainException('Invalid amount.');
        return (int)str_replace('.','',$value);
    }
}
