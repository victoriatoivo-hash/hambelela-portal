<?php
declare(strict_types=1);

/** Default records used only to seed the canonical database collection. */
function cw_default_size_conversions(): array
{
    return [
        ['label'=>'10ml','measurement_type'=>'volume','quantity'=>10,'unit'=>'ml','base_value'=>0.01,'base_unit'=>'L'],
        ['label'=>'20ml','measurement_type'=>'volume','quantity'=>20,'unit'=>'ml','base_value'=>0.02,'base_unit'=>'L'],
        ['label'=>'50ml','measurement_type'=>'volume','quantity'=>50,'unit'=>'ml','base_value'=>0.05,'base_unit'=>'L'],
        ['label'=>'100ml','measurement_type'=>'volume','quantity'=>100,'unit'=>'ml','base_value'=>0.1,'base_unit'=>'L'],
        ['label'=>'250ml','measurement_type'=>'volume','quantity'=>250,'unit'=>'ml','base_value'=>0.25,'base_unit'=>'L'],
        ['label'=>'500ml','measurement_type'=>'volume','quantity'=>500,'unit'=>'ml','base_value'=>0.5,'base_unit'=>'L'],
        ['label'=>'1L','measurement_type'=>'volume','quantity'=>1,'unit'=>'L','base_value'=>1,'base_unit'=>'L'],
        ['label'=>'5L','measurement_type'=>'volume','quantity'=>5,'unit'=>'L','base_value'=>5,'base_unit'=>'L'],
        ['label'=>'50g','measurement_type'=>'weight','quantity'=>50,'unit'=>'g','base_value'=>0.05,'base_unit'=>'kg'],
        ['label'=>'100g','measurement_type'=>'weight','quantity'=>100,'unit'=>'g','base_value'=>0.1,'base_unit'=>'kg'],
        ['label'=>'200g','measurement_type'=>'weight','quantity'=>200,'unit'=>'g','base_value'=>0.2,'base_unit'=>'kg'],
        ['label'=>'250g','measurement_type'=>'weight','quantity'=>250,'unit'=>'g','base_value'=>0.25,'base_unit'=>'kg'],
        ['label'=>'500g','measurement_type'=>'weight','quantity'=>500,'unit'=>'g','base_value'=>0.5,'base_unit'=>'kg'],
        ['label'=>'750g','measurement_type'=>'weight','quantity'=>750,'unit'=>'g','base_value'=>0.75,'base_unit'=>'kg'],
        ['label'=>'1kg','measurement_type'=>'weight','quantity'=>1,'unit'=>'kg','base_value'=>1,'base_unit'=>'kg'],
        ['label'=>'1.5kg','measurement_type'=>'weight','quantity'=>1.5,'unit'=>'kg','base_value'=>1.5,'base_unit'=>'kg'],
        ['label'=>'5kg','measurement_type'=>'weight','quantity'=>5,'unit'=>'kg','base_value'=>5,'base_unit'=>'kg'],
        ['label'=>'10kg','measurement_type'=>'weight','quantity'=>10,'unit'=>'kg','base_value'=>10,'base_unit'=>'kg'],
    ];
}

function cw_size_conversion_number($value): string
{
    $formatted=rtrim(rtrim(number_format((float)$value,6,'.',''),'0'),'.');
    return $formatted===''?'0':$formatted;
}

function cw_size_conversion_row(array $row): array
{
    return ['id'=>(int)($row['id']??0),'label'=>(string)$row['label'],'measurement_type'=>(string)$row['measurement_type'],'quantity'=>(float)$row['quantity'],'unit'=>(string)$row['unit'],'base_value'=>(float)$row['base_value'],'base_value_display'=>cw_size_conversion_number($row['base_value']),'base_unit'=>(string)$row['base_unit'],'active'=>(bool)($row['active']??true)];
}

/** Canonical read used by the page and future Cost Workbook calculations. */
function cw_size_conversions(?PDO $pdo=null): array
{
    try {
        $pdo=$pdo?:db();
        $rows=$pdo->query('SELECT id,label,measurement_type,quantity,unit,base_value,base_unit,active FROM cw_size_conversions WHERE active=1 ORDER BY measurement_type,base_value,id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map('cw_size_conversion_row',$rows);
    } catch(Throwable $error) {
        error_log('Cost Workbook size conversions fallback: '.get_class($error));
        return array_map('cw_size_conversion_row',cw_default_size_conversions());
    }
}

function cw_size_conversions_by_type(string $measurementType,?PDO $pdo=null): array
{
    return array_values(array_filter(cw_size_conversions($pdo),static function(array $row)use($measurementType):bool{return$row['active']&&$row['measurement_type']===$measurementType;}));
}

function cw_validate_size_conversion(array $input): array
{
    $type=strtolower(trim((string)($input['measurement_type']??'')));
    $unitRaw=trim((string)($input['unit']??''));
    $unit=strtolower($unitRaw)==='l'?'L':strtolower($unitRaw);
    $allowed=['volume'=>['ml','L'],'weight'=>['g','kg']];
    if(!isset($allowed[$type]))throw new DomainException('Select a valid measurement type.');
    if(!in_array($unit,$allowed[$type],true))throw new DomainException('Select a unit that matches the measurement type.');
    $amountRaw=trim((string)($input['amount']??$input['quantity']??''));
    if($amountRaw===''||!preg_match('/^(?:\d+\.?\d*|\.\d+)$/',$amountRaw)||!is_finite((float)$amountRaw)||(float)$amountRaw<=0)throw new DomainException('Amount must be a number greater than zero.');
    $amount=(float)$amountRaw;$baseUnit=$type==='volume'?'L':'kg';$baseValue=in_array($unit,['ml','g'],true)?$amount/1000:$amount;
    return ['label'=>cw_size_conversion_number($amount).$unit,'measurement_type'=>$type,'quantity'=>$amount,'unit'=>$unit,'base_value'=>$baseValue,'base_unit'=>$baseUnit];
}
