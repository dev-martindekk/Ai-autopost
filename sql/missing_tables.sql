-- ============================================================
-- Missing Tables Migration
-- Run: docker exec -i ai-autopost-db mysql -u seouser -p ai_autopost_seo < sql/missing_tables.sql
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 1. ip_bans
-- ============================================================
CREATE TABLE IF NOT EXISTS `ip_bans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `is_permanent` TINYINT(1) DEFAULT 0,
    `expires_at` DATETIME DEFAULT NULL,
    `banned_by` INT UNSIGNED DEFAULT NULL COMMENT 'admin_users.id or NULL for auto-ban',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. rate_limits
-- ============================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `window_bucket` INT UNSIGNED NOT NULL COMMENT 'Unix timestamp truncated to window',
    `attempts` INT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_bucket` (`ip_address`, `action`, `window_bucket`),
    INDEX `idx_ip_action` (`ip_address`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. security_events
-- ============================================================
CREATE TABLE IF NOT EXISTS `security_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(50) NOT NULL,
    `severity` ENUM('low','medium','high','critical') DEFAULT 'medium',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `request_uri` VARCHAR(1000) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `member_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`event_type`),
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. member_settings (key-value store per member)
-- ============================================================
CREATE TABLE IF NOT EXISTS `member_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` ENUM('string','int','bool','json') DEFAULT 'string',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_member_key` (`member_id`, `setting_key`),
    INDEX `idx_member` (`member_id`),
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. topics
-- ============================================================
CREATE TABLE IF NOT EXISTS `topics` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name_th` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `sort_order` INT DEFAULT 10,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `topics` (`slug`, `name_th`, `is_active`, `sort_order`) VALUES
('general', 'ทั่วไป', 1, 1);

-- ============================================================
-- ALTER: telegram_settings — add owner columns (conditional via procedure)
-- ============================================================
DROP PROCEDURE IF EXISTS migrate_telegram;
DELIMITER //
CREATE PROCEDURE migrate_telegram()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_settings' AND COLUMN_NAME = 'owner_type'
    ) THEN
        ALTER TABLE `telegram_settings`
            ADD COLUMN `owner_type` ENUM('admin','member') NOT NULL DEFAULT 'admin' AFTER `setting_name`,
            ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL AFTER `owner_type`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_settings' AND CONSTRAINT_NAME = 'unique_setting'
    ) THEN
        ALTER TABLE `telegram_settings` DROP INDEX `unique_setting`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_settings' AND CONSTRAINT_NAME = 'unique_owner_setting'
    ) THEN
        ALTER TABLE `telegram_settings`
            ADD UNIQUE KEY `unique_owner_setting` (`owner_type`, `owner_id`, `setting_name`);
    END IF;
END//
DELIMITER ;
CALL migrate_telegram();
DROP PROCEDURE IF EXISTS migrate_telegram;

-- ============================================================
-- ALTER: admin_users — add staff to role enum
-- ============================================================
ALTER TABLE `admin_users`
    MODIFY COLUMN `role` ENUM('super_admin','admin','staff','editor') DEFAULT 'admin';

-- ============================================================
-- 6. article_keywords (keyword deduplication tracking)
-- ============================================================
CREATE TABLE IF NOT EXISTS `article_keywords` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `article_id` INT UNSIGNED NOT NULL,
    `site_id` INT UNSIGNED NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `keyword_type` ENUM('primary','secondary','long_tail','lsi') DEFAULT 'primary',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_site_keyword` (`site_id`, `keyword`(100)),
    INDEX `idx_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. job_queue (async job processing)
-- ============================================================
CREATE TABLE IF NOT EXISTS `job_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_type` VARCHAR(50) NOT NULL,
    `payload` JSON NOT NULL,
    `priority` TINYINT UNSIGNED DEFAULT 5 COMMENT '1=highest 10=lowest',
    `site_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
    `attempts` INT DEFAULT 0,
    `max_retries` INT DEFAULT 3,
    `worker_id` VARCHAR(50) DEFAULT NULL,
    `result` JSON DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `locked_until` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status_scheduled` (`status`, `scheduled_at`),
    INDEX `idx_site` (`site_id`),
    INDEX `idx_job_type` (`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Missing tables migration completed.' AS result;
