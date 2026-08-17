<?php
declare(strict_types=1);

/**
 * Canonical Cost Workbook size conversions.
 *
 * Volume and weight deliberately remain separate measurement types. Consumers
 * must select records by measurement_type and must never convert L to kg.
 */
function cw_size_conversions(): array
{
    return [
        ['key'=>'10ml','label'=>'10ml','measurement_type'=>'volume','quantity'=>10,'unit'=>'ml','base_value'=>0.01,'base_unit'=>'L','display_order'=>10,'active'=>true],
        ['key'=>'20ml','label'=>'20ml','measurement_type'=>'volume','quantity'=>20,'unit'=>'ml','base_value'=>0.02,'base_unit'=>'L','display_order'=>20,'active'=>true],
        ['key'=>'50ml','label'=>'50ml','measurement_type'=>'volume','quantity'=>50,'unit'=>'ml','base_value'=>0.05,'base_unit'=>'L','display_order'=>30,'active'=>true],
        ['key'=>'100ml','label'=>'100ml','measurement_type'=>'volume','quantity'=>100,'unit'=>'ml','base_value'=>0.1,'base_unit'=>'L','display_order'=>40,'active'=>true],
        ['key'=>'250ml','label'=>'250ml','measurement_type'=>'volume','quantity'=>250,'unit'=>'ml','base_value'=>0.25,'base_unit'=>'L','display_order'=>50,'active'=>true],
        ['key'=>'500ml','label'=>'500ml','measurement_type'=>'volume','quantity'=>500,'unit'=>'ml','base_value'=>0.5,'base_unit'=>'L','display_order'=>60,'active'=>true],
        ['key'=>'1L','label'=>'1L','measurement_type'=>'volume','quantity'=>1,'unit'=>'L','base_value'=>1,'base_unit'=>'L','display_order'=>70,'active'=>true],
        ['key'=>'5L','label'=>'5L','measurement_type'=>'volume','quantity'=>5,'unit'=>'L','base_value'=>5,'base_unit'=>'L','display_order'=>80,'active'=>true],
        ['key'=>'50g','label'=>'50g','measurement_type'=>'weight','quantity'=>50,'unit'=>'g','base_value'=>0.05,'base_unit'=>'kg','display_order'=>10,'active'=>true],
        ['key'=>'100g','label'=>'100g','measurement_type'=>'weight','quantity'=>100,'unit'=>'g','base_value'=>0.1,'base_unit'=>'kg','display_order'=>20,'active'=>true],
        ['key'=>'200g','label'=>'200g','measurement_type'=>'weight','quantity'=>200,'unit'=>'g','base_value'=>0.2,'base_unit'=>'kg','display_order'=>30,'active'=>true],
        ['key'=>'250g','label'=>'250g','measurement_type'=>'weight','quantity'=>250,'unit'=>'g','base_value'=>0.25,'base_unit'=>'kg','display_order'=>40,'active'=>true],
        ['key'=>'500g','label'=>'500g','measurement_type'=>'weight','quantity'=>500,'unit'=>'g','base_value'=>0.5,'base_unit'=>'kg','display_order'=>50,'active'=>true],
        ['key'=>'750g','label'=>'750g','measurement_type'=>'weight','quantity'=>750,'unit'=>'g','base_value'=>0.75,'base_unit'=>'kg','display_order'=>60,'active'=>true],
        ['key'=>'1kg','label'=>'1kg','measurement_type'=>'weight','quantity'=>1,'unit'=>'kg','base_value'=>1,'base_unit'=>'kg','display_order'=>70,'active'=>true],
        ['key'=>'1.5kg','label'=>'1.5kg','measurement_type'=>'weight','quantity'=>1.5,'unit'=>'kg','base_value'=>1.5,'base_unit'=>'kg','display_order'=>80,'active'=>true],
        ['key'=>'5kg','label'=>'5kg','measurement_type'=>'weight','quantity'=>5,'unit'=>'kg','base_value'=>5,'base_unit'=>'kg','display_order'=>90,'active'=>true],
        ['key'=>'10kg','label'=>'10kg','measurement_type'=>'weight','quantity'=>10,'unit'=>'kg','base_value'=>10,'base_unit'=>'kg','display_order'=>100,'active'=>true],
    ];
}

function cw_size_conversions_by_type(string $measurementType): array
{
    return array_values(array_filter(cw_size_conversions(), static function (array $row) use ($measurementType): bool {
        return $row['active'] === true && $row['measurement_type'] === $measurementType;
    }));
}

