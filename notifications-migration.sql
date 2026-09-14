CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    module VARCHAR(60) NOT NULL DEFAULT 'system',
    related_type VARCHAR(80) NULL,
    related_id INT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    action_link VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_module_created (module, created_at),
    INDEX idx_notifications_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    employee_id INT NOT NULL,
    read_at DATETIME NULL,
    cleared_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notification_employee (notification_id, employee_id),
    INDEX idx_notification_employee_state (employee_id, cleared_at, read_at, created_at),
    CONSTRAINT fk_notification_recipient_notification
        FOREIGN KEY (notification_id) REFERENCES notifications(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_preferences (
    employee_id INT PRIMARY KEY,
    desktop_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
    muted_when_unavailable TINYINT(1) NOT NULL DEFAULT 0,
    modules_json TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
