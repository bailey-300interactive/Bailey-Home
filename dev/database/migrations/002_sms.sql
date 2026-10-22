-- Text message alerts (php dev/install.php runs this once on existing installs)
ALTER TABLE admin_users ADD COLUMN phone VARCHAR(20) NULL AFTER email;
ALTER TABLE admin_users ADD COLUMN phone_verified_at DATETIME NULL AFTER phone;
ALTER TABLE admin_users ADD COLUMN sms_reminders TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_digest;
ALTER TABLE admin_users ADD COLUMN sms_digest TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_reminders;
CREATE TABLE IF NOT EXISTS sms_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    to_phone VARCHAR(20) NOT NULL,
    body VARCHAR(480) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    provider_id VARCHAR(64) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_unsent (sent_at, attempts),
    KEY idx_user_sent (user_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
