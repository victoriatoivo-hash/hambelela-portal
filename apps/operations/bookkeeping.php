<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'Cash Ledger | ' . APP_NAME;
$activeApp = 'operations-bookkeeping';
$ready = ops_database_ready();
$employeeId = ops_current_employee_id();
$message = null;
$messageType = 'success';

function ledger_money(float $amount): string
{
    return 'N$' . number_format($amount, 2);
}

function ledger_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ledger_wants_json(): bool
{
    return strtolower((string) ($_POST['response'] ?? '')) === 'json'
        || strpos(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
}

function ledger_normalize_datetime(string $value): string
{
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') return date('Y-m-d H:i:s');
    $timestamp = strtotime($value);
    if ($timestamp === false) throw new RuntimeException('Use a valid date and time.');
    return date('Y-m-d H:i:s', $timestamp);
}

function ledger_number($value): float
{
    return max(0, (float) preg_replace('/[^0-9.\-]+/', '', (string) $value));
}

function ledger_transaction_type(float $cashIn, float $cashOut): string
{
    if ($cashOut > 0 && $cashOut >= $cashIn) return 'cash_taken_out';
    return 'cash_received';
}

function ledger_bootstrap_schema(): void
{
    if (!ops_database_ready()) return;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_cash_book_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME NOT NULL,
            transaction_type VARCHAR(40) NOT NULL DEFAULT 'cash_received',
            description VARCHAR(190) NOT NULL,
            related_order_id INT NULL,
            related_order_number VARCHAR(80) NULL,
            customer_name VARCHAR(190) NULL,
            order_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_in DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_out DECIMAL(12,2) NOT NULL DEFAULT 0,
            actual_count DECIMAL(12,2) NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'manual_cash_entry',
            notes TEXT NULL,
            attachment_path VARCHAR(255) NULL,
            recorded_by INT NULL,
            edited_by INT NULL,
            archived_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS hambelela_cashbook_recon (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recon_date DATE NOT NULL,
            system_balance DECIMAL(10,2) NOT NULL,
            counted_total DECIMAL(10,2) NOT NULL,
            variance DECIMAL(10,2) NOT NULL,
            variance_note TEXT,
            logged_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ledger_entry(int $id): array
{
    $rows = ops_rows('SELECT * FROM ops_cash_book_entries WHERE id = ? AND archived_at IS NULL LIMIT 1', [$id]);
    if (!$rows) throw new RuntimeException('Ledger entry not found.');
    $row = $rows[0];
    $cashIn = (float) ($row['cash_in'] ?? 0);
    $cashOut = (float) ($row['cash_out'] ?? 0);
    return [
        'id' => (int) $row['id'],
        'date' => (string) $row['transaction_date'],
        'date_input' => date('Y-m-d\TH:i', strtotime((string) $row['transaction_date'])),
        'day' => date('Y-m-d', strtotime((string) $row['transaction_date'])),
        'description' => (string) $row['description'],
        'cash_in' => $cashIn,
        'cash_out' => $cashOut,
        'total' => $cashIn - $cashOut,
        'notes' => (string) ($row['notes'] ?? ''),
    ];
}

if ($ready) {
    ledger_bootstrap_schema();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'add_entry') {
            $description = ops_post_string('description', 190);
            if ($description === '') throw new RuntimeException('Description is required.');
            $date = ledger_normalize_datetime(ops_post_string('transaction_date', 30));
            $cashIn = ledger_number($_POST['cash_in'] ?? 0);
            $cashOut = ledger_number($_POST['cash_out'] ?? 0);
            if ($cashIn <= 0 && $cashOut <= 0) throw new RuntimeException('Enter cash in or cash out.');
            $type = ledger_transaction_type($cashIn, $cashOut);
            $stmt = db()->prepare(
                "INSERT INTO ops_cash_book_entries
                 (transaction_date, transaction_type, description, cash_in, cash_out, source, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, 'manual_cash_entry', ?, ?)"
            );
            $stmt->execute([$date, $type, $description, $cashIn, $cashOut, ops_post_string('notes', 1500), $employeeId]);
            $id = (int) db()->lastInsertId();
            ledger_json(['ok' => true, 'message' => 'Entry saved.', 'entry' => ledger_entry($id)]);
        }
        if ($action === 'update_entry') {
            $id = (int) ($_POST['entry_id'] ?? 0);
            $field = ops_post_string('field', 40);
            $allowed = [
                'description' => 'description',
                'transaction_date' => 'transaction_date',
                'cash_in' => 'cash_in',
                'cash_out' => 'cash_out',
                'notes' => 'notes',
            ];
            if ($id <= 0 || !isset($allowed[$field])) throw new RuntimeException('Invalid edit.');
            $value = trim((string) ($_POST['value'] ?? ''));
            if ($field === 'description' && $value === '') throw new RuntimeException('Description is required.');
            if ($field === 'transaction_date') $value = ledger_normalize_datetime($value);
            if (in_array($field, ['cash_in', 'cash_out'], true)) $value = (string) ledger_number($value);
            $stmt = db()->prepare('UPDATE ops_cash_book_entries SET ' . $allowed[$field] . ' = ?, edited_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND archived_at IS NULL');
            $stmt->execute([$value, $employeeId, $id]);
            $entry = ledger_entry($id);
            $type = ledger_transaction_type($entry['cash_in'], $entry['cash_out']);
            db()->prepare('UPDATE ops_cash_book_entries SET transaction_type = ? WHERE id = ?')->execute([$type, $id]);
            ledger_json(['ok' => true, 'message' => 'Saved.', 'entry' => ledger_entry($id)]);
        }
        if ($action === 'cashbook_save_recon') {
            $reconDate = ops_post_string('recon_date', 20);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconDate)) $reconDate = date('Y-m-d');
            $systemBalance = (float) ($_POST['system_balance'] ?? 0);
            $countedTotal = (float) ($_POST['counted_total'] ?? 0);
            $variance = $countedTotal - $systemBalance;
            $stmt = db()->prepare(
                "INSERT INTO hambelela_cashbook_recon
                 (recon_date, system_balance, counted_total, variance, variance_note, logged_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $reconDate,
                $systemBalance,
                $countedTotal,
                $variance,
                ops_post_string('variance_note', 1500),
                $employeeId ?: 0,
            ]);
            $history = ops_rows(
                "SELECT recon_date, system_balance, counted_total, variance, variance_note, created_at
                 FROM hambelela_cashbook_recon
                 ORDER BY created_at DESC, id DESC
                 LIMIT 5"
            );
            ledger_json(['ok' => true, 'message' => 'Reconciliation saved.', 'history' => $history]);
        }
        throw new RuntimeException('Unknown ledger action.');
    } catch (Throwable $e) {
        if (ledger_wants_json()) ledger_json(['ok' => false, 'message' => $e->getMessage()], 400);
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$entries = $ready ? ops_rows(
    "SELECT *
     FROM ops_cash_book_entries
     WHERE archived_at IS NULL
     ORDER BY transaction_date DESC, id DESC"
) : [];

$today = date('Y-m-d');
$cashInToday = 0.0;
$cashOutToday = 0.0;
$entriesToday = 0;
$closingBalance = 0.0;
$groups = [];

foreach ($entries as $entry) {
    $cashIn = (float) ($entry['cash_in'] ?? 0);
    $cashOut = (float) ($entry['cash_out'] ?? 0);
    $day = date('Y-m-d', strtotime((string) $entry['transaction_date']));
    $closingBalance += $cashIn - $cashOut;
    if ($day === $today) {
        $cashInToday += $cashIn;
        $cashOutToday += $cashOut;
        $entriesToday++;
    }
    $groups[$day][] = $entry;
}

if (!$groups) {
    $groups[$today] = [];
}

$netToday = $cashInToday - $cashOutToday;
$reconHistory = $ready ? ops_rows(
    "SELECT recon_date, system_balance, counted_total, variance, variance_note, created_at
     FROM hambelela_cashbook_recon
     ORDER BY created_at DESC, id DESC
     LIMIT 5"
) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ledger-red: #721B1A;
            --ledger-rust: #AB3619;
            --ledger-orange: #F07420;
            --ledger-lime: #A8CA19;
            --ledger-text: #721B1A;
            --ledger-muted: #AB3619;
            --ledger-border: rgba(171, 54, 25, .22);
            --ledger-soft: rgba(240, 116, 32, .06);
            --ledger-white: #fff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--ledger-white);
            color: var(--ledger-text);
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
        }
        .ledger-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 220px minmax(0, 1fr);
            background: var(--ledger-white);
        }
        .ledger-side-panel {
            min-height: 100vh;
            border-right: 1px solid var(--ledger-border);
            background: var(--ledger-white);
            padding: 18px 14px;
            position: sticky;
            top: 0;
            align-self: start;
        }
        .ledger-back-button {
            width: 100%;
            min-height: 34px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 999px;
            background: var(--ledger-white);
            color: var(--ledger-red);
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            text-align: left;
            padding: 0 12px;
        }
        .ledger-side-title {
            margin: 18px 0 10px;
            color: var(--ledger-red);
            font-size: 14px;
            font-weight: 900;
        }
        .ledger-side-nav {
            display: grid;
            gap: 7px;
        }
        .ledger-side-nav a {
            min-height: 32px;
            display: flex;
            align-items: center;
            border: 1px solid transparent;
            border-radius: 12px;
            color: var(--ledger-rust);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            padding: 0 10px;
        }
        .ledger-side-nav a:hover,
        .ledger-side-nav a.is-active {
            border-color: rgba(240, 116, 32, .28);
            background: rgba(240, 116, 32, .08);
            color: var(--ledger-red);
        }
        .ledger-page {
            min-height: 100vh;
            background: var(--ledger-white);
            padding: 28px;
        }
        .ledger-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }
        h1 {
            margin: 0;
            color: var(--ledger-red);
            font-size: 14px;
            letter-spacing: 0;
            line-height: 1;
            font-weight: 900;
        }
        .ledger-subtitle {
            margin: 8px 0 0;
            color: var(--ledger-muted);
            font-size: 12px;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 26px;
        }
        .stat-card {
            border: 1px solid var(--ledger-border);
            border-radius: 18px;
            background: var(--ledger-white);
            box-shadow: 0 12px 28px rgba(114, 27, 26, .07);
            padding: 18px;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 6px;
            background: var(--accent);
        }
        .stat-label {
            display: block;
            color: #6B6B6B;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .stat-value {
            display: block;
            margin-top: 10px;
            color: #1a1a1a;
            font-size: 14px;
            font-weight: 800;
        }
        .ledger-board {
            width: 100%;
            overflow-x: auto;
            background: var(--ledger-white);
            padding-bottom: 8px;
        }
        .ledger-board-inner {
            min-width: 1060px;
        }
        .day-group {
            border: 1px solid var(--ledger-border);
            border-radius: 18px;
            background: var(--ledger-white);
            box-shadow: 0 10px 26px rgba(114, 27, 26, .06);
            margin-bottom: 16px;
            overflow: hidden;
            border-left: 6px solid var(--ledger-rust);
        }
        .day-head,
        .ledger-row {
            display: grid;
            grid-template-columns: 32px 220px 130px 100px 100px 100px minmax(160px, 1fr);
        }
        .day-head {
            min-height: 58px;
            border-bottom: 1px solid var(--ledger-border);
            background: var(--ledger-white);
        }
        .day-title {
            grid-column: 1 / 4;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 16px;
        }
        .toggle-day {
            width: 28px;
            height: 28px;
            border: 1px solid rgba(171, 54, 25, .2);
            border-radius: 999px;
            background: var(--ledger-white);
            color: var(--ledger-rust);
            cursor: pointer;
            font-size: 12px;
            line-height: 1;
        }
        .day-name {
            color: #1a1a1a;
            font-size: 14px;
            font-weight: 600;
        }
        .day-count {
            color: var(--ledger-muted);
            font-size: 12px;
            font-weight: 700;
        }
        .day-sum {
            display: flex;
            align-items: center;
            justify-content: center;
            border-left: 1px solid var(--ledger-border);
            font-weight: 800;
            font-size: 11px;
        }
        .ledger-header {
            background: #fafafa;
            color: #1a1a1a;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ledger-header .ledger-cell {
            color: #1a1a1a;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            background: #fafafa;
        }
        .ledger-row {
            border-bottom: 1px solid var(--ledger-border);
        }
        .ledger-cell {
            min-height: 42px;
            display: flex;
            align-items: center;
            min-width: 0;
            padding: 0 12px;
            border-right: 1px solid var(--ledger-border);
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 12px;
            color: #1a1a1a;
        }
        .ledger-cell:last-child {
            border-right: 0;
        }
        .ledger-total,
        .money-cell {
            justify-content: flex-end;
            font-weight: 800;
        }
        .money-in { color: #3d5c00; }
        .money-out { color: #BB1B21; }
        .money-net { color: #1a1a1a; }
        [data-row-total].money-net,
        [data-add-total].money-net {
            color: #1a1a1a;
            font-weight: 600;
        }
        .check-cell { justify-content: center; padding: 0; }
        .row-dot {
            width: 16px;
            height: 16px;
            border-radius: 5px;
            border: 1px solid rgba(171, 54, 25, .3);
            background: var(--ledger-white);
        }
        .ledger-data-cell {
            cursor: text;
            transition: background .12s ease, outline-color .12s ease;
        }
        .ledger-data-cell:hover {
            background: rgba(171, 54, 25, .05);
            outline: 1px dashed var(--ledger-orange);
            outline-offset: -2px;
        }
        .ledger-data-cell.is-editing {
            background: var(--ledger-white);
            outline: 2px solid var(--ledger-orange);
            outline-offset: -2px;
        }
        .ledger-data-cell input,
        .ledger-data-cell textarea,
        .add-row input,
        .add-row textarea {
            width: 100%;
            height: 30px;
            border: 1px solid rgba(171, 54, 25, .28);
            border-radius: 10px;
            background: var(--ledger-white);
            color: var(--ledger-text);
            font: inherit;
            font-size: 12px;
            padding: 0 9px;
            outline: 0;
        }
        .ledger-data-cell textarea,
        .add-row textarea {
            height: 32px;
            padding-top: 7px;
            resize: none;
        }
        .add-row {
            background: var(--ledger-white);
        }
        .add-row .ledger-cell {
            min-height: 50px;
            overflow: visible;
        }
        .is-invalid {
            border-color: var(--ledger-red) !important;
            animation: shake .3s ease;
        }
        .day-group.is-collapsed .ledger-header,
        .day-group.is-collapsed .entry-row,
        .day-group.is-collapsed .add-row {
            display: none;
        }
        .closing-card {
            border: 1px solid var(--ledger-border);
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(114, 27, 26, .06), rgba(240, 116, 32, .08)), var(--ledger-white);
            box-shadow: 0 14px 30px rgba(114, 27, 26, .08);
            padding: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-top: 18px;
        }
        .closing-card span {
            color: var(--ledger-muted);
            font-weight: 800;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: .05em;
        }
        .closing-card strong {
            color: var(--ledger-red);
            font-size: 14px;
            font-weight: 900;
        }
        .bk-page-layout {
            display: block;
        }
        .bk-ledger-col {
            min-width: 0;
        }
        .bk-sidebar-col {
            display: none;
        }
        .bk-filter-section {
            margin-bottom: 16px;
        }
        .bk-side-section {
            border: 1px solid var(--ledger-border);
            border-radius: 14px;
            background: var(--ledger-white);
            box-shadow: 0 8px 18px rgba(114, 27, 26, .04);
            overflow: hidden;
        }
        .bk-side-head {
            min-height: 38px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 0 12px;
            border-bottom: 1px solid var(--ledger-border);
            background: #fafafa;
            color: #1a1a1a;
            font-size: 14px;
            font-weight: 700;
        }
        .bk-side-toggle {
            width: 24px;
            height: 24px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 999px;
            background: var(--ledger-white);
            color: var(--ledger-rust);
            cursor: pointer;
            font-size: 12px;
        }
        .bk-side-body {
            display: grid;
            gap: 10px;
            padding: 12px;
        }
        .bk-side-section.is-collapsed .bk-side-body {
            display: none;
        }
        .bk-field {
            display: grid;
            gap: 4px;
            color: #6B6B6B;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .bk-field input,
        .bk-field textarea,
        .bk-filter-grid input {
            width: 100%;
            height: 30px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 9px;
            background: var(--ledger-white);
            color: #1a1a1a;
            font: inherit;
            font-size: 12px;
            padding: 0 8px;
            outline: 0;
        }
        .bk-field textarea {
            height: 58px;
            padding-top: 7px;
            resize: vertical;
        }
        .bk-field input:focus,
        .bk-field textarea:focus,
        .bk-filter-grid input:focus {
            border-color: var(--ledger-rust);
            box-shadow: 0 0 0 3px rgba(171, 54, 25, .10);
        }
        .bk-filter-grid,
        .bk-denom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .bk-denom-row {
            display: grid;
            grid-template-columns: 52px 1fr;
            align-items: center;
            gap: 6px;
            color: #1a1a1a;
            font-size: 12px;
            font-weight: 600;
        }
        .bk-denom-row input {
            text-align: right;
        }
        .bk-counter-total,
        .bk-recon-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: #1a1a1a;
            font-size: 12px;
        }
        .bk-counter-total strong,
        .bk-recon-line strong {
            font-size: 14px;
        }
        .bk-recon-variance.is-negative {
            color: #BB1B21;
        }
        .bk-recon-variance.is-positive {
            color: #3d5c00;
        }
        .bk-side-button {
            height: 32px;
            border: 0;
            border-radius: 999px;
            background: var(--ledger-rust);
            color: var(--ledger-white);
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
        }
        .bk-side-button:hover {
            background: var(--ledger-orange);
        }
        .bk-history-list {
            display: grid;
            gap: 7px;
            margin-top: 2px;
        }
        .bk-history-item {
            border: 1px solid var(--ledger-border);
            border-radius: 10px;
            padding: 8px;
            color: #1a1a1a;
            font-size: 12px;
            background: #fff;
        }
        .bk-history-item small {
            display: block;
            color: #6B6B6B;
            font-size: 10px;
            margin-top: 2px;
        }
        .bk-drawer-trigger {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 35;
            height: 38px;
            border: 0;
            border-radius: 999px;
            background: var(--ledger-rust);
            color: var(--ledger-white);
            box-shadow: 0 12px 28px rgba(114, 27, 26, .16);
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            padding: 0 18px;
        }
        .bk-drawer-trigger:hover {
            background: var(--ledger-orange);
        }
        .bk-overlay {
            position: fixed;
            inset: 0;
            z-index: 40;
            background: rgba(37, 39, 51, .18);
            opacity: 0;
            pointer-events: none;
            transition: opacity .16s ease;
        }
        .bk-overlay.is-open {
            opacity: 1;
            pointer-events: auto;
        }
        .bk-drawer {
            position: fixed;
            top: 0;
            right: 0;
            z-index: 45;
            width: min(380px, calc(100vw - 28px));
            height: 100vh;
            background: var(--ledger-white);
            border-left: 1px solid var(--ledger-border);
            box-shadow: -18px 0 36px rgba(114, 27, 26, .12);
            transform: translateX(100%);
            transition: transform .18s ease;
            display: flex;
            flex-direction: column;
        }
        .bk-drawer.is-open {
            transform: translateX(0);
        }
        .bk-drawer-header {
            min-height: 54px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 14px;
            border-bottom: 1px solid var(--ledger-border);
        }
        .bk-drawer-title {
            color: #1a1a1a;
            font-size: 14px;
            font-weight: 900;
        }
        .bk-drawer-close {
            width: 30px;
            height: 30px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 999px;
            background: var(--ledger-white);
            color: #1a1a1a;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .bk-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 12px 14px 0;
        }
        .bk-tab {
            height: 32px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 999px;
            background: #fff;
            color: #1a1a1a;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
        }
        .bk-tab.is-active {
            background: var(--ledger-rust);
            border-color: var(--ledger-rust);
            color: var(--ledger-white);
        }
        .bk-drawer-body {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 14px;
            overflow-y: auto;
        }
        .bk-tab-panel {
            display: none;
            min-width: 0;
        }
        .bk-tab-panel.is-active {
            display: block;
        }
        .bk-drawer-body .denom-card,
        .bk-drawer-body .recon-card {
            width: 100% !important;
            min-width: 0 !important;
            flex-shrink: 0;
        }
        .bk-drawer-body .denom-grid {
            width: 100%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
            grid-template-columns: none !important;
        }
        .bk-drawer-body .bk-side-body {
            min-width: 0;
        }
        .bk-drawer-body .bk-denom-row {
            min-width: 0;
            display: grid;
            grid-template-columns: 70px 1fr;
            align-items: center;
            column-gap: 12px;
        }
        .bk-drawer-body .bk-denom-row span {
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            white-space: nowrap;
        }
        .bk-drawer-body .bk-denom-row input {
            width: 100%;
            height: 22px;
            min-width: 0;
            padding: 0 8px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 3px;
            background: #ffffff;
            box-sizing: border-box;
            text-align: right;
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            font-weight: 400;
            color: #252733;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
            appearance: textfield;
            -moz-appearance: textfield;
        }
        .bk-drawer-body .bk-denom-row input:hover {
            border-color: rgba(171, 54, 25, .42);
        }
        .bk-drawer-body .bk-denom-row input:focus {
            border-color: #ab3619;
            box-shadow: 0 0 0 2px rgba(171, 54, 25, .10);
        }
        .bk-drawer-body .bk-denom-row input::-webkit-outer-spin-button,
        .bk-drawer-body .bk-denom-row input::-webkit-inner-spin-button {
            opacity: .55;
            margin: 0;
        }
        .bk-denom-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            width: auto;
            height: 28px;
            padding: 0 8px;
            background: #ffffff;
            border: 1px solid #f4b8b1;
            border-radius: 8px;
            color: #ef6b62;
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            font-weight: 500;
            line-height: 1;
            cursor: pointer;
            white-space: nowrap;
            transition: all .2s ease;
        }
        .bk-denom-reset svg {
            width: 12px;
            height: 12px;
            flex: 0 0 12px;
            color: inherit;
            stroke-width: 2;
        }
        .bk-denom-reset:hover {
            background: #fff6f5;
            border-color: #ef6b62;
            color: #d84b42;
        }
        .bk-denom-reset:active {
            transform: scale(.98);
        }
        .bk-denom-reset:active svg,
        .bk-denom-reset.is-resetting svg {
            animation: reset-spin .45s ease;
        }
        .bk-denom-reset:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(239, 107, 98, .18);
        }
        @keyframes reset-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(-360deg); }
        }
        #copyTotalBtn {
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            font-weight: 800;
            width: 180px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 10px auto 0;
            background: #AB3619;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background .2s ease;
        }
        #copyTotalBtn:active,
        #copyTotalBtn.copied {
            background: #F07420;
        }
        .bk-copy-total-row {
            padding: 0;
        }
        .toast {
            position: fixed;
            right: 22px;
            bottom: 74px;
            border-radius: 999px;
            background: var(--ledger-red);
            color: var(--ledger-white);
            padding: 10px 16px;
            box-shadow: 0 12px 28px rgba(114, 27, 26, .16);
            font-size: 12px;
            font-weight: 800;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity .16s ease, transform .16s ease;
            z-index: 20;
        }
        .toast.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }
        @media (max-width: 760px) {
            .ledger-shell { grid-template-columns: 1fr; }
            .ledger-side-panel { min-height: 0; position: static; border-right: 0; border-bottom: 1px solid var(--ledger-border); }
            .ledger-side-nav { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .bk-page-layout { grid-template-columns: 1fr; }
            .ledger-page { padding: 18px; }
            .ledger-top { flex-direction: column; }
            .stat-grid { grid-template-columns: 1fr; }
            .ledger-board-inner { min-width: 980px; }
            .closing-card { align-items: flex-start; flex-direction: column; }
        }
        @media (max-width: 900px) {
            .bk-page-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="ledger-shell">
<aside class="ledger-side-panel" aria-label="Cash ledger navigation">
    <button class="ledger-back-button" type="button" data-ledger-back>&larr; Back</button>
    <div class="ledger-side-title">Operations</div>
    <nav class="ledger-side-nav">
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/dashboard.php">Dashboard</a>
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/orders-board.php">Orders Board</a>
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/orders.php">Orders</a>
        <a class="is-active" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/bookkeeping.php">Cash Ledger</a>
    </nav>
</aside>
<main class="ledger-page bk-wrap">
    <header class="ledger-top">
        <div>
            <h1>Cash Ledger</h1>
            <p class="ledger-subtitle">Daily cash in, cash out, net movement, and closing balance.</p>
        </div>
    </header>

    <?php if (!$ready): ?>
        <section class="stat-card" style="--accent: #721B1A;"><span class="stat-label">Database</span><strong class="stat-value">Not ready</strong></section>
    <?php else: ?>
        <section class="stat-grid" aria-label="Cash ledger summary">
            <article class="stat-card" style="--accent: #AB3619;"><span class="stat-label">Cash In Today</span><strong class="stat-value" data-stat-cash-in><?= ledger_money($cashInToday) ?></strong></article>
            <article class="stat-card" style="--accent: #F07420;"><span class="stat-label">Cash Out Today</span><strong class="stat-value" data-stat-cash-out><?= ledger_money($cashOutToday) ?></strong></article>
            <article class="stat-card" style="--accent: #721B1A;"><span class="stat-label">Net Balance Today</span><strong class="stat-value" data-stat-net><?= ledger_money($netToday) ?></strong></article>
            <article class="stat-card" style="--accent: #A8CA19;"><span class="stat-label">Entries Today</span><strong class="stat-value" data-stat-count><?= number_format($entriesToday) ?></strong></article>
        </section>

        <section class="bk-side-section bk-filter-section" aria-label="Cash ledger filters">
            <div class="bk-side-head"><span>Filters</span></div>
            <div class="bk-side-body">
                <div class="bk-filter-grid">
                    <label class="bk-field">From<input type="date" data-bk-filter-from></label>
                    <label class="bk-field">To<input type="date" data-bk-filter-to></label>
                </div>
                <label class="bk-field">Search<input type="search" data-bk-filter-search placeholder="Description or notes"></label>
                <button class="bk-side-button" type="button" data-bk-filter-clear>Clear filters</button>
            </div>
        </section>

        <div class="bk-page-layout">
        <div class="bk-ledger-col">
        <section class="ledger-board" aria-label="Cash ledger board">
            <div class="ledger-board-inner">
                <?php foreach ($groups as $day => $dayEntries): ?>
                    <?php
                    $dayIn = array_sum(array_map(static fn (array $row): float => (float) $row['cash_in'], $dayEntries));
                    $dayOut = array_sum(array_map(static fn (array $row): float => (float) $row['cash_out'], $dayEntries));
                    $dayNet = $dayIn - $dayOut;
                    $dayLabel = (new DateTimeImmutable($day))->format('d F Y');
                    $addDate = $day . 'T' . date('H:i');
                    ?>
                    <section class="day-group" data-day-group="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="day-head">
                            <div class="day-title">
                                <button class="toggle-day" type="button" data-toggle-day aria-label="Toggle <?= htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8') ?>">v</button>
                                <span class="day-name"><?= htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="day-count" data-day-count><?= count($dayEntries) ?> <?= count($dayEntries) === 1 ? 'entry' : 'entries' ?></span>
                            </div>
                            <div class="day-sum money-in" data-day-in><?= ledger_money($dayIn) ?></div>
                            <div class="day-sum money-out" data-day-out><?= ledger_money($dayOut) ?></div>
                            <div class="day-sum money-net" data-day-net><?= ledger_money($dayNet) ?></div>
                            <div class="day-sum"></div>
                        </div>
                        <div class="ledger-row ledger-header">
                            <div class="ledger-cell check-cell"></div>
                            <div class="ledger-cell">Description</div>
                            <div class="ledger-cell">Date & Time</div>
                            <div class="ledger-cell">Cash In</div>
                            <div class="ledger-cell">Cash Out</div>
                            <div class="ledger-cell">Total</div>
                            <div class="ledger-cell">Notes</div>
                        </div>
                        <?php foreach ($dayEntries as $entry): ?>
                            <?php
                            $rowIn = (float) ($entry['cash_in'] ?? 0);
                            $rowOut = (float) ($entry['cash_out'] ?? 0);
                            $rowTotal = $rowIn - $rowOut;
                            $entryDate = (string) $entry['transaction_date'];
                            ?>
                            <div class="ledger-row entry-row" data-entry-id="<?= (int) $entry['id'] ?>" data-cash-in="<?= $rowIn ?>" data-cash-out="<?= $rowOut ?>">
                                <div class="ledger-cell check-cell"><span class="row-dot"></span></div>
                                <div class="ledger-cell ledger-data-cell" data-field="description" data-value="<?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="ledger-cell ledger-data-cell" data-field="transaction_date" data-value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($entryDate)), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, g:i A', strtotime($entryDate)), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="ledger-cell ledger-data-cell money-cell money-in" data-field="cash_in" data-value="<?= htmlspecialchars((string) $rowIn, ENT_QUOTES, 'UTF-8') ?>"><?= $rowIn > 0 ? ledger_money($rowIn) : '' ?></div>
                                <div class="ledger-cell ledger-data-cell money-cell money-out" data-field="cash_out" data-value="<?= htmlspecialchars((string) $rowOut, ENT_QUOTES, 'UTF-8') ?>"><?= $rowOut > 0 ? ledger_money($rowOut) : '' ?></div>
                                <div class="ledger-cell ledger-total money-net" data-row-total><?= ledger_money($rowTotal) ?></div>
                                <div class="ledger-cell ledger-data-cell" data-field="notes" data-value="<?= htmlspecialchars((string) ($entry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($entry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="ledger-row add-row" data-add-row data-day="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="ledger-cell check-cell"></div>
                            <div class="ledger-cell"><input data-add-field="description" placeholder="Add cash entry"></div>
                            <div class="ledger-cell"><input data-add-field="transaction_date" type="datetime-local" value="<?= htmlspecialchars($addDate, ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="ledger-cell"><input data-add-field="cash_in" type="number" min="0" step="0.01" placeholder="0.00"></div>
                            <div class="ledger-cell"><input data-add-field="cash_out" type="number" min="0" step="0.01" placeholder="0.00"></div>
                            <div class="ledger-cell ledger-total money-net" data-add-total>N$0.00</div>
                            <div class="ledger-cell"><input data-add-field="notes" placeholder="Notes"></div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="closing-card" aria-label="Closing balance">
            <span>Closing Balance</span>
            <strong data-closing-balance><?= ledger_money($closingBalance) ?></strong>
        </section>
        </div>
        </div>
    <?php endif; ?>
</main>
</div>
<?php if ($ready): ?>
<button class="bk-drawer-trigger" type="button" id="bkDrawerBtn" onclick="openDrawer()">Cash tools</button>
<div class="bk-overlay" id="bkOverlay" onclick="closeDrawer()"></div>
<aside class="bk-drawer cash-tools-panel" id="bkDrawer" aria-label="Cash ledger tools">
    <div class="bk-drawer-header">
        <div class="bk-drawer-title">Cash tools</div>
        <button class="bk-drawer-close" type="button" onclick="closeDrawer()" aria-label="Close cash tools">&times;</button>
    </div>
    <div class="bk-tabs" role="tablist" aria-label="Cash tools tabs">
        <button class="bk-tab is-active" type="button" data-tab="counter" onclick="switchTab(this, 'counter')">Count till</button>
        <button class="bk-tab" type="button" data-tab="recon" onclick="switchTab(this, 'recon')">Reconcile</button>
    </div>
    <div class="bk-drawer-body">
        <section class="bk-tab-panel is-active" id="tab-counter">
            <section class="bk-side-section denom-card denomination-counter">
                <div class="bk-side-head"><span>Denomination Counter</span><button class="bk-denom-reset" type="button" data-reset-denoms><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v6h6"></path><path d="M3 13a9 9 0 1 0 3-7.7L3 8"></path></svg><span>Reset</span></button></div>
                <div class="bk-side-body">
                    <div class="bk-denom-grid denom-grid denomination-counter-body" data-denom-counter>
                        <?php foreach ([200, 100, 60, 50, 30, 20, 10, 5, 1, 0.5, 0.1] as $denom): ?>
                            <label class="bk-denom-row denomination-row"><span>N$<?= rtrim(rtrim(number_format((float) $denom, 2), '0'), '.') ?></span><input type="number" min="0" step="1" value="0" data-denom="<?= htmlspecialchars((string) $denom, ENT_QUOTES, 'UTF-8') ?>"></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="bk-counter-total"><span>Counted total</span><strong id="denomTotal" data-counted-total>N$0.00</strong></div>
                </div>
            </section>
            <div class="bk-copy-total-row">
                <button class="bk-copy-total" id="copyTotalBtn" type="button" onclick="copyCountedTotal()">Copy counted total</button>
            </div>
        </section>
        <section class="bk-tab-panel" id="tab-recon">
            <section class="bk-side-section recon-card">
                <div class="bk-side-head"><span>Variance Reconciliation</span></div>
                <div class="bk-side-body">
                    <div class="bk-recon-line"><span>System balance</span><strong data-recon-system><?= ledger_money($closingBalance) ?></strong></div>
                    <div class="bk-recon-line"><span>Counted total</span><strong data-recon-counted>N$0.00</strong></div>
                    <div class="bk-recon-line"><span>Variance</span><strong class="bk-recon-variance" data-recon-variance><?= ledger_money(0 - $closingBalance) ?></strong></div>
                    <label class="bk-field">Variance note<textarea data-recon-note placeholder="Reason for variance"></textarea></label>
                    <button class="bk-side-button" type="button" data-save-recon>Save reconciliation</button>
                    <div class="bk-history-list" data-recon-history>
                        <?php foreach ($reconHistory as $row): ?>
                            <div class="bk-history-item">
                                <strong><?= htmlspecialchars((string) $row['recon_date'], ENT_QUOTES, 'UTF-8') ?> - <?= ledger_money((float) $row['variance']) ?></strong>
                                <small>Counted <?= ledger_money((float) $row['counted_total']) ?> vs system <?= ledger_money((float) $row['system_balance']) ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$reconHistory): ?><div class="bk-history-item">No reconciliations saved yet.</div><?php endif; ?>
                    </div>
                </div>
            </section>
        </section>
    </div>
</aside>
<?php endif; ?>
<script>
const todayKey = <?= json_encode($today, JSON_UNESCAPED_SLASHES) ?>;
let systemBalance = <?= json_encode(round($closingBalance, 2), JSON_UNESCAPED_SLASHES) ?>;

function money(value) {
  return `N$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function parseMoney(value) {
  return Number(String(value || '').replace(/[^0-9.-]+/g, '')) || 0;
}

function inputDate(value) {
  return String(value || '').replace(' ', 'T').slice(0, 16);
}

function displayDate(value) {
  const date = new Date(String(value || '').replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value || '';
  return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function toast(message) {
  const node = document.createElement('div');
  node.className = 'toast';
  node.textContent = message;
  document.body.appendChild(node);
  requestAnimationFrame(() => node.classList.add('is-visible'));
  setTimeout(() => {
    node.classList.remove('is-visible');
    setTimeout(() => node.remove(), 180);
  }, 1400);
}

function setReconValues() {
  if (!document.querySelector('[data-counted-total]')) return;
  const counted = Array.from(document.querySelectorAll('[data-denom]')).reduce((sum, input) => {
    return sum + (Number(input.dataset.denom || 0) * (Number(input.value || 0) || 0));
  }, 0);
  const variance = counted - systemBalance;
  document.querySelector('[data-counted-total]').textContent = money(counted);
  document.querySelector('[data-recon-system]').textContent = money(systemBalance);
  document.querySelector('[data-recon-counted]').textContent = money(counted);
  const varianceNode = document.querySelector('[data-recon-variance]');
  varianceNode.textContent = money(variance);
  varianceNode.classList.toggle('is-negative', variance < 0);
  varianceNode.classList.toggle('is-positive', variance > 0);
}

function copyCountedTotal() {
  const total = document.getElementById('denomTotal')?.textContent.trim() || money(0);
  const text = `Bank deposit \u2014 ${total}`;
  const btn = document.getElementById('copyTotalBtn');
  const markCopied = () => {
    if (!btn) return;
    btn.textContent = 'Copied';
    btn.style.background = '#F07420';
    setTimeout(() => {
      btn.textContent = 'Copy counted total';
      btn.style.background = '#AB3619';
    }, 2000);
  };
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(markCopied).catch(() => {});
  }
}

function renderReconHistory(rows) {
  const history = document.querySelector('[data-recon-history]');
  if (!history) return;
  if (!rows || !rows.length) {
    history.innerHTML = '<div class="bk-history-item">No reconciliations saved yet.</div>';
    return;
  }
  history.innerHTML = rows.map((row) => `
    <div class="bk-history-item">
      <strong>${row.recon_date} - ${money(row.variance)}</strong>
      <small>Counted ${money(row.counted_total)} vs system ${money(row.system_balance)}</small>
    </div>
  `).join('');
}

function openDrawer() {
  document.getElementById('bkDrawer')?.classList.add('is-open');
  document.getElementById('bkOverlay')?.classList.add('is-open');
}

function closeDrawer() {
  document.getElementById('bkDrawer')?.classList.remove('is-open');
  document.getElementById('bkOverlay')?.classList.remove('is-open');
}

function switchTab(button, tab) {
  document.querySelectorAll('.bk-tab').forEach((node) => node.classList.toggle('is-active', node === button));
  document.querySelectorAll('.bk-tab-panel').forEach((panel) => panel.classList.toggle('is-active', panel.id === `tab-${tab}`));
}

function applySidebarFilters() {
  const from = document.querySelector('[data-bk-filter-from]')?.value || '';
  const to = document.querySelector('[data-bk-filter-to]')?.value || '';
  const search = (document.querySelector('[data-bk-filter-search]')?.value || '').toLowerCase().trim();
  document.querySelectorAll('[data-day-group]').forEach((group) => {
    const day = group.dataset.dayGroup || '';
    let visibleRows = 0;
    group.querySelectorAll('.entry-row').forEach((row) => {
      const text = row.textContent.toLowerCase();
      const matchesDate = (!from || day >= from) && (!to || day <= to);
      const matchesSearch = !search || text.includes(search);
      const visible = matchesDate && matchesSearch;
      row.hidden = !visible;
      if (visible) visibleRows++;
    });
    const dateVisible = (!from || day >= from) && (!to || day <= to);
    group.hidden = !dateVisible || (search && visibleRows === 0);
  });
}

async function postLedger(action, fields = {}) {
  const form = new FormData();
  form.set('response', 'json');
  form.set('action', action);
  Object.entries(fields).forEach(([key, value]) => form.set(key, value));
  const response = await fetch('bookkeeping.php', {
    method: 'POST',
    body: form,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
  });
  const data = await response.json().catch(() => ({ ok: false, message: 'Server returned an unexpected response.' }));
  if (!response.ok || !data.ok) throw new Error(data.message || 'Ledger update failed.');
  return data;
}

function entryTotal(row) {
  return parseMoney(row.dataset.cashIn) - parseMoney(row.dataset.cashOut);
}

function recalcGroup(group) {
  let cashIn = 0;
  let cashOut = 0;
  group.querySelectorAll('.entry-row').forEach((row) => {
    cashIn += parseMoney(row.dataset.cashIn);
    cashOut += parseMoney(row.dataset.cashOut);
    const total = entryTotal(row);
    const totalCell = row.querySelector('[data-row-total]');
    if (totalCell) totalCell.textContent = money(total);
  });
  group.querySelector('[data-day-in]').textContent = money(cashIn);
  group.querySelector('[data-day-out]').textContent = money(cashOut);
  group.querySelector('[data-day-net]').textContent = money(cashIn - cashOut);
  const count = group.querySelectorAll('.entry-row').length;
  group.querySelector('[data-day-count]').textContent = `${count} ${count === 1 ? 'entry' : 'entries'}`;
  recalcStats();
}

function recalcStats() {
  let todayIn = 0;
  let todayOut = 0;
  let todayCount = 0;
  let closing = 0;
  document.querySelectorAll('[data-day-group]').forEach((group) => {
    const isToday = group.dataset.dayGroup === todayKey;
    group.querySelectorAll('.entry-row').forEach((row) => {
      const cashIn = parseMoney(row.dataset.cashIn);
      const cashOut = parseMoney(row.dataset.cashOut);
      closing += cashIn - cashOut;
      if (isToday) {
        todayIn += cashIn;
        todayOut += cashOut;
        todayCount++;
      }
    });
  });
  document.querySelector('[data-stat-cash-in]').textContent = money(todayIn);
  document.querySelector('[data-stat-cash-out]').textContent = money(todayOut);
  document.querySelector('[data-stat-net]').textContent = money(todayIn - todayOut);
  document.querySelector('[data-stat-count]').textContent = String(todayCount);
  document.querySelector('[data-closing-balance]').textContent = money(closing);
  systemBalance = closing;
  setReconValues();
}

function renderEntry(entry) {
  const row = document.createElement('div');
  row.className = 'ledger-row entry-row';
  row.dataset.entryId = entry.id;
  row.dataset.cashIn = String(entry.cash_in || 0);
  row.dataset.cashOut = String(entry.cash_out || 0);
  row.innerHTML = `
    <div class="ledger-cell check-cell"><span class="row-dot"></span></div>
    <div class="ledger-cell ledger-data-cell" data-field="description"></div>
    <div class="ledger-cell ledger-data-cell" data-field="transaction_date" data-value="${entry.date_input || inputDate(entry.date)}">${displayDate(entry.date)}</div>
    <div class="ledger-cell ledger-data-cell money-cell money-in" data-field="cash_in" data-value="${Number(entry.cash_in || 0)}">${Number(entry.cash_in || 0) > 0 ? money(entry.cash_in) : ''}</div>
    <div class="ledger-cell ledger-data-cell money-cell money-out" data-field="cash_out" data-value="${Number(entry.cash_out || 0)}">${Number(entry.cash_out || 0) > 0 ? money(entry.cash_out) : ''}</div>
    <div class="ledger-cell ledger-total money-net" data-row-total>${money(Number(entry.total || 0))}</div>
    <div class="ledger-cell ledger-data-cell" data-field="notes"></div>
  `;
  row.querySelector('[data-field="description"]').textContent = entry.description || '';
  row.querySelector('[data-field="description"]').dataset.value = entry.description || '';
  row.querySelector('[data-field="notes"]').textContent = entry.notes || '';
  row.querySelector('[data-field="notes"]').dataset.value = entry.notes || '';
  return row;
}

function addValue(row, field) {
  return row.querySelector(`[data-add-field="${field}"]`)?.value || '';
}

function clearAddRow(row) {
  ['description', 'cash_in', 'cash_out', 'notes'].forEach((field) => {
    const input = row.querySelector(`[data-add-field="${field}"]`);
    if (input) input.value = '';
  });
  row.querySelector('[data-add-total]').textContent = money(0);
}

function markInvalid(input) {
  input.classList.add('is-invalid');
  input.focus();
  setTimeout(() => input.classList.remove('is-invalid'), 800);
}

async function saveAddRow(row) {
  const description = addValue(row, 'description').trim();
  const cashIn = parseMoney(addValue(row, 'cash_in'));
  const cashOut = parseMoney(addValue(row, 'cash_out'));
  if (!description) return markInvalid(row.querySelector('[data-add-field="description"]'));
  if (!cashIn && !cashOut) return markInvalid(row.querySelector('[data-add-field="cash_in"]'));
  try {
    const data = await postLedger('add_entry', {
      description,
      transaction_date: addValue(row, 'transaction_date'),
      cash_in: cashIn,
      cash_out: cashOut,
      notes: addValue(row, 'notes').trim()
    });
    const entry = data.entry;
    if (entry.day !== row.dataset.day) {
      window.location.reload();
      return;
    }
    row.before(renderEntry(entry));
    clearAddRow(row);
    recalcGroup(row.closest('[data-day-group]'));
    toast('Entry saved');
    setTimeout(() => row.querySelector('[data-add-field="description"]')?.focus(), 50);
  } catch (error) {
    alert(error.message);
  }
}

function startEdit(cell) {
  if (!cell || cell.classList.contains('is-editing')) return;
  const row = cell.closest('.entry-row');
  const field = cell.dataset.field;
  const id = row?.dataset.entryId;
  if (!row || !field || !id) return;
  const originalText = cell.textContent.trim();
  const originalValue = cell.dataset.value ?? originalText;
  const input = field === 'notes' ? document.createElement('textarea') : document.createElement('input');
  input.type = field === 'transaction_date' ? 'datetime-local' : (['cash_in', 'cash_out'].includes(field) ? 'number' : 'text');
  if (input.type === 'number') {
    input.min = '0';
    input.step = '0.01';
  }
  input.value = field === 'transaction_date' ? inputDate(originalValue) : originalValue;
  cell.classList.add('is-editing');
  cell.innerHTML = '';
  cell.appendChild(input);
  input.focus();
  if (input.select) input.select();
  let cancelled = false;
  const finish = async (save) => {
    if (!cell.classList.contains('is-editing')) return;
    const value = input.value.trim();
    cell.classList.remove('is-editing');
    cell.textContent = save && !cancelled ? value : originalText;
    if (!save || cancelled || value === String(originalValue)) return;
    try {
      const data = await postLedger('update_entry', { entry_id: id, field, value });
      const entry = data.entry;
      if (entry.day !== row.closest('[data-day-group]')?.dataset.dayGroup) {
        window.location.reload();
        return;
      }
      if (field === 'description') {
        cell.textContent = entry.description;
        cell.dataset.value = entry.description;
      }
      if (field === 'notes') {
        cell.textContent = entry.notes;
        cell.dataset.value = entry.notes;
      }
      if (field === 'transaction_date') {
        cell.textContent = displayDate(entry.date);
        cell.dataset.value = entry.date_input;
      }
      if (field === 'cash_in') {
        row.dataset.cashIn = String(entry.cash_in);
        cell.textContent = entry.cash_in > 0 ? money(entry.cash_in) : '';
        cell.dataset.value = String(entry.cash_in);
      }
      if (field === 'cash_out') {
        row.dataset.cashOut = String(entry.cash_out);
        cell.textContent = entry.cash_out > 0 ? money(entry.cash_out) : '';
        cell.dataset.value = String(entry.cash_out);
      }
      recalcGroup(row.closest('[data-day-group]'));
      toast('Saved');
    } catch (error) {
      cell.textContent = originalText;
      cell.dataset.value = originalValue;
      alert(error.message);
    }
  };
  input.addEventListener('blur', () => finish(true), { once: true });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      cancelled = true;
      event.preventDefault();
      finish(false);
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      finish(true);
    }
  });
}

document.addEventListener('click', (event) => {
  const back = event.target.closest('[data-ledger-back]');
  const toggle = event.target.closest('[data-toggle-day]');
  const save = event.target.closest('[data-save-add]');
  const editable = event.target.closest('.ledger-data-cell');
  const clearFilters = event.target.closest('[data-bk-filter-clear]');
  const saveRecon = event.target.closest('[data-save-recon]');
  const resetDenoms = event.target.closest('[data-reset-denoms]');
  if (back) {
    if (window.history.length > 1) window.history.back();
    else window.location.href = '<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/dashboard.php';
    return;
  }
  if (resetDenoms) {
    resetDenoms.classList.remove('is-resetting');
    void resetDenoms.offsetWidth;
    resetDenoms.classList.add('is-resetting');
    document.querySelectorAll('[data-denom]').forEach((input) => {
      input.value = '0';
    });
    setReconValues();
    setTimeout(() => resetDenoms.classList.remove('is-resetting'), 500);
    return;
  }
  if (clearFilters) {
    document.querySelector('[data-bk-filter-from]').value = '';
    document.querySelector('[data-bk-filter-to]').value = '';
    document.querySelector('[data-bk-filter-search]').value = '';
    applySidebarFilters();
    return;
  }
  if (saveRecon) {
    const countedTotal = parseMoney(document.querySelector('[data-counted-total]').textContent);
    postLedger('cashbook_save_recon', {
      recon_date: todayKey,
      system_balance: systemBalance,
      counted_total: countedTotal,
      variance_note: document.querySelector('[data-recon-note]')?.value || ''
    }).then((data) => {
      renderReconHistory(data.history || []);
      document.querySelector('[data-recon-note]').value = '';
      toast('Reconciliation saved');
    }).catch((error) => alert(error.message));
    return;
  }
  if (toggle) {
    const group = toggle.closest('[data-day-group]');
    group.classList.toggle('is-collapsed');
    toggle.textContent = group.classList.contains('is-collapsed') ? '>' : 'v';
    return;
  }
  if (save) {
    saveAddRow(save.closest('[data-add-row]'));
    return;
  }
  if (editable) startEdit(editable);
});

document.addEventListener('input', (event) => {
  const row = event.target.closest('[data-add-row]');
  if (event.target.matches('[data-denom]')) {
    setReconValues();
    return;
  }
  if (event.target.matches('[data-bk-filter-from], [data-bk-filter-to], [data-bk-filter-search]')) {
    applySidebarFilters();
    return;
  }
  if (!row || !event.target.matches('[data-add-field="cash_in"], [data-add-field="cash_out"]')) return;
  row.querySelector('[data-add-total]').textContent = money(parseMoney(addValue(row, 'cash_in')) - parseMoney(addValue(row, 'cash_out')));
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    closeDrawer();
  }
  const row = event.target.closest('[data-add-row]');
  if (!row || event.key !== 'Enter') return;
  event.preventDefault();
  saveAddRow(row);
});

setReconValues();
</script>
</body>
</html>
