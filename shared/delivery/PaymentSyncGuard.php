<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Pure guard for the future shared writer integration. Call under the Order row lock.
 * Do not merge external snapshots by adding them: they may already include the receipt.
 */
final class PaymentSyncGuard
{
    public static function decision(string $currentSource,string $currentVersion,string $incomingSource,string $incomingVersion):string
    {
        if($currentVersion!==''&&$currentVersion===$incomingVersion)return 'unchanged';
        if($currentSource==='delivery')return 'review_required';
        if($incomingSource==='woocommerce'&&in_array($currentSource,['pos','order_list'],true))return 'preserve';
        if($incomingSource==='order_list'&&$currentSource==='pos')return 'preserve';
        return 'apply';
    }
}
