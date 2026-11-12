<?php
/**
 * Plugin Name: Hambelela Sale Manager
 * Description: Scheduled portal campaigns for the online shop and Hambelela POS.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
namespace Hambelela\Sales;
defined('ABSPATH') || exit;
require_once __DIR__.'/rules.php';

function campaigns(): array {
    static $rules;
    if ($rules !== null) return $rules;
    global $wpdb;
    $rules = [];
    $prefix = 'ho_campaign_v1_';
    $records = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like($prefix).'%'));
    foreach ($records as $rec) {
        $row = json_decode($rec->option_value, true);
        if (is_array($row)) $rules[substr($rec->option_name,strlen($prefix))] = $row;
    }
    return $rules;
}

function active_rules(): array {
    static $active;
    if ($active === null) {
        $now = time();
        $active = array_filter(campaigns(), static function($r) use ($now) {
            return $r['enabled'] && (!$r['from'] || $r['from'] <= $now) && (!$r['until'] || $now < $r['until']);
        });
    }
    return $active;
}

function row_for($p): array {
    static $groups = [];
    $id = $p->get_id();
    $parent = $p->get_parent_id();
    $root = $parent ?: $id;
    if (!isset($groups[$root])) {
        $cats = wc_get_product_cat_ids($root);
        $groups[$root] = array_map('intval',$cats);
    }
    return ['id'=>$id, 'parent'=>$parent, 'categories'=>$groups[$root],
        'regular'=>(int)round((float)$p->get_regular_price('edit')*100),
        'base'=>(int)round((float)$p->get_price()*100)];
}

function catalog_page($req) {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status='publish' ORDER BY ID LIMIT 120", absint($req['after'])));
    $rows = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if (!$p || !$p->is_type(['simple','variation','variable'])) continue;
        if ($p->get_parent_id() && get_post_status($p->get_parent_id()) !== 'publish') continue;
        $rows[] = row_for($p) + ['name'=>wp_strip_all_tags($p->get_name()),'group'=>$p->is_type('variable')];
    }
    $result = ['items'=>$rows,'after'=>count($ids)===120 ? (int)end($ids) : 0];
    if (!$req['after']) {
        $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
        if (is_wp_error($terms)) return $terms;
        $result['categories'] = array_map(static function($c){return ['id'=>$c->term_id,'name'=>$c->name];},$terms);
    }
    return $result;
}
function save_campaign($req) {
    global $wpdb;
    $input = $req->get_json_params();
    try { $r = clean_rule($input); }
    catch (\InvalidArgumentException $e) { return new \WP_Error('campaign_input',$e->getMessage(),['status'=>400]); }
    $id = $req['id'];
    $name = 'ho_campaign_v1_'.$id;
    $old = get_option($name, false);
    $previous = $old === false ? [] : json_decode($old,true);
    $revision = (int)($input['revision'] ?? 0);
    if (($previous['revision'] ?? 0) !== $revision) return new \WP_Error('conflict','Another session changed this sale. Reload before editing.', ['status'=>409]);
    $r['revision'] = $revision + 1;
    $encoded = wp_json_encode($r);
    $ok = $old === false ? add_option($name,$encoded,'',false) : $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=%s",$encoded,$name,$old));
    if (!$ok) return new \WP_Error('write_failed','The campaign could not be saved. Reload and retry.', ['status'=>409]);
    wp_cache_delete($name,'options');
    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients();
    do_action('litespeed_purge_all');
    return ['id'=>$id,'revision'=>$r['revision']];
}
add_action('rest_api_init',static function(){
    $permission = static function(){return current_user_can('manage_woocommerce');};
    foreach (['campaigns'=>static function(){return ['campaigns'=>(object)campaigns()];}, 'catalog'=>__NAMESPACE__.'\\catalog_page'] as $path=>$cb)
        register_rest_route('wc/v3','/hambelela-sales/'.$path,['methods'=>'GET','callback'=>$cb,'permission_callback'=>$permission]);
    register_rest_route('wc/v3','/hambelela-sales/campaigns/(?P<id>[a-f0-9]{32})',['methods'=>'PUT','callback'=>__NAMESPACE__.'\\save_campaign','permission_callback'=>$permission]);
});

// Keep the catalogue price intact. Only the current cart product receives an adjusted unit.
add_action('woocommerce_before_calculate_totals',static function($cart){
    $previousUnits = $GLOBALS['ho_sale_cart_units'] ?? null;
    $GLOBALS['ho_sale_cart_units'] = new \SplObjectStorage();
    foreach ($cart->get_cart() as $key=>$line) {
        if (!isset($line['ho_campaign_basis'])) continue;
        if (isset($previousUnits) && $previousUnits->contains($line['data'])) $line['data']->set_price($line['ho_campaign_basis']);
        unset($cart->cart_contents[$key]['ho_campaign_basis'],$cart->cart_contents[$key]['ho_campaign']);
    }
},1);
add_action('woocommerce_before_calculate_totals',static function($cart){
    $rules = active_rules();
    if (!$rules) return;
    foreach ($cart->get_cart() as $key=>$line) {
        $qty = (int)$line['quantity'];
        if ($qty < 1 || $qty != $line['quantity']) continue;
        $p = $line['data'];
        $row = row_for($p);
        $best = quote($rules,$row,$qty,time());
        if (!$best['rule']) continue;
        $cart->cart_contents[$key]['ho_campaign_basis'] = $p->get_price('edit');
        $cart->cart_contents[$key]['ho_campaign'] = ['name'=>$best['rule']['name'],'stack'=>$best['rule']['stack'],'regular'=>$row['regular']/100,'unit'=>$best['total']/(100*$qty)];
        $unit = $best['total']/(100*$qty);
        $GLOBALS['ho_sale_cart_units'][$p] = $unit;
        $p->set_price($unit);
    }
},PHP_INT_MAX);
function cart_unit($price,$product) {
    $map = $GLOBALS['ho_sale_cart_units'] ?? null;
    return $map && $map->contains($product) ? $map[$product] : $price;
}
add_action('wp_loaded',static function(){
    foreach (['woocommerce_product_get_price','woocommerce_product_variation_get_price'] as $hook) add_filter($hook,__NAMESPACE__.'\\cart_unit',PHP_INT_MAX,2);
},PHP_INT_MAX);
add_filter('woocommerce_coupon_is_valid',static function($valid){
    if ($valid && WC()->cart) foreach (WC()->cart->get_cart() as $line) {
        if (isset($line['ho_campaign']) && !$line['ho_campaign']['stack']) throw new \Exception('This campaign cannot be combined with a coupon on the same order.');
    }
    return $valid;
},20);
add_action('woocommerce_checkout_create_order_line_item',static function($item,$key,$line){
    if (empty($line['ho_campaign'])) return;
    $sale = $line['ho_campaign'];
    $item->add_meta_data('Sale',$sale['name'],true);
    $item->add_meta_data('_hpos_regular_price_incl',wc_get_price_including_tax($line['data'],['price'=>$sale['regular']]),true);
},10,3);

function offer_for($p) {
    $rules = active_rules();
    if (!$rules || !$p || !$p->is_type(['simple','variation'])) return null;
    $row = row_for($p);
    $one = quote($rules,$row,1,time());
    if ($one['rule']) return ['label'=>$one['rule']['name'],'campaign'=>$one['rule']['name'],'amount'=>$one['total']/100,'regular'=>$row['regular']/100];
    foreach ($rules as $r) {
        if ($r['kind'] !== 'bundle' || !quote([$r],$row,$r['quantity'],time())['rule']) continue;
        return ['label'=>$r['quantity'].' for N$'.number_format(whole_dollar_cents($r['value'])/100,2),'campaign'=>$r['name'],'amount'=>null,'regular'=>$row['regular']/100];
    }
    return null;
}
function is_pre_black_friday(string $name): bool {
    return (bool)preg_match('/\bpre[\s\p{Pd}_]*black[\s\p{Pd}_]*friday\b/iu', $name);
}

function badge_for($p): array {
    if (!active_rules() || !$p) return [];
    $variable = $p->is_type('variable');
    $fallback = [];
    foreach ($variable ? $p->get_visible_children() : [$p->get_id()] as $id) {
        $offer = offer_for($variable ? wc_get_product($id) : $p);
        if (!$offer) continue;
        $prefix = $variable ? 'Selected sizes · ' : '';
        if (is_pre_black_friday($offer['campaign'])) {
            return ['corner'=>true, 'label'=>$prefix.'PRE BLACK FRIDAY'];
        }
        if (!$fallback) $fallback = ['corner'=>false, 'label'=>$prefix.$offer['label']];
    }
    return $fallback;
}
function badge() {
    global $product;
    $badge = badge_for($product);
    if (!$badge) return;
    if ($badge['corner'] && current_filter() === 'woocommerce_before_shop_loop_item_title') {
        echo '<span class="ho-pre-black-friday"><span aria-hidden="true">PRE<br>BLACK<br>FRIDAY</span><span class="screen-reader-text">'.esc_html($badge['label']).'</span></span>';
    } else {
        echo '<span class="ho-portal-sale">'.esc_html($badge['label']).'</span>';
    }
}
add_action('woocommerce_before_shop_loop_item_title',__NAMESPACE__.'\\badge',8);
add_action('woocommerce_before_add_to_cart_form',__NAMESPACE__.'\\badge',7);
add_action('wp_head',static function(){
    if (!active_rules()) return;
    echo '<style>
.products .product{position:relative}
.ho-portal-sale{display:inline-block;padding:.35em .75em;border-radius:4px;background:#8b2734;color:white;font-size:.8rem;font-weight:700}
.products .ho-portal-sale{position:absolute;top:.65rem;right:.65rem;z-index:5;max-width:85%}
.ho-pre-black-friday{--edge:clamp(68px,6vw,80px);font-family:inherit;position:absolute;top:0;left:0;z-index:5;width:var(--edge);height:var(--edge);background:#101010;color:#fff;clip-path:polygon(0 0,100% 0,0 100%);pointer-events:none}
.ho-pre-black-friday>[aria-hidden]{position:absolute;top:33%;left:33%;width:84%;transform:translate(-50%,-50%) rotate(-45deg);text-align:center;font-family:inherit;font-weight:700;font-size:calc(var(--edge) * .14);line-height:1;letter-spacing:.015em;color:#fff}
</style>';
});
function offer_price_html($html,$p) {
    $offer=offer_for($p);
    if (!$offer || $offer['amount'] === null) return $html;
    return wc_format_sale_price(wc_get_price_to_display($p,['price'=>$offer['regular']]),wc_get_price_to_display($p,['price'=>$offer['amount']]));
}
add_filter('woocommerce_get_price_html',__NAMESPACE__.'\\offer_price_html',1000,2);
add_filter('woocommerce_available_variation',static function($data,$parent,$v){
    $offer=offer_for($v);
    if ($offer) {
        $data['price_html'] = $offer['amount'] === null ? $v->get_price_html().' · '.esc_html($offer['label']) : offer_price_html('',$v);
        if ($offer['amount'] !== null) $data['display_price']=wc_get_price_to_display($v,['price'=>$offer['amount']]);
    }
    return $data;
},100,3);

// The terminal asks /price-check for totals, then /orders accepts the exact amount tendered.
add_action('woocommerce_after_calculate_totals',static function($cart){
    if (empty($GLOBALS['ho_sale_capture'])) return;
    foreach ($cart->get_cart() as $line) {
        if (empty($line['ho_campaign'])) continue;
        $key = (int)$line['product_id'].'-'.(int)($line['variation_id'] ?? 0);
        $GLOBALS['ho_sale_capture_rows'][$key] = ['regular'=>round(wc_get_price_including_tax($line['data'],['price'=>$line['ho_campaign']['regular']]),2),
            'stack'=>$line['ho_campaign']['stack']];
    }
},PHP_INT_MAX);
add_filter('rest_request_before_callbacks',static function($response,$handler,$req){
    if ($req->get_method()==='POST' && $req->get_route()==='/hpos/v1/price-check') {
        $GLOBALS['ho_sale_capture'] = true;
        $GLOBALS['ho_sale_capture_rows'] = [];
    }
    return $response;
},10,3);
add_filter('rest_request_after_callbacks',static function($response,$handler,$req){
    if ($req->get_route() !== '/hpos/v1/price-check' || empty($GLOBALS['ho_sale_capture'])) return $response;
    $GLOBALS['ho_sale_capture'] = false;
    if (!($response instanceof \WP_REST_Response) || $response->get_status()!==200) return $response;
    $data = $response->get_data();
    $data['campaigns'] = $GLOBALS['ho_sale_capture_rows'];
    foreach ($data['campaigns'] as $key=>$details) if (isset($data['prices'][$key])) $data['prices'][$key.'_regular']=$details['regular'];
    $response->set_data($data);
    return $response;
},90,3);
add_filter('rest_dispatch_request',static function($dispatch,$req){
    if ($dispatch!==null || $req->get_route()!=='/hpos/v1/orders' || $req->get_method()!=='POST') return $dispatch;
    $payload = $req->get_json_params();
    $items = [];
    $submitted = [];
    $hadSale = false;
    foreach (($payload['items'] ?? []) as $item) {
        if (!empty($item['custom_size'])) continue;
        $variation = (int)($item['variation_id'] ?? 0);
        $p = wc_get_product($variation ?: (int)($item['id'] ?? 0));
        if (!$p) continue;
        $id = $variation ? $p->get_parent_id() : $p->get_id();
        $key = $id.'-'.$variation;
        $submitted[$key] = $item;
        $hadSale = $hadSale || !empty($item['portal_sale']);
        $items[] = ['id'=>$id,'variation_id'=>$variation,'qty'=>$item['qty']];
    }
    if (!$items || (!active_rules() && !$hadSale)) return $dispatch;
    $check = new \WP_REST_Request('POST','/hpos/v1/price-check');
    // Carry the same POS authentication headers/parameters into the internal price check.
    $check->set_headers($req->get_headers());
    $check->set_query_params($req->get_query_params());
    $check->set_header('content-type','application/json');
    $check->set_body(wp_json_encode(['items'=>$items]));
    $result = rest_do_request($check);
    $quote = $result->get_data();
    if ($result->get_status()!==200 || !isset($quote['prices'])) return new \WP_Error('campaign_quote','Current sale prices could not be confirmed. Refresh the basket and retry.', ['status'=>409]);
    foreach ($submitted as $key=>$item) {
        $applied = $quote['campaigns'][$key] ?? null;
        if (!$applied && empty($item['portal_sale'])) continue;
        $qty = (int)$item['qty'];
        if (!isset($quote['prices'][$key]) || round($quote['prices'][$key]*$qty*100) !== round((float)$item['price']*$qty*100)) return new \WP_Error('campaign_changed','A sale price changed. Refresh the basket and confirm the new total before charging.', ['status'=>409]);
        if ($applied && !$applied['stack'] && !empty($payload['coupon_code'])) return new \WP_Error('campaign_coupon','Remove the coupon before charging this sale basket.', ['status'=>409]);
        if ($applied && round((float)($item['regular_price'] ?? 0)*100)!==round($applied['regular']*100)) return new \WP_Error('campaign_original','Refresh the basket to record the correct original price on the receipt.', ['status'=>409]);
    }
    return $dispatch;
},10,2);
function terminal_markup($html) {
    if (strpos($html,'id="hpos-root"')===false) return $html;
    $before = 'var newPrice=res.prices[key];';
    $after = 'var newPrice=res.prices[key]; i.portalSale=!!(res.campaigns && res.campaigns[key]);';
    $field = 'regular_price:i.regularPrice||i.price,name:i.name';
    return str_replace([$before,$field],[$after,'portal_sale:!!i.portalSale,'.$field],$html);
}
add_action('template_redirect',static function(){if (($_GET['hpos_staff'] ?? '')==='open') ob_start(__NAMESPACE__.'\\terminal_markup');},-2);
add_action('admin_head',static function(){$page=$_GET['page'] ?? '';if (is_string($page) && ($page==='hpos' || strpos($page,'hpos-')===0)) ob_start(__NAMESPACE__.'\\terminal_markup');});

function pos_catalog_price(array &$entry) {
    $product = wc_get_product($entry['id']);
    if (!$product) return;
    $offer = offer_for($product);
    if ($offer && $offer['amount'] !== null) $entry['price'] = min($entry['price'],$offer['amount']);
}
add_filter('rest_request_after_callbacks',static function($response,$handler,$req){
    if ($req->get_route()!=='/hpos/v1/products' || $req->get_method()!=='GET' || $req->get_param('ho_copy_prices_for') ||
        !($response instanceof \WP_REST_Response) || $response->get_status()!==200 || !active_rules()) return $response;
    $rows = $response->get_data();
    if (!isset($rows[0]['id'])) return $response;
    foreach ($rows as &$row) {
        if (!empty($row['variations'])) {
            foreach ($row['variations'] as &$variant) pos_catalog_price($variant);
            unset($variant);
            $row['price'] = min(array_column($row['variations'],'price'));
        } else pos_catalog_price($row);
    }
    unset($row);
    $response->set_data($rows);
    return $response;
},85,3);
