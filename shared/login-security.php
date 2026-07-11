<?php

declare(strict_types=1);

function access_secret_validation_error(string $secret, string $roleKey): ?string
{
    if ($roleKey === 'owner_admin') {
        if (strlen($secret) < 8 || !preg_match('/[A-Za-z]/', $secret) || !preg_match('/\d/', $secret)) {
            return 'Owner/Admin access requires at least 8 characters with letters and numbers.';
        }
        return null;
    }

    if (!preg_match('/^\d{6,10}$/', $secret)) {
        return 'Staff access codes must contain 6 to 10 digits.';
    }
    if (preg_match('/^(\d)\1+$/', $secret)) {
        return 'Repeated-digit access codes are not allowed.';
    }
    $ascending = '01234567890123456789';
    $descending = '98765432109876543210';
    if (str_contains($ascending, $secret) || str_contains($descending, $secret)) {
        return 'Sequential access codes are not allowed.';
    }

    return null;
}

function access_secret_is_shared(string $secret, int $excludeEmployeeId = 0): bool
{
    $stmt = db()->query("SELECT id, password_hash FROM ops_employees WHERE password_hash IS NOT NULL AND password_hash <> ''");
    foreach ($stmt->fetchAll() as $employee) {
        if ((int) $employee['id'] === $excludeEmployeeId) {
            continue;
        }
        if (password_verify($secret, (string) $employee['password_hash'])) {
            return true;
        }
    }
    return false;
}

function record_security_event(string $eventType, ?int $employeeId = null, array $metadata = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO ops_security_events (event_type, employee_id, ip_address, user_agent, metadata_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventType,
            $employeeId,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // Security logging must not expose secrets or break the requested action.
    }
}
