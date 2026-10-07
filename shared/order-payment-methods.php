<?php
declare(strict_types=1);
function ops_payment_method_map():array
{
    return ['cash'=>'Cash','card_swipe'=>'Swipe','eft'=>'EFT','fnb_ewallet'=>'FNB eWallet',
        'easywallet'=>'EasyWallet','blue_wallet'=>'Blue Wallet','nedbank'=>'Nedbank',
        'netbank_wallet'=>'NetBank Wallet','pay2cell'=>'Pay2Cell','paytoday'=>'PayToday','dpo'=>'DPO'];
}
