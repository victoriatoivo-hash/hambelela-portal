<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$portalRoot = dirname(__DIR__);
require_once $portalRoot . '/config.php';
require_once $portalRoot . '/shared/database.php';
require_once $portalRoot . '/shared/login-security.php';
require_once $portalRoot . '/shared/temporary-access-codes.php';

try {
    $database = db();
    ensure_temporary_access_schema($database);

    $stmt = $database->prepare(
        "SELECT e.id, e.full_name, e.email, r.role_key
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active'
           AND r.role_key = 'owner_admin'
           AND LOWER(e.full_name) = LOWER(?)
           AND LOWER(e.email) = LOWER(?)"
    );
    $stmt->execute(['Victoria Toivo', 'victoria@hambelelaorganic.com']);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($matches) !== 1) {
        fwrite(STDERR, 'Recovery aborted: expected exactly one active Victoria Toivo Owner/Admin account; found ' . count($matches) . ".\n");
        exit(1);
    }

    $owner = $matches[0];
    $temporaryCode = (string) random_int(1000, 9999);
    $temporaryHash = password_hash($temporaryCode, PASSWORD_DEFAULT);
    if ($temporaryHash === false) {
        throw new RuntimeException('Unable to hash the temporary code.');
    }
    $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

    $database->beginTransaction();
    try {
        $update = $database->prepare(
            "UPDATE ops_employees
             SET temporary_code_hash = ?, temporary_code_expires_at = ?, temporary_code_used_at = NULL,
                 must_change_access_code = 1, failed_login_attempts = 0, locked_until = NULL,
                 last_failed_login_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'active'"
        );
        $update->execute([$temporaryHash, $expiresAt, (int) $owner['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Owner recovery update did not affect exactly one active account.');
        }

        $check = $database->prepare(
            'SELECT temporary_code_hash, temporary_code_expires_at, must_change_access_code
             FROM ops_employees WHERE id = ? LIMIT 1'
        );
        $check->execute([(int) $owner['id']]);
        $saved = $check->fetch(PDO::FETCH_ASSOC);
        if (
            !$saved
            || empty($saved['must_change_access_code'])
            || !password_verify($temporaryCode, (string) $saved['temporary_code_hash'])
            || (string) $saved['temporary_code_expires_at'] !== $expiresAt
        ) {
            throw new RuntimeException('The stored temporary-code hash could not be verified.');
        }

        $database->commit();
    } catch (Throwable $updateError) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $updateError;
    }

    record_security_event('owner_temporary_access_code_created', (int) $owner['id'], [
        'employee_name' => (string) $owner['full_name'],
        'expires_at' => $expiresAt,
        'source' => 'cli_recovery',
    ]);

    fwrite(STDOUT, "Owner temporary access code: {$temporaryCode}\n");
    fwrite(STDOUT, "Expires: {$expiresAt}\n");
    fwrite(STDOUT, "Use it once at /login.php, then create a new private code immediately.\n");

    if (!@unlink(__FILE__)) {
        fwrite(STDERR, "WARNING: recovery succeeded, but the CLI script could not delete itself. Delete it manually now.\n");
        exit(2);
    }
} catch (Throwable $error) {
    error_log('Owner temporary-code CLI recovery failed: ' . $error->getMessage());
    fwrite(STDERR, "Owner recovery failed. Check the server error log.\n");
    exit(1);
}

