-- Phase 07: additive driver proposals only. Existing delivery_errands remain canonical assignments.
-- Run explicitly after backup/schema review. Never executed by a web request.
CREATE TABLE IF NOT EXISTS delivery_errand_requests (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 requested_by INT NOT NULL,
 title VARCHAR(110) NOT NULL,
 reason VARCHAR(1500) NOT NULL,
 place VARCHAR(255) NOT NULL,
 urgent TINYINT(1) NOT NULL DEFAULT 0,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 revision INT NOT NULL DEFAULT 1,
 errand_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 KEY requester_history(requested_by,id), KEY dispatch_review(status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS delivery_errand_request_events (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 request_id BIGINT UNSIGNED NOT NULL,
 actor_id INT NOT NULL,
 action VARCHAR(30) NOT NULL,
 metadata_json MEDIUMTEXT NOT NULL,
 created_at DATETIME NOT NULL,
 KEY request_history(request_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
