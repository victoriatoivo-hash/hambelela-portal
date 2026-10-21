<?php
require __DIR__.'/../shared/cost-workbook-size-conversions.php';
foreach(cw_default_size_conversions() as $expected){
    $actual=cw_validate_size_conversion(['measurement_type'=>$expected['measurement_type'],'amount'=>$expected['quantity'],'unit'=>$expected['unit']]);
    if(abs($actual['base_value']-$expected['base_value'])>0.000000001 || $actual['label']!==$expected['label'])throw new RuntimeException('Conversion mismatch');
}
foreach([['volume','1','kg'],['weight','1','L'],['volume','-1','ml'],['volume','0','L'],['weight','no','g']] as $case){
    try{cw_validate_size_conversion(['measurement_type'=>$case[0],'amount'=>$case[1],'unit'=>$case[2]]);throw new RuntimeException('Invalid size accepted');}catch(DomainException $expected){}
}
echo "Canonical backend conversion/invalid-unit checks passed\n";
