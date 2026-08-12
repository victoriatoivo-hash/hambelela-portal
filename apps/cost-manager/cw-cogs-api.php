<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/cost-workbook.php';
require_once BASE_PATH . '/shared/woocommerce.php';
require_once BASE_PATH . '/shared/cost-workbook-cogs.php';
require_once BASE_PATH . '/shared/cost-workbook-cogs-endpoint.php';

function cw3_source(PDO $pdo, int $saleSizeId): array
{
    $sql = "SELECT ss.id sale_size_id,ss.complete_cost_per_sale_unit confirmed_cost,v.id calculation_version_id,v.status version_status,v.confirmed_at,m.classification,m.woo_product_id,m.woo_variation_id,s.parent_product_id,s.product_name,s.variation_name,s.attributes FROM cw_sale_size_costs ss JOIN cw_landed_calculation_lines l ON l.id=ss.calculation_line_id JOIN cw_landed_calculation_versions v ON v.id=l.calculation_version_id JOIN cw_calculation_product_matches m ON m.sale_size_cost_id=ss.id JOIN cw_product_snapshots s ON s.sync_batch_id=m.snapshot_id AND s.product_id=m.woo_product_id AND s.variation_id=COALESCE(m.woo_variation_id,0) WHERE ss.id=? AND v.status='confirmed'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$saleSizeId]);
    $row = $stmt->fetch();
    if (!$row) throw new DomainException('confirmed_cost_required');
    $cost = (float) $row['confirmed_cost'];
    if (!is_finite($cost) || $cost <= 0) throw new DomainException('confirmed_cost_required');
    if ($row['classification'] === 'variation' && (int) $row['woo_variation_id'] > 0) {
        $row['entity_type'] = 'variation'; $row['entity_id'] = (int) $row['woo_variation_id']; $row['parent_id'] = (int) $row['parent_product_id'];
    } elseif ($row['classification'] === 'simple' && (int) $row['woo_product_id'] > 0) {
        $row['entity_type'] = 'product'; $row['entity_id'] = (int) $row['woo_product_id']; $row['parent_id'] = null;
    } else throw new DomainException('exact_entity_required');
    $row['confirmed_cost'] = $cost;
    return $row;
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$request = $method === 'POST' ? json_decode((string) file_get_contents('php://input'), true) : $_GET;
$request = is_array($request) ? $request : [];
$pdo = null;
$dependencies = [
    'authorize' => static function (): void { require_role('owner_admin'); },
    'verify_nonce' => static function (): void { cw_require_csrf(); },
    'source' => static function (int $id) use (&$pdo): array { $pdo ??= db(); cw_install_schema($pdo); return cw3_source($pdo, $id); },
    'adapter' => static fn(): CostWorkbookNativeCogs => new CostWorkbookNativeCogs(),
    'user' => static fn(): array => cw_user(),
    'audit' => static function (string $action, int $id, array $before, array $after, array $user) use (&$pdo): void {
        $pdo ??= db();
        $stmt = $pdo->prepare('INSERT INTO cw_cost_audit_events(entity_type,entity_id,action_key,before_json,after_json,reason,actor_id,actor_name) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute(['sale_size', $id, $action, json_encode($before, JSON_UNESCAPED_SLASHES), json_encode($after, JSON_UNESCAPED_SLASHES), 'Owner-confirmed native WooCommerce COGS publication', $user['id'], $user['name']]);
    },
];
$response = cw3_handle($method, $request, $dependencies);
http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
