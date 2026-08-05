-- Reversible data-preserving Error Log attribution amendment.
ALTER TABLE ops_error_logs
  ADD COLUMN attribution_type VARCHAR(30) NULL AFTER responsible_employee_id,
  ADD COLUMN attributed_employee_id INT NULL AFTER attribution_type,
  ADD COLUMN original_attribution_type VARCHAR(30) NULL AFTER attributed_employee_id,
  ADD COLUMN original_attributed_employee_id INT NULL AFTER original_attribution_type,
  ADD COLUMN attribution_verified_by INT NULL AFTER accuracy_verified_at,
  ADD COLUMN attribution_verified_at DATETIME NULL AFTER attribution_verified_by,
  ADD COLUMN logged_by_user_id INT NULL AFTER logged_by,
  ADD COLUMN has_financial_impact TINYINT(1) NULL AFTER financial_impact,
  ADD COLUMN financial_impact_notes TEXT NULL AFTER has_financial_impact;

-- Only records with an existing explicit employee responsibility are mapped.
-- Unattributed history and financial-impact history remain NULL for owner review.
UPDATE ops_error_logs
SET attribution_type='employee',
    attributed_employee_id=responsible_employee_id,
    original_attribution_type='employee',
    original_attributed_employee_id=responsible_employee_id
WHERE attribution_type IS NULL
  AND responsible_employee_id IS NOT NULL;

CREATE INDEX idx_error_attribution ON ops_error_logs(attribution_type,attributed_employee_id,logged_at);
CREATE INDEX idx_error_financial_impact ON ops_error_logs(has_financial_impact,logged_at);

-- Rollback (data-destructive for the new fields; take a backup first):
-- ALTER TABLE ops_error_logs DROP INDEX idx_error_attribution, DROP INDEX idx_error_financial_impact,
--   DROP COLUMN financial_impact_notes, DROP COLUMN has_financial_impact,
--   DROP COLUMN logged_by_user_id, DROP COLUMN attribution_verified_at,
--   DROP COLUMN attribution_verified_by, DROP COLUMN original_attributed_employee_id,
--   DROP COLUMN original_attribution_type, DROP COLUMN attributed_employee_id,
--   DROP COLUMN attribution_type;
