<?php

declare(strict_types=1);

require_once '/wordpress/wp-load.php';

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        file_put_contents('/workspace/.tmp-phase3-cogs-fatal.json', json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
});

if (!defined('PHASE3_COGS_EXPECTED_STATE')) {
    throw new RuntimeException('PHASE3_COGS_EXPECTED_STATE is required.');
}

wp_set_current_user(1);

$result = [
    'wordpress_version' => get_bloginfo('version'),
    'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
    'php_version' => PHP_VERSION,
    'database_version' => $GLOBALS['wpdb']->db_version(),
    'expected_state' => PHASE3_COGS_EXPECTED_STATE,
    'tests' => [],
];

$assert = static function (bool $condition, string $name, array $details = []) use (&$result): void {
    $result['tests'][] = ['name' => $name, 'passed' => $condition, 'details' => $details];
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $name);
    }
};

$finish = static function () use (&$result): void {
    $json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $path = '/workspace/.tmp-phase3-cogs-' . PHASE3_COGS_EXPECTED_STATE . '.json';
    file_put_contents($path, $json);
    echo $json;
};

$controller = wc_get_container()->get(Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController::class);
$enabled = $controller->feature_is_enabled();
$assert($enabled === (PHASE3_COGS_EXPECTED_STATE === 'enabled'), 'feature state matches isolated configuration', ['enabled' => $enabled]);

$simple = new WC_Product_Simple();
$simple->set_name('Synthetic simple COGS product');
$simple->set_status('draft');
$simple->set_regular_price('29.99');
$simpleId = $simple->save();

$parent = new WC_Product_Variable();
$parent->set_name('Synthetic variable COGS parent');
$parent->set_status('draft');
$parent->set_regular_price('49.99');
$parentId = $parent->save();

$variationA = new WC_Product_Variation();
$variationA->set_parent_id($parentId);
$variationA->set_status('publish');
$variationA->set_regular_price('39.99');
$variationAId = $variationA->save();

$variationB = new WC_Product_Variation();
$variationB->set_parent_id($parentId);
$variationB->set_status('publish');
$variationB->set_regular_price('44.99');
$variationBId = $variationB->save();

$dispatch = static function (string $method, string $route, ?array $body = null): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);
    $request->set_header('Content-Type', 'application/json');
    if ($body !== null) {
        $request->set_body(wp_json_encode($body));
    }
    $response = rest_do_request($request);
    if ($response instanceof WP_Error) {
        return rest_convert_error_to_response($response);
    }
    return $response;
};

$simpleRoute = '/wc/v3/products/' . $simpleId;
$variationRoute = '/wc/v3/products/' . $parentId . '/variations/' . $variationAId;
$simpleBefore = $dispatch('GET', $simpleRoute)->get_data();
$variationBefore = $dispatch('GET', $variationRoute)->get_data();

if (!$enabled) {
    $assert(!array_key_exists('cost_of_goods_sold', $simpleBefore), 'COGS absent from simple product response while disabled');
    $assert(!array_key_exists('cost_of_goods_sold', $variationBefore), 'COGS absent from variation response while disabled');
    $assert($simple->get_regular_price() === '29.99', 'enablement test fixture price remains unchanged');
    $finish();
    return;
}

$assert(array_key_exists('cost_of_goods_sold', $simpleBefore), 'COGS present in simple product response when enabled');
$assert(array_key_exists('cost_of_goods_sold', $variationBefore), 'COGS present in variation response when enabled');
$result['initial_cogs'] = [
    'simple' => $simpleBefore['cost_of_goods_sold'],
    'variation' => $variationBefore['cost_of_goods_sold'],
];
file_put_contents('/workspace/.tmp-phase3-cogs-initial.json', wp_json_encode($result['initial_cogs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$assert(array_key_exists('defined_value', $simpleBefore['cost_of_goods_sold']['values'][0]) && $simpleBefore['cost_of_goods_sold']['values'][0]['defined_value'] === null, 'enablement alone leaves simple product cost undefined');
$assert(array_key_exists('defined_value', $variationBefore['cost_of_goods_sold']['values'][0]) && $variationBefore['cost_of_goods_sold']['values'][0]['defined_value'] === null, 'enablement alone leaves variation cost undefined');

$hooks = [];
add_filter('woocommerce_save_product_cogs_value', static function ($value, $product) use (&$hooks) {
    $hooks[] = ['hook' => 'woocommerce_save_product_cogs_value', 'product_id' => $product->get_id()];
    return $value;
}, 10, 2);

$simplePayload = ['cost_of_goods_sold' => ['values' => [['defined_value' => 12.3456]]]];
$simpleWrite = $dispatch('PUT', $simpleRoute, $simplePayload);
$assert($simpleWrite->get_status() === 200, 'simple product COGS PUT succeeds', ['status' => $simpleWrite->get_status()]);
$simpleWritten = $simpleWrite->get_data();
$simpleRead = $dispatch('GET', $simpleRoute)->get_data();
$assert(isset($simpleWritten['cost_of_goods_sold']), 'simple PUT returns COGS representation');
$assert(isset($simpleRead['cost_of_goods_sold']), 'simple GET returns COGS representation');
$assert((float) $simpleRead['cost_of_goods_sold']['values'][0]['defined_value'] === 12.3456, 'simple defined value reads back');
$assert((float) $simpleRead['cost_of_goods_sold']['values'][0]['effective_value'] === 12.3456, 'simple effective value reads back');
$assert((float) $simpleRead['cost_of_goods_sold']['total_value'] === 12.3456, 'simple total value reads back');

$parentPayload = ['cost_of_goods_sold' => ['values' => [['defined_value' => 3.25]]]];
$parentWrite = $dispatch('PUT', '/wc/v3/products/' . $parentId, $parentPayload);
$assert($parentWrite->get_status() === 200, 'variable parent COGS PUT succeeds');

$variationPayload = [
    'cost_of_goods_sold' => [
        'values' => [['defined_value' => 7.125]],
        'defined_value_is_additive' => false,
    ],
];
$variationWrite = $dispatch('PUT', $variationRoute, $variationPayload);
$assert($variationWrite->get_status() === 200, 'variation COGS PUT succeeds', ['status' => $variationWrite->get_status()]);
$variationWritten = $variationWrite->get_data();
$variationRead = $dispatch('GET', $variationRoute)->get_data();
$parentRead = $dispatch('GET', '/wc/v3/products/' . $parentId)->get_data();
$siblingRead = $dispatch('GET', '/wc/v3/products/' . $parentId . '/variations/' . $variationBId)->get_data();
$assert((float) $variationRead['cost_of_goods_sold']['values'][0]['defined_value'] === 7.125, 'variation defined value reads back');
$assert((float) $variationRead['cost_of_goods_sold']['total_value'] === 7.125, 'non-additive variation overrides parent total');
$assert((float) $parentRead['cost_of_goods_sold']['total_value'] === 3.25, 'variation write leaves parent unchanged');
$assert(array_key_exists('defined_value', $siblingRead['cost_of_goods_sold']['values'][0]) && $siblingRead['cost_of_goods_sold']['values'][0]['defined_value'] === null, 'variation write leaves sibling undefined');
$assert((float) $siblingRead['cost_of_goods_sold']['total_value'] === 3.25, 'undefined sibling inherits parent total');

$additivePayload = [
    'cost_of_goods_sold' => [
        'values' => [['defined_value' => 1.75]],
        'defined_value_is_additive' => true,
    ],
];
$additiveWrite = $dispatch('PUT', $variationRoute, $additivePayload);
$assert($additiveWrite->get_status() === 200, 'additive variation PUT succeeds');
$additiveRead = $dispatch('GET', $variationRoute)->get_data();
$assert(($additiveRead['cost_of_goods_sold']['defined_value_is_additive'] ?? null) === true, 'variation additive flag reads back');
$assert((float) $additiveRead['cost_of_goods_sold']['total_value'] === 5.0, 'additive variation total includes parent');

$zeroWrite = $dispatch('PUT', $simpleRoute, ['cost_of_goods_sold' => ['values' => [['defined_value' => 0]]]]);
$zeroRead = $dispatch('GET', $simpleRoute)->get_data();
$assert($zeroWrite->get_status() === 200, 'native API accepts zero');
$assert(array_key_exists('defined_value', $zeroRead['cost_of_goods_sold']['values'][0]) && $zeroRead['cost_of_goods_sold']['values'][0]['defined_value'] === null, 'native zero normalizes to undefined value');
$assert((float) $zeroRead['cost_of_goods_sold']['total_value'] === 0.0, 'native zero total is zero');

$emptyWrite = $dispatch('PUT', $simpleRoute, ['cost_of_goods_sold' => ['values' => []]]);
$emptyRead = $dispatch('GET', $simpleRoute)->get_data();
$assert($emptyWrite->get_status() === 200, 'native API accepts empty values array');
$assert(array_key_exists('defined_value', $emptyRead['cost_of_goods_sold']['values'][0]) && $emptyRead['cost_of_goods_sold']['values'][0]['defined_value'] === null, 'empty values array clears defined value');

$nullWrite = $dispatch('PUT', $simpleRoute, ['cost_of_goods_sold' => ['values' => [['defined_value' => null]]]]);
$assert($nullWrite->get_status() >= 400, 'native API rejects null defined value', ['status' => $nullWrite->get_status()]);

$assert(count($hooks) >= 5, 'supported REST updates invoke native product COGS save hook', ['count' => count($hooks)]);

$result['contract'] = [
    'simple_payload' => $simplePayload,
    'simple_response' => $simpleWritten['cost_of_goods_sold'],
    'variation_payload' => $variationPayload,
    'variation_response' => $variationWritten['cost_of_goods_sold'],
    'additive_response' => $additiveRead['cost_of_goods_sold'],
    'null_status' => $nullWrite->get_status(),
    'hook_count' => count($hooks),
];

$finish();
