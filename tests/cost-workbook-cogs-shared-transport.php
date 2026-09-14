<?php

declare(strict_types=1);

putenv('WC_STORE_URL=http://127.0.0.1:18731');
putenv('WC_CONSUMER_KEY=synthetic-key');
putenv('WC_CONSUMER_SECRET=synthetic-secret');
require_once '/workspace/shared/woocommerce.php';

$product = wc_get('products/41', ['context'=>'edit']);
$variation = wc_get('products/40/variations/81', ['context'=>'edit']);
$writtenProduct = wc_put('products/41', ['cost_of_goods_sold'=>['values'=>[['defined_value'=>14.5]]]]);
$writtenVariation = wc_put('products/40/variations/81', ['cost_of_goods_sold'=>['values'=>[['defined_value'=>8.25]],'defined_value_is_additive'=>false]]);
if ((float)$product['cost_of_goods_sold']['total_value'] !== 12.3456) throw new RuntimeException('wc_get product failed.');
if ((float)$variation['cost_of_goods_sold']['total_value'] !== 12.3456) throw new RuntimeException('wc_get variation failed.');
if ((float)$writtenProduct['cost_of_goods_sold']['total_value'] !== 14.5) throw new RuntimeException('wc_put product failed.');
if ((float)$writtenVariation['cost_of_goods_sold']['total_value'] !== 8.25) throw new RuntimeException('wc_put variation failed.');
file_put_contents('/workspace/.tmp-phase3-transport-result.txt', "Passed shared wc_get/wc_put product and exact-variation transport tests.\n");
