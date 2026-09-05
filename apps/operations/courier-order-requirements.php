<?php
declare(strict_types=1);

function courier_requirements_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS ops_courier_requirements (
        order_id INT NOT NULL PRIMARY KEY, employee_id INT NOT NULL,
        courier VARCHAR(160) NOT NULL, box_count INT NOT NULL,
        service_date DATE NOT NULL, upload_due_at DATETIME NOT NULL,
        recorded_at DATETIME NOT NULL, recorded_by INT NOT NULL,
        batch_id VARCHAR(60) NULL, linked_at DATETIME NULL, linked_by INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function courier_order_required(int $orderId): bool {
    $order=ops_row('SELECT order_type,fulfilment_mode FROM ops_orders WHERE id=?',[$orderId]);
    return strpos(strtolower((string)(($order['fulfilment_mode']??'')?:($order['order_type']??''))),'courier')!==false;
}

function courier_requirement_input(int $orderId): ?array {
    if (!courier_order_required($orderId)) return null;
    $courier=trim((string)($_POST['dispatch_courier']??''));
    $boxes=filter_var($_POST['dispatch_boxes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
    $date=(string)($_POST['dispatch_date']??'');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Africa/Windhoek'));
    if ($courier==='' || strlen($courier)>160 || !$boxes || !$parsed || $parsed->format('Y-m-d')!==$date) throw new RuntimeException('Courier orders require courier name, physical box count and courier service date. Update this order individually on the Orders board.');
    return ['courier'=>$courier,'boxes'=>$boxes,'date'=>$date];
}

function courier_requirement_save(int $orderId,int $employeeId,?array $details): void {
    if (!$details) return;
    if ($employeeId<=0) throw new RuntimeException('Select the responsible packer for this courier order.');
    $setting=ops_row("SELECT setting_value FROM kpi_settings WHERE setting_key='courier_sameday_cutoff'");
    $cutoff=(string)($setting['setting_value']??'17:00');
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$cutoff)) $cutoff='17:00';
    // Do not silently replace a recorded shipment on a repeated status change.
    db()->prepare('INSERT INTO ops_courier_requirements (order_id,employee_id,courier,box_count,service_date,upload_due_at,recorded_at,recorded_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)')->execute([$orderId,$employeeId,$details['courier'],$details['boxes'],$details['date'],$details['date'].' '.$cutoff.':00',(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s'),ops_current_employee_id()]);
}
