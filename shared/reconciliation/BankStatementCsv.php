<?php
declare(strict_types=1);
namespace Hambelela\Accounts;
/** Consumes the existing Bank Statement Processor's Sage CSV export, not a competing PDF parser. */
final class BankStatementCsv
{
    public static function parse(string $text,string $account,string $method):array
    {
        $account=trim($account);if($account===''||strlen($account)>100)throw new \DomainException('Enter the bank account identifier consistently for duplicate detection.');
        $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$text));rewind($stream);
        if(fgetcsv($stream)!==['Date','Description','Reference','Debit','Credit'])throw new \DomainException('Use the Sage CSV exported by the existing Bank Statement Processor.');
        $rows=[];$line=1;
        while(($r=fgetcsv($stream))!==false){$line++;if($r===[null])continue;if(count($r)!==5)throw new \DomainException('Invalid CSV row '.$line.'.');
            [$date,$description,$reference,$debit,$credit]=$r;$d=\DateTimeImmutable::createFromFormat('!d/m/Y',$date);
            if(!$d||$d->format('d/m/Y')!==$date)throw new \DomainException('Invalid date on row '.$line.'.');
            foreach([$debit,$credit] as $amount)if($amount!==''&&!preg_match('/^\d{1,10}\.\d{2}$/',$amount))throw new \DomainException('Invalid amount on row '.$line.'.');
            if($debit!==''&&$credit!=='')throw new \DomainException('Both debit and credit on row '.$line.'.');
            if($credit===''||(int)str_replace('.','',$credit)<=0)continue;
            $cents=(int)str_replace('.','',$credit);$ref=trim($reference);if(strlen($ref)>190||strlen($description)>2000)throw new \DomainException('Statement text is too long.');
            // Exclude payment method from identity: re-importing with another method must not duplicate a receipt.
            $key=hash('sha256',json_encode(['bank',strtolower($account),$date,$description,$ref,$cents],JSON_THROW_ON_ERROR));
            $rows[]=['source_key'=>$key,'reference'=>$ref,'transaction_at'=>$d->format('Y-m-d 00:00:00'),'amount_cents'=>$cents,'method_code'=>$method,'description'=>$description,'account'=>$account];
        }fclose($stream);if(!$rows)throw new \DomainException('No credit receipts found.');if(count($rows)>5000)throw new \DomainException('Import at most 5,000 receipts at a time.');return ['transactions'=>$rows];
    }
}
