<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
/** Explicit release migration; never invoked by page views or device requests. */
final class TrackingSchema
{
    public static function install(\PDO $db): void
    {
        foreach([
            "CREATE TABLE IF NOT EXISTS delivery_tracking_devices (
                id CHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL, bike VARCHAR(100) NOT NULL,
                employee_id BIGINT NOT NULL, active TINYINT NOT NULL DEFAULT 1, ready TINYINT NOT NULL DEFAULT 0,
                token_hash CHAR(64) NULL, pairing_hash CHAR(64) NULL, pairing_expires BIGINT NULL,
                settings_json TEXT NOT NULL, assigned_at BIGINT NOT NULL,
                last_contact BIGINT NULL, last_position BIGINT NULL, gps_available TINYINT NOT NULL DEFAULT 0,
                latitude DOUBLE NULL, longitude DOUBLE NULL, accuracy DOUBLE NULL,
                battery INT NULL, app_version VARCHAR(40) NULL, created_by BIGINT NOT NULL,
                INDEX driver_devices(employee_id,active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS delivery_tracking_points (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id CHAR(32) NOT NULL,
                event_id CHAR(36) NOT NULL, employee_id BIGINT NOT NULL, delivery_id BIGINT NULL,
                recorded_at BIGINT NOT NULL, received_at BIGINT NOT NULL,
                latitude DOUBLE NOT NULL, longitude DOUBLE NOT NULL, accuracy DOUBLE NOT NULL,
                UNIQUE KEY device_event(device_id,event_id), INDEX history(device_id,recorded_at),
                INDEX delivery_history(delivery_id,recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS delivery_tracking_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id CHAR(32) NULL,
                actor_id BIGINT NOT NULL, event_type VARCHAR(50) NOT NULL, metadata_json TEXT NOT NULL,
                recorded_at BIGINT NOT NULL, INDEX device_events(device_id,recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS delivery_tracking_routes (
                delivery_id BIGINT PRIMARY KEY, address_hash CHAR(64) NOT NULL,
                destination_lat DOUBLE NULL, destination_lon DOUBLE NULL, geocoded_at BIGINT NULL,
                calculated_at BIGINT NULL, origin_at BIGINT NULL, distance_metres INT NULL,
                duration_seconds INT NULL, expected_arrival BIGINT NULL, polyline MEDIUMTEXT NULL,
                error_code VARCHAR(80) NULL, route_lock_until BIGINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS delivery_tracking_alerts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id CHAR(32) NOT NULL,
                delivery_id BIGINT NULL, alert_key CHAR(64) NOT NULL, kind VARCHAR(40) NOT NULL,
                detail_json TEXT NOT NULL, created_at BIGINT NOT NULL, reviewed_by BIGINT NULL,
                reviewed_at BIGINT NULL, review_note VARCHAR(1000) NULL,
                UNIQUE KEY alert_once(alert_key), INDEX device_alerts(device_id,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS delivery_tracking_api_usage (
                usage_day DATE NOT NULL, api_name VARCHAR(20) NOT NULL, requests INT NOT NULL DEFAULT 0,
                PRIMARY KEY(usage_day,api_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ] as $sql)$db->exec($sql);
    }
}
