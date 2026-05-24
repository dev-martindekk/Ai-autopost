-- ============================================================
-- SaaS Schema Migration - AI AutoPost SEO
-- Run once: docker exec -i ai-autopost-db mysql -u seouser -p ai_autopost_seo < sql/saas_schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ============================================================
-- 1. plans
-- ============================================================
CREATE TABLE IF NOT EXISTS `plans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'ราคา/เดือน (บาท)',
    `articles_per_month` INT DEFAULT 30 COMMENT '0 = unlimited',
    `max_sites` INT DEFAULT 1,
    `max_keywords` INT DEFAULT 200,
    `max_tokens_per_month` INT DEFAULT 0 COMMENT '0 = unlimited',
    `features` JSON DEFAULT NULL COMMENT 'array of feature keys',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
    `trial_articles_total` INT NOT NULL DEFAULT 0 COMMENT 'total articles allowed for trial (0 = use monthly quota)',
    `sort_order` INT DEFAULT 10,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. member_plans (subscription)
-- ============================================================
CREATE TABLE IF NOT EXISTS `member_plans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED NOT NULL,
    `started_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `articles_used_this_month` INT DEFAULT 0,
    `tokens_used_this_month` INT DEFAULT 0,
    `quota_reset_at` DATE DEFAULT NULL COMMENT 'วันที่ reset quota ล่าสุด',
    `status` ENUM('active','expired','suspended') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member` (`member_id`),
    INDEX `idx_status_expires` (`status`, `expires_at`),
    INDEX `idx_quota_reset` (`quota_reset_at`, `status`),
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. payment_slips
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_slips` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `months_to_add` INT DEFAULT 1,
    `slip_file` VARCHAR(500) NOT NULL,
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `transfer_date` DATE DEFAULT NULL,
    `note_from_member` TEXT DEFAULT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `review_note` TEXT DEFAULT NULL,
    `telegram_notified` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member` (`member_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. invite_codes
-- ============================================================
CREATE TABLE IF NOT EXISTS `invite_codes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(32) NOT NULL UNIQUE,
    `plan_id` INT UNSIGNED NOT NULL,
    `months_duration` INT DEFAULT 1,
    `max_uses` INT DEFAULT 1 COMMENT '0 = unlimited',
    `used_count` INT DEFAULT 0,
    `expires_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_code` (`code`),
    INDEX `idx_active` (`is_active`),
    FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`created_by`) REFERENCES `admin_users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. invite_code_uses
-- ============================================================
CREATE TABLE IF NOT EXISTS `invite_code_uses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invite_code_id` INT UNSIGNED NOT NULL,
    `member_id` INT UNSIGNED NOT NULL,
    `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_member_code` (`invite_code_id`, `member_id`),
    FOREIGN KEY (`invite_code_id`) REFERENCES `invite_codes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ALTER EXISTING TABLES (conditional via procedure)
-- ============================================================

DROP PROCEDURE IF EXISTS saas_migrate;
DELIMITER //
CREATE PROCEDURE saas_migrate()
BEGIN
    -- members.plan_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'plan_id'
    ) THEN
        ALTER TABLE `members` ADD COLUMN `plan_id` INT UNSIGNED DEFAULT NULL AFTER `credits`;
    END IF;

    -- members.plan_expires_at
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'plan_expires_at'
    ) THEN
        ALTER TABLE `members` ADD COLUMN `plan_expires_at` DATETIME DEFAULT NULL AFTER `plan_id`;
    END IF;

    -- members.invite_code_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'invite_code_id'
    ) THEN
        ALTER TABLE `members` ADD COLUMN `invite_code_id` INT UNSIGNED DEFAULT NULL AFTER `plan_expires_at`;
    END IF;
END//
DELIMITER ;
CALL saas_migrate();
DROP PROCEDURE IF EXISTS saas_migrate;

-- admin_users: เพิ่ม staff role
ALTER TABLE `admin_users`
    MODIFY COLUMN `role` ENUM('super_admin','admin','staff','editor') DEFAULT 'admin';

-- ============================================================
-- SEED: Default plans
-- ============================================================
INSERT IGNORE INTO `plans` (`name`, `slug`, `description`, `price`, `articles_per_month`, `max_sites`, `max_keywords`, `is_trial`, `trial_articles_total`, `sort_order`) VALUES
('Starter', 'starter', 'เหมาะสำหรับเว็บไซต์เริ่มต้น', 499.00, 30, 1, 200, 0, 0, 1),
('Pro', 'pro', 'เหมาะสำหรับนักการตลาดมืออาชีพ', 1499.00, 100, 3, 1000, 0, 0, 2),
('Agency', 'agency', 'เหมาะสำหรับเอเจนซี่', 3999.00, 0, 10, 0, 0, 0, 3),
('Trial', 'trial', 'ทดลองใช้ฟรี', 0.00, 0, 1, 50, 1, 5, 0);

SET foreign_key_checks = 1;

SELECT 'SaaS schema migration completed.' AS result;
