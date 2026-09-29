<?php

function hrOvertimeColumnExists(PDO $db, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='overtime' AND COLUMN_NAME=?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetchColumn();
}

function hrEnsureOvertimeReviewSchema(PDO $db): void {
    static $done = false;
    if ($done) return;

    $columns = [
        'approved_start_time' => "TIME NULL AFTER end_time",
        'approved_end_time' => "TIME NULL AFTER approved_start_time",
        'approved_hours' => "DECIMAL(5,2) NULL AFTER hours",
        'approved_amount' => "DECIMAL(10,2) NULL AFTER amount",
        'review_outcome' => "VARCHAR(32) NULL AFTER status",
        'adjustment_reason' => "TEXT NULL AFTER notes",
        'payroll_run_id' => "INT UNSIGNED NULL AFTER approved_at",
        'payroll_processed_at' => "TIMESTAMP NULL AFTER payroll_run_id",
    ];
    foreach ($columns as $name => $definition) {
        if (!hrOvertimeColumnExists($db, $name)) {
            $db->exec("ALTER TABLE overtime ADD COLUMN {$name} {$definition}");
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS overtime_review_audit (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        overtime_id INT UNSIGNED NOT NULL,
        action VARCHAR(40) NOT NULL,
        old_values_json LONGTEXT NULL,
        new_values_json LONGTEXT NULL,
        reason TEXT NULL,
        performed_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ot_review_audit_overtime (overtime_id, created_at),
        INDEX idx_ot_review_audit_actor (performed_by),
        CONSTRAINT fk_ot_review_audit_overtime FOREIGN KEY (overtime_id) REFERENCES overtime(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("UPDATE overtime SET
        approved_start_time=COALESCE(approved_start_time,start_time),
        approved_end_time=COALESCE(approved_end_time,end_time),
        approved_hours=COALESCE(approved_hours,hours),
        approved_amount=COALESCE(approved_amount,amount),
        review_outcome=COALESCE(review_outcome,'approved_as_submitted')
        WHERE status='approved' AND (approved_start_time IS NULL OR approved_end_time IS NULL OR approved_hours IS NULL OR approved_amount IS NULL OR review_outcome IS NULL)");
    $done = true;
}

function hrOvertimeDuration(string $date, string $start, string $end): float {
    $startAt = strtotime($date . ' ' . $start);
    $endAt = strtotime($date . ' ' . $end);
    if ($startAt === false || $endAt === false) throw new RuntimeException('Enter a valid overtime time range.');
    if ($endAt <= $startAt) $endAt += 86400;
    $hours = round(($endAt - $startAt) / 3600, 2);
    if ($hours <= 0 || $hours > 24) throw new RuntimeException('Approved overtime must be greater than 0 hours and no more than 24 hours.');
    return $hours;
}

function hrOvertimeSnapshot(array $row): array {
    $keys = ['status','approved_start_time','approved_end_time','approved_hours','approved_amount','review_outcome','adjustment_reason','approved_by','approved_at','payroll_run_id','payroll_processed_at'];
    $snapshot = [];
    foreach ($keys as $key) $snapshot[$key] = $row[$key] ?? null;
    return $snapshot;
}

function hrLogOvertimeReview(PDO $db, int $overtimeId, string $action, array $old, array $new, ?string $reason, int $actor): void {
    $stmt = $db->prepare("INSERT INTO overtime_review_audit (overtime_id,action,old_values_json,new_values_json,reason,performed_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$overtimeId, $action, json_encode($old), json_encode($new), $reason, $actor]);
}

function hrOvertimeDisplayHours($hours): string {
    if ($hours === null || $hours === '') return '—';
    $minutes = (int)round((float)$hours * 60);
    return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
}
