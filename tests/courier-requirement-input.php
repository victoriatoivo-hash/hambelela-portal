<?php
require __DIR__.'/../apps/operations/courier-order-requirements.php';
function ops_row(...$args): array { return ['order_type'=>'courier','fulfilment_mode'=>'courier']; }
foreach ([['',1,'2026-09-05',false],['Nampost',0,'2026-09-05',false],['Nampost',2,'2026-02-30',false],['Nampost',2,'2026-09-05',true]] as [$courier,$boxes,$date,$expected]) {
    $_POST=['dispatch_courier'=>$courier,'dispatch_boxes'=>$boxes,'dispatch_date'=>$date];
    $valid=true;try{courier_requirement_input(1);}catch(RuntimeException $error){$valid=false;}
    if($valid!==$expected)throw new RuntimeException('Courier details validation failed.');
}
echo "Courier requirement input checks passed.\n";
