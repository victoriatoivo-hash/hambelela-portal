<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Domain policy only. Actor must be constructed from server-verified session data. */
final class DeliveryPolicy
{
    public static function can(array $actor, string $permission): bool
    {
        if (empty($actor['active']) || (int)($actor['id']??0)<1) return false;
        if (($actor['kind']??'')!=='employee') return false;
        $role=$actor['role']??'';
        $permissions=['delivery_view','delivery_arrange','delivery_view_assigned','delivery_drive',
            'delivery_collect','delivery_reconcile','delivery_accounting_view','delivery_accounting_manage',
            'delivery_accounting_expenses_manage','delivery_rates_manage','delivery_partner_manage','delivery_driver_manage'];
        if (!in_array($permission,$permissions,true)) return false;
        if ($role==='owner_admin') return true;
        // A Driver cannot gain financial/admin access through generic grants.
        if ($role==='delivery_driver') return !empty($actor['driver_verified']) && in_array($permission,
            ['delivery_view_assigned','delivery_drive','delivery_collect'],true);
        $roleDefaults=[
            'front_desk_admin'=>['delivery_view','delivery_arrange','delivery_reconcile'],
            'front_desk_admin_employee'=>['delivery_view','delivery_arrange','delivery_reconcile'],
            'marketing_sales'=>['delivery_view','delivery_arrange'],
        ];
        $defaults=$roleDefaults[$role]??[];
        return in_array($permission,$defaults,true)||in_array($permission,$actor['grants']??[],true);
    }

    public static function canView(array $actor,array $job): bool
    {
        if(empty($actor['active'])||(int)($actor['id']??0)<1) return false;
        if(($actor['kind']??'')==='partner') {
            return ($job['source']??'')==='partner' && (int)($actor['partner_id']??0)>0
                && (int)$actor['partner_id']===(int)($job['partner_id']??0)
                && (int)$actor['id']===(int)($job['partner_user_id']??0);
        }
        return self::can($actor,'delivery_view') || (self::can($actor,'delivery_view_assigned')
            && (int)$actor['id']===(int)($job['driver_employee_id']??0));
    }

    public static function requireAssignedDriver(array $actor,array $job): void
    {
        if(!self::can($actor,'delivery_drive') || (int)$actor['id']!==(int)($job['driver_employee_id']??0)
            || empty($actor['driver_verified'])) throw new \DomainException('Assigned active Driver required.');
    }

    /** Browser amount strings -> exact integer cents, no binary float rounding. */
    public static function cents(string $amount): int
    {
        if(!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D',$amount,$m))
            throw new \DomainException('Invalid currency amount.');
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }

    /** Bounds must come from locked server-side balances, never request fields. */
    public static function validateCollection(array $job,array $amounts,array $remaining,string $method,array $approvedMethods): void
    {
        if(($job['status']??'')!=='out_for_delivery') throw new \DomainException('Delivery is not collecting.');
        if(!in_array($method,$approvedMethods,true)) throw new \DomainException('Unapproved payment method.');
        $total=0;
        foreach(['order_goods','delivery_fee','partner_cod'] as $type) {
            $amount=$amounts[$type]??0;$due=$remaining[$type]??null;
            if(!is_int($amount)||$amount<0||!is_int($due)||$due<0||$amount>$due)
                throw new \DomainException('Collection exceeds validated balance.');
            $total+=$amount;
        }
        if($total<=0) throw new \DomainException('Collection must be positive.');
        if(($job['source']??'')==='hambelela' && ($amounts['partner_cod']??0)!==0)
            throw new \DomainException('Hambelela receipt cannot contain partner COD.');
        if(($job['source']??'')==='partner' && ($amounts['order_goods']??0)!==0)
            throw new \DomainException('Partner receipt cannot contain Hambelela product money.');
        if(($job['fee_payer']??'')!=='customer' && ($amounts['delivery_fee']??0)!==0)
            throw new \DomainException('Customer does not owe this delivery fee.');
    }

    public static function completionStatus(array $actor,array $job,int $remainingCents,int $unreconciledCents): string
    {
        self::requireAssignedDriver($actor,$job);
        if(($job['status']??'')!=='out_for_delivery') throw new \DomainException('Delivery cannot complete from this state.');
        if($remainingCents!==0||$unreconciledCents<0) throw new \DomainException('Resolve required collection first.');
        return $unreconciledCents>0?'awaiting_reconciliation':'completed';
    }

    /** Ledger signs include explicit reversing entries; product/COD never enter fund. */
    public static function financialSummary(array $ledger): array
    {
        $result=['fee_earned'=>0,'fee_received'=>0,'expense_paid'=>0,'order_funds_received'=>0,
            'partner_cod_held'=>0,'partner_cod_remitted'=>0];
        foreach($ledger as $entry) {
            if(!array_key_exists($entry['account']??'',$result)||!is_int($entry['amount_cents']??null))
                throw new \DomainException('Invalid ledger movement.');
            $result[$entry['account']]+=$entry['amount_cents'];
        }
        $result['available_fund']=$result['fee_received']-$result['expense_paid'];
        $result['partner_cod_liability']=$result['partner_cod_held']-$result['partner_cod_remitted'];
        return $result;
    }
}
