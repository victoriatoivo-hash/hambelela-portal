<?php
// LOCAL ONLY: no business fixture changes and no live configuration inclusion.
if(PHP_SAPI!=='cli')exit(1);
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(dirname(__DIR__).'/database/delivery/009-notification-access-proposed.sql'));
// The local lab did not contain this already-canonical portal table. Reuse its schema.
$db->exec("CREATE TABLE IF NOT EXISTS notification_preferences(employee_id INT PRIMARY KEY,desktop_enabled TINYINT(1) NOT NULL DEFAULT 1,sound_enabled TINYINT(1) NOT NULL DEFAULT 1,muted_when_unavailable TINYINT(1) NOT NULL DEFAULT 0,modules_json TEXT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Local notification audit and canonical preferences tables ready. No existing records changed.\n";
