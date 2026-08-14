<?php

declare(strict_types=1);

require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/accounts-input-vat.php';
require_once BASE_PATH . '/shared/woocommerce.php';

function output_vat_require_owner(): void
{
    require_login();
    if (!accounts_is_owner()) {
        http_response_code(403);
        exit('You do not have access to Output VAT.');
    }
}

function output_vat_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    $queries = [
        "CREATE TABLE IF NOT EXISTS accounts_output_vat_periods (month_key CHAR(7) PRIMARY KEY, source_hash CHAR(64) NULL, source_summary LONGTEXT NULL, source_synced_at DATETIME NULL, sync_error TEXT NULL, reconciliation_status VARCHAR(30) NOT NULL DEFAULT 'in_progress', adjustment_amount DECIMAL(13,2) NOT NULL DEFAULT 0, adjustment_reason VARCHAR(500) NULL, adjustment_note TEXT NULL, snapshot_hash CHAR(64) NULL, snapshot_json LONGTEXT NULL, completed_at DATETIME NULL, completed_by INT NULL, completed_by_name VARCHAR(190) NULL, historical_change TINYINT(1) NOT NULL DEFAULT 0, change_json LONGTEXT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_output_vat_orders (month_key CHAR(7) NOT NULL, woo_order_id BIGINT UNSIGNED NOT NULL, order_number VARCHAR(80) NOT NULL, order_date DATETIME NOT NULL, order_status VARCHAR(40) NOT NULL, included TINYINT(1) NOT NULL DEFAULT 0, exclusion_reason VARCHAR(190) NULL, gross_total DECIMAL(13,2) NOT NULL DEFAULT 0, total_sales_incl_vat DECIMAL(13,2) NOT NULL DEFAULT 0, shipping DECIMAL(13,2) NOT NULL DEFAULT 0, shipping_tax DECIMAL(13,2) NOT NULL DEFAULT 0, discount_amount DECIMAL(13,2) NOT NULL DEFAULT 0, refund_amount DECIMAL(13,2) NOT NULL DEFAULT 0, standard_rated_incl DECIMAL(13,2) NOT NULL DEFAULT 0, zero_rated_sales DECIMAL(13,2) NOT NULL DEFAULT 0, exempt_sales DECIMAL(13,2) NOT NULL DEFAULT 0, woo_vat DECIMAL(13,2) NOT NULL DEFAULT 0, expected_vat DECIMAL(13,2) NOT NULL DEFAULT 0, net_sales_excl_vat DECIMAL(13,2) NOT NULL DEFAULT 0, treatment VARCHAR(30) NOT NULL DEFAULT 'review', source_hash CHAR(64) NOT NULL, source_modified_at DATETIME NULL, PRIMARY KEY(month_key,woo_order_id), KEY idx_output_vat_period_included(month_key,included), KEY idx_output_vat_number(order_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_output_vat_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, month_key CHAR(7) NOT NULL, action_key VARCHAR(50) NOT NULL, before_json LONGTEXT NULL, after_json LONGTEXT NULL, actor_id INT NOT NULL, actor_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_output_vat_audit(month_key,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($queries as $query) db()->exec($query);
    $ready = true;
    return true;
}

function output_vat_csrf_token(): string
{
    if (empty($_SESSION['accounts_output_vat_csrf'])) $_SESSION['accounts_output_vat_csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['accounts_output_vat_csrf'];
}

function output_vat_verify_csrf(string $token): void
{
    if ($token === '' || !hash_equals(output_vat_csrf_token(), $token)) throw new RuntimeException('Your session expired. Refresh and try again.');
}

function output_vat_month(string $value): string
{
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $value)) return date('Y-m');
    return $value;
}

function output_vat_money($value): float { return round((float) $value, 2); }
function output_vat_sql_datetime(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
}

function output_vat_tax_class(string $taxClass, float $tax): string
{
    $key = strtolower(trim(str_replace(['_', ' '], '-', $taxClass)));
    if ($tax > 0.00001) return 'standard';
    if (in_array($key, ['zero', 'zero-rate', 'zero-rated', '0'], true)) return 'zero_rated';
    if (in_array($key, ['exempt', 'non-taxable', 'non-vat', 'no-vat'], true)) return 'exempt';
    return $key === '' || $key === 'standard' ? 'standard' : 'review';
}

function output_vat_order_is_included(array $order): array
{
    $status = strtolower((string) ($order['status'] ?? ''));
    if (in_array($status, ['processing', 'completed'], true)) return [true, ''];
    if ($status === 'on-hold' && !empty($order['date_paid'])) return [true, 'Paid order on hold'];
    return [false, 'Excluded status: ' . ($status !== '' ? $status : 'unknown')];
}

function output_vat_refund_totals(int $orderId, array $order): array
{
    $listed = (array) ($order['refunds'] ?? []);
    if (!$listed) return [0.0, 0.0, 0.0, 0.0, 0.0, false];
    $amount = 0.0; $tax = 0.0; $standard = 0.0; $zero = 0.0; $exempt = 0.0; $allocated = false;
    foreach ($listed as $refund) $amount += abs((float) ($refund['total'] ?? 0));
    try {
        $details = wc_get('orders/' . $orderId . '/refunds', ['per_page' => 100], 12);
        $amount = 0.0;
        foreach ($details as $refund) {
            $amount += abs((float) ($refund['total'] ?? 0));
            foreach ((array) ($refund['line_items'] ?? []) as $line) {
                $lineNet=abs((float)($line['refund_total']??$line['total']??0));$lineTax=abs((float)($line['refund_tax']??$line['total_tax']??0));$tax+=$lineTax;$class=output_vat_tax_class((string)($line['tax_class']??''),$lineTax);
                if($class==='standard')$standard+=$lineNet+$lineTax;elseif($class==='zero_rated')$zero+=$lineNet;else$exempt+=$lineNet;$allocated=true;
            }
            foreach ((array) ($refund['shipping_lines'] ?? []) as $line) {
                $lineNet=abs((float)($line['total']??0));$lineTax=abs((float)($line['total_tax']??0));$tax+=$lineTax;$class=output_vat_tax_class((string)($line['tax_class']??''),$lineTax);
                if($class==='standard')$standard+=$lineNet+$lineTax;elseif($class==='zero_rated')$zero+=$lineNet;else$exempt+=$lineNet;$allocated=true;
            }
        }
    } catch (Throwable $e) {
        error_log('Output VAT refund detail fallback for order ' . $orderId . ': ' . $e->getMessage());
    }
    return [output_vat_money($amount), output_vat_money($tax), output_vat_money($standard), output_vat_money($zero), output_vat_money($exempt), $allocated];
}

function output_vat_map_order(array $order, string $month): array
{
    list($included, $reason) = output_vat_order_is_included($order);
    $standard = 0.0; $zero = 0.0; $exempt = 0.0; $review = 0.0; $lineTax = 0.0;
    foreach ((array) ($order['line_items'] ?? []) as $line) {
        $net = (float) ($line['total'] ?? 0); $tax = (float) ($line['total_tax'] ?? 0); $incl = $net + $tax;
        $class = output_vat_tax_class((string) ($line['tax_class'] ?? ''), $tax);
        if ($class === 'standard') $standard += $incl;
        elseif ($class === 'zero_rated') $zero += $incl;
        elseif ($class === 'exempt') $exempt += $incl;
        else $review += $incl;
        $lineTax += $tax;
    }
    $shipping = 0.0; $shippingTax = 0.0;
    foreach ((array) ($order['shipping_lines'] ?? []) as $line) {
        $net = (float) ($line['total'] ?? 0); $tax = (float) ($line['total_tax'] ?? 0); $shipping += $net; $shippingTax += $tax;
        $class = output_vat_tax_class((string) ($line['tax_class'] ?? ''), $tax);
        /* Shipping follows the tax actually recorded by WooCommerce. A blank
           class with zero stored tax is not evidence that shipping was taxable. */
        if ($tax > 0.00001) $standard += $net + $tax;
        elseif ($class === 'zero_rated') $zero += $net;
        else $exempt += $net;
    }
    list($refundAmount, $refundTax, $refundStandard, $refundZero, $refundExempt, $refundAllocated) = output_vat_refund_totals((int) ($order['id'] ?? 0), $order);
    if ($refundAmount > 0 && !$refundAllocated) {
        $base=max(0.01,$standard+$zero+$exempt+$review);$refundStandard=$refundAmount*($standard/$base);$refundZero=$refundAmount*($zero/$base);$refundExempt=$refundAmount*(($exempt+$review)/$base);
    }
    $standard=max(0.0,$standard-$refundStandard);$zero=max(0.0,$zero-$refundZero);$exempt=max(0.0,$exempt-$refundExempt);
    $wooVat = max(0.0, $lineTax + $shippingTax - $refundTax);
    $gross = (float) ($order['total'] ?? 0); $sales = max(0.0, $gross - $refundAmount);
    $rate = accounts_standard_vat_rate();
    $expected = $rate > 0 ? $standard * $rate / (100 + $rate) : 0.0;
    $treatment = $review > 0 ? 'review' : ($standard > 0 && ($zero > 0 || $exempt > 0) ? 'mixed' : ($standard > 0 ? 'standard' : ($zero > 0 ? 'zero_rated' : 'exempt')));
    $row = [
        'month_key'=>$month,'woo_order_id'=>(int)($order['id']??0),'order_number'=>(string)($order['number']??$order['id']??''),
        'order_date'=>output_vat_sql_datetime((string)($order['date_created']??'')),'order_status'=>(string)($order['status']??''),'included'=>$included?1:0,'exclusion_reason'=>$reason,
        'gross_total'=>output_vat_money($gross),'total_sales_incl_vat'=>output_vat_money($sales),'shipping'=>output_vat_money($shipping),'shipping_tax'=>output_vat_money($shippingTax),
        'discount_amount'=>output_vat_money($order['discount_total']??0),'refund_amount'=>$refundAmount,'standard_rated_incl'=>output_vat_money($standard),
        'zero_rated_sales'=>output_vat_money($zero),'exempt_sales'=>output_vat_money($exempt+$review),'woo_vat'=>output_vat_money($wooVat),'expected_vat'=>output_vat_money($expected),
        'net_sales_excl_vat'=>output_vat_money($sales-$wooVat),'treatment'=>$treatment,'source_modified_at'=>output_vat_sql_datetime((string)($order['date_modified']??$order['date_created']??'')),
    ];
    $row['source_hash'] = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES));
    return $row;
}

function output_vat_fetch_month(string $month): array
{
    $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('Africa/Windhoek'));
    $end = $start->modify('+1 month'); $orders = [];
    for ($page = 1; $page <= 50; $page++) {
        $batch = wc_get('orders', ['after'=>$start->format(DateTime::ATOM),'before'=>$end->format(DateTime::ATOM),'status'=>'any','per_page'=>100,'page'=>$page,'orderby'=>'date','order'=>'asc'], 25);
        foreach ($batch as $order) $orders[] = output_vat_map_order($order, $month);
        if (count($batch) < 100) break;
    }
    return $orders;
}

function output_vat_summary_from_rows(array $rows): array
{
    $summary = ['orders'=>0,'excluded_orders'=>0,'gross_sales'=>0.0,'total_sales'=>0.0,'shipping'=>0.0,'shipping_tax'=>0.0,'discounts'=>0.0,'refunds'=>0.0,'standard_sales'=>0.0,'zero_rated_sales'=>0.0,'exempt_sales'=>0.0,'woo_vat'=>0.0,'expected_vat'=>0.0,'net_sales_excl_vat'=>0.0];
    foreach ($rows as $row) {
        if (!(int) $row['included']) { $summary['excluded_orders']++; continue; }
        $summary['orders']++;
        foreach (['gross_total'=>'gross_sales','total_sales_incl_vat'=>'total_sales','shipping'=>'shipping','shipping_tax'=>'shipping_tax','discount_amount'=>'discounts','refund_amount'=>'refunds','standard_rated_incl'=>'standard_sales','zero_rated_sales'=>'zero_rated_sales','exempt_sales'=>'exempt_sales','woo_vat'=>'woo_vat','expected_vat'=>'expected_vat','net_sales_excl_vat'=>'net_sales_excl_vat'] as $source=>$target) $summary[$target] += (float) $row[$source];
    }
    foreach ($summary as $key=>$value) if (is_float($value)) $summary[$key] = output_vat_money($value);
    $summary['difference'] = output_vat_money($summary['expected_vat'] - $summary['woo_vat']);
    $summary['rounding_tolerance'] = output_vat_money(max(0.02, $summary['orders'] * 0.01));
    return $summary;
}

function output_vat_audit(string $month, string $action, ?array $before, ?array $after): void
{
    $user=current_user(); $stmt=db()->prepare('INSERT INTO accounts_output_vat_audit(month_key,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?)');
    $stmt->execute([$month,$action,$before?json_encode($before,JSON_UNESCAPED_SLASHES):null,$after?json_encode($after,JSON_UNESCAPED_SLASHES):null,(int)($user['id']??0),(string)($user['name']??'Owner')]);
}

function output_vat_period(string $month): array
{
    output_vat_schema_ready();
    $stmt=db()->prepare('SELECT * FROM accounts_output_vat_periods WHERE month_key=?'); $stmt->execute([$month]); $row=$stmt->fetch();
    if (!$row) return ['month_key'=>$month,'source_summary'=>null,'source_synced_at'=>null,'reconciliation_status'=>'in_progress','adjustment_amount'=>0.0,'adjustment_reason'=>'','adjustment_note'=>'','completed_at'=>null,'historical_change'=>0];
    $row['source_summary']=$row['source_summary']?json_decode((string)$row['source_summary'],true):null;
    $row['change_json']=$row['change_json']?json_decode((string)$row['change_json'],true):null;
    return $row;
}

function output_vat_sync(string $month): array
{
    output_vat_schema_ready(); $rows=output_vat_fetch_month($month); $summary=output_vat_summary_from_rows($rows);
    $hash=hash('sha256',json_encode([$summary,array_column($rows,'source_hash')],JSON_UNESCAPED_SLASHES));
    $period=output_vat_period($month); $historical=!empty($period['snapshot_hash']) && !hash_equals((string)$period['snapshot_hash'],$hash);
    db()->beginTransaction();
    try {
        $delete=db()->prepare('DELETE FROM accounts_output_vat_orders WHERE month_key=?'); $delete->execute([$month]);
        $sql='INSERT INTO accounts_output_vat_orders(month_key,woo_order_id,order_number,order_date,order_status,included,exclusion_reason,gross_total,total_sales_incl_vat,shipping,shipping_tax,discount_amount,refund_amount,standard_rated_incl,zero_rated_sales,exempt_sales,woo_vat,expected_vat,net_sales_excl_vat,treatment,source_hash,source_modified_at) VALUES(:month_key,:woo_order_id,:order_number,:order_date,:order_status,:included,:exclusion_reason,:gross_total,:total_sales_incl_vat,:shipping,:shipping_tax,:discount_amount,:refund_amount,:standard_rated_incl,:zero_rated_sales,:exempt_sales,:woo_vat,:expected_vat,:net_sales_excl_vat,:treatment,:source_hash,:source_modified_at)';
        $insert=db()->prepare($sql); foreach($rows as $row) $insert->execute($row);
        $upsert=db()->prepare("INSERT INTO accounts_output_vat_periods(month_key,source_hash,source_summary,source_synced_at,sync_error,historical_change,change_json) VALUES(?,?,?,NOW(),NULL,?,?) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_summary=VALUES(source_summary),source_synced_at=NOW(),sync_error=NULL,historical_change=VALUES(historical_change),change_json=VALUES(change_json)");
        $change=$historical?['snapshot_hash'=>$period['snapshot_hash'],'current_hash'=>$hash,'detected_at'=>date('c')]:null;
        $upsert->execute([$month,$hash,json_encode($summary,JSON_UNESCAPED_SLASHES),$historical?1:0,$change?json_encode($change,JSON_UNESCAPED_SLASHES):null]); db()->commit();
    } catch(Throwable $e){db()->rollBack();throw $e;}
    return output_vat_payload($month);
}

function output_vat_payload(string $month, string $search='', string $status='', string $treatment=''): array
{
    $period=output_vat_period($month); $summary=(array)($period['source_summary']??output_vat_summary_from_rows([]));
    $sql='SELECT * FROM accounts_output_vat_orders WHERE month_key=?';$args=[$month];
    if($search!==''){$sql.=' AND order_number LIKE ?';$args[]='%'.$search.'%';}
    if($status!==''){$sql.=' AND order_status=?';$args[]=$status;}
    if($treatment!==''){$sql.=' AND treatment=?';$args[]=$treatment;}
    $sql.=' ORDER BY order_date DESC,woo_order_id DESC';$stmt=db()->prepare($sql);$stmt->execute($args);$rows=$stmt->fetchAll();
    $adjustment=(float)($period['adjustment_amount']??0);$final=output_vat_money((float)($summary['expected_vat']??0)+$adjustment);
    $difference=output_vat_money((float)($summary['difference']??0));
    $statusKey=(string)($period['reconciliation_status']??'in_progress');
    $tolerance=(float)($summary['rounding_tolerance']??0.02);
    if(empty($period['completed_at'])) $statusKey=$adjustment!=0.0?'adjusted':(abs($difference)<=$tolerance?'reconciled':'review_required');
    return ['month'=>$month,'summary'=>$summary,'orders'=>$rows,'period'=>['status'=>$statusKey,'adjustment'=>$adjustment,'adjustment_reason'=>(string)($period['adjustment_reason']??''),'adjustment_note'=>(string)($period['adjustment_note']??''),'final_output_vat'=>$final,'completed_at'=>$period['completed_at']??null,'historical_change'=>(bool)($period['historical_change']??false),'change'=>$period['change_json']??null,'synced_at'=>$period['source_synced_at']??null]];
}
