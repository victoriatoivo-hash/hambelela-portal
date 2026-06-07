CREATE TABLE IF NOT EXISTS ops_employee_availability (
  employee_id INT PRIMARY KEY,
  availability_status ENUM('available', 'on_lunch', 'offline') NOT NULL DEFAULT 'available',
  unavailable_until DATETIME NULL,
  note VARCHAR(255),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);
