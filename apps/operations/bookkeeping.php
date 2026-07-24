<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Hambelela Bookkeeping | ' . APP_NAME;
$activeApp = !empty($_GET['cash_tools']) ? 'operations-cash-tools' : 'operations-bookkeeping';
$ready = ops_database_ready();
$employeeId = ops_current_employee_id();
$currentUser = current_user();
$bookkeepingRoleKey = normalise_portal_role((string) ($currentUser['role_key'] ?? current_role_key()));
$canOperateBookkeeping = $bookkeepingRoleKey !== 'guest'
    && portal_role_can_access_feature($bookkeepingRoleKey, 'bookkeeping');
$canManageBookkeeping = $bookkeepingRoleKey === 'owner_admin';
$isBookkeepingReadOnly = !$canOperateBookkeeping;
$canSelectBookkeepingRows = $canOperateBookkeeping;
$ledgerUserId = (int) ($currentUser['id'] ?? $employeeId ?? 0);
$ledgerUserName = (string) ($currentUser['name'] ?? 'Unknown user');
$bookkeepingCsrfToken = (string) ($_SESSION['bookkeeping_csrf_token'] ?? '');
if ($bookkeepingCsrfToken === '') {
    $bookkeepingCsrfToken = bin2hex(random_bytes(32));
    $_SESSION['bookkeeping_csrf_token'] = $bookkeepingCsrfToken;
}
$message = null;
$messageType = 'success';

final class LedgerPermissionException extends RuntimeException
{
}

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

function ledger_custom_column_key(): string
{
    return 'col_' . bin2hex(random_bytes(5));
}

function ledger_decode_json($value, array $fallback = []): array
{
    $decoded = json_decode((string) ($value ?? ''), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function ledger_custom_columns(bool $includeDeleted = false): array
{
    if (!ops_database_ready()) return [];
    $where = $includeDeleted ? '1 = 1' : 'deleted_at IS NULL';
    $rows = ops_rows(
        "SELECT *
         FROM hambelela_cashbook_columns
         WHERE {$where}
         ORDER BY sort_order ASC, id ASC"
    );
    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'column_key' => (string) $row['column_key'],
            'name' => (string) $row['name'],
            'type' => (string) $row['column_type'],
            'options' => ledger_decode_json($row['options_json'] ?? '[]'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }, $rows);
}

function ledger_normalize_custom_options(array $rawOptions): array
{
    $options = [];
    foreach ($rawOptions as $option) {
        if (!is_array($option)) continue;
        $label = trim((string) ($option['label'] ?? ''));
        if ($label === '') continue;
        $colour = trim((string) ($option['colour'] ?? '#F07420'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) $colour = '#F07420';
        $options[] = [
            'value' => substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), 0, 40) ?: ledger_custom_column_key(),
            'label' => substr($label, 0, 60),
            'colour' => $colour,
        ];
    }
    return $options;
}

function ledger_transaction_type(float $cashIn, float $cashOut): string
{
    if ($cashOut > 0 && $cashOut >= $cashIn) return 'cash_taken_out';
    return 'cash_received';
}

function ledger_is_opening_balance(array $entry): bool
{
    return (string) ($entry['transaction_type'] ?? '') === 'opening_balance'
        || (string) ($entry['source'] ?? '') === 'opening_balance';
}

function ledger_calculate_daily_balance(array $entries): array
{
    $openingCents = 0;
    $cashInCents = 0;
    $cashOutCents = 0;
    foreach ($entries as $entry) {
        $inCents = (int) round(abs((float) ($entry['cash_in'] ?? 0)) * 100);
        $outCents = (int) round(abs((float) ($entry['cash_out'] ?? 0)) * 100);
        if (ledger_is_opening_balance($entry)) {
            $openingCents += $inCents - $outCents;
            continue;
        }
        $cashInCents += $inCents;
        $cashOutCents += $outCents;
    }
    return [
        'opening_balance' => $openingCents / 100,
        'cash_in' => $cashInCents / 100,
        'cash_out' => $cashOutCents / 100,
        'closing_balance' => ($openingCents + $cashInCents - $cashOutCents) / 100,
    ];
}

function ledger_active_where(): string
{
    return "archived_at IS NULL AND COALESCE(status, 'active') = 'active'";
}

function ledger_bulk_ids(): array
{
    $raw = (string) ($_POST['ids'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0)));
    if (!$ids) throw new RuntimeException('Select at least one ledger entry.');
    return $ids;
}

function ledger_bulk_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function ledger_required_reason(string $message): string
{
    $reason = ops_post_string('reason', 500);
    if ($reason === '') throw new RuntimeException($message);
    return $reason;
}

function ledger_kpi_status_event(int $entryId, ?string $oldStatus, string $newStatus, ?int $actorId): void
{
    if ($entryId <= 0 || $newStatus === '' || $oldStatus === $newStatus) return;
    try {
        db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['bookkeeping', $entryId, $oldStatus, $newStatus, $actorId ?: null]);
    } catch (Throwable $kpiError) {
        error_log(date(DATE_ATOM) . ' bookkeeping status: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
    }
}

function cashbook_log(
    int $userId,
    string $userName,
    string $action,
    ?int $entryId = null,
    ?string $field = null,
    ?string $oldValue = null,
    ?string $newValue = null,
    ?string $description = null
): void {
    if (!ops_database_ready()) return;
    if ($description !== null) $description = substr($description, 0, 255);
    $sessionReference = substr(hash('sha256', session_id()), 0, 20);
    $deviceReference = substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-device')), 0, 20);
    $stmt = db()->prepare(
        "INSERT INTO hambelela_cashbook_log
         (entry_id, action, field, old_value, new_value, description, user_id, user_name, session_reference, device_reference, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );
    $stmt->execute([$entryId, $action, $field, $oldValue, $newValue, $description, $userId, $userName, $sessionReference, $deviceReference]);
    $stmt->closeCursor();
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
            created_by_user_id INT NULL,
            created_by_name VARCHAR(190) NULL,
            updated_by_user_id INT NULL,
            archived_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    if (!ops_column_exists('ops_cash_book_entries', 'status')) {
        db()->exec("ALTER TABLE ops_cash_book_entries ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
    }
    if (!ops_column_exists('ops_cash_book_entries', 'deleted_at')) {
        db()->exec("ALTER TABLE ops_cash_book_entries ADD COLUMN deleted_at DATETIME NULL");
    }
    if (!ops_column_exists('ops_cash_book_entries', 'custom_fields_json')) {
        db()->exec("ALTER TABLE ops_cash_book_entries ADD COLUMN custom_fields_json JSON NULL");
    }
    if (!ops_column_exists('ops_cash_book_entries', 'created_by_user_id')) {
        db()->exec("ALTER TABLE ops_cash_book_entries ADD COLUMN created_by_user_id INT NULL AFTER recorded_by");
    }
    if (!ops_column_exists('ops_cash_book_entries', 'created_by_name')) {
        db()->exec("ALTER TABLE ops_cash_book_entries ADD COLUMN created_by_name VARCHAR(190) NULL AFTER created_by_user_id");
    }
    if (!ops_column_exists('ops_cash_book_entries', 'updated_by_user_id')) {
        db()->exec("ALTER TABLE ops_cash_book_entries ADD COLUMN updated_by_user_id INT NULL AFTER edited_by");
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS hambelela_cashbook_columns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            column_key VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            column_type VARCHAR(20) NOT NULL,
            options_json JSON NULL,
            sort_order INT NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
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
    db()->exec(
        "CREATE TABLE IF NOT EXISTS hambelela_cashbook_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            field VARCHAR(50) DEFAULT NULL,
            old_value TEXT DEFAULT NULL,
            new_value TEXT DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            user_id INT NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            session_reference VARCHAR(40) NULL,
            device_reference VARCHAR(40) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!ops_column_exists('hambelela_cashbook_log', 'session_reference')) {
        db()->exec("ALTER TABLE hambelela_cashbook_log ADD COLUMN session_reference VARCHAR(40) NULL AFTER user_name");
    }
    if (!ops_column_exists('hambelela_cashbook_log', 'device_reference')) {
        db()->exec("ALTER TABLE hambelela_cashbook_log ADD COLUMN device_reference VARCHAR(40) NULL AFTER session_reference");
    }
}

function ledger_entry(int $id): array
{
    $rows = ops_rows('SELECT * FROM ops_cash_book_entries WHERE id = ? AND ' . ledger_active_where() . ' LIMIT 1', [$id]);
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
        'transaction_type' => (string) ($row['transaction_type'] ?? ''),
        'source' => (string) ($row['source'] ?? ''),
        'created_by_user_id' => (int) ($row['created_by_user_id'] ?? $row['recorded_by'] ?? 0),
        'created_by_name' => (string) ($row['created_by_name'] ?? ''),
        'custom_fields' => ledger_decode_json($row['custom_fields_json'] ?? '{}'),
    ];
}

function ledger_display_datetime(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('d/m/Y h:i A', $timestamp);
}

function ledger_custom_cell_html(array $column, array $customFields): string
{
    $key = (string) $column['column_key'];
    $value = (string) ($customFields[$key] ?? '');
    if ($value === '') return '';
    if (in_array($column['type'], ['status', 'dropdown', 'people'], true)) {
        foreach (($column['options'] ?? []) as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                $label = htmlspecialchars((string) ($option['label'] ?? $value), ENT_QUOTES, 'UTF-8');
                $colour = htmlspecialchars((string) ($option['colour'] ?? '#AB3619'), ENT_QUOTES, 'UTF-8');
                if ($column['type'] === 'people') {
                    $initial = htmlspecialchars(strtoupper(substr((string) ($option['label'] ?? $value), 0, 1)), ENT_QUOTES, 'UTF-8');
                    return '<span class="custom-person-pill" style="--pill-colour:' . $colour . '"><span>' . $initial . '</span>' . $label . '</span>';
                }
                return '<span class="custom-status-pill" style="--pill-colour:' . $colour . '">' . $label . '</span>';
            }
        }
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($ready) {
    ledger_bootstrap_schema();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedCsrfToken = (string) ($_POST['csrf_token'] ?? '');
        if ($submittedCsrfToken === '' || !hash_equals($bookkeepingCsrfToken, $submittedCsrfToken)) {
            throw new LedgerPermissionException('Your session token is invalid. Refresh Bookkeeping and try again.');
        }
        $action = ops_post_string('action', 40);
        if (!$canOperateBookkeeping) {
            throw new LedgerPermissionException('Your Bookkeeping access is read-only.');
        }
        $ownerOnlyActions = [
            'cashbook_add_custom_column',
            'cashbook_rename_custom_column',
            'cashbook_delete_custom_column',
            'cashbook_permanent_delete',
        ];
        if (in_array($action, $ownerOnlyActions, true) && !$canManageBookkeeping) {
            throw new LedgerPermissionException('Only the Owner/Admin can perform this Bookkeeping action.');
        }
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
                 (transaction_date, transaction_type, description, cash_in, cash_out, source, notes, recorded_by, created_by_user_id, created_by_name)
                 VALUES (?, ?, ?, ?, ?, 'manual_cash_entry', ?, ?, ?, ?)"
            );
            $stmt->execute([$date, $type, $description, $cashIn, $cashOut, ops_post_string('notes', 1500), $employeeId, $ledgerUserId, $ledgerUserName]);
            $id = (int) db()->lastInsertId();
            cashbook_log(
                $ledgerUserId,
                $ledgerUserName,
                'created',
                $id,
                null,
                null,
                null,
                $description . ' - ' . ledger_money($cashIn) . ' in / ' . ledger_money($cashOut) . ' out'
            );
            ledger_json(['ok' => true, 'message' => 'Entry saved.', 'entry' => ledger_entry($id)]);
        }
        if ($action === 'save_opening_balance') {
            $today = date('Y-m-d');
            $existing = ops_rows(
                "SELECT id
                 FROM ops_cash_book_entries
                 WHERE " . ledger_active_where() . "
                   AND DATE(transaction_date) = ?
                   AND (
                       source = 'opening_balance'
                       OR transaction_type = 'opening_balance'
                   )
                 LIMIT 1",
                [$today]
            );
            if ($existing) throw new RuntimeException('Opening balance is already saved for today.');
            $cashIn = ledger_number($_POST['cash_in'] ?? 0);
            if ($cashIn <= 0) throw new RuntimeException('Enter the opening cash amount.');
            $stmt = db()->prepare(
                "INSERT INTO ops_cash_book_entries
                 (transaction_date, transaction_type, description, cash_in, cash_out, source, notes, recorded_by, created_by_user_id, created_by_name)
                 VALUES (?, 'opening_balance', 'Opening balance', ?, 0, 'opening_balance', ?, ?, ?, ?)"
            );
            $stmt->execute([
                date('Y-m-d H:i:s'),
                $cashIn,
                ops_post_string('notes', 1500),
                $employeeId,
                $ledgerUserId,
                $ledgerUserName,
            ]);
            $id = (int) db()->lastInsertId();
            cashbook_log(
                $ledgerUserId,
                $ledgerUserName,
                'opening_balance',
                $id,
                null,
                null,
                null,
                'Opening balance saved - ' . ledger_money($cashIn)
            );
            ledger_json(['ok' => true, 'message' => 'Opening balance saved.', 'entry' => ledger_entry($id)]);
        }
        if ($action === 'update_entry' || $action === 'cashbook_edit_entry') {
            $id = (int) ($_POST['entry_id'] ?? 0);
            $field = ops_post_string('field', 40);
            if ($field === 'entry_dt') $field = 'transaction_date';
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
            $oldStmt = db()->prepare('SELECT ' . $allowed[$field] . ', transaction_date, transaction_type, source FROM ops_cash_book_entries WHERE id = ? AND ' . ledger_active_where() . ' LIMIT 1');
            $oldStmt->execute([$id]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $oldValue = (string) ($oldRow[$allowed[$field]] ?? '');
            $oldStmt->closeCursor();
            $entryDate = substr((string) ($oldRow['transaction_date'] ?? ''), 0, 10);
            $isOpeningEntry = ledger_is_opening_balance($oldRow);
            if ($field === 'transaction_date' && $isOpeningEntry) {
                $newDay = substr((string) $value, 0, 10);
                $duplicateOpening = ops_rows(
                    "SELECT id FROM ops_cash_book_entries
                     WHERE id <> ? AND " . ledger_active_where() . " AND DATE(transaction_date) = ?
                       AND (source = 'opening_balance' OR transaction_type = 'opening_balance')
                     LIMIT 1",
                    [$id, $newDay]
                );
                if ($duplicateOpening) throw new RuntimeException('That date already has an opening balance.');
            }
            $isReconciled = $entryDate !== '' && (bool) ops_rows('SELECT id FROM hambelela_cashbook_recon WHERE recon_date = ? LIMIT 1', [$entryDate]);
            $editReason = ops_post_string('reason', 500);
            if (($field === 'transaction_date' || $isReconciled) && $editReason === '') {
                throw new RuntimeException($field === 'transaction_date' ? 'A reason is required to change an entry date.' : 'A reason is required to edit an entry from a reconciled date.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare('UPDATE ops_cash_book_entries SET ' . $allowed[$field] . ' = ?, edited_by = ?, updated_by_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND ' . ledger_active_where());
            $stmt->execute([$value, $employeeId, $ledgerUserId, $id]);
            cashbook_log($ledgerUserId, $ledgerUserName, 'edited', $id, $field, $oldValue, $value, $editReason !== '' ? 'Reason: ' . $editReason : null);
            $entry = ledger_entry($id);
            $type = $isOpeningEntry ? 'opening_balance' : ledger_transaction_type($entry['cash_in'], $entry['cash_out']);
            db()->prepare('UPDATE ops_cash_book_entries SET transaction_type = ? WHERE id = ?')->execute([$type, $id]);
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Saved.', 'entry' => ledger_entry($id)]);
        }
        if ($action === 'cashbook_add_custom_column') {
            $type = ops_post_string('column_type', 20);
            $allowedTypes = ['status', 'dropdown', 'text', 'date', 'people', 'numbers'];
            if (!in_array($type, $allowedTypes, true)) throw new RuntimeException('Choose a valid column type.');
            $name = ops_post_string('name', 80);
            if ($name === '') throw new RuntimeException('Column name is required.');
            $options = [];
            if (in_array($type, ['status', 'dropdown'], true)) {
                $options = ledger_normalize_custom_options(ledger_decode_json($_POST['options_json'] ?? '[]'));
                if (!$options) throw new RuntimeException('Add at least one option.');
            }
            if ($type === 'people') {
                $options = [
                    ['value' => 'victoria', 'label' => 'Victoria', 'colour' => '#721B1A'],
                    ['value' => 'cecilia', 'label' => 'Cecilia', 'colour' => '#F07420'],
                    ['value' => 'klaudia', 'label' => 'Klaudia', 'colour' => '#A8CA19'],
                    ['value' => 'ndinelao', 'label' => 'Ndinelao', 'colour' => '#AB3619'],
                ];
            }
            $sortOrder = (int) (ops_rows("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM hambelela_cashbook_columns")[0]['next_order'] ?? 1);
            $key = ledger_custom_column_key();
            $stmt = db()->prepare(
                "INSERT INTO hambelela_cashbook_columns
                 (column_key, name, column_type, options_json, sort_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$key, $name, $type, json_encode($options, JSON_UNESCAPED_SLASHES), $sortOrder, $employeeId ?: null]);
            cashbook_log($ledgerUserId, $ledgerUserName, 'edited', null, 'custom_column', null, $name, 'Added custom column');
            ledger_json(['ok' => true, 'message' => 'Column added.', 'columns' => ledger_custom_columns()]);
        }
        if ($action === 'cashbook_rename_custom_column') {
            $key = ops_post_string('column_key', 40);
            $name = ops_post_string('name', 80);
            if ($key === '' || $name === '') throw new RuntimeException('Column name is required.');
            $old = ops_rows('SELECT name FROM hambelela_cashbook_columns WHERE column_key = ? AND deleted_at IS NULL LIMIT 1', [$key]);
            if (!$old) throw new RuntimeException('Custom column not found.');
            db()->prepare('UPDATE hambelela_cashbook_columns SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE column_key = ? AND deleted_at IS NULL')->execute([$name, $key]);
            cashbook_log($ledgerUserId, $ledgerUserName, 'edited', null, 'custom_column', (string) $old[0]['name'], $name, 'Renamed custom column');
            ledger_json(['ok' => true, 'message' => 'Column renamed.', 'columns' => ledger_custom_columns()]);
        }
        if ($action === 'cashbook_delete_custom_column') {
            $key = ops_post_string('column_key', 40);
            $old = ops_rows('SELECT name FROM hambelela_cashbook_columns WHERE column_key = ? AND deleted_at IS NULL LIMIT 1', [$key]);
            if (!$old) throw new RuntimeException('Custom column not found.');
            db()->prepare('UPDATE hambelela_cashbook_columns SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE column_key = ? AND deleted_at IS NULL')->execute([$key]);
            cashbook_log($ledgerUserId, $ledgerUserName, 'deleted', null, 'custom_column', (string) $old[0]['name'], null, 'Deleted custom column; values retained');
            ledger_json(['ok' => true, 'message' => 'Column hidden. Data is retained.', 'columns' => ledger_custom_columns()]);
        }
        if ($action === 'cashbook_update_custom_value') {
            $id = (int) ($_POST['entry_id'] ?? 0);
            $key = ops_post_string('column_key', 40);
            if ($id <= 0 || $key === '') throw new RuntimeException('Invalid custom value.');
            $columnRows = ops_rows('SELECT column_type FROM hambelela_cashbook_columns WHERE column_key = ? AND deleted_at IS NULL LIMIT 1', [$key]);
            if (!$columnRows) throw new RuntimeException('Custom column not found.');
            $value = trim((string) ($_POST['value'] ?? ''));
            if ((string) $columnRows[0]['column_type'] === 'numbers') $value = $value === '' ? '' : (string) ((float) preg_replace('/[^0-9.\-]+/', '', $value));
            $rows = ops_rows('SELECT custom_fields_json, transaction_date FROM ops_cash_book_entries WHERE id = ? AND ' . ledger_active_where() . ' LIMIT 1', [$id]);
            if (!$rows) throw new RuntimeException('Ledger entry not found.');
            $customEntryDate = substr((string) ($rows[0]['transaction_date'] ?? ''), 0, 10);
            $customIsReconciled = $customEntryDate !== '' && (bool) ops_rows('SELECT id FROM hambelela_cashbook_recon WHERE recon_date = ? LIMIT 1', [$customEntryDate]);
            $customReason = ops_post_string('reason', 500);
            if ($customIsReconciled && $customReason === '') throw new RuntimeException('A reason is required to edit an entry from a reconciled date.');
            $custom = ledger_decode_json($rows[0]['custom_fields_json'] ?? '{}');
            $oldValue = (string) ($custom[$key] ?? '');
            if ($value === '') unset($custom[$key]);
            else $custom[$key] = $value;
            db()->beginTransaction();
            db()->prepare('UPDATE ops_cash_book_entries SET custom_fields_json = ?, edited_by = ?, updated_by_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND ' . ledger_active_where())->execute([
                json_encode($custom, JSON_UNESCAPED_SLASHES),
                $employeeId,
                $ledgerUserId,
                $id,
            ]);
            cashbook_log($ledgerUserId, $ledgerUserName, 'edited', $id, $key, $oldValue, $value, $customReason !== '' ? 'Reason: ' . $customReason : null);
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Saved.', 'custom_fields' => $custom]);
        }
        if ($action === 'cashbook_soft_delete') {
            $ids = ledger_bulk_ids();
            $reason = ledger_required_reason('A reason is required to move entries to Trash.');
            $placeholders = ledger_bulk_placeholders($ids);
            $beforeRows = ops_rows("SELECT id, status FROM ops_cash_book_entries WHERE id IN ({$placeholders}) AND " . ledger_active_where(), $ids);
            db()->beginTransaction();
            $stmt = db()->prepare("UPDATE ops_cash_book_entries SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            foreach ($beforeRows as $row) {
                cashbook_log($ledgerUserId, $ledgerUserName, 'deleted', (int) $row['id'], 'status', (string) ($row['status'] ?? 'active'), 'deleted', 'Moved to Trash. Reason: ' . $reason);
                ledger_kpi_status_event((int) $row['id'], (string) ($row['status'] ?? 'active'), 'deleted', $employeeId);
            }
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Moved to trash.']);
        }
        if ($action === 'cashbook_archive') {
            $ids = ledger_bulk_ids();
            $placeholders = ledger_bulk_placeholders($ids);
            $beforeRows = ops_rows("SELECT id, status FROM ops_cash_book_entries WHERE id IN ({$placeholders}) AND " . ledger_active_where(), $ids);
            db()->beginTransaction();
            $stmt = db()->prepare("UPDATE ops_cash_book_entries SET status = 'archived', deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            foreach ($beforeRows as $row) {
                cashbook_log($ledgerUserId, $ledgerUserName, 'archived', (int) $row['id'], 'status', (string) ($row['status'] ?? 'active'), 'archived', 'Archived from active Bookkeeping');
                ledger_kpi_status_event((int) $row['id'], (string) ($row['status'] ?? 'active'), 'archived', $employeeId);
            }
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Archived.']);
        }
        if ($action === 'cashbook_move_date') {
            $ids = ledger_bulk_ids();
            $reason = ledger_required_reason('A reason is required to change an entry date.');
            $newDate = ops_post_string('new_date', 20);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) throw new RuntimeException('Use date format YYYY-MM-DD.');
            $placeholders = ledger_bulk_placeholders($ids);
            $movingRows = ops_rows(
                "SELECT id, transaction_type, source FROM ops_cash_book_entries
                 WHERE id IN ({$placeholders}) AND " . ledger_active_where(),
                $ids
            );
            $movingOpeningIds = array_values(array_map(
                static fn (array $row): int => (int) $row['id'],
                array_filter($movingRows, 'ledger_is_opening_balance')
            ));
            if (count($movingOpeningIds) > 1) throw new RuntimeException('Only one opening balance can exist on a date. Move opening balances separately.');
            if ($movingOpeningIds) {
                $duplicateOpening = ops_rows(
                    "SELECT id FROM ops_cash_book_entries
                     WHERE id NOT IN ({$placeholders}) AND " . ledger_active_where() . " AND DATE(transaction_date) = ?
                       AND (source = 'opening_balance' OR transaction_type = 'opening_balance')
                     LIMIT 1",
                    [...$ids, $newDate]
                );
                if ($duplicateOpening) throw new RuntimeException('That date already has an opening balance.');
            }
            $select = db()->prepare('SELECT transaction_date FROM ops_cash_book_entries WHERE id = ? AND ' . ledger_active_where() . ' LIMIT 1');
            $update = db()->prepare('UPDATE ops_cash_book_entries SET transaction_date = ?, edited_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND ' . ledger_active_where());
            db()->beginTransaction();
            foreach ($ids as $id) {
                $select->execute([$id]);
                $current = (string) $select->fetchColumn();
                $select->closeCursor();
                if ($current === '') continue;
                $time = date('H:i:s', strtotime($current));
                $newDateTime = $newDate . ' ' . $time;
                $update->execute([$newDateTime, $employeeId, $id]);
                cashbook_log($ledgerUserId, $ledgerUserName, 'moved', $id, 'transaction_date', $current, $newDateTime, 'Reason: ' . $reason);
            }
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Moved to date.']);
        }
        if ($action === 'cashbook_restore') {
            $ids = ledger_bulk_ids();
            $placeholders = ledger_bulk_placeholders($ids);
            $beforeRows = ops_rows("SELECT id, status FROM ops_cash_book_entries WHERE id IN ({$placeholders}) AND COALESCE(status, 'active') IN ('deleted', 'archived')", $ids);
            db()->beginTransaction();
            $stmt = db()->prepare("UPDATE ops_cash_book_entries SET status = 'active', deleted_at = NULL, archived_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            foreach ($beforeRows as $row) {
                cashbook_log($ledgerUserId, $ledgerUserName, 'restored', (int) $row['id'], 'status', (string) ($row['status'] ?? ''), 'active', 'Restored to active Bookkeeping');
                ledger_kpi_status_event((int) $row['id'], (string) ($row['status'] ?? ''), 'active', $employeeId);
            }
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Restored.']);
        }
        if ($action === 'cashbook_permanent_delete') {
            if (!user_has_role('owner_admin')) throw new LedgerPermissionException('Only the owner/admin can permanently delete ledger entries.');
            $ids = ledger_bulk_ids();
            $placeholders = ledger_bulk_placeholders($ids);
            $beforeRows = ops_rows("SELECT * FROM ops_cash_book_entries WHERE id IN ({$placeholders}) AND COALESCE(status, 'active') IN ('deleted', 'archived')", $ids);
            db()->beginTransaction();
            foreach ($beforeRows as $row) {
                cashbook_log($ledgerUserId, $ledgerUserName, 'permanently_deleted', (int) $row['id'], 'record', json_encode($row, JSON_UNESCAPED_SLASHES), null, 'Owner/Admin permanent deletion');
            }
            $stmt = db()->prepare("DELETE FROM ops_cash_book_entries WHERE id IN ({$placeholders}) AND COALESCE(status, 'active') IN ('deleted', 'archived')");
            $stmt->execute($ids);
            db()->commit();
            ledger_json(['ok' => true, 'message' => 'Permanently deleted.']);
        }
        if ($action === 'cashbook_save_recon') {
            $reconDate = ops_post_string('recon_date', 20);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconDate)) $reconDate = date('Y-m-d');
            $priorRecon = ops_rows('SELECT id, system_balance, counted_total, variance, variance_note FROM hambelela_cashbook_recon WHERE recon_date = ? ORDER BY id DESC LIMIT 1', [$reconDate]);
            $reconReason = ops_post_string('reason', 500);
            if ($priorRecon && $reconReason === '') throw new RuntimeException('A reason is required to reopen or change a completed reconciliation.');
            $systemBalance = (float) ($_POST['system_balance'] ?? 0);
            $countedTotal = (float) ($_POST['counted_total'] ?? 0);
            $variance = $countedTotal - $systemBalance;
            db()->beginTransaction();
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
            $reconciliationId = (int) db()->lastInsertId();
            cashbook_log(
                $ledgerUserId,
                $ledgerUserName,
                'reconciled',
                null,
                'reconciliation',
                $priorRecon ? json_encode($priorRecon[0], JSON_UNESCAPED_SLASHES) : null,
                json_encode(['id' => $reconciliationId, 'date' => $reconDate, 'system_balance' => $systemBalance, 'counted_total' => $countedTotal, 'variance' => $variance], JSON_UNESCAPED_SLASHES),
                'Reconciliation #' . $reconciliationId . ': System ' . ledger_money($systemBalance) . ' - Counted ' . ledger_money($countedTotal) . ' - Variance ' . ledger_money($variance)
                    . ($reconReason !== '' ? ' - Reason: ' . $reconReason : '')
            );
            db()->commit();
            $history = ops_rows(
                "SELECT r.recon_date, r.system_balance, r.counted_total, r.variance, r.variance_note, r.created_at, e.full_name AS reconciled_by
                 FROM hambelela_cashbook_recon r
                 LEFT JOIN ops_employees e ON e.id = r.logged_by
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT 5"
            );
            ledger_json(['ok' => true, 'message' => 'Reconciliation saved.', 'history' => $history]);
        }
        throw new RuntimeException('Unknown ledger action.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if (ledger_wants_json()) {
            ledger_json(
                ['ok' => false, 'message' => $e->getMessage()],
                $e instanceof LedgerPermissionException ? 403 : 400
            );
        }
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$entries = $ready ? ops_rows(
    "SELECT *
     FROM ops_cash_book_entries
     WHERE " . ledger_active_where() . "
     ORDER BY transaction_date DESC, id DESC"
) : [];
$customColumns = $ready ? ledger_custom_columns() : [];

$today = date('Y-m-d');
$hasOpening = false;
$suggestedAmount = 0.0;
$suggestedSource = 'Previous day closing balance';
if ($ready) {
    $openingRows = ops_rows(
        "SELECT COUNT(*) AS opening_count
         FROM ops_cash_book_entries
                 WHERE " . ledger_active_where() . "
                   AND DATE(transaction_date) = ?
           AND (
               source = 'opening_balance'
               OR transaction_type = 'opening_balance'
           )",
        [$today]
    );
    $hasOpening = (int) ($openingRows[0]['opening_count'] ?? 0) > 0;
}
$cashInToday = 0.0;
$cashOutToday = 0.0;
$entriesToday = 0;
$closingBalance = 0.0;
$groups = [];

foreach ($entries as $entry) {
    $day = date('Y-m-d', strtotime((string) $entry['transaction_date']));
    $groups[$day][] = $entry;
}

if (!$groups) {
    $groups[$today] = [];
}
krsort($groups, SORT_STRING);
$dailyBalances = [];
foreach ($groups as $day => $dayEntries) {
    $dailyBalances[$day] = ledger_calculate_daily_balance($dayEntries);
}
$latestApplicableDate = (string) array_key_first($groups);
$closingBalance = (float) ($dailyBalances[$latestApplicableDate]['closing_balance'] ?? 0);
$cashInToday = (float) ($dailyBalances[$today]['cash_in'] ?? 0);
$cashOutToday = (float) ($dailyBalances[$today]['cash_out'] ?? 0);
$entriesToday = isset($groups[$today]) ? count($groups[$today]) : 0;
foreach ($dailyBalances as $day => $balance) {
    if ($day >= $today) continue;
    $suggestedAmount = (float) $balance['closing_balance'];
    $suggestedSource = 'Closing balance for ' . date('d M Y', strtotime($day));
    break;
}

$netToday = $cashInToday - $cashOutToday;
$reconHistory = $ready ? ops_rows(
    "SELECT r.recon_date, r.system_balance, r.counted_total, r.variance, r.variance_note, r.created_at, e.full_name AS reconciled_by
     FROM hambelela_cashbook_recon r
     LEFT JOIN ops_employees e ON e.id = r.logged_by
     ORDER BY r.created_at DESC, r.id DESC
     LIMIT 5"
) : [];
$reconciledDateRows = $ready ? ops_rows('SELECT DISTINCT recon_date FROM hambelela_cashbook_recon') : [];
$reconciledDateSet = array_fill_keys(array_map(static fn (array $row): string => (string) $row['recon_date'], $reconciledDateRows), true);
$trashItems = $ready ? ops_rows(
    "SELECT *
     FROM ops_cash_book_entries
     WHERE COALESCE(status, 'active') IN ('deleted', 'archived')
     ORDER BY COALESCE(deleted_at, archived_at, updated_at, created_at) DESC, id DESC
     LIMIT 50"
) : [];
$activityLog = $ready ? ops_rows(
    "SELECT l.*, e.description AS entry_desc
     FROM hambelela_cashbook_log l
     LEFT JOIN ops_cash_book_entries e ON e.id = l.entry_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 100"
) : [];
$canHardDelete = $canManageBookkeeping;
$headerNotificationSummary = function_exists('notifications_summary_for_current_user')
    ? notifications_summary_for_current_user(1)
    : ['unread_count' => 0];
$headerNotificationUnread = (int) ($headerNotificationSummary['unread_count'] ?? 0);
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= (int) @filemtime(BASE_PATH . '/assets/css/portal.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal-date-picker.css?v=<?= (int) @filemtime(BASE_PATH . '/assets/css/portal-date-picker.css') ?>">
    <script defer src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal-date-picker.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/portal-date-picker.js') ?>"></script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal-presence.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/portal-presence.js') ?>"></script>
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
            background: var(--ledger-white);
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
        .ledger-top-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            min-width: 0;
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
            font-size: 12px !important;
            font-weight: 800;
        }
        .bk-wrap .bk-opening-prompt {
            border: 1px solid #EDE3D8;
            border-left: 4px solid #A8CA19;
            border-radius: 0 12px 12px 0;
            background: #fff;
            padding: 10px 16px;
            margin-bottom: 14px;
            animation: fadeUp .3s ease both;
        }
        .bk-wrap .bk-opening-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: nowrap;
        }
        .bk-wrap .bk-opening-left {
            flex: 1;
            min-width: 0;
        }
        .bk-wrap .bk-opening-title {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 2px;
        }
        .bk-wrap .bk-opening-sub {
            font-size: 11px;
            color: #6B6B6B;
        }
        .bk-wrap .bk-opening-ref {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            color: #A08070;
        }
        .bk-wrap .bk-opening-ref strong {
            color: #1a1a1a;
        }
        .bk-wrap .bk-opening-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            flex-direction: row;
        }
        .bk-wrap .bk-opening-input-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            height: 32px;
            border: 1px solid #EDE3D8;
            border-radius: 8px;
            padding: 0 10px;
            background: #fff;
            transition: border-color .15s;
        }
        .bk-wrap .bk-opening-input-wrap:focus-within {
            border-color: #AB3619;
            box-shadow: 0 0 0 2px rgba(171, 54, 25, .12);
        }
        .bk-wrap .bk-opening-input-wrap.is-invalid {
            border-color: #BB1B21;
            box-shadow: 0 0 0 2px rgba(187, 27, 33, .12);
        }
        .bk-wrap .bk-opening-currency {
            font-size: 14px;
            font-weight: 600;
            color: #6B6B6B;
        }
        .bk-wrap .bk-opening-input-wrap input {
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            -webkit-appearance: none;
            appearance: none;
            width: 100px;
            font-weight: 700;
            color: #1a1a1a;
            padding: 8px 0 !important;
            background: transparent;
        }
        .bk-wrap .bk-opening-input-wrap input:focus {
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
        }
        .bk-wrap .bk-opening-variance {
            font-size: 11px;
            font-weight: 600;
            min-height: 16px;
        }
        .bk-wrap .bk-opening-btn {
            background: #AB3619;
            color: #fff;
            border: none;
            border-radius: 8px;
            height: 32px;
            padding: 0 16px;
            font-size: 12px;
            font-weight: 400;
            white-space: nowrap;
            cursor: pointer;
            font-family: Figtree, system-ui, -apple-system, sans-serif;
            transition: background .15s, transform .1s;
        }
        .bk-wrap .bk-opening-btn:hover { background: #721B1A; }
        .bk-wrap .bk-opening-btn:active { transform: scale(.97); }
        .ledger-board {
            width: 100%;
            overflow-x: auto;
            background: var(--ledger-white);
            padding-bottom: 8px;
        }
        .ledger-board-inner {
            --ledger-grid-template: 32px 220px 130px 100px 100px 100px 380px 44px;
            --ledger-grid-width: 1106px;
            width: 100%;
            min-width: max(100%, var(--ledger-grid-width));
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
            grid-template-columns: var(--ledger-grid-template);
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
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-left: 1px solid var(--ledger-border);
            font-weight: 800;
            font-size: 11px;
        }
        .day-sum span { color: var(--ledger-muted); font-size: 8px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .day-sum strong { color: inherit; font: inherit; }
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
            position: relative;
            padding-right: 18px;
        }
        .ledger-header .ledger-cell.check-cell {
            padding: 0;
            justify-content: center;
        }
        .ledger-column-resize-handle {
            position: absolute;
            top: 0;
            right: -4px;
            bottom: 0;
            width: 8px;
            cursor: col-resize;
            z-index: 5;
            background: transparent;
            touch-action: none;
        }
        .ledger-column-resize-handle::after {
            content: "";
            position: absolute;
            top: 7px;
            bottom: 7px;
            left: 3px;
            width: 2px;
            border-radius: 999px;
            background: transparent;
            transition: background .12s ease;
        }
        .ledger-column-resize-handle:hover::after,
        .ledger-column-resize-handle.is-active::after {
            background: var(--ledger-orange);
        }
        body.is-ledger-resizing {
            cursor: col-resize;
            user-select: none;
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
        .entry-row .ledger-cell,
        .add-row .ledger-cell {
            font-size: 12px;
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
        .ledger-add-col-cell {
            justify-content: center;
            padding: 0;
        }
        .ledger-add-column-btn {
            width: 26px;
            height: 26px;
            border: 1px solid rgba(171, 54, 25, .24);
            border-radius: 8px;
            background: var(--ledger-white);
            color: var(--ledger-rust);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
        }
        .ledger-add-column-btn:hover,
        .ledger-add-column-btn:focus-visible {
            background: #FDF6EE;
            border-color: var(--ledger-rust);
            color: var(--ledger-burgundy);
            outline: none;
        }
        .ledger-custom-header {
            gap: 6px;
        }
        .ledger-custom-title {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ledger-custom-delete {
            width: 18px;
            height: 18px;
            border: 1px solid rgba(171, 54, 25, .18);
            border-radius: 999px;
            background: #fff;
            color: var(--ledger-rust);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0;
            font-size: 12px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .ledger-custom-header:hover .ledger-custom-delete {
            display: inline-flex;
        }
        .ledger-custom-cell {
            cursor: text;
        }
        .ledger-custom-cell select,
        .ledger-custom-cell input {
            width: 100%;
            height: 26px;
            border: 0;
            outline: none;
            box-shadow: none;
            background: transparent;
            font: inherit;
            color: #1a1a1a;
        }
        .custom-status-pill,
        .custom-person-pill {
            max-width: 100%;
            height: 22px;
            border-radius: 999px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: color-mix(in srgb, var(--pill-colour, #AB3619) 16%, #fff);
            color: var(--pill-colour, #AB3619);
            font-size: 11px;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .custom-person-pill span {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--pill-colour, #AB3619);
            color: #fff;
            font-size: 9px;
            flex-shrink: 0;
        }
        .custom-column-popover {
            position: fixed;
            width: 320px;
            max-width: calc(100vw - 24px);
            max-height: calc(100vh - 24px);
            overflow-y: auto;
            border: 1px solid var(--ledger-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 18px 42px rgba(114, 27, 26, .18);
            z-index: 2500;
            padding: 12px;
            display: none;
        }
        .custom-column-popover.is-open {
            display: block;
        }
        .custom-type-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .custom-type-btn {
            min-height: 42px;
            border: 1px solid var(--ledger-border);
            border-radius: 10px;
            background: #fff;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .custom-type-btn:hover {
            border-color: var(--ledger-rust);
            background: #FDF6EE;
        }
        .custom-column-form {
            display: none;
            flex-direction: column;
            gap: 10px;
        }
        .custom-column-form.is-open {
            display: flex;
        }
        .custom-column-form label,
        .custom-option-row label {
            display: grid;
            gap: 4px;
            color: #6B4C3B;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .custom-column-form input,
        .custom-option-row input {
            height: 30px;
            border: 1px solid var(--ledger-border);
            border-radius: 8px;
            padding: 0 9px;
            font-family: Figtree, system-ui, sans-serif;
            font-size: 12px;
        }
        .custom-option-row {
            display: grid;
            grid-template-columns: 1fr 48px 24px;
            align-items: end;
            gap: 6px;
        }
        .custom-option-remove {
            height: 30px;
            border: 0;
            background: transparent;
            color: var(--ledger-red);
            cursor: pointer;
        }
        .custom-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .custom-form-actions button,
        .custom-add-option {
            height: 30px;
            border-radius: 8px;
            border: 1px solid var(--ledger-border);
            background: #fff;
            color: var(--ledger-rust);
            padding: 0 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .custom-form-actions .primary {
            background: var(--ledger-rust);
            color: #fff;
            border-color: var(--ledger-rust);
        }
        .row-dot {
            width: 16px;
            height: 16px;
            border-radius: 5px;
            border: 1px solid rgba(171, 54, 25, .3);
            background: var(--ledger-white);
        }
        .ledger-page .check-cell .bk-row-check,
        .ledger-page .check-cell .bk-select-all {
            position: relative;
            width: 15px;
            height: 15px;
            min-width: 15px;
            min-height: 15px;
            margin: 0;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            -webkit-appearance: none;
            appearance: none;
            border: 1px solid rgba(171, 54, 25, .30);
            border-radius: 3px;
            background: #fff;
            color: #fff;
            cursor: pointer;
            box-sizing: border-box;
            transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .ledger-page .check-cell .bk-row-check:hover,
        .ledger-page .check-cell .bk-select-all:hover {
            border-color: rgba(171, 54, 25, .55);
            transform: translateY(-1px);
        }
        .ledger-page .check-cell .bk-row-check:checked,
        .ledger-page .check-cell .bk-select-all:checked {
            border-color: var(--ledger-rust);
            background-color: var(--ledger-rust);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 14 14'%3E%3Cpath d='M3 7.2 5.7 10 11 4.5' fill='none' stroke='%23fff' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-position: center;
            background-repeat: no-repeat;
            background-size: 12px 12px;
        }
        .ledger-page .check-cell .bk-select-all:indeterminate {
            border-color: var(--ledger-rust);
            background-color: var(--ledger-rust);
            background-image: linear-gradient(#fff, #fff);
            background-position: center;
            background-repeat: no-repeat;
            background-size: 8px 2px;
        }
        .ledger-page .check-cell .bk-row-check:focus-visible,
        .ledger-page .check-cell .bk-select-all:focus-visible {
            outline: 0;
            box-shadow: 0 0 0 3px rgba(171, 54, 25, .12);
        }
        .entry-row.bk-row-selected .ledger-cell {
            background: #f9f5f4 !important;
        }
        .entry-row.bk-row-selected .ledger-data-cell:hover {
            background: #f9f5f4 !important;
        }
        .ledger-data-cell,
        .bk-wrap .bk-editable {
            cursor: text;
            transition: background .12s ease;
        }
        .ledger-data-cell:hover,
        .bk-wrap .bk-editable:hover {
            background: #FDF6EE !important;
            outline: 1px dashed var(--ledger-orange);
            outline-offset: -2px;
            box-shadow: none;
        }
        .ledger-data-cell.is-editing {
            background: var(--ledger-white);
            outline: none;
            box-shadow: none;
        }
        .ledger-data-cell input,
        .ledger-data-cell textarea,
        .bk-wrap .bk-editable input,
        .bk-wrap .bk-editable textarea,
        .bk-wrap .bk-editable select,
        .add-row input,
        .add-row textarea {
            width: 100%;
            height: 26px;
            border: 1px solid #AB3619;
            border-radius: 5px;
            background: var(--ledger-white);
            color: #1a1a1a;
            font-family: Figtree, system-ui, sans-serif;
            font-size: 12px;
            padding: 0 6px;
            outline: 0;
        }
        .ledger-data-cell input,
        .ledger-data-cell textarea,
        .bk-wrap .bk-editable input,
        .bk-wrap .bk-editable textarea,
        .bk-wrap .bk-editable select {
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .ledger-data-cell input:focus,
        .ledger-data-cell textarea:focus,
        .bk-wrap .bk-editable input:focus,
        .bk-wrap .bk-editable textarea:focus,
        .bk-wrap .bk-editable select:focus {
            outline: none;
            border: 0;
            box-shadow: none;
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
            color: #721b1a;
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
            font-weight: 400;
            padding: 0 8px;
            outline: 0;
        }

        .add-row input[data-add-field="transaction_date"] {
            font-size: 12px;
            font-weight: 400;
        }
        .bk-filter-grid .portal-date-picker,
        .add-row .portal-date-picker,
        .ledger-data-cell .portal-date-picker {
            width: 100%;
            min-width: 0;
        }
        .bk-filter-grid .portal-date-field,
        .add-row .portal-date-field,
        .ledger-data-cell .portal-date-field {
            height: 32px;
            min-height: 32px;
            color: #1a1a1a;
            font-size: 12px;
            font-weight: 400;
        }
        .bk-filter-grid .portal-date-icon,
        .add-row .portal-date-icon,
        .ledger-data-cell .portal-date-icon {
            width: 15px;
            height: 15px;
            flex-basis: 15px;
            color: #ab3619;
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
        .recon-card .bk-recon-line,
        .recon-card .bk-recon-line strong {
            font-size: 12px;
        }
        .recon-card .bk-field {
            color: #721b1a;
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
        .bk-filter-section .bk-side-button {
            font-weight: 400;
        }
        .recon-card .bk-side-button {
            font-weight: 400;
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
            background: #AB3619;
            color: #fff;
            border: none;
            border-radius: 50px;
            height: 32px;
            padding: 0 18px;
            font-family: Figtree, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            font-weight: 400;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex-shrink: 0;
            transition: background .2s ease;
        }
        .bk-drawer-trigger:hover {
            background: #721B1A;
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
        .bk-tabs.portal-panel-tabs {
            padding: 0 14px;
            gap: 22px;
        }
        .bk-tabs.portal-panel-tabs .bk-tab {
            height: 43px;
            min-height: 43px;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            color: #6B4C3B;
            font: 500 12px/1 Figtree, system-ui, sans-serif;
            box-shadow: none;
        }
        .bk-tabs.portal-panel-tabs .bk-tab:hover,
        .bk-tabs.portal-panel-tabs .bk-tab.is-active {
            background: transparent;
            color: #AB3619;
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
            font-weight: 400;
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
        .bk-trash-list { display: flex; flex-direction: column; gap: 0; }
        .bk-trash-item {
            min-height: 70px;
            border: 0;
            border-bottom: 1px solid var(--ledger-border);
            border-radius: 0;
            background: #fff;
            padding: 10px 8px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
        }
        .bk-trash-item:hover {
            background: #FFFDFC;
        }
        .bk-trash-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .bk-trash-title {
            color: #1a1a1a;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.25;
        }
        .bk-trash-meta {
            color: #6B6B6B;
            font-size: 11px;
            margin-top: 3px;
        }
        .bk-trash-amount {
            color: #1a1a1a;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }
        .bk-trash-actions {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }
        .bk-trash-btn {
            height: 28px;
            border-radius: 8px;
            border: 1px solid rgba(171, 54, 25, .24);
            background: #fff;
            color: var(--ledger-rust);
            cursor: pointer;
            font: inherit;
            font-size: 10px;
            font-weight: 500;
            padding: 0 9px;
        }
        .bk-trash-btn:hover {
            background: #FDF6EE;
        }
        .bk-trash-btn.danger {
            color: #BB1B21;
            border-color: rgba(187, 27, 33, .26);
        }
        @media (max-width: 600px) {
            .bk-tabs.portal-panel-tabs { gap: 18px; padding-inline: 12px; }
            .bk-trash-item { grid-template-columns: minmax(0, 1fr); gap: 8px; }
            .bk-trash-actions { justify-content: flex-start; }
        }
        #tab-trash .bk-side-head,
        .bk-trash-title,
        .bk-trash-meta,
        .bk-trash-amount,
        .bk-trash-btn,
        .bk-trash-btn.danger {
            color: #1A1A1A !important;
            font-weight: 400 !important;
        }
        .bk-trash-btn:hover,
        .bk-trash-btn:focus,
        .bk-trash-btn:focus-visible,
        .bk-trash-btn:active,
        .bk-trash-btn.danger:hover,
        .bk-trash-btn.danger:focus,
        .bk-trash-btn.danger:focus-visible,
        .bk-trash-btn.danger:active {
            color: #1A1A1A !important;
            font-weight: 400 !important;
        }
        #tab-trash .bk-trash-list .bk-trash-amount {
            font-weight: 400 !important;
        }
        #tab-trash .bk-trash-list .bk-trash-title {
            font-weight: 400 !important;
        }
        .bk-log-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 0 2px;
        }
        .bk-log-list::-webkit-scrollbar {
            width: 3px;
        }
        .bk-log-list::-webkit-scrollbar-thumb {
            background: #EDE3D8;
            border-radius: 2px;
        }
        .bk-log-empty {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #A08070;
        }
        .bk-action-bar {
            position: fixed;
            left: 50%;
            bottom: 16px;
            transform: translate(-50%, 18px);
            z-index: 300;
            width: fit-content;
            max-width: calc(100vw - 24px);
            min-height: 56px;
            padding: 7px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #FFFFFF;
            border: 1px solid #EDE3D8;
            border-radius: 14px;
            box-shadow: 0 14px 34px rgba(114, 27, 26, .18);
            box-sizing: border-box;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
        }
        .bk-action-bar.visible {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, 0);
            pointer-events: auto;
        }
        .bk-action-selection {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .bk-action-count {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 34px;
            border-radius: 50%;
            background: #AB3619;
            color: #FFFFFF;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
        }
        .bk-action-label {
            color: #1A1A1A;
            font-size: 12px;
            font-weight: 400;
            line-height: 1;
        }
        .bk-action-divider {
            width: 1px;
            height: 32px;
            flex: 0 0 1px;
            background: #EDE3D8;
        }
        .bk-action-btns {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .bk-action-btn {
            min-width: 58px;
            height: 40px;
            padding: 4px 8px;
            background: transparent;
            color: #6B4C3B;
            border: 0;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 400;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: background-color .15s ease, color .15s ease;
            font-family: Figtree, system-ui, sans-serif;
        }
        .bk-action-btn svg { width: 15px; height: 15px; color: #AB3619; }
        .bk-action-btn:hover { background: rgba(240,116,32,.08); color: #AB3619; }
        .bk-action-btn.danger { color: #BB1B21; }
        .bk-action-btn.danger svg { color: #BB1B21; }
        .bk-action-btn.danger:hover { background: rgba(187,27,33,.08); }
        .bk-action-close {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 32px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #AB3619;
            cursor: pointer;
        }
        .bk-action-close:hover { background: rgba(240,116,32,.08); }
        .bk-action-close svg { width: 15px; height: 15px; }
        @media (max-width: 620px) {
            .bk-action-bar { gap: 6px; padding: 6px; }
            .bk-action-label, .bk-action-divider { display: none; }
            .bk-action-btn { min-width: 50px; padding-inline: 5px; }
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
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 760px) {
            .bk-page-layout { grid-template-columns: 1fr; }
            .ledger-page { padding: 18px; }
            .ledger-top { flex-direction: column; }
            .stat-grid { grid-template-columns: 1fr; }
            .ledger-board-inner { min-width: 980px; }
            .closing-card { align-items: flex-start; flex-direction: column; }
        }
        @media (max-width: 600px) {
            .bk-wrap .bk-opening-inner { flex-direction: column; align-items: stretch; }
            .bk-wrap .bk-opening-right { align-items: stretch; }
        }
        @media (max-width: 900px) {
            .bk-page-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="ledger-shell shell">
<?php include BASE_PATH . '/shared/sidebar.php'; ?>
<main class="ledger-page bk-wrap" data-bookkeeping-access="<?= $isBookkeepingReadOnly ? 'read-only' : ($canManageBookkeeping ? 'full' : 'operational') ?>">
    <header class="ledger-top">
        <div>
            <h1>Hambelela Bookkeeping</h1>
            <p class="ledger-subtitle">Daily cash in, cash out, net movement, and closing balance.</p>
        </div>
        <div class="ledger-top-actions" data-portal-header-status-target>
            <?php if ($ready && $canOperateBookkeeping): ?>
                <button class="bk-drawer-trigger" type="button" id="bkDrawerBtn" data-view-bar-action data-toolbar-action="cash-tools" aria-expanded="false" onclick="openDrawer()"><i data-lucide="calculator"></i><span>Cash Tools</span></button>
            <?php endif; ?>
            <section class="portal-header-status" data-portal-header-status
                     data-presence-endpoint="<?= htmlspecialchars(BASE_URL . '/apps/operations/portal-presence.php', ENT_QUOTES, 'UTF-8') ?>">
                <div class="portal-header-clock" aria-label="Current Namibia time">
                    <span data-portal-date>---</span>
                    <strong data-portal-time>--:-- --</strong>
                </div>
                <div class="portal-online-widget" data-portal-online-widget tabindex="0"
                     aria-label="Online employees" aria-expanded="false">
                    <div class="portal-online-avatars" data-portal-online-avatars></div>
                    <span class="portal-online-count" data-portal-online-count>0 online</span>
                    <div class="portal-online-popover" data-portal-online-popover hidden>
                        <strong>Currently online</strong>
                        <div data-portal-online-list>
                            <p class="portal-online-empty">Checking staff status...</p>
                        </div>
                    </div>
                </div>
                <a class="portal-header-notifications"
                   href="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="Notifications">
                    <i data-lucide="bell"></i>
                    <?php if ($headerNotificationUnread > 0): ?>
                        <span><?= htmlspecialchars($headerNotificationUnread > 99 ? '99+' : (string) $headerNotificationUnread, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </a>
            </section>
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

        <?php if (!$hasOpening && $canOperateBookkeeping): ?>
            <section class="bk-opening-prompt" id="bkOpeningPrompt" aria-label="Opening balance prompt">
                <div class="bk-opening-inner">
                    <div class="bk-opening-left">
                        <div class="bk-opening-title">Good morning - start today's cash ledger</div>
                        <div class="bk-opening-sub">
                            Enter the physical cash in the till right now.
                            <?php if ($suggestedAmount > 0): ?>
                                <span class="bk-opening-ref">
                                    <?= htmlspecialchars($suggestedSource, ENT_QUOTES, 'UTF-8') ?>:
                                    <strong><?= ledger_money($suggestedAmount) ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bk-opening-right">
                        <div class="bk-opening-input-wrap">
                            <span class="bk-opening-currency">N$</span>
                            <input type="number" id="openingAmount" placeholder="0.00" min="0" step="0.01" value="<?= $suggestedAmount > 0 ? htmlspecialchars(number_format($suggestedAmount, 2, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>">
                        </div>
                        <?php if ($suggestedAmount > 0): ?>
                            <div class="bk-opening-variance" id="openingVariance"></div>
                        <?php endif; ?>
                        <button class="bk-opening-btn" type="button" onclick="saveOpeningBalance()">Open till</button>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="bk-side-section bk-filter-section" data-portal-view-filter aria-label="Cash ledger filters">
            <div class="bk-side-head"><span>Filters</span></div>
            <div class="bk-side-body">
                <div class="bk-filter-grid portal-data-filter-grid">
                    <label class="bk-field">Date Range<select data-bk-date-range data-portal-custom-select><option value="all">All dates</option><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option></select></label>
                    <label class="bk-field">Entry Type<select data-bk-filter-entry-type data-portal-custom-select><option value="">All entries</option><option value="cash_in">Cash In</option><option value="cash_out">Cash Out</option></select></label>
                    <label class="bk-field">Payment<select data-bk-filter-payment data-portal-custom-select><option value="">All payments</option></select></label>
                    <label class="bk-field">Person<select data-bk-filter-person data-portal-custom-select><option value="">All people</option></select></label>
                    <label class="bk-field">Group By<select data-bk-filter-group data-portal-custom-select><option value="date">Date</option></select></label>
                    <label class="bk-field">Search Bookkeeping<input type="search" data-bk-filter-search placeholder="Search bookkeeping..."></label>
                    <input type="hidden" data-bk-filter-from><input type="hidden" data-bk-filter-to>
                </div>
                <button class="bk-side-button" type="button" data-bk-filter-clear>Clear filters</button>
            </div>
        </section>

        <div class="bk-page-layout">
        <div class="bk-ledger-col">
        <section class="ledger-board" aria-label="Cash ledger board">
            <div class="ledger-board-inner">
                <?php foreach ($groups as $day => $dayEntries): ?>
                    <?php
                    $dayBalance = $dailyBalances[$day];
                    $dayOpening = (float) $dayBalance['opening_balance'];
                    $dayIn = (float) $dayBalance['cash_in'];
                    $dayOut = (float) $dayBalance['cash_out'];
                    $dayClosing = (float) $dayBalance['closing_balance'];
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
                            <div class="day-sum"><span>Opening</span><strong data-day-opening><?= ledger_money($dayOpening) ?></strong></div>
                            <div class="day-sum money-in"><span>Cash In</span><strong data-day-in><?= ledger_money($dayIn) ?></strong></div>
                            <div class="day-sum money-out"><span>Cash Out</span><strong data-day-out><?= ledger_money($dayOut) ?></strong></div>
                            <div class="day-sum money-net"><span>Closing</span><strong data-day-closing><?= ledger_money($dayClosing) ?></strong></div>
                            <?php foreach ($customColumns as $column): ?>
                                <div class="day-sum"></div>
                            <?php endforeach; ?>
                            <div class="day-sum"></div>
                        </div>
                        <div class="ledger-row ledger-header">
                            <div class="ledger-cell check-cell"><?php if ($canSelectBookkeepingRows): ?><input class="bk-select-all" type="checkbox" aria-label="Select all visible entries for <?= htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?></div>
                            <div class="ledger-cell" data-ledger-column="description">Description<span class="ledger-column-resize-handle" data-ledger-resize-column="description" aria-hidden="true"></span></div>
                            <div class="ledger-cell" data-ledger-column="transaction_date">Date & Time<span class="ledger-column-resize-handle" data-ledger-resize-column="transaction_date" aria-hidden="true"></span></div>
                            <div class="ledger-cell" data-ledger-column="cash_in">Cash In<span class="ledger-column-resize-handle" data-ledger-resize-column="cash_in" aria-hidden="true"></span></div>
                            <div class="ledger-cell" data-ledger-column="cash_out">Cash Out<span class="ledger-column-resize-handle" data-ledger-resize-column="cash_out" aria-hidden="true"></span></div>
                            <div class="ledger-cell" data-ledger-column="total">Total<span class="ledger-column-resize-handle" data-ledger-resize-column="total" aria-hidden="true"></span></div>
                            <div class="ledger-cell" data-ledger-column="notes">Notes<span class="ledger-column-resize-handle" data-ledger-resize-column="notes" aria-hidden="true"></span></div>
                            <?php foreach ($customColumns as $column): ?>
                                <div class="ledger-cell ledger-custom-header" data-ledger-column="<?= htmlspecialchars($column['column_key'], ENT_QUOTES, 'UTF-8') ?>" data-custom-column-key="<?= htmlspecialchars($column['column_key'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span class="ledger-custom-title" data-custom-rename><?= htmlspecialchars($column['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <button class="ledger-custom-delete" type="button" data-delete-custom-column="<?= htmlspecialchars($column['column_key'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Delete custom column">&times;</button>
                                    <span class="ledger-column-resize-handle" data-ledger-resize-column="<?= htmlspecialchars($column['column_key'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="ledger-cell ledger-add-col-cell"><?php if ($canManageBookkeeping): ?><button class="ledger-add-column-btn" type="button" data-add-ledger-column aria-label="Add ledger column">+</button><?php endif; ?></div>
                        </div>
                        <?php foreach ($dayEntries as $entry): ?>
                            <?php
                            $rowIn = (float) ($entry['cash_in'] ?? 0);
                            $rowOut = (float) ($entry['cash_out'] ?? 0);
                            $rowTotal = $rowIn - $rowOut;
                            $entryDate = (string) $entry['transaction_date'];
                            $entryCustomFields = ledger_decode_json($entry['custom_fields_json'] ?? '{}');
                            ?>
                            <div class="ledger-row entry-row" data-entry-id="<?= (int) $entry['id'] ?>" data-cash-in="<?= $rowIn ?>" data-cash-out="<?= $rowOut ?>" data-entry-type="<?= htmlspecialchars((string) ($entry['transaction_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-entry-source="<?= htmlspecialchars((string) ($entry['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-reconciled="<?= isset($reconciledDateSet[substr($entryDate, 0, 10)]) ? '1' : '0' ?>" data-created-by="<?= htmlspecialchars((string) ($entry['created_by_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(($entry['created_by_name'] ?? '') !== '' ? 'Created by ' . (string) $entry['created_by_name'] : '', ENT_QUOTES, 'UTF-8') ?>">
                            <div class="ledger-cell check-cell"><?php if ($canSelectBookkeepingRows): ?><input class="bk-row-check" type="checkbox" data-id="<?= (int) $entry['id'] ?>" aria-label="Select ledger entry"><?php endif; ?></div>
                            <div class="ledger-cell ledger-data-cell <?= $canOperateBookkeeping ? 'bk-editable' : '' ?>" data-field="description" data-id="<?= (int) $entry['id'] ?>" data-value="<?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="ledger-cell ledger-data-cell <?= $canOperateBookkeeping ? 'bk-editable' : '' ?>" data-field="transaction_date" data-id="<?= (int) $entry['id'] ?>" data-value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($entryDate)), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ledger_display_datetime($entryDate), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="ledger-cell ledger-data-cell <?= $canOperateBookkeeping ? 'bk-editable' : '' ?> money-cell money-in" data-field="cash_in" data-id="<?= (int) $entry['id'] ?>" data-value="<?= htmlspecialchars((string) $rowIn, ENT_QUOTES, 'UTF-8') ?>"><?= $rowIn > 0 ? ledger_money($rowIn) : '' ?></div>
                            <div class="ledger-cell ledger-data-cell <?= $canOperateBookkeeping ? 'bk-editable' : '' ?> money-cell money-out" data-field="cash_out" data-id="<?= (int) $entry['id'] ?>" data-value="<?= htmlspecialchars((string) $rowOut, ENT_QUOTES, 'UTF-8') ?>"><?= $rowOut > 0 ? ledger_money($rowOut) : '' ?></div>
                                <div class="ledger-cell ledger-total money-net" data-row-total><?= ledger_money($rowTotal) ?></div>
                            <div class="ledger-cell ledger-data-cell <?= $canOperateBookkeeping ? 'bk-editable' : '' ?>" data-field="notes" data-id="<?= (int) $entry['id'] ?>" data-value="<?= htmlspecialchars((string) ($entry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($entry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php foreach ($customColumns as $column): ?>
                                    <?php $customValue = (string) ($entryCustomFields[$column['column_key']] ?? ''); ?>
                                    <div class="ledger-cell ledger-custom-cell" <?= $canOperateBookkeeping ? 'data-custom-cell' : '' ?> data-custom-column-key="<?= htmlspecialchars($column['column_key'], ENT_QUOTES, 'UTF-8') ?>" data-custom-type="<?= htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int) $entry['id'] ?>" data-value="<?= htmlspecialchars($customValue, ENT_QUOTES, 'UTF-8') ?>"><?= ledger_custom_cell_html($column, $entryCustomFields) ?></div>
                                <?php endforeach; ?>
                                <div class="ledger-cell ledger-add-col-cell"></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($canOperateBookkeeping): ?>
                        <div class="ledger-row add-row" data-add-row data-day="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="ledger-cell check-cell"></div>
                            <div class="ledger-cell"><input data-add-field="description" placeholder="Add cash entry"></div>
                            <div class="ledger-cell"><input data-add-field="transaction_date" data-portal-date-mode="datetime" type="datetime-local" value="<?= htmlspecialchars($addDate, ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="ledger-cell"><input data-add-field="cash_in" type="number" min="0" step="0.01" placeholder="0.00"></div>
                            <div class="ledger-cell"><input data-add-field="cash_out" type="number" min="0" step="0.01" placeholder="0.00"></div>
                            <div class="ledger-cell ledger-total money-net" data-add-total>N$0.00</div>
                            <div class="ledger-cell"><input data-add-field="notes" placeholder="Notes"></div>
                            <?php foreach ($customColumns as $column): ?>
                                <div class="ledger-cell ledger-custom-cell is-empty" data-custom-placeholder data-custom-column-key="<?= htmlspecialchars($column['column_key'], ENT_QUOTES, 'UTF-8') ?>"></div>
                            <?php endforeach; ?>
                            <div class="ledger-cell ledger-add-col-cell"></div>
                        </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="closing-card" aria-label="Closing balance">
            <span data-closing-balance-label><?= $latestApplicableDate === $today ? 'Current Balance' : 'Closing Balance' ?> &mdash; <?= htmlspecialchars(date('d F Y', strtotime($latestApplicableDate)), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-closing-balance><?= ledger_money($closingBalance) ?></strong>
        </section>
        </div>
        </div>
    <?php endif; ?>
</main>
</div>
<?php if ($ready && $canOperateBookkeeping): ?>
<div class="bk-overlay" id="bkOverlay" onclick="closeDrawer()"></div>
<aside class="bk-drawer cash-tools-panel" id="bkDrawer" aria-label="Cash ledger tools">
    <div class="bk-drawer-header">
        <div class="bk-drawer-title">Cash tools</div>
        <button class="bk-drawer-close" type="button" onclick="closeDrawer()" aria-label="Close cash tools">&times;</button>
    </div>
    <div class="bk-tabs portal-panel-tabs" role="tablist" aria-label="Cash tools tabs">
        <button class="bk-tab portal-panel-tab is-active" type="button" role="tab" aria-selected="true" data-tab="counter" onclick="switchTab(this, 'counter')"><i data-lucide="calculator" aria-hidden="true"></i><span>Count till</span></button>
        <button class="bk-tab portal-panel-tab" type="button" role="tab" aria-selected="false" data-tab="recon" onclick="switchTab(this, 'recon')"><i data-lucide="circle-check-big" aria-hidden="true"></i><span>Reconcile</span></button>
        <button class="bk-tab portal-panel-tab" type="button" role="tab" aria-selected="false" data-tab="trash" onclick="switchTab(this, 'trash')"><i data-lucide="trash-2" aria-hidden="true"></i><span>Trash</span></button>
        <button class="bk-tab portal-panel-tab" type="button" role="tab" aria-selected="false" data-tab="activity" onclick="switchTab(this, 'activity')"><i data-lucide="history" aria-hidden="true"></i><span>Activity</span></button>
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
                                <small>Counted <?= ledger_money((float) $row['counted_total']) ?> vs system <?= ledger_money((float) $row['system_balance']) ?> · Reconciled by <?= htmlspecialchars((string) ($row['reconciled_by'] ?? 'Unknown employee'), ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(ledger_display_datetime((string) $row['created_at']), ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$reconHistory): ?><div class="bk-history-item">No reconciliations saved yet.</div><?php endif; ?>
                    </div>
                </div>
            </section>
        </section>
        <section class="bk-tab-panel" id="tab-trash">
            <section class="bk-side-section">
                <div class="bk-side-head"><span>Trash</span></div>
                <div class="bk-side-body">
                    <div class="bk-trash-list" data-trash-list>
                        <?php foreach ($trashItems as $item): ?>
                            <?php
                            $trashIn = (float) ($item['cash_in'] ?? 0);
                            $trashOut = (float) ($item['cash_out'] ?? 0);
                            $trashTotal = $trashIn - $trashOut;
                            $trashStatus = (string) ($item['status'] ?? 'deleted');
                            ?>
                            <div class="bk-trash-item" data-trash-id="<?= (int) $item['id'] ?>">
                                <div class="bk-trash-top">
                                    <div>
                                        <div class="bk-trash-title"><?= htmlspecialchars((string) $item['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="bk-trash-meta"><?= htmlspecialchars(ledger_display_datetime((string) $item['transaction_date']), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars(ucfirst($trashStatus), ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="bk-trash-amount"><?= ledger_money($trashTotal) ?></div>
                                </div>
                                <div class="bk-trash-actions">
                                    <button class="bk-trash-btn" type="button" data-restore-id="<?= (int) $item['id'] ?>">Restore</button>
                                    <?php if ($canHardDelete): ?>
                                        <button class="bk-trash-btn danger" type="button" data-delete-id="<?= (int) $item['id'] ?>">Permanently delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$trashItems): ?><div class="bk-history-item">Trash is empty.</div><?php endif; ?>
                    </div>
                </div>
            </section>
        </section>
        <section class="bk-tab-panel" id="tab-activity">
            <section class="bk-side-section">
                <div class="bk-side-head"><span>Activity Log</span></div>
                <div class="bk-side-body">
                    <div class="bk-log-list" id="bkLogList"></div>
                </div>
            </section>
        </section>
    </div>
</aside>
<?php endif; ?>
<?php if ($ready && $canSelectBookkeepingRows): ?>
<div class="bk-action-bar" id="bkActionBar" aria-live="polite">
    <div class="bk-action-selection">
        <span class="bk-action-count" id="bkActionCount">0</span>
        <strong class="bk-action-label" id="bkActionLabel">items selected</strong>
    </div>
    <div class="bk-action-divider" aria-hidden="true"></div>
    <div class="bk-action-btns">
        <button class="bk-action-btn" type="button" onclick="exportSelected()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
            <span>Export selected</span>
        </button>
        <button class="bk-action-btn" type="button" onclick="moveToDate()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Move date</span>
        </button>
        <button class="bk-action-btn" type="button" onclick="archiveSelected()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
            <span>Archive</span>
        </button>
        <button class="bk-action-btn danger" type="button" onclick="softDeleteSelected()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            <span>Delete</span>
        </button>
    </div>
    <button class="bk-action-close" type="button" onclick="clearSelection()" aria-label="Clear selection">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
</div>
<?php endif; ?>
<?php if ($ready && $canManageBookkeeping): ?>
<div class="custom-column-popover" id="customColumnPopover" aria-hidden="true">
    <div class="custom-type-grid" data-custom-type-grid>
        <button class="custom-type-btn" type="button" data-custom-type="status">Status</button>
        <button class="custom-type-btn" type="button" data-custom-type="dropdown">Dropdown</button>
        <button class="custom-type-btn" type="button" data-custom-type="text">T Text</button>
        <button class="custom-type-btn" type="button" data-custom-type="date">Date</button>
        <button class="custom-type-btn" type="button" data-custom-type="people">People</button>
        <button class="custom-type-btn" type="button" data-custom-type="numbers"># Numbers</button>
    </div>
    <form class="custom-column-form" data-custom-column-form>
        <label>Column name<input type="text" data-custom-column-name maxlength="80" placeholder="Column name"></label>
        <div data-custom-options-shell hidden>
            <div data-custom-options-list></div>
            <button class="custom-add-option" type="button" data-custom-add-option>+ Add option</button>
        </div>
        <div class="custom-form-actions">
            <button type="button" data-custom-cancel>Cancel</button>
            <button class="primary" type="submit">Add column</button>
        </div>
    </form>
</div>
<?php endif; ?>
<script>
const todayKey = <?= json_encode($today, JSON_UNESCAPED_SLASHES) ?>;
let systemBalance = <?= json_encode(round($closingBalance, 2), JSON_UNESCAPED_SLASHES) ?>;
const suggestedOpeningAmount = <?= json_encode($suggestedAmount > 0 ? round($suggestedAmount, 2) : 0, JSON_UNESCAPED_SLASHES) ?>;
window.bkActivityLog = <?= json_encode($activityLog, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
window.bkReconciledDates = <?= json_encode(array_keys($reconciledDateSet), JSON_UNESCAPED_SLASHES) ?>;
window.bkCustomColumns = <?= json_encode($customColumns, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
window.bookkeepingPermissions = <?= json_encode([
    'canSelectRows' => $canSelectBookkeepingRows,
    'canOperate' => $canOperateBookkeeping,
    'canManage' => $canManageBookkeeping,
], JSON_UNESCAPED_SLASHES) ?>;
window.bookkeepingCsrfToken = <?= json_encode($bookkeepingCsrfToken, JSON_UNESCAPED_SLASHES) ?>;
const ledgerColumnStorageKey = 'hambelela.cashLedger.columnWidths.v1';
const coreLedgerColumns = [
  { key: 'select', width: 32, min: 32, locked: true },
  { key: 'description', width: 220, min: 150 },
  { key: 'transaction_date', width: 130, min: 110 },
  { key: 'cash_in', width: 100, min: 84 },
  { key: 'cash_out', width: 100, min: 84 },
  { key: 'total', width: 100, min: 84 },
  { key: 'notes', width: 380, min: 180 },
];
const ledgerAddColumn = { key: 'add', width: 44, min: 44, locked: true };
let ledgerColumnDefaults = buildLedgerColumnDefaults();
let ledgerColumnWidths = loadLedgerColumnWidths();

function buildLedgerColumnDefaults() {
  const custom = (window.bkCustomColumns || []).map((column) => ({
    key: column.column_key,
    width: 150,
    min: 100,
    custom: true
  }));
  return [...coreLedgerColumns, ...custom, ledgerAddColumn];
}

function loadLedgerColumnWidths() {
  try {
    const saved = JSON.parse(localStorage.getItem(ledgerColumnStorageKey) || '{}');
    return Object.fromEntries(ledgerColumnDefaults.map((column) => {
      const width = Number(saved[column.key]);
      return [column.key, Number.isFinite(width) ? Math.max(column.min, width) : column.width];
    }));
  } catch (error) {
    return Object.fromEntries(ledgerColumnDefaults.map((column) => [column.key, column.width]));
  }
}

function ledgerGridTemplate() {
  return ledgerColumnDefaults.map((column) => `${Math.round(ledgerColumnWidths[column.key])}px`).join(' ');
}

function applyLedgerColumnWidths() {
  const board = document.querySelector('.ledger-board-inner');
  if (!board) return;
  const width = ledgerColumnDefaults.reduce((total, column) => total + Number(ledgerColumnWidths[column.key] || column.width), 0);
  board.style.setProperty('--ledger-grid-template', ledgerGridTemplate());
  board.style.setProperty('--ledger-grid-width', `${Math.round(width)}px`);
}

function persistLedgerColumnWidths() {
  const payload = Object.fromEntries(ledgerColumnDefaults.map((column) => [column.key, Math.round(ledgerColumnWidths[column.key])]));
  try {
    localStorage.setItem(ledgerColumnStorageKey, JSON.stringify(payload));
  } catch (error) {
    // Column resizing should keep working even if persistence is unavailable.
  }
}

function startLedgerColumnResize(handle, event) {
  const key = handle.dataset.ledgerResizeColumn;
  const column = ledgerColumnDefaults.find((item) => item.key === key && !item.locked);
  if (!column) return;
  event.preventDefault();
  event.stopPropagation();
  const startX = event.clientX;
  const startWidth = Number(ledgerColumnWidths[key] || column.width);
  handle.classList.add('is-active');
  document.body.classList.add('is-ledger-resizing');
  const move = (moveEvent) => {
    ledgerColumnWidths[key] = Math.max(column.min, startWidth + moveEvent.clientX - startX);
    applyLedgerColumnWidths();
  };
  const stop = () => {
    handle.classList.remove('is-active');
    document.body.classList.remove('is-ledger-resizing');
    persistLedgerColumnWidths();
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', stop);
    window.removeEventListener('pointercancel', stop);
  };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', stop);
  window.addEventListener('pointercancel', stop);
}

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
  const pad = (number) => String(number).padStart(2, '0');
  const hour = date.getHours() % 12 || 12;
  const meridiem = date.getHours() >= 12 ? 'PM' : 'AM';
  return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(hour)}:${pad(date.getMinutes())} ${meridiem}`;
}

function customColumnByKey(key) {
  return (window.bkCustomColumns || []).find((column) => column.column_key === key);
}

function customOption(column, value) {
  return (column?.options || []).find((option) => String(option.value) === String(value));
}

function renderCustomValue(column, value) {
  if (!value) return '';
  const option = customOption(column, value);
  if (option && ['status', 'dropdown', 'people'].includes(column.type)) {
    if (column.type === 'people') {
      const initial = escapeHtml(String(option.label || value).slice(0, 1).toUpperCase());
      return `<span class="custom-person-pill" style="--pill-colour:${escapeHtml(option.colour || '#AB3619')}"><span>${initial}</span>${escapeHtml(option.label || value)}</span>`;
    }
    return `<span class="custom-status-pill" style="--pill-colour:${escapeHtml(option.colour || '#AB3619')}">${escapeHtml(option.label || value)}</span>`;
  }
  return escapeHtml(value);
}

function openCustomColumnPopover(button) {
  const popover = document.getElementById('customColumnPopover');
  if (!popover || !button) return;
  const rect = button.getBoundingClientRect();
  popover.style.left = `${Math.min(window.innerWidth - 332, Math.max(12, rect.right - 320))}px`;
  popover.style.top = `${Math.min(window.innerHeight - 220, rect.bottom + 8)}px`;
  popover.classList.add('is-open');
  popover.setAttribute('aria-hidden', 'false');
  popover.dataset.selectedType = '';
  popover.querySelector('[data-custom-type-grid]').hidden = false;
  const form = popover.querySelector('[data-custom-column-form]');
  form.classList.remove('is-open');
  form.reset();
  popover.querySelector('[data-custom-options-shell]').hidden = true;
  popover.querySelector('[data-custom-options-list]').innerHTML = '';
}

function clampCustomColumnPopover() {
  const popover = document.getElementById('customColumnPopover');
  if (!popover?.classList.contains('is-open')) return;
  const rect = popover.getBoundingClientRect();
  const top = Math.min(Math.max(12, rect.top), Math.max(12, window.innerHeight - rect.height - 12));
  const left = Math.min(Math.max(12, rect.left), Math.max(12, window.innerWidth - rect.width - 12));
  popover.style.top = `${top}px`;
  popover.style.left = `${left}px`;
}

function closeCustomColumnPopover() {
  const popover = document.getElementById('customColumnPopover');
  if (!popover) return;
  popover.classList.remove('is-open');
  popover.setAttribute('aria-hidden', 'true');
}

function addCustomOptionRow(label = '', colour = '#F07420') {
  const list = document.querySelector('[data-custom-options-list]');
  if (!list) return;
  const row = document.createElement('div');
  row.className = 'custom-option-row';
  row.innerHTML = `
    <label>Label<input type="text" data-option-label maxlength="60" value="${escapeHtml(label)}"></label>
    <label>Color<input type="color" data-option-colour value="${escapeHtml(colour)}"></label>
    <button class="custom-option-remove" type="button" data-remove-option aria-label="Remove option">&times;</button>
  `;
  list.appendChild(row);
}

function chooseCustomType(type) {
  const popover = document.getElementById('customColumnPopover');
  if (!popover) return;
  popover.dataset.selectedType = type;
  popover.querySelector('[data-custom-type-grid]').hidden = true;
  const form = popover.querySelector('[data-custom-column-form]');
  form.classList.add('is-open');
  form.querySelector('[data-custom-column-name]').focus();
  const needsOptions = ['status', 'dropdown'].includes(type);
  popover.querySelector('[data-custom-options-shell]').hidden = !needsOptions;
  if (needsOptions && !popover.querySelector('[data-custom-options-list]').children.length) {
    addCustomOptionRow(type === 'status' ? 'Paid' : 'Option', '#A8CA19');
    addCustomOptionRow(type === 'status' ? 'Pending' : 'Another option', '#F07420');
  }
  clampCustomColumnPopover();
  requestAnimationFrame(clampCustomColumnPopover);
  setTimeout(clampCustomColumnPopover, 80);
}

async function saveCustomColumn(event) {
  event.preventDefault();
  const popover = document.getElementById('customColumnPopover');
  if (!popover) return;
  const type = popover.dataset.selectedType;
  const name = popover.querySelector('[data-custom-column-name]').value.trim();
  const options = Array.from(popover.querySelectorAll('.custom-option-row')).map((row) => ({
    label: row.querySelector('[data-option-label]')?.value.trim() || '',
    colour: row.querySelector('[data-option-colour]')?.value || '#F07420'
  })).filter((option) => option.label);
  try {
    await postLedger('cashbook_add_custom_column', {
      column_type: type,
      name,
      options_json: JSON.stringify(options)
    });
    toast('Column added');
    setTimeout(() => location.reload(), 350);
  } catch (error) {
    alert(error.message);
  }
}

function renameCustomColumn(header) {
  const key = header?.dataset.customColumnKey;
  const column = customColumnByKey(key);
  const title = header?.querySelector('[data-custom-rename]');
  if (!column || !title || header.classList.contains('is-renaming')) return;
  const input = document.createElement('input');
  input.type = 'text';
  input.maxLength = 80;
  input.value = column.name;
  input.style.width = '100%';
  input.style.border = '0';
  input.style.outline = 'none';
  input.style.background = 'transparent';
  input.style.font = 'inherit';
  input.style.color = 'inherit';
  header.classList.add('is-renaming');
  title.replaceWith(input);
  input.focus();
  input.select();
  let cancelled = false;
  const finish = async (save) => {
    if (!header.classList.contains('is-renaming')) return;
    const name = input.value.trim();
    const nextTitle = document.createElement('span');
    nextTitle.className = 'ledger-custom-title';
    nextTitle.dataset.customRename = '';
    nextTitle.textContent = save && !cancelled && name ? name : column.name;
    input.replaceWith(nextTitle);
    header.classList.remove('is-renaming');
    if (!save || cancelled || !name || name === column.name) return;
    try {
      await postLedger('cashbook_rename_custom_column', { column_key: key, name });
      column.name = name;
      toast('Column renamed');
    } catch (error) {
      nextTitle.textContent = column.name;
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

async function deleteCustomColumn(key) {
  const column = customColumnByKey(key);
  if (!column) return;
  if (!confirm(`Delete "${column.name}"? Data will be hidden but not permanently erased.`)) return;
  try {
    await postLedger('cashbook_delete_custom_column', { column_key: key });
    toast('Column hidden');
    setTimeout(() => location.reload(), 350);
  } catch (error) {
    alert(error.message);
  }
}

function startCustomEdit(cell) {
  if (!cell || cell.classList.contains('is-editing')) return;
  const key = cell.dataset.customColumnKey;
  const column = customColumnByKey(key);
  const id = cell.dataset.id;
  if (!column || !id) return;
  const originalValue = cell.dataset.value || '';
  let input;
  if (['status', 'dropdown', 'people'].includes(column.type)) {
    input = document.createElement('select');
    input.innerHTML = `<option value=""></option>` + (column.options || []).map((option) => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join('');
  } else {
    input = document.createElement('input');
    input.type = column.type === 'date' ? 'date' : (column.type === 'numbers' ? 'number' : 'text');
    if (column.type === 'numbers') input.step = 'any';
  }
  input.value = originalValue;
  cell.classList.add('is-editing');
  cell.innerHTML = '';
  cell.appendChild(input);
  input.focus();
  let cancelled = false;
  const finish = async (save) => {
    if (!cell.classList.contains('is-editing')) return;
    const value = input.value.trim();
    cell.classList.remove('is-editing');
    cell.dataset.value = save && !cancelled ? value : originalValue;
    cell.innerHTML = renderCustomValue(column, cell.dataset.value);
    if (!save || cancelled || value === originalValue) return;
    try {
      const reconciled = cell.closest('.entry-row')?.dataset.reconciled === '1';
      const reason = reconciled ? (prompt('Reason for editing this reconciled entry (required):')?.trim() || '') : '';
      if (reconciled && !reason) {
        cell.dataset.value = originalValue;
        cell.innerHTML = renderCustomValue(column, originalValue);
        return;
      }
      await postLedger('cashbook_update_custom_value', { entry_id: id, column_key: key, value, reason });
      toast('Saved');
    } catch (error) {
      cell.dataset.value = originalValue;
      cell.innerHTML = renderCustomValue(column, originalValue);
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
  if (input.tagName === 'SELECT') input.addEventListener('change', () => input.blur(), { once: true });
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

function setOpeningVariance() {
  if (!suggestedOpeningAmount) return;
  const input = document.getElementById('openingAmount');
  const varianceNode = document.getElementById('openingVariance');
  if (!input || !varianceNode) return;
  const entered = parseMoney(input.value);
  const difference = entered - suggestedOpeningAmount;
  if (!entered || Math.abs(difference) < 0.005) {
    varianceNode.textContent = '';
    varianceNode.style.color = '';
    return;
  }
  const direction = difference > 0 ? 'over' : 'under';
  varianceNode.textContent = `${money(Math.abs(difference))} ${direction} suggested`;
  varianceNode.style.color = difference > 0 ? '#3d5c00' : '#BB1B21';
}

async function saveOpeningBalance() {
  const input = document.getElementById('openingAmount');
  const wrapper = input?.closest('.bk-opening-input-wrap');
  const amount = parseMoney(input?.value || 0);
  wrapper?.classList.remove('is-invalid');
  if (amount <= 0) {
    wrapper?.classList.add('is-invalid');
    input?.focus();
    toast('Enter the opening cash amount.');
    return;
  }
  const button = document.querySelector('.bk-opening-btn');
  const originalText = button?.textContent || 'Open till';
  if (button) {
    button.textContent = 'Saving...';
    button.disabled = true;
  }
  try {
    const notes = suggestedOpeningAmount > 0 ? `Suggested opening: ${money(suggestedOpeningAmount)}` : '';
    await postLedger('save_opening_balance', { cash_in: amount, notes });
    const prompt = document.getElementById('bkOpeningPrompt');
    if (prompt) {
      prompt.style.transition = 'opacity .25s ease, transform .25s ease';
      prompt.style.opacity = '0';
      prompt.style.transform = 'translateY(-8px)';
      setTimeout(() => {
        prompt.remove();
        location.reload();
      }, 260);
    } else {
      location.reload();
    }
  } catch (error) {
    if (button) {
      button.textContent = originalText;
      button.disabled = false;
    }
    toast(error.message || 'Could not save the opening balance.');
  }
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
      <small>Counted ${money(row.counted_total)} vs system ${money(row.system_balance)} · Reconciled by ${escapeHtml(row.reconciled_by || 'Unknown employee')} at ${escapeHtml(displayDate(row.created_at))}</small>
    </div>
  `).join('');
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
}

function renderActivityLog() {
  const list = document.getElementById('bkLogList');
  if (!list) return;
  const rows = Array.isArray(window.bkActivityLog) ? window.bkActivityLog : [];
  if (!rows.length) {
    list.innerHTML = '<div class="bk-log-empty">No activity yet.</div>';
    return;
  }
  const actionLabels = {
    created: { label: 'Created', colour: '#A8CA19' },
    opening_balance: { label: 'Opening balance', colour: '#A8CA19' },
    edited: { label: 'Edited', colour: '#F07420' },
    deleted: { label: 'Deleted', colour: '#BB1B21' },
    archived: { label: 'Archived', colour: '#6B6B6B' },
    restored: { label: 'Restored', colour: '#A8CA19' },
    moved: { label: 'Moved', colour: '#F07420' },
    reconciled: { label: 'Reconciled', colour: '#AB3619' },
    permanently_deleted: { label: 'Permanently deleted', colour: '#721B1A' },
  };
  const fieldLabels = {
    description: 'Description',
    entry_dt: 'Date and time',
    transaction_date: 'Date and time',
    cash_in: 'Cash in',
    cash_out: 'Cash out',
    notes: 'Notes',
  };
  list.innerHTML = rows.map((row) => {
    const action = actionLabels[row.action] || { label: row.action || 'Activity', colour: '#6B6B6B' };
    const parsedDate = new Date(String(row.created_at || '').replace(' ', 'T'));
    const time = Number.isNaN(parsedDate.getTime())
      ? escapeHtml(row.created_at || '')
      : parsedDate.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    const entryDesc = row.entry_desc ? String(row.entry_desc).slice(0, 30) : '';
    const entryRef = entryDesc ? `<span style="color:#A08070;font-size:10px">${escapeHtml(entryDesc)}</span>` : '';
    const change = row.field
      ? `<div style="font-size:10px;color:#A08070;margin-top:2px">${escapeHtml(fieldLabels[row.field] || row.field)}: <span style="color:#BB1B21">${escapeHtml(row.old_value || '-')}</span> -> <span style="color:#3d5c00">${escapeHtml(row.new_value || '-')}</span></div>`
      : (row.description ? `<div style="font-size:10px;color:#A08070;margin-top:2px">${escapeHtml(row.description)}</div>` : '');
    return `<div style="padding:10px 0;border-bottom:1px solid #EDE3D8;display:flex;gap:10px;align-items:flex-start">
      <div style="width:8px;height:8px;border-radius:50%;background:${action.colour};margin-top:4px;flex-shrink:0"></div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
          <div style="font-size:12px;font-weight:600;color:#1a1a1a">${escapeHtml(action.label)} ${entryRef}</div>
          <div style="font-size:10px;color:#A08070;white-space:nowrap">${time}</div>
        </div>
        <div style="font-size:11px;color:#6B6B6B;margin-top:1px">${escapeHtml(row.user_name || 'Unknown user')}</div>
        ${change}
      </div>
    </div>`;
  }).join('');
}

function getSelectedIds() {
  return Array.from(document.querySelectorAll('.bk-row-check:checked')).map((cb) => cb.dataset.id).filter(Boolean);
}

function visibleRowChecks(group = document) {
  return Array.from(group.querySelectorAll('.bk-row-check')).filter((checkbox) => {
    const row = checkbox.closest('.entry-row');
    return row && !row.hidden && row.offsetParent !== null;
  });
}

function updateSelectAllState() {
  document.querySelectorAll('.bk-select-all').forEach((checkbox) => {
    const group = checkbox.closest('[data-day-group]');
    const rowChecks = visibleRowChecks(group || document);
    const selectedCount = rowChecks.filter((rowCheck) => rowCheck.checked).length;
    checkbox.checked = rowChecks.length > 0 && selectedCount === rowChecks.length;
    checkbox.indeterminate = selectedCount > 0 && selectedCount < rowChecks.length;
    checkbox.disabled = rowChecks.length === 0;
  });
}

function updateFloatingBar() {
  const ids = getSelectedIds();
  const bar = document.getElementById('bkActionBar');
  const count = document.getElementById('bkActionCount');
  const label = document.getElementById('bkActionLabel');
  if (!bar || !count || !label) return;
  if (ids.length > 0) {
    bar.classList.add('visible');
    count.textContent = String(ids.length);
    label.textContent = ids.length === 1 ? 'item selected' : 'items selected';
  } else {
    bar.classList.remove('visible');
    count.textContent = '0';
    label.textContent = 'items selected';
  }
  updateSelectAllState();
}

function clearSelection() {
  document.querySelectorAll('.bk-row-check').forEach((cb) => {
    cb.checked = false;
    cb.closest('.entry-row')?.classList.remove('bk-row-selected');
  });
  updateFloatingBar();
}

function exportSelected() {
  const selectedRows = Array.from(document.querySelectorAll('.bk-row-check:checked'))
    .map((checkbox) => checkbox.closest('.entry-row'))
    .filter(Boolean);
  if (!selectedRows.length) return;
  const headings = ['Description', 'Date & Time', 'Cash In', 'Cash Out', 'Total', 'Notes'];
  const rows = selectedRows.map((row) => Array.from(row.querySelectorAll('.ledger-cell'))
    .slice(1, 7)
    .map((cell) => (cell.textContent || '').trim()));
  const csv = [headings, ...rows]
    .map((columns) => columns.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(','))
    .join('\r\n');
  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `hambelela-bookkeeping-selected-${todayKey}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function runSelectedAction(action, fields = {}) {
  const ids = getSelectedIds();
  if (!ids.length) return;
  const data = await postLedger(action, { ids: ids.join(','), ...fields });
  toast(data.message || 'Updated');
  clearSelection();
  setTimeout(() => location.reload(), 450);
}

function softDeleteSelected() {
  const ids = getSelectedIds();
  if (!ids.length) return;
  const reason = prompt(`Move ${ids.length} selected ${ids.length === 1 ? 'entry' : 'entries'} to Trash?\n\nThe entries will be removed from the active bookkeeping list but can be restored later.\n\nReason (required):`)?.trim() || '';
  if (!reason) return;
  if (!confirm(`Move ${ids.length} selected ${ids.length === 1 ? 'entry' : 'entries'} to Trash?\n\nReason: ${reason}`)) return;
  runSelectedAction('cashbook_soft_delete', { reason }).catch((error) => alert(error.message));
}

function archiveSelected() {
  const ids = getSelectedIds();
  if (!ids.length) return;
  runSelectedAction('cashbook_archive').catch((error) => alert(error.message));
}

function moveToDate() {
  const ids = getSelectedIds();
  if (!ids.length) return;
  const newDate = prompt('Move to date (YYYY-MM-DD):');
  if (!newDate) return;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
    alert('Invalid date format. Use YYYY-MM-DD.');
    return;
  }
  const reason = prompt('Reason for changing the selected entry date(s) (required):')?.trim() || '';
  if (!reason) return;
  runSelectedAction('cashbook_move_date', { new_date: newDate, reason }).catch((error) => alert(error.message));
}

async function restoreTrashItem(id) {
  if (!id) return;
  const data = await postLedger('cashbook_restore', { ids: String(id) });
  toast(data.message || 'Restored');
  setTimeout(() => location.reload(), 450);
}

async function permanentDeleteTrashItem(id) {
  if (!id) return;
  if (!confirm('Permanently delete this ledger entry? This cannot be undone.')) return;
  const data = await postLedger('cashbook_permanent_delete', { ids: String(id) });
  toast(data.message || 'Deleted');
  setTimeout(() => location.reload(), 450);
}

function openDrawer() {
  document.getElementById('bkDrawer')?.classList.add('is-open');
  document.getElementById('bkOverlay')?.classList.add('is-open');
  document.getElementById('bkDrawerBtn')?.setAttribute('aria-expanded', 'true');
}

function closeDrawer(restoreFocus = false) {
  document.getElementById('bkDrawer')?.classList.remove('is-open');
  document.getElementById('bkOverlay')?.classList.remove('is-open');
  document.getElementById('bkDrawerBtn')?.setAttribute('aria-expanded', 'false');
  if (restoreFocus) document.getElementById('bkDrawerBtn')?.focus({ preventScroll: true });
}

function switchTab(button, tab) {
  document.querySelectorAll('.bk-tab').forEach((node) => {
    const selected = node === button;
    node.classList.toggle('is-active', selected);
    node.setAttribute('aria-selected', selected ? 'true' : 'false');
  });
  document.querySelectorAll('.bk-tab-panel').forEach((panel) => panel.classList.toggle('is-active', panel.id === `tab-${tab}`));
  if (tab === 'activity') renderActivityLog();
}

function applySidebarFilters() {
  const from = document.querySelector('[data-bk-filter-from]')?.value || '';
  const to = document.querySelector('[data-bk-filter-to]')?.value || '';
  const search = (document.querySelector('[data-bk-filter-search]')?.value || '').toLowerCase().trim();
  const entryType = document.querySelector('[data-bk-filter-entry-type]')?.value || '';
  const payment = document.querySelector('[data-bk-filter-payment]')?.value || '';
  const person = document.querySelector('[data-bk-filter-person]')?.value || '';
  document.querySelectorAll('[data-day-group]').forEach((group) => {
    const day = group.dataset.dayGroup || '';
    let visibleRows = 0;
    group.querySelectorAll('.entry-row').forEach((row) => {
      const text = row.textContent.toLowerCase();
      const matchesDate = (!from || day >= from) && (!to || day <= to);
      const matchesSearch = !search || text.includes(search);
      const matchesType = !entryType || (entryType === 'cash_in' ? Number(row.dataset.cashIn || 0) > 0 : Number(row.dataset.cashOut || 0) > 0);
      const matchesPayment = !payment || (row.dataset.entrySource || '') === payment;
      const matchesPerson = !person || (row.dataset.createdBy || '') === person;
      const visible = matchesDate && matchesSearch && matchesType && matchesPayment && matchesPerson;
      row.hidden = !visible;
      if (!visible) {
        const checkbox = row.querySelector('.bk-row-check');
        if (checkbox) checkbox.checked = false;
        row.classList.remove('bk-row-selected');
      }
      if (visible) visibleRows++;
    });
    const dateVisible = (!from || day >= from) && (!to || day <= to);
    group.hidden = !dateVisible || visibleRows === 0;
  });
  updateFloatingBar();
  updateDisplayedClosingBalance();
  updateFilteredBookkeepingStats();
}

function syncBookkeepingFilterOptions() {
  const payment = document.querySelector('[data-bk-filter-payment]');
  const person = document.querySelector('[data-bk-filter-person]');
  if (!payment || !person || payment.dataset.optionsReady) return;
  const rows = [...document.querySelectorAll('.entry-row')];
  const addOptions = (select, values) => values.filter(Boolean).sort((a, b) => a.localeCompare(b)).forEach((value) => select.add(new Option(value, value)));
  addOptions(payment, [...new Set(rows.map((row) => row.dataset.entrySource || ''))]);
  addOptions(person, [...new Set(rows.map((row) => row.dataset.createdBy || ''))]);
  payment.dataset.optionsReady = 'true';
  person.dataset.optionsReady = 'true';
}

function applyBookkeepingDatePreset(value) {
  const from = document.querySelector('[data-bk-filter-from]');
  const to = document.querySelector('[data-bk-filter-to]');
  if (!from || !to) return;
  const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const today = new Date();
  let start = null;
  if (value === 'today') start = new Date(today);
  if (value === 'week') { start = new Date(today); start.setDate(today.getDate() - ((today.getDay() + 6) % 7)); }
  if (value === 'month') start = new Date(today.getFullYear(), today.getMonth(), 1);
  from.value = start ? localDate(start) : '';
  to.value = start ? localDate(today) : '';
}

syncBookkeepingFilterOptions();

<?php if (!empty($_GET['cash_tools'])): ?>
document.addEventListener('DOMContentLoaded', openDrawer, { once: true });
<?php endif; ?>

async function postLedger(action, fields = {}) {
  const form = new FormData();
  form.set('response', 'json');
  form.set('action', action);
  form.set('csrf_token', window.bookkeepingCsrfToken || '');
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

function rowIsOpeningBalance(row) {
  return row.dataset.entryType === 'opening_balance' || row.dataset.entrySource === 'opening_balance';
}

function calculateGroupBalance(group, visibleOnly = false) {
  let openingCents = 0;
  let cashInCents = 0;
  let cashOutCents = 0;
  group.querySelectorAll('.entry-row').forEach((row) => {
    if (visibleOnly && row.hidden) return;
    const inCents = Math.round(Math.abs(parseMoney(row.dataset.cashIn)) * 100);
    const outCents = Math.round(Math.abs(parseMoney(row.dataset.cashOut)) * 100);
    if (rowIsOpeningBalance(row)) {
      openingCents += inCents - outCents;
      return;
    }
    cashInCents += inCents;
    cashOutCents += outCents;
  });
  return {
    opening: openingCents / 100,
    cashIn: cashInCents / 100,
    cashOut: cashOutCents / 100,
    closing: (openingCents + cashInCents - cashOutCents) / 100
  };
}

function latestDayGroup(visibleOnly = false) {
  return [...document.querySelectorAll('[data-day-group]')]
    .filter((group) => !visibleOnly || !group.hidden)
    .sort((left, right) => String(right.dataset.dayGroup || '').localeCompare(String(left.dataset.dayGroup || '')))[0] || null;
}

function updateDisplayedClosingBalance() {
  const group = latestDayGroup(true);
  const balance = group ? calculateGroupBalance(group, true).closing : 0;
  const day = group?.dataset.dayGroup || '';
  const valueNode = document.querySelector('[data-closing-balance]');
  const labelNode = document.querySelector('[data-closing-balance-label]');
  if (valueNode) valueNode.textContent = money(balance);
  if (labelNode) {
    const label = day === todayKey ? 'Current Balance' : 'Closing Balance';
    const dateLabel = day
      ? new Date(`${day}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
      : '';
    labelNode.textContent = dateLabel ? `${label} — ${dateLabel}` : label;
  }
}

function updateFilteredBookkeepingStats() {
  let cashIn = 0;
  let cashOut = 0;
  let count = 0;
  document.querySelectorAll('[data-day-group]:not([hidden]) .entry-row:not([hidden])').forEach((row) => {
    if (rowIsOpeningBalance(row)) return;
    cashIn += Math.abs(parseMoney(row.dataset.cashIn));
    cashOut += Math.abs(parseMoney(row.dataset.cashOut));
    count += 1;
  });
  document.querySelector('[data-stat-cash-in]').textContent = money(cashIn);
  document.querySelector('[data-stat-cash-out]').textContent = money(cashOut);
  document.querySelector('[data-stat-net]').textContent = money(cashIn - cashOut);
  document.querySelector('[data-stat-count]').textContent = String(count);
}

function recalcGroup(group) {
  group.querySelectorAll('.entry-row').forEach((row) => {
    const total = entryTotal(row);
    const totalCell = row.querySelector('[data-row-total]');
    if (totalCell) totalCell.textContent = money(total);
  });
  const balance = calculateGroupBalance(group);
  group.querySelector('[data-day-opening]').textContent = money(balance.opening);
  group.querySelector('[data-day-in]').textContent = money(balance.cashIn);
  group.querySelector('[data-day-out]').textContent = money(balance.cashOut);
  group.querySelector('[data-day-closing]').textContent = money(balance.closing);
  const count = group.querySelectorAll('.entry-row').length;
  group.querySelector('[data-day-count]').textContent = `${count} ${count === 1 ? 'entry' : 'entries'}`;
  recalcStats();
}

function recalcStats() {
  let todayCount = 0;
  let todayBalance = { cashIn: 0, cashOut: 0 };
  document.querySelectorAll('[data-day-group]').forEach((group) => {
    const isToday = group.dataset.dayGroup === todayKey;
    if (!isToday) return;
    todayBalance = calculateGroupBalance(group);
    todayCount = group.querySelectorAll('.entry-row').length;
  });
  document.querySelector('[data-stat-cash-in]').textContent = money(todayBalance.cashIn);
  document.querySelector('[data-stat-cash-out]').textContent = money(todayBalance.cashOut);
  document.querySelector('[data-stat-net]').textContent = money(todayBalance.cashIn - todayBalance.cashOut);
  document.querySelector('[data-stat-count]').textContent = String(todayCount);
  const latestGroup = latestDayGroup(false);
  systemBalance = latestGroup ? calculateGroupBalance(latestGroup).closing : 0;
  updateDisplayedClosingBalance();
  setReconValues();
}

function renderEntry(entry) {
  const row = document.createElement('div');
  row.className = 'ledger-row entry-row';
  row.dataset.entryId = entry.id;
  row.dataset.cashIn = String(entry.cash_in || 0);
  row.dataset.cashOut = String(entry.cash_out || 0);
  row.dataset.entryType = entry.transaction_type || '';
  row.dataset.entrySource = entry.source || '';
  const customCells = (window.bkCustomColumns || []).map((column) => {
    const value = entry.custom_fields?.[column.column_key] || '';
    return `<div class="ledger-cell ledger-custom-cell" data-custom-cell data-custom-column-key="${escapeHtml(column.column_key)}" data-custom-type="${escapeHtml(column.type)}" data-id="${entry.id}" data-value="${escapeHtml(value)}">${renderCustomValue(column, value)}</div>`;
  }).join('');
  row.innerHTML = `
    <div class="ledger-cell check-cell">${window.bookkeepingPermissions?.canSelectRows ? `<input class="bk-row-check" type="checkbox" data-id="${entry.id}" aria-label="Select ledger entry">` : ''}</div>
    <div class="ledger-cell ledger-data-cell bk-editable" data-field="description" data-id="${entry.id}"></div>
    <div class="ledger-cell ledger-data-cell bk-editable" data-field="transaction_date" data-id="${entry.id}" data-value="${entry.date_input || inputDate(entry.date)}">${displayDate(entry.date)}</div>
    <div class="ledger-cell ledger-data-cell bk-editable money-cell money-in" data-field="cash_in" data-id="${entry.id}" data-value="${Number(entry.cash_in || 0)}">${Number(entry.cash_in || 0) > 0 ? money(entry.cash_in) : ''}</div>
    <div class="ledger-cell ledger-data-cell bk-editable money-cell money-out" data-field="cash_out" data-id="${entry.id}" data-value="${Number(entry.cash_out || 0)}">${Number(entry.cash_out || 0) > 0 ? money(entry.cash_out) : ''}</div>
    <div class="ledger-cell ledger-total money-net" data-row-total>${money(Number(entry.total || 0))}</div>
    <div class="ledger-cell ledger-data-cell bk-editable" data-field="notes" data-id="${entry.id}"></div>
    ${customCells}
    <div class="ledger-cell ledger-add-col-cell"></div>
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
  if (field === 'transaction_date') input.dataset.portalDateMode = 'datetime';
  if (input.type === 'number') {
    input.min = '0';
    input.step = '0.01';
  }
  input.value = field === 'transaction_date' ? inputDate(originalValue) : originalValue;
  cell.classList.add('is-editing');
  cell.innerHTML = '';
  cell.appendChild(input);
  let cancelled = false;
  const finish = async (save) => {
    if (!cell.classList.contains('is-editing')) return;
    const value = input.value.trim();
    cell.classList.remove('is-editing');
    cell.textContent = save && !cancelled ? value : originalText;
    if (!save || cancelled || value === String(originalValue)) return;
    try {
      let reason = '';
      if (field === 'transaction_date' || row.dataset.reconciled === '1') {
        reason = prompt(field === 'transaction_date' ? 'Reason for changing this entry date (required):' : 'Reason for editing this reconciled entry (required):')?.trim() || '';
        if (!reason) {
          cell.textContent = originalText;
          toast('No change saved. A reason is required.');
          return;
        }
      }
      const data = await postLedger('update_entry', { entry_id: id, field, value, reason });
      const entry = data.entry;
      const currentGroup = row.closest('[data-day-group]');
      if (entry.day !== currentGroup?.dataset.dayGroup) {
        const destination = [...document.querySelectorAll('[data-day-group]')]
          .find((group) => group.dataset.dayGroup === entry.day);
        const replacement = renderEntry(entry);
        if (destination) {
          const addRow = destination.querySelector('[data-add-row]');
          addRow ? addRow.before(replacement) : destination.appendChild(replacement);
          row.remove();
          recalcGroup(destination);
        } else {
          row.replaceWith(replacement);
        }
        if (currentGroup?.isConnected) recalcGroup(currentGroup);
        recalcStats();
        toast('Saved');
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
  if (field === 'transaction_date') {
    window.initialisePortalDatePickers?.(input);
    const dateTrigger = cell.querySelector('[data-portal-date-trigger]');
    input.addEventListener('change', () => finish(true), { once: true });
    dateTrigger?.focus();
    dateTrigger?.click();
  } else {
    input.addEventListener('blur', () => finish(true), { once: true });
    input.focus();
    if (input.select) input.select();
  }
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

document.addEventListener('pointerdown', (event) => {
  const handle = event.target.closest('[data-ledger-resize-column]');
  if (!handle) return;
  startLedgerColumnResize(handle, event);
});

document.addEventListener('click', (event) => {
  const back = event.target.closest('[data-ledger-back]');
  const addLedgerColumn = event.target.closest('[data-add-ledger-column]');
  const customType = event.target.closest('[data-custom-type]');
  const customCancel = event.target.closest('[data-custom-cancel]');
  const customAddOption = event.target.closest('[data-custom-add-option]');
  const customRemoveOption = event.target.closest('[data-remove-option]');
  const customDelete = event.target.closest('[data-delete-custom-column]');
  const customRename = event.target.closest('[data-custom-rename]');
  const customCell = event.target.closest('[data-custom-cell]');
  const toggle = event.target.closest('[data-toggle-day]');
  const save = event.target.closest('[data-save-add]');
  const editable = event.target.closest('.ledger-data-cell');
  const clearFilters = event.target.closest('[data-bk-filter-clear]');
  const saveRecon = event.target.closest('[data-save-recon]');
  const resetDenoms = event.target.closest('[data-reset-denoms]');
  const selectAll = event.target.closest('.bk-select-all');
  const rowCheck = event.target.closest('.bk-row-check');
  const restore = event.target.closest('[data-restore-id]');
  const hardDelete = event.target.closest('[data-delete-id]');
  if (addLedgerColumn) {
    openCustomColumnPopover(addLedgerColumn);
    return;
  }
  if (customType) {
    chooseCustomType(customType.dataset.customType);
    return;
  }
  if (customCancel) {
    closeCustomColumnPopover();
    return;
  }
  if (customAddOption) {
    addCustomOptionRow();
    return;
  }
  if (customRemoveOption) {
    customRemoveOption.closest('.custom-option-row')?.remove();
    return;
  }
  if (customDelete) {
    deleteCustomColumn(customDelete.dataset.deleteCustomColumn);
    return;
  }
  if (customRename) {
    renameCustomColumn(customRename.closest('[data-custom-column-key]'));
    return;
  }
  if (customCell) {
    startCustomEdit(customCell);
    return;
  }
  if (selectAll) {
    const group = selectAll.closest('[data-day-group]');
    visibleRowChecks(group || document).forEach((checkbox) => {
      checkbox.checked = selectAll.checked;
      checkbox.closest('.entry-row')?.classList.toggle('bk-row-selected', checkbox.checked);
    });
    updateFloatingBar();
    return;
  }
  if (rowCheck) {
    const row = rowCheck.closest('.entry-row');
    row?.classList.toggle('bk-row-selected', rowCheck.checked);
    updateFloatingBar();
    return;
  }
  if (restore) {
    restoreTrashItem(restore.dataset.restoreId).catch((error) => alert(error.message));
    return;
  }
  if (hardDelete) {
    permanentDeleteTrashItem(hardDelete.dataset.deleteId).catch((error) => alert(error.message));
    return;
  }
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
    document.querySelector('[data-bk-date-range]').value = 'all';
    document.querySelector('[data-bk-filter-entry-type]').value = '';
    document.querySelector('[data-bk-filter-payment]').value = '';
    document.querySelector('[data-bk-filter-person]').value = '';
    applySidebarFilters();
    return;
  }
  if (saveRecon) {
    const countedTotal = parseMoney(document.querySelector('[data-counted-total]').textContent);
    const difference = countedTotal - systemBalance;
    if (!confirm(`Reconcile ${todayKey}?\n\nExpected cash: ${money(systemBalance)}\nCounted cash: ${money(countedTotal)}\nDifference: ${money(difference)}`)) return;
    let reason = '';
    if ((window.bkReconciledDates || []).includes(todayKey)) {
      reason = prompt('Reason for reopening or changing this completed reconciliation (required):')?.trim() || '';
      if (!reason) return;
    }
    postLedger('cashbook_save_recon', {
      recon_date: todayKey,
      system_balance: systemBalance,
      counted_total: countedTotal,
      variance_note: document.querySelector('[data-recon-note]')?.value || '',
      reason
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

document.addEventListener('submit', (event) => {
  if (!event.target.matches('[data-custom-column-form]')) return;
  saveCustomColumn(event);
});

let bookkeepingSearchTimer = 0;
document.addEventListener('input', (event) => {
  if (event.target.id === 'openingAmount') {
    event.target.closest('.bk-opening-input-wrap')?.classList.remove('is-invalid');
    setOpeningVariance();
    return;
  }
  const row = event.target.closest('[data-add-row]');
  if (event.target.matches('[data-denom]')) {
    setReconValues();
    return;
  }
  if (event.target.matches('[data-bk-filter-search]')) {
    window.clearTimeout(bookkeepingSearchTimer);
    bookkeepingSearchTimer = window.setTimeout(applySidebarFilters, 180);
    return;
  }
  if (event.target.matches('[data-bk-filter-from], [data-bk-filter-to], [data-bk-filter-entry-type], [data-bk-filter-payment], [data-bk-filter-person]')) {
    applySidebarFilters();
    return;
  }
  if (!row || !event.target.matches('[data-add-field="cash_in"], [data-add-field="cash_out"]')) return;
  row.querySelector('[data-add-total]').textContent = money(parseMoney(addValue(row, 'cash_in')) - parseMoney(addValue(row, 'cash_out')));
});

document.addEventListener('change', (event) => {
  if (event.target.matches('[data-bk-date-range]')) applyBookkeepingDatePreset(event.target.value);
  if (event.target.matches('[data-bk-date-range], [data-bk-filter-entry-type], [data-bk-filter-payment], [data-bk-filter-person]')) applySidebarFilters();
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    closeDrawer(true);
    closeCustomColumnPopover();
  }
  const row = event.target.closest('[data-add-row]');
  if (!row || event.key !== 'Enter') return;
  event.preventDefault();
  saveAddRow(row);
});

applyLedgerColumnWidths();
setReconValues();
setOpeningVariance();
updateFloatingBar();
</script>
</body>
</html>
