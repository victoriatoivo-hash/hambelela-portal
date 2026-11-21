<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
final class OrderReference
{
    /** Presentation only: never write this label back to order/payment records. */
    public static function display(?string $value):?string
    {
        if($value===null)return null;
        return preg_match('/^(?:WEB-|INV-|#)?([0-9]+)$/iD',trim($value),$m)?'Order '.$m[1]:$value;
    }
}
