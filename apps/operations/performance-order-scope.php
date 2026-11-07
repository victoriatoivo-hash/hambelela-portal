<?php
declare(strict_types=1);
/** Same active records and displayed dates as the default Orders Board. */
function performance_order_scope(string $alias='ops_orders'): array
{
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/',$alias))throw new InvalidArgumentException('Invalid order alias');
    $conditions=[];
    foreach(['archived_at','deleted_at']as$column)if(ops_column_exists('ops_orders',$column))$conditions[]=$alias.'.'.$column.' IS NULL';
    return ['date'=>ops_order_display_datetime_expr($alias),'visible'=>$conditions?implode(' AND ',$conditions):'1=1'];
}
