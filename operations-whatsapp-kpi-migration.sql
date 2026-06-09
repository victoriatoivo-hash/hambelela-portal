CREATE TABLE IF NOT EXISTS ops_whatsapp_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platform ENUM('whatsapp', 'instagram', 'facebook') NOT NULL DEFAULT 'whatsapp',
  external_id VARCHAR(120) NULL,
  wa_id VARCHAR(80) NOT NULL UNIQUE,
  display_name VARCHAR(190),
  phone_number VARCHAR(80),
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NULL,
  platform ENUM('whatsapp', 'instagram', 'facebook') NOT NULL DEFAULT 'whatsapp',
  customer_name VARCHAR(190) NOT NULL,
  customer_phone VARCHAR(80),
  source ENUM('manual', 'whatsapp_business', 'meta_import', 'csv_import') NOT NULL DEFAULT 'manual',
  status ENUM('awaiting_response', 'follow_up', 'waiting_customer', 'waiting_stock', 'pending_payment', 'pending_courier', 'resolved', 'escalated', 'abandoned') NOT NULL DEFAULT 'awaiting_response',
  assigned_employee_id INT NULL,
  first_customer_message_at DATETIME NULL,
  first_response_at DATETIME NULL,
  last_customer_message_at DATETIME NULL,
  last_staff_response_at DATETIME NULL,
  last_message_at DATETIME NULL,
  follow_up_at DATETIME NULL,
  order_id INT NULL,
  converted_to_sale TINYINT(1) NOT NULL DEFAULT 0,
  sale_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  complaint_flag TINYINT(1) NOT NULL DEFAULT 0,
  flagged_reason VARCHAR(255),
  faq_topic VARCHAR(190),
  phone_number_id VARCHAR(80),
  wa_business_account_id VARCHAR(80),
  meta_conversation_id VARCHAR(120),
  notes TEXT,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_wa_status (status),
  INDEX idx_wa_follow_up (follow_up_at),
  INDEX idx_wa_created (created_at),
  INDEX idx_wa_customer_phone (customer_phone),
  FOREIGN KEY (contact_id) REFERENCES ops_whatsapp_contacts(id),
  FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id),
  FOREIGN KEY (order_id) REFERENCES ops_orders(id),
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  contact_id INT NULL,
  platform ENUM('whatsapp', 'instagram', 'facebook') NOT NULL DEFAULT 'whatsapp',
  wa_message_id VARCHAR(120) NULL UNIQUE,
  direction ENUM('inbound', 'outbound') NOT NULL,
  message_type VARCHAR(40) NOT NULL DEFAULT 'text',
  message_text TEXT NULL,
  message_at DATETIME NOT NULL,
  status VARCHAR(40),
  status_at DATETIME NULL,
  employee_id INT NULL,
  raw_payload JSON NULL,
  external_message_id VARCHAR(120),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wa_msg_at (message_at),
  INDEX idx_wa_msg_direction (direction),
  FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES ops_whatsapp_contacts(id),
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tag_key VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'slate',
  active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_conversation_tags (
  conversation_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (conversation_id, tag_id),
  FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES ops_whatsapp_tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  due_at DATETIME NOT NULL,
  reason VARCHAR(255),
  status ENUM('pending', 'done', 'missed') NOT NULL DEFAULT 'pending',
  completed_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_flagged_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  message_id INT NULL,
  flag_type VARCHAR(80) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  severity ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  resolved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES ops_whatsapp_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (message_id) REFERENCES ops_whatsapp_messages(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ops_whatsapp_webhook_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platform ENUM('whatsapp', 'instagram', 'facebook', 'meta') NOT NULL DEFAULT 'whatsapp',
  event_type VARCHAR(80) NOT NULL,
  phone_number_id VARCHAR(80),
  wa_message_id VARCHAR(120),
  payload JSON NULL,
  processed_status ENUM('received', 'processed', 'failed') NOT NULL DEFAULT 'received',
  error_message TEXT NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO ops_whatsapp_tags (tag_key, name, color) VALUES
('sale_completed', 'Sale Completed', 'green'),
('follow_up_required', 'Follow-up Required', 'amber'),
('complaint', 'Complaint', 'red'),
('waiting_for_stock', 'Waiting for Stock', 'purple'),
('courier_inquiry', 'Courier Inquiry', 'blue'),
('customer_undecided', 'Customer Undecided', 'slate'),
('payment_pending', 'Payment Pending', 'orange'),
('escalated', 'Escalated', 'red'),
('resolved', 'Resolved', 'green')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  color = VALUES(color),
  active = 1;
