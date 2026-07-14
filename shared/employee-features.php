<?php
declare(strict_types=1);

function employee_feature_availability(): array
{
    return ['packing_list'=>true,'orders'=>false,'tasks'=>false,'bookkeeping'=>false,'cash_tools'=>false,'courier'=>false,'inventory'=>false,'notifications'=>false,'error_log'=>false,'hr'=>false,'reports'=>false,'settings'=>false,'operations'=>false,'barcode'=>false];
}

function is_employee_session(): bool
{
    return !in_array(current_role_key(), ['owner_admin', 'guest'], true);
}

function employee_can_access_feature(string $featureKey, ?array $user = null): bool
{
    $roleKey = (string) (($user ?? current_user())['role_key'] ?? 'guest');
    return $roleKey === 'owner_admin' || (bool) (employee_feature_availability()[$featureKey] ?? false);
}

function employee_feature_for_request(string $scriptName): ?array
{
    $path = strtolower(str_replace('\\', '/', $scriptName));
    $routes = [
        '/notifications.php'=>['notifications','Notifications'], '/apps/operations/orders-board.php'=>['orders','Orders'],
        '/apps/operations/orders.php'=>['inventory','Inventory'], '/apps/operations/checklists.php'=>['tasks','Tasks'],
        '/apps/operations/bookkeeping.php'=>['bookkeeping','Bookkeeping'], '/apps/operations/bank-statement-processor.php'=>['cash_tools','Cash Tools'],
        '/apps/operations/courier.php'=>['courier','Courier'], '/apps/operations/errors.php'=>['error_log','Error Log'],
        '/apps/operations/reports.php'=>['reports','Reports'], '/apps/operations/my-account.php'=>['settings','Settings'],
        '/apps/operations/index.php'=>['operations','Operations'], '/apps/operations/barcode.php'=>['barcode','Barcode Verification'],
        '/apps/hr-portal/portal-login.php'=>['hr','HR Portal'], '/apps/hr-portal/index.php'=>['hr','HR Portal'],
    ];
    foreach ($routes as $suffix => $feature) {
        if (str_ends_with($path, $suffix)) return $feature;
    }
    return null;
}

function render_employee_coming_soon_page(string $moduleName): never
{
    $packingUrl = htmlspecialchars(BASE_URL . '/apps/operations/consignments.php', ENT_QUOTES, 'UTF-8');
    $homeUrl = htmlspecialchars(BASE_URL . '/index.php', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8');
    http_response_code(200);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.' - Coming soon</title><link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700&display=swap" rel="stylesheet"><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#fbfaf9;color:#1a1a1a;font-family:Figtree,system-ui,sans-serif}.soon-card{width:min(520px,100%);padding:30px;background:#fff;border:1px solid #ede3d8;border-radius:12px;box-shadow:0 18px 44px rgba(114,27,26,.09)}.eyebrow{margin:0 0 8px;color:#ab3619;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}h1{margin:0;color:#721b1a;font-size:24px}.badge{display:inline-flex;align-items:center;min-height:24px;margin:18px 0 10px;padding:0 9px;border-radius:999px;background:rgba(240,116,32,.08);color:#ab3619;font-size:10px;font-weight:600}.copy{margin:0;color:#6b4c3b;font-size:12px;line-height:1.55}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:22px}.actions a{height:34px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(171,54,25,.25);border-radius:9px;color:#ab3619;font-size:11px;font-weight:600;text-decoration:none}.actions a:first-child{background:#ab3619;color:#fff;border-color:#ab3619}</style></head><body><main class="soon-card"><p class="eyebrow">Employee Workspace</p><h1>'.$title.'</h1><span class="badge">Coming soon</span><p class="copy">This section is still being prepared for employee use.<br>Packing List is currently available.</p><div class="actions"><a href="'.$packingUrl.'">Open Packing List</a><a href="'.$homeUrl.'">Back to Employee Home</a></div></main></body></html>';
    exit;
}

function enforce_employee_feature_for_current_request(): void
{
    if (!is_employee_session()) return;
    $feature = employee_feature_for_request((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($feature !== null && !employee_can_access_feature($feature[0])) render_employee_coming_soon_page($feature[1]);
}
