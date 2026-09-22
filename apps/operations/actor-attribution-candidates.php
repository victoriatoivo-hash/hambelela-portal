<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_login();
if (!user_has_role('owner_admin')) {
    http_response_code(403);
    exit('Owner access required.');
}

header('Cache-Control: private, no-store');

function actor_audit_person(string $name): string
{
    $name = strtolower(trim($name));
    if (preg_match('/\bhope\b/u', $name)) return 'hope';
    if (preg_match('/\b(?:secilia|cecilia)\b/u', $name)) return 'secilia';
    return '';
}

function actor_audit_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-180 days')));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    http_response_code(400);
    exit('Choose a valid date range.');
}

$candidates = [];
$errors = [];
if (ops_table_exists('ops_activity_logs')) {
    try {
        $rows = ops_rows(
            "SELECT l.id, l.entity_id, l.action, l.created_at, l.employee_id, l.metadata, e.full_name AS employee_name
             FROM ops_activity_logs l
             LEFT JOIN ops_employees e ON e.id = l.employee_id
             WHERE l.entity_type = 'order' AND l.created_at >= ? AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY)
             ORDER BY l.created_at DESC, l.id DESC LIMIT 5000",
            [$from, $to]
        );
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            $displayName = trim((string) (is_array($metadata) ? ($metadata['changed_by'] ?? '') : ''));
            $storedPerson = actor_audit_person($displayName);
            $idPerson = actor_audit_person((string) ($row['employee_name'] ?? ''));
            if (!$storedPerson || !$idPerson || $storedPerson === $idPerson) continue;
            $candidates[] = [
                'module' => 'Orders', 'record' => (int) $row['entity_id'],
                'action' => (string) $row['action'], 'at' => (string) $row['created_at'],
                'displayed' => $displayName, 'employee' => (string) $row['employee_name'],
                'evidence' => 'ops_activity_logs #' . (int) $row['id'] . ': employee_id #' . (int) $row['employee_id'] . ' conflicts with changed_by text',
                'confidence' => 'Ambiguous — identity conflict',
            ];
        }
    } catch (Throwable $error) {
        $errors[] = 'Orders activity could not be read: ' . $error->getMessage();
    }
}

if (ops_table_exists('hambelela_cashbook_log')) {
    try {
        $rows = ops_rows(
            'SELECT l.id, l.entry_id, l.action, l.created_at, l.user_id, l.user_name, e.full_name AS employee_name
             FROM hambelela_cashbook_log l
             LEFT JOIN ops_employees e ON e.id = l.user_id
             WHERE l.created_at >= ? AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY)
             ORDER BY l.created_at DESC, l.id DESC LIMIT 5000',
            [$from, $to]
        );
        foreach ($rows as $row) {
            $storedPerson = actor_audit_person((string) ($row['user_name'] ?? ''));
            $idPerson = actor_audit_person((string) ($row['employee_name'] ?? ''));
            if (!$storedPerson || !$idPerson || $storedPerson === $idPerson) continue;
            $candidates[] = [
                'module' => 'Bookkeeping', 'record' => (int) $row['entry_id'],
                'action' => (string) $row['action'], 'at' => (string) $row['created_at'],
                'displayed' => (string) $row['user_name'], 'employee' => (string) $row['employee_name'],
                'evidence' => 'hambelela_cashbook_log #' . (int) $row['id'] . ': user_id #' . (int) $row['user_id'] . ' conflicts with user_name',
                'confidence' => 'Ambiguous — identity conflict',
            ];
        }
    } catch (Throwable $error) {
        $errors[] = 'Bookkeeping history could not be read: ' . $error->getMessage();
    }
}

usort($candidates, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
$pageTitle = 'Attribution candidates | ' . APP_NAME;
$activeApp = 'operations';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module" style="padding:24px;max-width:1200px">
    <h1>Hope / Secilia attribution candidates</h1>
    <p>Read-only comparison of stored employee IDs with displayed actor names. A conflict is a review candidate, not proof that either name is correct. Matching ID and name do not rule out a wrongly shared login.</p>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;margin:18px 0">
        <label>From <input type="date" name="from" value="<?= actor_audit_html($from) ?>"></label>
        <label>To <input type="date" name="to" value="<?= actor_audit_html($to) ?>"></label>
        <button type="submit">Review records</button>
    </form>
    <p><?= count($candidates) ?> identity conflict<?= count($candidates) === 1 ? '' : 's' ?> found in the selected range. The newest 5,000 activity rows per module were checked.</p>
    <?php foreach ($errors as $error): ?><p role="alert"><?= actor_audit_html($error) ?></p><?php endforeach; ?>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
        <thead><tr><th>Section</th><th>Record ID</th><th>Action</th><th>Time</th><th>Displayed actor</th><th>Employee linked by ID</th><th>Evidence</th><th>Confidence</th></tr></thead>
        <tbody><?php foreach ($candidates as $candidate): ?><tr>
            <td><?= actor_audit_html($candidate['module']) ?></td><td><?= actor_audit_html($candidate['record']) ?></td><td><?= actor_audit_html($candidate['action']) ?></td>
            <td><?= actor_audit_html($candidate['at']) ?></td><td><?= actor_audit_html($candidate['displayed']) ?></td><td><?= actor_audit_html($candidate['employee']) ?></td>
            <td><?= actor_audit_html($candidate['evidence']) ?></td><td><?= actor_audit_html($candidate['confidence']) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
