<?php

declare(strict_types=1);

function kpi_performance_bootstrap(): void
{
    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_performance_reviews (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            period_key VARCHAR(32) NOT NULL,
            automatic_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            manual_adjustment DECIMAL(5,2) NOT NULL DEFAULT 0,
            final_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            tier VARCHAR(40) NOT NULL DEFAULT 'No Bonus',
            review_status VARCHAR(40) NOT NULL DEFAULT 'Not Reviewed',
            bonus_status VARCHAR(40) NOT NULL DEFAULT 'Not Eligible',
            bonus_amount DECIMAL(12,2) NULL,
            adjustment_reason TEXT NULL,
            owner_note TEXT NULL,
            employee_note TEXT NULL,
            employee_acknowledged_at DATETIME NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            locked_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kpi_performance_review (employee_id, period_key),
            INDEX idx_kpi_performance_period (period_key, review_status, bonus_status),
            FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES ops_employees(id) ON DELETE SET NULL
        )"
    );
    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_performance_audit (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NULL,
            period_key VARCHAR(32) NOT NULL,
            action_key VARCHAR(80) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            reason TEXT NULL,
            actor_employee_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kpi_audit_period (period_key, created_at),
            FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE SET NULL,
            FOREIGN KEY (actor_employee_id) REFERENCES ops_employees(id) ON DELETE SET NULL
        )"
    );

    if (kpi_setting('kpi_performance_schema_version', '0') !== '2') {
        foreach (kpi_default_weights() as $group => $weights) {
            foreach ($weights as $key => $weight) {
                foreach (['ops_kpi_role_weights', 'kpi_score_weights'] as $table) {
                    if (!ops_table_exists($table)) {
                        continue;
                    }
                    db()->prepare(
                        "INSERT INTO {$table} (role_group, component_key, component_label, weight_percent, active)
                         VALUES (?, ?, ?, ?, 1)
                         ON DUPLICATE KEY UPDATE component_label = VALUES(component_label), weight_percent = VALUES(weight_percent), active = 1"
                    )->execute([$group, $key, $weight['label'], $weight['weight']]);
                }
            }
        }
        kpi_save_setting('kpi_performance_schema_version', '2');
    }
}

function kpi_performance_reviews(string $period): array
{
    if (!ops_table_exists('kpi_performance_reviews')) {
        return [];
    }
    $map = [];
    foreach (ops_rows('SELECT * FROM kpi_performance_reviews WHERE period_key = ?', [$period]) as $row) {
        $map[(int) $row['employee_id']] = $row;
    }
    return $map;
}

function kpi_performance_sync(string $period, array $scores): void
{
    if (!ops_table_exists('kpi_performance_reviews')) {
        return;
    }
    $stmt = db()->prepare(
        "INSERT INTO kpi_performance_reviews
            (employee_id, period_key, automatic_score, final_score, tier, review_status, bonus_status, bonus_amount)
         VALUES (?, ?, ?, ?, ?, 'Not Reviewed', ?, ?)
         ON DUPLICATE KEY UPDATE
            automatic_score = VALUES(automatic_score),
            final_score = LEAST(100, GREATEST(0, VALUES(automatic_score) + manual_adjustment)),
            updated_at = CURRENT_TIMESTAMP"
    );
    foreach ($scores as $score) {
        $eligible = (float) $score['score'] >= 75;
        $stmt->execute([
            (int) $score['employee_id'],
            $period,
            (float) $score['score'],
            (float) $score['score'],
            (string) $score['tier']['label'],
            $eligible ? 'Eligible' : 'Not Eligible',
            $eligible ? (float) $score['bonus_amount'] : null,
        ]);
    }
}

function kpi_performance_audit(?int $employeeId, string $period, string $action, $oldValue, $newValue, string $reason = ''): void
{
    if (!ops_table_exists('kpi_performance_audit')) {
        return;
    }
    db()->prepare(
        'INSERT INTO kpi_performance_audit (employee_id, period_key, action_key, old_value, new_value, reason, actor_employee_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $employeeId,
        $period,
        $action,
        is_scalar($oldValue) ? (string) $oldValue : json_encode($oldValue),
        is_scalar($newValue) ? (string) $newValue : json_encode($newValue),
        $reason,
        ops_current_employee_id(),
    ]);
}

function kpi_performance_tier(float $score): array
{
    return kpi_tier(max(0, min(100, $score)));
}

function kpi_performance_apply_reviews(array $scores, array $reviews): array
{
    foreach ($scores as &$score) {
        $review = $reviews[(int) $score['employee_id']] ?? [];
        $adjustment = max(-10, min(10, (float) ($review['manual_adjustment'] ?? 0)));
        $final = max(0, min(100, (float) $score['score'] + $adjustment));
        $tier = kpi_performance_tier($final);
        $score['automatic_score'] = (float) $score['score'];
        $score['manual_adjustment'] = $adjustment;
        $score['score'] = $final;
        $score['tier'] = $tier;
        $score['review'] = $review;
        $score['review_status'] = (string) ($review['review_status'] ?? 'Not Reviewed');
        $score['bonus_status'] = (string) ($review['bonus_status'] ?? ($final >= 75 ? 'Eligible' : 'Not Eligible'));
        $score['bonus_amount'] = isset($review['bonus_amount']) ? (float) $review['bonus_amount'] : round((float) $score['max_bonus'] * (float) $tier['bonus_multiplier'], 2);
    }
    unset($score);
    usort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    foreach ($scores as $index => &$score) {
        $score['rank'] = $index + 1;
    }
    unset($score);
    return $scores;
}

function kpi_performance_history(int $employeeId, string $period): array
{
    if (!ops_table_exists('kpi_performance_audit')) {
        return [];
    }
    return ops_rows(
        'SELECT a.*, e.full_name AS actor_name FROM kpi_performance_audit a LEFT JOIN ops_employees e ON e.id = a.actor_employee_id WHERE a.employee_id = ? AND a.period_key = ? ORDER BY a.created_at DESC LIMIT 30',
        [$employeeId, $period]
    );
}
