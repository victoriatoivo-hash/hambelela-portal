<?php
/* Versioned HR policies and immutable employee acknowledgements. */

const HR_POLICY_ACK_TEXT_VERSION = '1.0';

function hrPolicyAcknowledgementText(): string {
    return "EMPLOYEE ACKNOWLEDGEMENT\n\n"
        . "I confirm that I have received access to the Hambelela Organic Employee Handbook & HR Policy, Version 1.0.\n\n"
        . "I confirm that I have read the policy and have been given an opportunity to ask questions about matters I do not understand.\n\n"
        . "I understand the standards, procedures and responsibilities applicable to my employment and agree to comply with lawful company policies and reasonable lawful instructions.\n\n"
        . "I understand that this handbook must be read together with my employment contract and applicable Namibian law.\n\n"
        . "I understand that this acknowledgement does not waive, reduce or remove any right or protection granted to me by applicable law.\n\n"
        . "I understand that Hambelela Organic may lawfully amend its policies from time to time and that material amendments may require me to read and acknowledge a new version.";
}

function hrPolicyEnsureSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policies (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        policy_type VARCHAR(30) NOT NULL DEFAULT 'company_policy',
        current_version_id INT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_versions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        policy_id INT UNSIGNED NOT NULL,
        version_number VARCHAR(30) NOT NULL,
        title VARCHAR(190) NOT NULL,
        created_date DATE NOT NULL,
        effective_date DATE NOT NULL,
        acknowledgement_deadline DATE NULL,
        next_review VARCHAR(80) NULL,
        file_path VARCHAR(500) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        document_hash CHAR(64) NOT NULL,
        acknowledgement_required TINYINT(1) NOT NULL DEFAULT 0,
        acknowledgement_text_version VARCHAR(30) NOT NULL DEFAULT '1.0',
        changes_summary TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        published_by INT UNSIGNED NULL,
        published_at DATETIME NULL,
        superseded_at DATETIME NULL,
        UNIQUE KEY policy_version (policy_id, version_number),
        KEY policy_status (policy_id, status),
        KEY effective_date (effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_acknowledgements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        policy_id INT UNSIGNED NOT NULL,
        version_id INT UNSIGNED NOT NULL,
        employee_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        legal_name VARCHAR(190) NULL,
        opened_at DATETIME NULL,
        reached_end_at DATETIME NULL,
        signed_at DATETIME NULL,
        acknowledged_at DATETIME NULL,
        acknowledgement_text MEDIUMTEXT NULL,
        acknowledgement_text_version VARCHAR(30) NULL,
        signature_method VARCHAR(20) NULL,
        signature_data MEDIUMTEXT NULL,
        document_hash CHAR(64) NULL,
        evidence_metadata TEXT NULL,
        acknowledgement_reference VARCHAR(80) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'opened',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY employee_version (employee_id, version_id),
        UNIQUE KEY acknowledgement_reference (acknowledgement_reference),
        KEY version_status (version_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NULL,
        actor_employee_id INT UNSIGNED NULL,
        policy_id INT UNSIGNED NULL,
        version_id INT UNSIGNED NULL,
        subject_employee_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        details TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY policy_event (policy_id, version_id, created_at),
        KEY employee_event (subject_employee_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hr_policy_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        notification_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY version_user (version_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hrPolicyCsrf(): string {
    if (empty($_SESSION['hr_policy_csrf'])) $_SESSION['hr_policy_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['hr_policy_csrf'];
}

function hrPolicyVerifyCsrf(string $token): void {
    if (!hash_equals(hrPolicyCsrf(), $token)) throw new RuntimeException('Your session token expired. Refresh the page and try again.');
}

function hrPolicyPrivateDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'policies';
}

function hrPolicyAudit(PDO $db, string $action, ?int $policyId, ?int $versionId, ?int $employeeId, array $details = array()): void {
    $u = currentUser();
    $stmt = $db->prepare("INSERT INTO hr_policy_audit (actor_user_id,actor_employee_id,policy_id,version_id,subject_employee_id,action,details) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute(array($u ? (int)$u['id'] : null, $u && !empty($u['emp_id']) ? (int)$u['emp_id'] : null, $policyId, $versionId, $employeeId, $action, json_encode($details)));
}

function hrPolicyEmployeeId(array $user): int {
    return !empty($user['emp_id']) ? (int)$user['emp_id'] : 0;
}

function hrPolicyLegalName(PDO $db, int $employeeId): string {
    $s = $db->prepare("SELECT TRIM(CONCAT(first_name,' ',last_name)) FROM employees WHERE id=? LIMIT 1");
    $s->execute(array($employeeId));
    return trim((string)$s->fetchColumn());
}

function hrPolicyVersion(PDO $db, int $id): ?array {
    $s=$db->prepare("SELECT v.*,p.policy_type,p.current_version_id FROM hr_policy_versions v JOIN hr_policies p ON p.id=v.policy_id WHERE v.id=?");
    $s->execute(array($id)); $row=$s->fetch(PDO::FETCH_ASSOC); return $row ?: null;
}

function hrPolicyDisplayStatus(array $v): string {
    if ($v['status']==='draft') return 'Draft';
    if ($v['status']==='superseded') return 'Superseded';
    if ($v['status']==='archived') return 'Archived';
    return date('Y-m-d') < $v['effective_date'] ? 'Published — Effective ' . date('j F Y', strtotime($v['effective_date'])) : 'Current — In Force';
}

function hrPolicyPending(PDO $db, int $employeeId): array {
    $s=$db->prepare("SELECT v.*,p.title AS policy_title,a.opened_at,a.signed_at
      FROM hr_policy_versions v JOIN hr_policies p ON p.id=v.policy_id
      LEFT JOIN hr_policy_acknowledgements a ON a.version_id=v.id AND a.employee_id=?
      WHERE v.status='published' AND v.acknowledgement_required=1 AND a.signed_at IS NULL
      ORDER BY v.acknowledgement_deadline IS NULL,v.acknowledgement_deadline,v.id");
    $s->execute(array($employeeId)); return $s->fetchAll(PDO::FETCH_ASSOC);
}

function hrPolicyAckStatus(array $version, ?array $ack): string {
    if ($ack && !empty($ack['signed_at'])) return 'Signed & Acknowledged';
    if (!empty($version['acknowledgement_deadline']) && date('Y-m-d') > $version['acknowledgement_deadline']) return 'Overdue';
    if ($ack && !empty($ack['opened_at'])) return 'Opened — Signature Pending';
    return 'Not Opened';
}
