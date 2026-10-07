<?php
declare(strict_types=1);

/** Shared read-only bridge prerequisites and owner-authorized provisioning. */
function hr_access_rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $rows;
}

function hr_access_identity(string $value): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
}

function hr_access_result(string $state, string $detail, array $profile = [], array $account = []): array
{
    $labels = ['ready'=>'Ready', 'not_linked'=>'Not linked', 'account_missing'=>'Account missing',
        'account_inactive'=>'Account inactive', 'profile_inactive'=>'Profile inactive',
        'conflict'=>'Conflict', 'unavailable'=>'Check unavailable'];
    return ['state'=>$state, 'label'=>$labels[$state], 'detail'=>$detail,
        'profile'=>$profile, 'account'=>$account];
}

function hr_access_health(PDO $portal, ?PDO $hr, int $portalId, ?int $selectedHrId = null): array
{
    try {
        $people = hr_access_rows($portal, "SELECT e.id,e.full_name,e.email,e.status,r.role_key
            FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?", [$portalId]);
        $person = $people[0] ?? null;
        if (!$person || $person['status'] !== 'active' || $person['role_key'] === 'owner_admin') {
            return hr_access_result('conflict', 'An active employee Business Portal account is required.');
        }
        $links = hr_access_rows($portal, 'SELECT hr_employee_id,active FROM employee_user_links WHERE portal_user_id=?', [$portalId]);
        $link = $links[0] ?? null;
        $linkedId = $link && (int) $link['active'] === 1 ? (int) $link['hr_employee_id'] : 0;
        $hrId = $selectedHrId ?? $linkedId;
        if ($hrId < 1) { return hr_access_result('not_linked', 'No active HR profile link.'); }
        if ($selectedHrId !== null && $linkedId > 0 && $linkedId !== $selectedHrId) {
            return hr_access_result('conflict', 'An existing HR link points to another profile. Review it before relinking.');
        }
        $otherLinks = hr_access_rows($portal, "SELECT l.portal_user_id FROM employee_user_links l
            JOIN ops_employees e ON e.id=l.portal_user_id
            WHERE l.hr_employee_id=? AND l.active=1 AND e.status='active' AND l.portal_user_id<>?", [$hrId,$portalId]);
        if ($otherLinks) { return hr_access_result('conflict', 'This HR profile is linked to another active portal account.'); }
        if (!$hr) { return hr_access_result('unavailable', 'HR connection unavailable. No account changes made.'); }
        $profiles = hr_access_rows($hr, 'SELECT id,emp_number,first_name,last_name,email,status FROM employees WHERE id=?', [$hrId]);
        $profile = $profiles[0] ?? [];
        if (!$profile) { return hr_access_result('conflict', 'The linked HR employee profile was not found.'); }
        $name = trim($profile['first_name'] . ' ' . $profile['last_name']);
        $profile['full_name'] = $name;
        // Existing staff legitimately use different Business and HR email addresses.
        // Verify the person's name and use the HR profile's verified email for HR.
        if (hr_access_identity($person['full_name']) !== hr_access_identity($name)) {
            return hr_access_result('conflict', 'Portal and HR employee names differ. Review the identity mapping.', $profile);
        }
        if ($profile['status'] !== 'active') {
            return hr_access_result('profile_inactive', 'The HR employee profile is not active.', $profile);
        }
        $email = strtolower(trim((string) $profile['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return hr_access_result('conflict', 'The HR employee needs a valid verified email address.', $profile);
        }
        $accounts = hr_access_rows($hr, 'SELECT id,name,email,role,employee_id,active FROM users
            WHERE employee_id=? OR LOWER(TRIM(email))=? OR LOWER(TRIM(name))=LOWER(TRIM(?))', [$hrId,$email,$name]);
        foreach ($accounts as $account) {
            if ((int) $account['employee_id'] !== $hrId) {
                return hr_access_result('conflict', 'An HR account with this email or name belongs to another employee. Review required.', $profile);
            }
        }
        if (!$accounts) { return hr_access_result('account_missing', 'Linked HR profile; employee user account missing.', $profile); }
        if (count($accounts) !== 1) { return hr_access_result('conflict', 'Multiple HR user accounts match this profile. Review required.', $profile); }
        $account = $accounts[0];
        if ($account['role'] !== 'employee' || strtolower(trim($account['email'])) !== $email
            || hr_access_identity($account['name']) !== hr_access_identity($name)) {
            return hr_access_result('conflict', 'HR user role or identity does not match the employee profile. Review required.', $profile);
        }
        if ((int) $account['active'] !== 1) {
            return hr_access_result('account_inactive', 'HR employee user account inactive. Review the deactivation reason before enabling access.', $profile, $account);
        }
        return hr_access_result('ready', 'Linked profile and active employee HR account verified.', $profile, $account);
    } catch (Throwable $error) {
        error_log('HR access health check failed: ' . $error->getMessage());
        return hr_access_result('unavailable', 'HR access could not be verified. No account changes made.');
    }
}

function hr_access_setup(PDO $portal, PDO $hr, int $portalId, int $hrId, int $actorId, bool $saveLink = false, string $note = ''): array
{
    $actors = hr_access_rows($portal, "SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id
        WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'", [$actorId]);
    if (!$actors) { throw new RuntimeException('Only Owner/Admin can set up HR access.'); }
    if ($portal->inTransaction() || $hr->inTransaction()) { throw new RuntimeException('HR setup requires independent transactions.'); }
    $created = false;
    try {
        $portal->beginTransaction();
        $hr->beginTransaction();
        hr_access_rows($portal, 'SELECT id FROM ops_employees WHERE id=? FOR UPDATE', [$portalId]);
        hr_access_rows($hr, 'SELECT id FROM employees WHERE id=? FOR UPDATE', [$hrId]);
        $health = hr_access_health($portal, $hr, $portalId, $saveLink ? $hrId : null);
        if ((int) ($health['profile']['id'] ?? 0) !== $hrId
            || !in_array($health['state'], ['ready','account_missing'], true)) {
            throw new RuntimeException($health['detail']);
        }
        if ($health['state'] === 'account_missing') {
            // Random, unshared and immediately discarded: Business Portal SSO is the login path.
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            if (!$hash) { throw new RuntimeException('Secure account provisioning unavailable.'); }
            $stmt = $hr->prepare("INSERT INTO users (name,email,password,role,employee_id,active) VALUES (?,?,?,'employee',?,1)");
            $stmt->execute([$health['profile']['full_name'],strtolower(trim($health['profile']['email'])),$hash,$hrId]);
            $created = true;
        }
        if ($saveLink) {
            $stmt = $portal->prepare("INSERT INTO employee_user_links (portal_user_id,hr_employee_id,role,linked_by,active)
                VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE hr_employee_id=VALUES(hr_employee_id),
                role=VALUES(role),linked_by=VALUES(linked_by),active=1,linked_at=CURRENT_TIMESTAMP");
            $stmt->execute([$portalId,$hrId,$note,$actorId]);
        }
        $verified = hr_access_health($portal, $hr, $portalId);
        if ($verified['state'] !== 'ready') { throw new RuntimeException($verified['detail']); }
        // Two databases cannot share an atomic commit. Provision the safe HR user first;
        // a portal commit failure leaves an employee user, never a misleading ready link.
        $hr->commit();
        $portal->commit();
        $verified['created'] = $created;
        return $verified;
    } catch (Throwable $error) {
        if ($hr->inTransaction()) { $hr->rollBack(); }
        if ($portal->inTransaction()) { $portal->rollBack(); }
        throw $error;
    }
}
