<?php

function ensureSocialSecuritySchema(PDO $db): void {
    hrSocialSecurityAddColumnIfMissing($db, 'employees', 'social_security_number', "VARCHAR(50) NULL");
}

function hrSocialSecurityAddColumnIfMissing(PDO $db, string $table, string $column, string $definition): void {
    $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
