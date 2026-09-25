<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_role('owner_admin');

header('Content-Type: application/json; charset=utf-8');

function miv_api_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function miv_api_ensure_tables(): void
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS miv_shipping_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quote_reference VARCHAR(80) NOT NULL UNIQUE,
            customer_name VARCHAR(190) NOT NULL,
            phone VARCHAR(80) NULL,
            products_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_weight DECIMAL(12,3) NOT NULL DEFAULT 0,
            payment_plan ENUM('full','split','custom') NOT NULL DEFAULT 'split',
            order_stage VARCHAR(80) NOT NULL DEFAULT 'accepted',
            quote_json LONGTEXT NOT NULL,
            accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT NULL,
            created_by_name VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_miv_stage (order_stage),
            INDEX idx_miv_customer (customer_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    db()->exec("
        CREATE TABLE IF NOT EXISTS miv_shipping_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            component ENUM('products','shipping','general') NOT NULL DEFAULT 'general',
            amount DECIMAL(12,2) NOT NULL,
            payment_method VARCHAR(80) NULL,
            paid_at DATE NOT NULL,
            note VARCHAR(500) NULL,
            created_by INT NULL,
            created_by_name VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_miv_payment_order FOREIGN KEY (order_id) REFERENCES miv_shipping_orders(id) ON DELETE CASCADE,
            INDEX idx_miv_payment_order (order_id),
            INDEX idx_miv_payment_date (paid_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function miv_api_order_row(int $orderId): ?array
{
    $stmt = db()->prepare("
        SELECT o.*,
               COALESCE(SUM(CASE WHEN p.component='products' THEN p.amount ELSE 0 END),0) AS products_paid,
               COALESCE(SUM(CASE WHEN p.component='shipping' THEN p.amount ELSE 0 END),0) AS shipping_paid,
               COALESCE(SUM(CASE WHEN p.component='general' THEN p.amount ELSE 0 END),0) AS general_paid,
               COALESCE(SUM(p.amount),0) AS paid_total
        FROM miv_shipping_orders o
        LEFT JOIN miv_shipping_payments p ON p.order_id=o.id
        WHERE o.id=?
        GROUP BY o.id
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['products_outstanding'] = max(0, (float)$row['products_total'] - (float)$row['products_paid']);
    $row['shipping_outstanding'] = max(0, (float)$row['shipping_total'] - (float)$row['shipping_paid']);
    $row['outstanding_total'] = max(0, (float)$row['total_amount'] - (float)$row['paid_total']);
    return $row;
}

miv_api_ensure_tables();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['mode'] ?? 'list');
    if ($mode === 'payments') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if ($orderId < 1) miv_api_json(400, ['ok'=>false,'message'=>'Order ID required.']);
        $stmt = db()->prepare("SELECT * FROM miv_shipping_payments WHERE order_id=? ORDER BY paid_at DESC,id DESC");
        $stmt->execute([$orderId]);
        miv_api_json(200, ['ok'=>true,'payments'=>$stmt->fetchAll()]);
    }

    $rows = db()->query("
        SELECT o.*,
               COALESCE(SUM(CASE WHEN p.component='products' THEN p.amount ELSE 0 END),0) AS products_paid,
               COALESCE(SUM(CASE WHEN p.component='shipping' THEN p.amount ELSE 0 END),0) AS shipping_paid,
               COALESCE(SUM(p.amount),0) AS paid_total
        FROM miv_shipping_orders o
        LEFT JOIN miv_shipping_payments p ON p.order_id=o.id
        GROUP BY o.id
        ORDER BY o.updated_at DESC,o.id DESC
        LIMIT 300
    ")->fetchAll();
    foreach ($rows as &$row) {
        $row['products_outstanding'] = max(0, (float)$row['products_total'] - (float)$row['products_paid']);
        $row['shipping_outstanding'] = max(0, (float)$row['shipping_total'] - (float)$row['shipping_paid']);
        $row['outstanding_total'] = max(0, (float)$row['total_amount'] - (float)$row['paid_total']);
    }
    unset($row);
    miv_api_json(200, ['ok'=>true,'orders'=>$rows]);
}

$csrf = (string)($_SERVER['HTTP_X_MIV_CSRF'] ?? '');
$expected = (string)($_SESSION['miv_shipping_csrf'] ?? '');
if ($expected === '' || $csrf === '' || !hash_equals($expected, $csrf)) {
    miv_api_json(403, ['ok'=>false,'message'=>'Your session token expired. Refresh and try again.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$action = (string)($input['action'] ?? '');
$user = current_user();

if ($action === 'create_order') {
    $quote = $input['quote'] ?? null;
    if (!is_array($quote)) miv_api_json(400, ['ok'=>false,'message'=>'Quote data is required.']);
    $reference = trim((string)($quote['reference'] ?? ''));
    $customer = trim((string)($quote['customer'] ?? ''));
    if ($reference === '' || $customer === '') miv_api_json(400, ['ok'=>false,'message'=>'Quote reference and customer are required.']);

    $products = round((float)($quote['itemsNad'] ?? 0), 2);
    $shipping = round((float)($quote['shippingTotal'] ?? 0), 2);
    $total = round((float)($quote['total'] ?? ($products + $shipping)), 2);
    $weight = round((float)($quote['totalWeight'] ?? 0), 3);
    $plan = in_array(($input['payment_plan'] ?? ''), ['full','split','custom'], true) ? (string)$input['payment_plan'] : 'split';
    $initialStage = $plan === 'split' ? 'awaiting_product_payment' : 'awaiting_payment';

    $stmt = db()->prepare("
        INSERT INTO miv_shipping_orders
        (quote_reference,customer_name,phone,products_total,shipping_total,total_amount,total_weight,payment_plan,order_stage,quote_json,created_by,created_by_name)
        VALUES(?,?,?,?,?,?,?,?, ?, ?,?,?)
        ON DUPLICATE KEY UPDATE
          customer_name=VALUES(customer_name), phone=VALUES(phone), products_total=VALUES(products_total),
          shipping_total=VALUES(shipping_total), total_amount=VALUES(total_amount), total_weight=VALUES(total_weight),
          quote_json=VALUES(quote_json), updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $reference,$customer,trim((string)($quote['phone'] ?? '')) ?: null,
        $products,$shipping,$total,$weight,$plan,$initialStage,json_encode($quote, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        (int)($user['id'] ?? 0) ?: null,(string)($user['name'] ?? '')
    ]);
    $id = (int)db()->lastInsertId();
    if ($id < 1) {
        $q = db()->prepare("SELECT id FROM miv_shipping_orders WHERE quote_reference=? LIMIT 1");
        $q->execute([$reference]);
        $id = (int)$q->fetchColumn();
    }
    miv_api_json(200, ['ok'=>true,'order'=>miv_api_order_row($id)]);
}

if ($action === 'update_order') {
    $id = (int)($input['order_id'] ?? 0);
    if ($id < 1) miv_api_json(400, ['ok'=>false,'message'=>'Order ID required.']);
    $stage = trim((string)($input['order_stage'] ?? ''));
    $plan = trim((string)($input['payment_plan'] ?? ''));
    $allowedStages = ['accepted','awaiting_payment','awaiting_product_payment','products_paid','paid','ordered_china','china_warehouse','in_transit_sa','in_south_africa','awaiting_shipping_payment','shipping_paid','in_transit_namibia','ready','completed','cancelled'];
    if (!in_array($stage,$allowedStages,true)) miv_api_json(400,['ok'=>false,'message'=>'Choose a valid order stage.']);
    if (!in_array($plan,['full','split','custom'],true)) miv_api_json(400,['ok'=>false,'message'=>'Choose a valid payment plan.']);
    $stmt = db()->prepare("UPDATE miv_shipping_orders SET order_stage=?,payment_plan=? WHERE id=?");
    $stmt->execute([$stage,$plan,$id]);
    miv_api_json(200, ['ok'=>true,'order'=>miv_api_order_row($id)]);
}

if ($action === 'record_payment') {
    $id = (int)($input['order_id'] ?? 0);
    $amount = round((float)($input['amount'] ?? 0),2);
    $component = (string)($input['component'] ?? 'general');
    $method = trim((string)($input['payment_method'] ?? ''));
    $date = trim((string)($input['paid_at'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    if ($id < 1 || $amount <= 0) miv_api_json(400,['ok'=>false,'message'=>'Enter a valid payment amount.']);
    if (!in_array($component,['products','shipping','general'],true)) $component='general';
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)) $date=date('Y-m-d');

    $before = miv_api_order_row($id);
    if (!$before) miv_api_json(404,['ok'=>false,'message'=>'Order not found.']);
    $limit = (float)$before['outstanding_total'];
    if ($component === 'products') $limit = (float)$before['products_outstanding'];
    if ($component === 'shipping') $limit = (float)$before['shipping_outstanding'];
    if ($amount > $limit + 0.01) {
        miv_api_json(400,['ok'=>false,'message'=>'Payment is greater than the outstanding amount for this section.']);
    }

    $insert = db()->prepare("INSERT INTO miv_shipping_payments(order_id,component,amount,payment_method,paid_at,note,created_by,created_by_name) VALUES(?,?,?,?,?,?,?,?)");
    $actorId = (int)($user['id']??0) ?: null;
    $actorName = (string)($user['name']??'');
    if ($component === 'general') {
        $remaining = $amount;
        $productPart = min($remaining, (float)$before['products_outstanding']);
        if ($productPart > 0.009) {
            $insert->execute([$id,'products',$productPart,$method?:null,$date,$note?:null,$actorId,$actorName]);
            $remaining = round($remaining - $productPart, 2);
        }
        $shippingPart = min($remaining, (float)$before['shipping_outstanding']);
        if ($shippingPart > 0.009) {
            $insert->execute([$id,'shipping',$shippingPart,$method?:null,$date,$note?:null,$actorId,$actorName]);
            $remaining = round($remaining - $shippingPart, 2);
        }
        if ($remaining > 0.009) {
            miv_api_json(400,['ok'=>false,'message'=>'Payment could not be allocated to the remaining order balance.']);
        }
    } else {
        $insert->execute([$id,$component,$amount,$method?:null,$date,$note?:null,$actorId,$actorName]);
    }

    $order = miv_api_order_row($id);
    if ($order) {
        $newStage = $order['order_stage'];
        if ($order['payment_plan'] === 'split') {
            if ((float)$order['products_outstanding'] <= 0.009 && in_array($newStage,['accepted','awaiting_product_payment','awaiting_payment'],true)) {
                $newStage='products_paid';
            }
            if ((float)$order['shipping_outstanding'] <= 0.009 && $newStage==='awaiting_shipping_payment') {
                $newStage='shipping_paid';
            }
        } elseif ((float)$order['outstanding_total'] <= 0.009 && in_array($newStage,['accepted','awaiting_payment','awaiting_product_payment'],true)) {
            $newStage='paid';
        }
        if ($newStage !== $order['order_stage']) {
            db()->prepare("UPDATE miv_shipping_orders SET order_stage=? WHERE id=?")->execute([$newStage,$id]);
            $order = miv_api_order_row($id);
        }
    }
    miv_api_json(200,['ok'=>true,'order'=>$order]);
}

miv_api_json(400,['ok'=>false,'message'=>'Unknown action.']);
