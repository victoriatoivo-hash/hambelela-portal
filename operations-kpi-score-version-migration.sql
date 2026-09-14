CREATE TABLE IF NOT EXISTS kpi_weight_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_key VARCHAR(100) NOT NULL,
    role_key VARCHAR(80) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    components_json LONGTEXT NOT NULL,
    status ENUM('draft','approved','retired') NOT NULL DEFAULT 'draft',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    change_reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kpi_weight_version (version_key),
    KEY idx_kpi_weight_role_effective (role_key,effective_from,effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kpi_score_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    weight_version_id BIGINT UNSIGNED NOT NULL,
    calculation_version VARCHAR(80) NOT NULL,
    calculated_score DECIMAL(7,3) NULL,
    owner_adjustment DECIMAL(7,3) NULL,
    final_score DECIMAL(7,3) NULL,
    performance_band VARCHAR(30) NULL,
    evidence_confidence VARCHAR(40) NOT NULL,
    finalisation_status ENUM('provisional','final','reopened') NOT NULL DEFAULT 'provisional',
    components_json LONGTEXT NOT NULL,
    evidence_summary_json LONGTEXT NULL,
    finalised_by INT NULL,
    finalised_at DATETIME NULL,
    owner_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kpi_score_snapshot (employee_id,period_from,period_to,weight_version_id,finalisation_status),
    KEY idx_kpi_score_employee_period (employee_id,period_from,period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kpi_score_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_snapshot_id BIGINT UNSIGNED NOT NULL,
    action_key VARCHAR(80) NOT NULL,
    component_key VARCHAR(100) NULL,
    previous_score DECIMAL(7,3) NULL,
    new_score DECIMAL(7,3) NULL,
    evidence_event_id VARCHAR(190) NULL,
    reason_note TEXT NOT NULL,
    actor_employee_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_kpi_score_review_snapshot (score_snapshot_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
