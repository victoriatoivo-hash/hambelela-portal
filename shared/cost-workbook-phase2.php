<?php
declare(strict_types=1);

function cw2_decimal_string($value, string $label, int $scale = 6, bool $positive = false): string
{
    $raw = trim(str_replace([',',' '], '', (string)$value));
    if ($raw === '' || !preg_match('/^\d+(?:\.\d{1,8})?$/', $raw) || ($positive && (float)$raw <= 0)) {
        throw new InvalidArgumentException($label . ($positive ? ' must be greater than zero.' : ' must be a valid non-negative number.'));
    }
    return number_format((float)$raw, $scale, '.', '');
}

function cw2_money_cents($value, string $label): int
{
    return (int)round((float)cw2_decimal_string($value,$label,6) * 100, 0, PHP_ROUND_HALF_UP);
}

function cw2_percent_basis_points($value, string $label): int
{
    $raw = cw2_decimal_string($value,$label,4);
    $bp = (int)round((float)$raw * 100,0,PHP_ROUND_HALF_UP);
    if ($bp > 10000) throw new InvalidArgumentException($label . ' cannot exceed 100%.');
    return $bp;
}

function cw2_invoice_line(array $line, string $invoiceTreatment, $configuredVatRate): array
{
    $quantity = cw2_decimal_string($line['quantity']??null,'Quantity',6,true);
    $unitPrice = cw2_decimal_string($line['unit_price']??null,'Unit cost',6);
    $gross = (int)round((float)$quantity*(float)$unitPrice*100,0,PHP_ROUND_HALF_UP);
    $discountType = (string)($line['discount_type']??'fixed');
    if (!in_array($discountType,['fixed','percentage'],true)) throw new InvalidArgumentException('Select a valid discount type.');
    $discountValue = $line['discount_value']??0;
    $discount = $discountType==='percentage'
        ? cw_round_divide($gross*cw2_percent_basis_points($discountValue,'Discount percentage'),10000)
        : cw2_money_cents($discountValue,'Discount');
    if ($discount>$gross) throw new InvalidArgumentException('Discount cannot exceed the gross line amount.');
    $netAfterDiscount=$gross-$discount;
    $treatment=$invoiceTreatment==='mixed'?(string)($line['vat_treatment']??'unconfirmed'):$invoiceTreatment;
    if (!in_array($treatment,['exclusive','inclusive','exempt'],true)) throw new InvalidArgumentException('Confirm VAT treatment for every line.');
    $rate=cw2_percent_basis_points($configuredVatRate,'VAT rate');
    if($treatment==='exclusive'){$subtotal=$netAfterDiscount;$calculatedVat=cw_round_divide($subtotal*$rate,10000);$total=$subtotal+$calculatedVat;}
    elseif($treatment==='inclusive'){$calculatedVat=cw_round_divide($netAfterDiscount*$rate,10000+$rate);$subtotal=$netAfterDiscount-$calculatedVat;$total=$netAfterDiscount;}
    else{$calculatedVat=0;$subtotal=$netAfterDiscount;$total=$netAfterDiscount;}
    $override=$line['vat_override_amount']??null;$reason=trim((string)($line['vat_override_reason']??''));
    $vat=$calculatedVat;$source='automatic';
    if($override!==null&&$override!==''){
        if($reason==='')throw new InvalidArgumentException('Explain why calculated VAT is being overridden.');
        $vat=cw2_money_cents($override,'VAT override');$source='override';
        if($treatment==='inclusive'){if($vat>$netAfterDiscount)throw new InvalidArgumentException('VAT cannot exceed the inclusive amount.');$subtotal=$netAfterDiscount-$vat;$total=$netAfterDiscount;}
        elseif($treatment==='exclusive'){$subtotal=$netAfterDiscount;$total=$subtotal+$vat;}
        elseif($vat!==0)throw new InvalidArgumentException('Exempt lines cannot contain VAT.');
    }
    $money=static fn(int $c):string=>number_format($c/100,2,'.','');
    return ['gross'=>$money($gross),'discount_type'=>$discountType,'discount_value'=>number_format((float)$discountValue,$discountType==='percentage'?4:2,'.',''),'discount'=>$money($discount),'vat_treatment'=>$treatment,'vat_rate'=>number_format($rate/100,4,'.',''),'line_subtotal'=>$money($subtotal),'calculated_vat_amount'=>$money($calculatedVat),'vat_amount'=>$money($vat),'line_total'=>$money($total),'vat_source'=>$source];
}

function cw2_convert_to_nad($amount,$rate): string
{
    $amountCents=cw2_money_cents($amount,'Original amount');$rateScaled=(int)round((float)cw2_decimal_string($rate,'Exchange rate',8,true)*100000000,0,PHP_ROUND_HALF_UP);
    return number_format(cw_round_divide($amountCents*$rateScaled,100000000)/100,2,'.','');
}

function cw2_allocate(int $expenseCents,array $lines,string $method,array $manual=[]): array
{
    if($expenseCents<0||!$lines)throw new InvalidArgumentException('Allocation requires an expense and eligible lines.');
    $weights=[];
    foreach($lines as $i=>$line){
        if($method==='net_value')$w=(float)($line['net_purchase']??0);
        elseif($method==='normalized_quantity')$w=(float)($line['normalized_quantity']??0);
        elseif($method==='weight')$w=(float)($line['allocation_weight_kg']??0);
        elseif($method==='equal')$w=1;
        elseif($method==='manual')$w=(float)($manual[$i]??0);
        else throw new InvalidArgumentException('Select a valid allocation method.');
        if($w<0)throw new InvalidArgumentException('Allocation values cannot be negative.');$weights[$i]=$w;
    }
    $total=array_sum($weights);if($total<=0)throw new InvalidArgumentException('Allocation basis must be greater than zero.');
    $out=[];$used=0;$largest=array_keys($weights,max($weights),true)[0];
    foreach($weights as $i=>$w){$out[$i]=cw_round_divide((int)round($w*1000000)*$expenseCents,(int)round($total*1000000));$used+=$out[$i];}
    $out[$largest]+=$expenseCents-$used;return $out;
}

function cw2_sale_size(array $data): array
{
    $normalized=(float)cw2_decimal_string($data['normalized_quantity']??null,'Normalized quantity',6,true);
    $saleSize=(float)cw2_decimal_string($data['sale_size']??null,'Sale size',6,true);
    $waste=(float)cw2_decimal_string($data['wastage_percent']??0,'Wastage percentage',4);
    if($waste>=100)throw new InvalidArgumentException('Wastage must be below 100%.');
    $landed=(float)cw2_decimal_string($data['landed_cost_per_unit']??null,'Landed unit cost',6);
    $pack=(float)cw2_decimal_string($data['packaging_cost']??0,'Packaging cost',4);$label=(float)cw2_decimal_string($data['label_cost']??0,'Label cost',4);$prep=(float)cw2_decimal_string($data['preparation_cost']??0,'Preparation cost',4);
    $usable=$normalized*(1-$waste/100);$units=$usable/$saleSize;$complete=$landed*$saleSize+$pack+$label+$prep;$whole=floor($units);$remaining=$usable-$whole*$saleSize;
    return ['usable_quantity'=>number_format($usable,6,'.',''),'theoretical_sale_units'=>number_format($units,6,'.',''),'remaining_quantity'=>number_format($remaining,6,'.',''),'product_cost_per_sale_unit'=>number_format($landed*$saleSize,6,'.',''),'complete_cost_per_sale_unit'=>number_format($complete,6,'.','')];
}

function cw2_recommended_price($completeCost,$targetMargin,$vatRate,string $rounding='nearest_1'): array
{
    $cost=(float)cw2_decimal_string($completeCost,'Complete cost',6);$target=(float)cw2_decimal_string($targetMargin,'Target margin',4);$vat=(float)cw2_decimal_string($vatRate,'VAT rate',4);
    if($target>=100)throw new InvalidArgumentException('Target margin must be below 100%.');$exact=($cost/(1-$target/100))*(1+$vat/100);
    if($rounding==='nearest_050')$rounded=round($exact*2,0,PHP_ROUND_HALF_UP)/2;
    elseif($rounding==='nearest_1')$rounded=round($exact,0,PHP_ROUND_HALF_UP);
    elseif($rounding==='end_99')$rounded=ceil($exact+0.01)-0.01;
    else $rounded=$exact;
    return ['exact'=>number_format($exact,6,'.',''),'rounded'=>number_format($rounded,2,'.','')];
}

function cw2_windhoek_time(?string $stored): ?string
{
    if(!$stored)return null;$utc=new DateTimeImmutable($stored,new DateTimeZone('UTC'));return $utc->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('j F Y \a\t H:i').' — Africa/Windhoek';
}
