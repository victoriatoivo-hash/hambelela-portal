<?php

declare(strict_types=1);

const PORTAL_WORKPLACE_AUDIT = 'audit';
const PORTAL_WORKPLACE_ENFORCED = 'enforced';
const PORTAL_WORKPLACE_DISABLED = 'disabled';

function portal_workplace_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS portal_workplace_settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, policy_mode VARCHAR(20) NOT NULL DEFAULT 'audit', updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS portal_workplace_networks (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, network_name VARCHAR(120) NOT NULL, ip_cidr VARCHAR(80) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, added_by INT NOT NULL, added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, first_seen_at DATETIME NULL, last_seen_at DATETIME NULL, UNIQUE KEY uq_workplace_network(ip_cidr), KEY idx_workplace_active(is_active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS portal_workplace_access_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, employee_name VARCHAR(160) NOT NULL, role_key VARCHAR(60) NOT NULL, source_ip VARCHAR(80) NULL, ip_source VARCHAR(30) NOT NULL, device_class VARCHAR(30) NOT NULL, user_agent VARCHAR(255) NULL, network_pass TINYINT(1) NOT NULL DEFAULT 0, device_pass TINYINT(1) NOT NULL DEFAULT 0, simulated_decision VARCHAR(20) NOT NULL, final_decision VARCHAR(20) NOT NULL, reason VARCHAR(120) NOT NULL, request_path VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_workplace_log_time(created_at), KEY idx_workplace_log_employee(employee_id,created_at), KEY idx_workplace_log_decision(final_decision,created_at), KEY idx_workplace_log_ip(source_ip,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("INSERT IGNORE INTO portal_workplace_settings (id, policy_mode) VALUES (1, 'audit')");
}

function portal_ip_in_cidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    $network = $parts[0];
    $ipBinary = @inet_pton($ip);
    $networkBinary = @inet_pton($network);
    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) return false;
    $maxBits = strlen($ipBinary) * 8;
    $prefix = isset($parts[1]) ? (int) $parts[1] : $maxBits;
    if ($prefix < 0 || $prefix > $maxBits) return false;
    $whole = intdiv($prefix, 8);
    $remaining = $prefix % 8;
    if ($whole > 0 && substr($ipBinary, 0, $whole) !== substr($networkBinary, 0, $whole)) return false;
    if ($remaining === 0) return true;
    $mask = (0xff << (8 - $remaining)) & 0xff;
    return (ord($ipBinary[$whole]) & $mask) === (ord($networkBinary[$whole]) & $mask);
}

function portal_cloudflare_proxy_ranges(): array
{
    return ['173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22','141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20','197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13','104.24.0.0/14','172.64.0.0/13','131.0.72.0/22','2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32','2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32'];
}

function portal_request_source_ip(): array
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $trustedProxy = false;
    if (filter_var($remote, FILTER_VALIDATE_IP)) {
        foreach (portal_cloudflare_proxy_ranges() as $range) {
            if (portal_ip_in_cidr($remote, $range)) { $trustedProxy = true; break; }
        }
    }
    $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($trustedProxy && $cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return ['ip' => $cf, 'source' => 'cloudflare'];
    return ['ip' => filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null, 'source' => $remote === '' ? 'unverified' : 'remote_addr'];
}

function portal_workplace_mode(): string
{
    portal_workplace_bootstrap();
    $mode = (string) db()->query('SELECT policy_mode FROM portal_workplace_settings WHERE id=1')->fetchColumn();
    return in_array($mode, [PORTAL_WORKPLACE_AUDIT, PORTAL_WORKPLACE_ENFORCED, PORTAL_WORKPLACE_DISABLED], true) ? $mode : PORTAL_WORKPLACE_AUDIT;
}

function portal_workplace_network_match(?string $ip): ?array
{
    if (!$ip) return null;
    $rows = db()->query('SELECT * FROM portal_workplace_networks WHERE is_active=1 ORDER BY id')->fetchAll();
    foreach ($rows as $row) if (portal_ip_in_cidr($ip, (string) $row['ip_cidr'])) return $row;
    return null;
}

function portal_workplace_evaluate(array $user): array
{
    $source = portal_request_source_ip();
    $mobile = portal_request_is_phone_or_tablet();
    $network = portal_workplace_network_match($source['ip']);
    $mode = portal_workplace_mode();
    $networkPass = $network !== null;
    $devicePass = !$mobile;
    $simulated = $networkPass && $devicePass ? 'allowed' : 'blocked';
    $final = $mode === PORTAL_WORKPLACE_ENFORCED ? $simulated : ($mobile ? 'blocked' : 'allowed');
    $reason = !$devicePass ? 'Mobile or tablet device' : (!$source['ip'] ? 'Unable to verify network' : (!$networkPass ? 'External network' : 'Approved office network'));
    return compact('mode', 'source', 'network', 'networkPass', 'devicePass', 'simulated', 'final', 'reason');
}

function portal_workplace_record(array $user, array $decision): void
{
    $stmt = db()->prepare('INSERT INTO portal_workplace_access_log (employee_id,employee_name,role_key,source_ip,ip_source,device_class,user_agent,network_pass,device_pass,simulated_decision,final_decision,reason,request_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([(int)($user['id']??0),(string)($user['name']??''),(string)($user['role_key']??''),$decision['source']['ip'],(string)$decision['source']['source'],$decision['devicePass']?'desktop':'phone_tablet',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),$decision['networkPass']?1:0,$decision['devicePass']?1:0,(string)$decision['simulated'],(string)$decision['final'],(string)$decision['reason'],substr((string)($_SERVER['REQUEST_URI']??''),0,255)]);
    if (!empty($decision['network']['id'])) db()->prepare('UPDATE portal_workplace_networks SET first_seen_at=COALESCE(first_seen_at,NOW()),last_seen_at=NOW() WHERE id=?')->execute([(int)$decision['network']['id']]);
}

function portal_render_workplace_required(string $reason): void
{
    portal_send_private_cache_headers();
    http_response_code(403);
    $logout = htmlspecialchars(BASE_URL . '/login.php?action=logout', ENT_QUOTES, 'UTF-8');
    $networkUnknown = $reason === 'Unable to verify network';
    $title = $networkUnknown ? 'Workplace Network Could Not Be Verified' : 'Workplace Access Required';
    $copy = $networkUnknown ? 'Your workplace network could not be verified. Please try again from your assigned work computer at the office.' : 'Your employee portal is only available from the Hambelela workplace network. Please use your assigned work computer at the office.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#721b1a"><title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').' | Hambelela Portal</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#fbf7f2;color:#2b1b16;font-family:Figtree,system-ui,sans-serif}.card{width:min(440px,100%);padding:30px 24px;border:1px solid #e8ddd3;border-radius:16px;background:#fff;text-align:center}.mark{display:grid;place-items:center;width:54px;height:54px;margin:0 auto 18px;border-radius:13px;background:#721b1a;color:#fff;font-size:24px;font-weight:800}.eyebrow{color:#ab3619;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em}h1{color:#721b1a;font-size:25px}p{color:#6b4c3b;line-height:1.6}a{display:inline-flex;min-height:44px;margin-top:20px;padding:0 20px;align-items:center;border-radius:10px;background:#721b1a;color:#fff;font-weight:700;text-decoration:none}</style></head><body><main class="card"><div class="mark">H</div><div class="eyebrow">Hambelela Organic</div><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($copy,ENT_QUOTES,'UTF-8').'</p><a href="'.$logout.'">Sign Out</a></main></body></html>';
    exit;
}

function portal_enforce_employee_workplace_access(array $user): void
{
    if ((string)($user['role_key']??'') === 'owner_admin') return;
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $decision = portal_workplace_evaluate($user);
        portal_workplace_record($user, $decision);
        if (!$decision['devicePass']) portal_render_employee_desktop_required();
        if ($decision['mode'] === PORTAL_WORKPLACE_ENFORCED && !$decision['networkPass']) portal_render_workplace_required((string)$decision['reason']);
    } catch (Throwable $e) {
        error_log('Employee workplace access check failed: '.$e->getMessage());
        try { if (portal_workplace_mode() === PORTAL_WORKPLACE_ENFORCED) portal_render_workplace_required('Unable to verify network'); } catch (Throwable $ignored) {}
    }
}
