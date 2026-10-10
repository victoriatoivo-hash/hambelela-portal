<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/PartnerAccess.php';
final class WorkspaceApps
{
    public static function forActor(array $actor): array
    {
        if(($actor['kind']??'')==='partner'){
            if(($actor['role']??'')!=='partner_admin'||empty($actor['active']))return [];
            $shared=PartnerAccess::can($actor,'shared_reporting');
            $apps=[];
            if(PartnerAccess::can($actor,'driver'))$apps[]=['driver.php','driver','Driver',$shared?'View shared Hambelela and Tedlaser Driver deliveries.':'View Tedlaser deliveries and their Driver progress.','Open Driver View','bike'];
            $apps[]=['index.php','partner','Tedlaser','Create and manage your company’s deliveries.','Open Tedlaser','boxes'];
            if(PartnerAccess::can($actor,'accounting'))$apps[]=['accounting.php','accounting','Delivery Accounting',$shared?'View shared Delivery income, expenses, collections and balances.':'View Tedlaser delivery fees, collections and settlements.','Open Delivery Accounting','wallet'];
            if($shared)$apps[]=['pricing.php','pricing','Delivery Pricing','View current delivery areas and fees.','View Pricing','map-pin'];
            return $apps;
        }
        $owner=($actor['role']??'')==='owner_admin';
        $apps=[];
        if(DeliveryPolicy::can($actor,'delivery_view'))$apps[]=['workspace.php','front','Front Operations','Arrange customer deliveries, monitor progress and reconcile collections.','Open Front Operations','clipboard-list'];
        if($owner||DeliveryPolicy::can($actor,'delivery_view_assigned'))$apps[]=[$owner?'driver-view.php':'index.php','driver','Driver',"View the Driver’s delivery queue, collections and completed deliveries.",'Open Driver View','bike'];
        if($owner)$apps[]=['tedlaser.php','partner','Tedlaser','Create and monitor Tedlaser deliveries handled by the Hambelela Driver.','Open Tedlaser','boxes'];
        if(DeliveryPolicy::can($actor,'delivery_accounting_view'))$apps[]=['accounting.php','accounting','Delivery Accounting','Track delivery fees, expenses, collections and partner balances.','Open Delivery Accounting','wallet'];
        if($owner)$apps[]=['pricing.php','pricing','Delivery Pricing','Manage delivery areas and standard delivery fees.','Manage Pricing','map-pin'];
        if($owner)$apps[]=['settings.php','pricing','Delivery Settings','Manage delivery accounts, partner access, drivers and system preferences.','Manage Settings','settings'];
        return $apps;
    }
}
