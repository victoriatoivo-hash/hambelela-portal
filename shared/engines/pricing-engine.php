<?php

declare(strict_types=1);

function pricing_engine_price_for_margin(float $unitCogs, float $marginPercent): float
{
    if ($unitCogs <= 0 || $marginPercent >= 100) {
        return 0.0;
    }

    return $unitCogs / (1 - ($marginPercent / 100));
}

function pricing_engine_add_vat(float $priceExVat, float $vatRate): float
{
    return $priceExVat * (1 + ($vatRate / 100));
}

function pricing_engine_margin(float $priceExVat, float $unitCogs): float
{
    return $priceExVat > 0 ? (($priceExVat - $unitCogs) / $priceExVat) * 100 : 0.0;
}
