ALTER TABLE ops_orders
  ADD COLUMN assigned_at DATETIME NULL AFTER assigned_packer_id,
  ADD COLUMN packing_started_at DATETIME NULL AFTER workload_score;

CREATE TABLE IF NOT EXISTS ops_board_presence (
  employee_id INT PRIMARY KEY,
  page VARCHAR(120) NOT NULL DEFAULT 'orders_board',
  last_seen_at DATETIME NOT NULL,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);
