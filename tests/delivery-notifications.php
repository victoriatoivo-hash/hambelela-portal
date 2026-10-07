<?php
declare(strict_types=1);require __DIR__.'/delivery-production-core.php';
$db->exec('CREATE TEMPORARY TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190),message TEXT,module VARCHAR(60),related_type VARCHAR(80),related_id BIGINT,priority VARCHAR(20),deduplication_key VARCHAR(190) UNIQUE,action_link VARCHAR(255),created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE notification_recipients(id INT AUTO_INCREMENT PRIMARY KEY,notification_id INT,employee_id INT,read_at DATETIME NULL,cleared_at DATETIME NULL,UNIQUE(notification_id,employee_id)) ENGINE=InnoDB');
// Isolate the outbox from prior synthetic suites, without deleting permanent rows.
$db->exec('CREATE TEMPORARY TABLE delivery_outbox(id INT AUTO_INCREMENT PRIMARY KEY,event_key VARCHAR(190) UNIQUE,delivery_id BIGINT,recipient_employee_id INT,payload_json TEXT,attempts INT DEFAULT 0,next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,delivered_at DATETIME NULL) ENGINE=InnoDB');
$db->beginTransaction();\Hambelela\Delivery\NotificationOutbox::queue($db,$jobId,$driverId,'synthetic-assignment',['kind'=>'assignment']);$db->commit();
ok(\Hambelela\Delivery\NotificationOutbox::drain($db)===1,'outbox delivers current assignment once');ok(\Hambelela\Delivery\NotificationOutbox::drain($db)===0,'outbox replay does not duplicate notification');
require __DIR__.'/../shared/delivery/DriverNotices.php';
$notices=\Hambelela\Delivery\DriverNotices::list($db,$driver);ok(count($notices)===1&&!$notices[0]['read_at'],'assigned Driver sees their unread notification');
$other=$driver;$other['id']=$driverId+987;ok(\Hambelela\Delivery\DriverNotices::list($db,$other)===[],'another Driver cannot read assignment notifications');
\Hambelela\Delivery\DriverNotices::read($db,$other,(int)$notices[0]['id']);ok(!\Hambelela\Delivery\DriverNotices::list($db,$driver)[0]['read_at'],'another Driver cannot mark this notification read');
\Hambelela\Delivery\DriverNotices::read($db,$driver,(int)$notices[0]['id']);ok((bool)\Hambelela\Delivery\DriverNotices::list($db,$driver)[0]['read_at'],'assigned Driver may acknowledge their notification');
$db->beginTransaction();\Hambelela\Delivery\NotificationOutbox::queue($db,$jobId,$driverId,'synthetic-revoked',['kind'=>'assignment']);$db->commit();$db->prepare("UPDATE ops_employees SET status='inactive' WHERE id=?")->execute([$driverId]);
ok(\Hambelela\Delivery\NotificationOutbox::drain($db)===0,'inactive recipient receives no assignment notification');
ok((int)$db->query('SELECT COUNT(*) FROM notification_recipients')->fetchColumn()===1,'only authorized notification recipient exists');
