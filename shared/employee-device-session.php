<?php
declare(strict_types=1);

// This credential remembers the original verified sign-in, not an employee's presence.
const PORTAL_DEVICE_COOKIE = '__Host-portal_employee_device';
const PORTAL_DEVICE_LIFETIME = 90 * 86400;
const PORTAL_OFFLINE_SECONDS = 300;
const PORTAL_DASHBOARD_GAP = 1800;

function portal_device_key(): ?string
{
    $value = $_COOKIE[PORTAL_DEVICE_COOKIE] ?? '';
    return is_string($value) && preg_match('/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/D', $value) ? hash('sha256', $value) : null;
}

function portal_device_cookie(string $secret, int $expires): void
{
    setcookie(PORTAL_DEVICE_COOKIE, $secret, ['expires'=>$expires, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
}

function portal_device_forget(): void
{
    $key = portal_device_key();
    $selector = $key ? substr($_COOKIE[PORTAL_DEVICE_COOKIE],0,32) : ($_SESSION['portal_device_id'] ?? null);
    if (!$selector) return;
    try {
        db()->prepare('DELETE FROM portal_employee_devices WHERE device_id=?')->execute([$selector]);
    } finally {
        portal_device_cookie('', 1);
        unset($_COOKIE[PORTAL_DEVICE_COOKIE],$_SESSION['portal_device_id']);
    }
}

function portal_device_issue(int $employee, string $passwordHash): void
{
    if (!portal_request_is_https() || $employee < 1 || $passwordHash === '') return;
    portal_device_forget();
    try {
        portal_device_store($employee, $passwordHash);
    } catch (Throwable $error) {
        // A storage outage must not prevent an otherwise valid interactive login.
        error_log('Employee device registration unavailable; using the standard short session.');
    }
}

function portal_device_store(int $employee, string $passwordHash): void
{
    $db = db();
    $db->exec('CREATE TABLE IF NOT EXISTS portal_employee_devices (
        device_id CHAR(32) CHARACTER SET ascii PRIMARY KEY, token_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, employee_id INT NOT NULL,
        credential_version CHAR(64) CHARACTER SET ascii NOT NULL, issued_at BIGINT NOT NULL,
        expires_at BIGINT NOT NULL, last_contact BIGINT NOT NULL, INDEX(expires_at), INDEX(employee_id)
    ) ENGINE=InnoDB');
    $now = time();
    $expires = $now + PORTAL_DEVICE_LIFETIME;
    $selector = bin2hex(random_bytes(16));
    $secret = $selector.'.'.bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM portal_employee_devices WHERE expires_at<=?')->execute([$now]);
    $db->prepare('INSERT INTO portal_employee_devices (device_id,token_hash,employee_id,credential_version,issued_at,expires_at,last_contact) VALUES (?,?,?,?,?,?,?)')
       ->execute([$selector,hash('sha256',$secret),$employee,hash('sha256',$passwordHash),$now,$expires,$now]);
    portal_device_cookie($secret,$expires);
    $_SESSION['portal_last_contact'] = $now;
    $_SESSION['portal_device_until'] = $expires;
    $_SESSION['portal_device_id'] = $selector;
}

// Only restore at the normal login entry point, on a GET, never to replay a write.
function portal_device_restore(): bool
{
    if (!portal_request_is_https() || !($key = portal_device_key())) return false;
    $db = db();
    $q = $db->prepare("SELECT d.device_id,d.issued_at,d.expires_at,d.last_contact,d.credential_version,e.id,e.full_name,e.email,e.password_hash,
                 e.must_change_access_code,e.locked_until,r.role_key,r.name role_name
              FROM portal_employee_devices d JOIN ops_employees e ON e.id=d.employee_id
              JOIN ops_roles r ON r.id=e.role_id
              WHERE d.token_hash=? AND d.expires_at>? AND e.status='active'
              AND (e.locked_until IS NULL OR e.locked_until<=NOW())");
    $q->execute([$key,time()]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['must_change_access_code'] || !hash_equals($row['credential_version'],hash('sha256',(string)$row['password_hash']))) return false;
    $issued = date(DATE_ATOM,(int)$row['issued_at']);
    if (is_file(__DIR__.'/employee-account-editor.php')) {
        require_once __DIR__.'/employee-account-editor.php';
        if (employee_account_session_revoked($db,(int)$row['id'],$issued)) return false;
    } else {
        $s = $db->prepare("SELECT metadata_json FROM ops_security_events WHERE employee_id=? AND event_type='employee_sessions_revoked' ORDER BY id DESC LIMIT 1");
        $s->execute([(int)$row['id']]);
        $event = $s->fetchColumn();
        if ($event !== false && (int)$row['issued_at'] <= (int)(json_decode((string)$event,true)['revoked_before'] ?? PHP_INT_MAX)) return false;
    }
    $user = ['id'=>(int)$row['id'],'name'=>$row['full_name'],'email'=>$row['email'],
        'role'=>$row['role_name'],'role_key'=>$row['role_key'],'source'=>'database'];
    require_once __DIR__.'/workplace-access.php';
    portal_enforce_employee_workplace_access($user);
    $secret = $row['device_id'].'.'.bin2hex(random_bytes(32));
    $rotate = $db->prepare('UPDATE portal_employee_devices SET token_hash=? WHERE token_hash=? AND expires_at>?');
    $rotate->execute([hash('sha256',$secret),$key,time()]);
    if ($rotate->rowCount() !== 1) return false;
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) return false;
    if (!session_regenerate_id(true)) return false;
    $_SESSION = [];
    $_SESSION['user'] = $user;
    portal_initialize_authenticated_session($_SESSION['user']);
    $_SESSION['authenticated_at'] = $issued; // Preserve original time for administrator revocation.
    $_SESSION['portal_device_until'] = (int)$row['expires_at'];
    $_SESSION['portal_device_id'] = $row['device_id'];
    $_SESSION['portal_restored_device'] = true;
    $_SESSION['absolute_expires_at'] = min($_SESSION['absolute_expires_at'],$_SESSION['portal_device_until']);
    $_SESSION['portal_last_contact'] = time();
    $_SESSION['portal_dashboard_pending'] = time() - (int)$row['last_contact'] >= PORTAL_DASHBOARD_GAP;
    portal_device_cookie($secret,(int)$row['expires_at']);
    return true;
}

function portal_presence_dashboard_due(int $now): bool
{
    return isset($_SESSION['portal_last_contact']) && $now - (int)$_SESSION['portal_last_contact'] >= PORTAL_DASHBOARD_GAP;
}

function portal_presence_local_time(int $epoch): string
{
    return (new DateTimeImmutable('@'.$epoch))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s');
}

// Caller holds the employee's ops_board_presence row lock until commit.
function portal_record_browser_contact(PDO $db, int $employee, int $now): void
{
    $q = $db->prepare('SELECT id,last_seen FROM portal_employee_presence_intervals WHERE employee_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$employee]);
    $prior = $q->fetch(PDO::FETCH_ASSOC);
    if (!$prior || $now - (int)$prior['last_seen'] >= PORTAL_OFFLINE_SECONDS) {
        $db->prepare('INSERT INTO portal_employee_presence_intervals(employee_id,returned_at,last_seen) VALUES (?,?,?)')->execute([$employee,$now,$now]);
    } else {
        $db->prepare('UPDATE portal_employee_presence_intervals SET last_seen=? WHERE id=?')->execute([max($now,(int)$prior['last_seen']),$prior['id']]);
    }
}

// Reapply credential changes and explicit device revocation on a restored PHP session.
function portal_restored_device_valid(int $employee, int $now): bool
{
    if (empty($_SESSION['portal_restored_device'])) return true;
    try {
        require_once __DIR__.'/database.php';
        $q = db()->prepare("SELECT d.credential_version,e.password_hash FROM portal_employee_devices d
            JOIN ops_employees e ON e.id=d.employee_id WHERE d.device_id=? AND e.id=? AND d.expires_at>?
            AND e.status='active' AND e.must_change_access_code=0 AND (e.locked_until IS NULL OR e.locked_until<=NOW())");
        $q->execute([$_SESSION['portal_device_id'] ?? '',$employee,$now]);
        $r = $q->fetch();
        return $r && hash_equals($r['credential_version'],hash('sha256',(string)$r['password_hash']));
    } catch (Throwable $e) { return false; }
}
