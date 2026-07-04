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
        .ledger-home {
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 999px;
            background: var(--ledger-white);
            color: var(--ledger-rust);
            text-decoration: none;
            padding: 10px 15px;
            font-weight: 700;
            white-space: nowrap;
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
        .save-add {
            width: 28px;
            height: 28px;
            border: 0;
            border-radius: 999px;
            background: var(--ledger-orange);
            color: var(--ledger-white);
            font-weight: 900;
            cursor: pointer;
        }
        .save-add:hover {
            background: var(--ledger-rust);
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
        .toast {
            position: fixed;
            right: 22px;
            bottom: 22px;
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
            .ledger-page { padding: 18px; }
            .ledger-top { flex-direction: column; }
            .stat-grid { grid-template-columns: 1fr; }
            .ledger-board-inner { min-width: 980px; }
            .closing-card { align-items: flex-start; flex-direction: column; }
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
<main class="ledger-page">
    <header class="ledger-top">
        <div>
            <h1>Cash Ledger</h1>
            <p class="ledger-subtitle">Daily cash in, cash out, net movement, and closing balance.</p>
        </div>
        <a class="ledger-home" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/dashboard.php">Operations</a>
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
                            <div class="ledger-cell check-cell"><button class="save-add" type="button" data-save-add aria-label="Save entry">+</button></div>
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
    <?php endif; ?>
</main>
</div>
<script>
const todayKey = <?= json_encode($today, JSON_UNESCAPED_SLASHES) ?>;

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
  if (back) {
    if (window.history.length > 1) window.history.back();
    else window.location.href = '<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/dashboard.php';
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
  if (!row || !event.target.matches('[data-add-field="cash_in"], [data-add-field="cash_out"]')) return;
  row.querySelector('[data-add-total]').textContent = money(parseMoney(addValue(row, 'cash_in')) - parseMoney(addValue(row, 'cash_out')));
});

document.addEventListener('keydown', (event) => {
  const row = event.target.closest('[data-add-row]');
  if (!row || event.key !== 'Enter') return;
  event.preventDefault();
  saveAddRow(row);
});
</script>
</body>
</html>
