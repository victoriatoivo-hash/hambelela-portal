<?php

declare(strict_types=1);

function normalise_portal_role(string $role): string
{
    $value = strtolower(trim($role));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim((string) $value, '_');
}

function portal_feature_permissions(): array
{
    $employeeModules = [
        'dashboard', 'packing_list', 'courier', 'hr', 'orders', 'task_management',
        'bookkeeping', 'cash_tools', 'notifications', 'system_issues',
    ];

    return [
        'owner_admin' => [
            'dashboard', 'orders', 'bookkeeping', 'cash_tools', 'packing_list',
            'inventory', 'pos_reports', 'kpi_dashboard', 'task_management',
            'error_log', 'settings', 'notifications', 'courier', 'hr',
            'operations', 'barcode', 'system_issues',
        ],
        'front_desk_admin' => [...$employeeModules, 'error_log'],
        'front_desk_admin_employee' => [...$employeeModules, 'error_log'],
        'packer' => $employeeModules,
        'packer_production_staff' => $employeeModules,
        'supervisor_manager' => $employeeModules,
    ];
}

function portal_role_capabilities(): array
{
    return [
        'owner_admin' => ['manage_hr'],
    ];
}

function portal_role_has_capability(string $roleKey, string $capability): bool
{
    $capabilities = portal_role_capabilities();
    $roleKey = normalise_portal_role($roleKey);

    return isset($capabilities[$roleKey]) && in_array($capability, $capabilities[$roleKey], true);
}

function current_user_has_capability(string $capability): bool
{
    return portal_role_has_capability(current_role_key(), $capability);
}

function portal_role_can_access_feature(string $roleKey, string $featureKey): bool
{
    $permissions = portal_feature_permissions();
    $roleKey = normalise_portal_role($roleKey);

    // Unknown authenticated staff roles receive the standard employee modules.
    // Error Log remains explicitly restricted to front desk and owner/admin.
    $employeeModules = [
        'dashboard', 'packing_list', 'courier', 'hr', 'orders', 'task_management',
        'bookkeeping', 'cash_tools', 'notifications', 'system_issues',
    ];
    if ($roleKey !== 'guest' && $roleKey !== 'owner_admin' && in_array($featureKey, $employeeModules, true)) {
        return true;
    }

    return isset($permissions[$roleKey]) && in_array($featureKey, $permissions[$roleKey], true);
}

function is_employee_session(): bool
{
    return !in_array(normalise_portal_role(current_role_key()), ['owner_admin', 'guest'], true);
}

function employee_feature_for_request(string $scriptName): ?array
{
    $path = strtolower(str_replace('\\', '/', $scriptName));
    $routes = [
        '/notifications.php' => ['notifications', 'Notifications'],
        '/apps/operations/orders-board.php' => ['orders', 'Orders'],
        '/apps/operations/orders.php' => ['inventory', 'Inventory'],
        '/apps/operations/checklists.php' => ['task_management', 'Task Management'],
        '/apps/operations/bookkeeping.php' => ['bookkeeping', 'Bookkeeping'],
        '/apps/operations/bank-statement-processor.php' => ['cash_tools', 'Cash Tools'],
        '/apps/operations/consignments.php' => ['packing_list', 'Packing List'],
        '/apps/operations/courier.php' => ['courier', 'Courier Waybills'],
        '/apps/operations/errors.php' => ['error_log', 'Error Log'],
        '/apps/operations/error-limited-edit.php' => ['error_log', 'Error Log'],
        '/apps/operations/system-issues.php' => ['system_issues', 'System Issues Log'],
        '/apps/operations/system-issue-brief-copy.php' => ['system_issues', 'System Issues Log'],
        '/apps/operations/system-issue-owner-recommendation.php' => ['system_issues', 'System Issues Log'],
        '/apps/operations/system-issue-attachment.php' => ['system_issues', 'System Issues Log'],
        '/apps/operations/reports.php' => ['kpi_dashboard', 'Employee Performance'],
        '/apps/operations/my-account.php' => ['settings', 'Settings'],
        '/apps/operations/index.php' => ['operations', 'Operations'],
        '/apps/operations/barcode.php' => ['barcode', 'Barcode Verification'],
        '/apps/hr-portal/portal-login.php' => ['hr', 'HR Portal'],
        '/apps/hr-portal/index.php' => ['hr', 'HR Portal'],
    ];

    foreach ($routes as $suffix => $feature) {
        if ($suffix !== '' && substr($path, -strlen($suffix)) === $suffix) {
            return $feature;
        }
    }
    return null;
}

function render_employee_coming_soon_page(string $moduleName): void
{
    $packingUrl = htmlspecialchars(BASE_URL . '/apps/operations/consignments.php', ENT_QUOTES, 'UTF-8');
    $logoutUrl = htmlspecialchars(BASE_URL . '/login.php?action=logout', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8');
    http_response_code(200);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.' - Coming soon</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#fbfaf9;color:#1a1a1a;font-family:Figtree,Arial,sans-serif}.soon-card{width:min(520px,100%);padding:30px;background:#fff;border:1px solid #ede3d8;border-radius:12px;box-shadow:0 18px 44px rgba(114,27,26,.09)}.eyebrow{margin:0 0 8px;color:#ab3619;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}h1{margin:0;color:#721b1a;font-size:24px}.badge{display:inline-flex;align-items:center;min-height:24px;margin:18px 0 10px;padding:0 9px;border-radius:999px;background:rgba(240,116,32,.08);color:#ab3619;font-size:10px;font-weight:600}.copy{margin:0;color:#6b4c3b;font-size:12px;line-height:1.55}.actions{display:flex;gap:8px;margin-top:22px}.actions a{height:34px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(171,54,25,.25);border-radius:9px;color:#ab3619;font-size:11px;font-weight:600;text-decoration:none}.actions a:first-child{background:#ab3619;color:#fff;border-color:#ab3619}</style></head><body><main class="soon-card"><p class="eyebrow">Employee Workspace</p><h1>'.$title.'</h1><span class="badge">Coming soon</span><p class="copy">This section is not yet available for employee accounts.<br>Packing List, Courier Waybills and HR Portal are currently available. Additional portal sections are coming soon.</p><div class="actions"><a href="'.$packingUrl.'">Open Packing List</a><a href="'.$logoutUrl.'">Logout</a></div></main></body></html>';
    exit;
}

function enforce_employee_feature_for_current_request(): void
{
    if (!is_employee_session()) {
        return;
    }
    $feature = employee_feature_for_request((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($feature !== null && !portal_role_can_access_feature(current_role_key(), $feature[0])) {
        render_employee_coming_soon_page($feature[1]);
    }
}
