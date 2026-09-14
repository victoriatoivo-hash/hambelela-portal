<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/cost-workbook-cogs.php';

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) throw new RuntimeException($message);
};

$productResponse = ['id' => 41, 'cost_of_goods_sold' => ['values' => [['defined_value' => 12.3456, 'effective_value' => 12.3456]], 'total_value' => 12.3456]];
$variationResponse = ['id' => 81, 'cost_of_goods_sold' => ['values' => [['defined_value' => 7.125, 'effective_value' => 7.125]], 'total_value' => 7.125, 'defined_value_is_additive' => false]];

$product = CostWorkbookNativeCogs::normalize($productResponse, 'product', 41);
$variation = CostWorkbookNativeCogs::normalize($variationResponse, 'variation', 81);
$assert($product['defined_cost'] === 12.3456 && $product['inheritance_mode'] === 'direct', 'Product normalization failed.');
$assert($variation['defined_cost'] === 7.125 && $variation['inheritance_mode'] === 'override', 'Variation normalization failed.');
$assert(CostWorkbookNativeCogs::payload(2.75, 'product')['cost_of_goods_sold']['values'][0]['defined_value'] === 2.75, 'Product payload failed.');
$assert(CostWorkbookNativeCogs::payload(2.75, 'variation')['cost_of_goods_sold']['defined_value_is_additive'] === false, 'Variation payload must disable additive mode.');
$assert(CostWorkbookNativeCogs::endpoint('variation', 81, 40) === 'products/40/variations/81', 'Variation endpoint failed.');

foreach ([['unknown', 41, null], ['product', 0, null], ['variation', 81, 0]] as [$type, $id, $parentId]) {
    try { CostWorkbookNativeCogs::endpoint($type, $id, $parentId); $assert(false, 'Invalid entity accepted.'); }
    catch (InvalidArgumentException $e) { $assert(true, ''); }
}

foreach ([0.0, -1.0] as $bad) {
    try { CostWorkbookNativeCogs::payload($bad, 'product'); $assert(false, 'Invalid cost accepted.'); }
    catch (InvalidArgumentException $e) { $assert(true, ''); }
}

$disabled = new CostWorkbookNativeCogs(static fn() => ['id' => 41], static fn() => throw new RuntimeException('PUT must not run'));
try { $disabled->updateVerified('product', 41, null, 4.25, null); $assert(false, 'Disabled feature published.'); }
catch (DomainException $e) { $assert($e->getMessage() === 'woocommerce_cogs_disabled', 'Wrong disabled code.'); }

$putCount = 0;
$adapter = new CostWorkbookNativeCogs(
    static fn() => $productResponse,
    static function (string $path, array $payload) use (&$putCount): array { $putCount++; return ['id'=>41,'cost_of_goods_sold'=>['values'=>[['defined_value'=>$payload['cost_of_goods_sold']['values'][0]['defined_value'],'effective_value'=>$payload['cost_of_goods_sold']['values'][0]['defined_value']]],'total_value'=>$payload['cost_of_goods_sold']['values'][0]['defined_value']]]; }
);
try { $adapter->updateVerified('product', 41, null, 14.5, 99.0); $assert(false, 'Stale value accepted.'); }
catch (DomainException $e) { $assert($e->getMessage() === 'woocommerce_cogs_stale' && $putCount === 0, 'Stale request mutated.'); }
$updated = $adapter->updateVerified('product', 41, null, 14.5, 12.3456);
$assert($updated['verified'] === true && $updated['defined_cost'] === 14.5 && $putCount === 1, 'Verified update failed.');

$badAdapter = new CostWorkbookNativeCogs(static fn() => $productResponse, static fn() => $productResponse);
try { $badAdapter->updateVerified('product', 41, null, 14.5, 12.3456); $assert(false, 'Mismatch accepted.'); }
catch (RuntimeException $e) { $assert($e->getMessage() === 'woocommerce_cogs_verification_failed', 'Wrong mismatch code.'); }

$safe = CostWorkbookNativeCogs::safeError(new RuntimeException('consumer_secret=never expose this'));
$assert($safe['code'] === 'woocommerce_cogs_request_failed' && !str_contains(json_encode($safe), 'consumer_secret'), 'Unsafe error leaked.');

$message = "Passed {$tests} native COGS adapter tests.\n";
file_put_contents('/workspace/.tmp-phase3-adapter-result.txt', $message);
echo $message;
