-- fix_missing_columns.sql
-- Creates content_plan table and adds missing columns
-- ============================================================================

-- 1. content_plan table (required by content_calendar.php + content_planner.php)
CREATE TABLE IF NOT EXISTS `content_plan` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id`             INT UNSIGNED NOT NULL,
    `planned_date`        DATE NOT NULL,
    `primary_keyword_id`  INT UNSIGNED DEFAULT NULL,
    `primary_keyword`     VARCHAR(255) NOT NULL DEFAULT '',
    `secondary_keywords`  JSON DEFAULT NULL,
    `content_type`        VARCHAR(50) DEFAULT 'article',
    `search_intent`       VARCHAR(50) DEFAULT 'informational',
    `priority`            INT DEFAULT 0,
    `ai_reasoning`        TEXT DEFAULT NULL,
    `status`              ENUM('planned','in_progress','completed','skipped') DEFAULT 'planned',
    `article_id`          INT UNSIGNED DEFAULT NULL,
    `is_manual`           TINYINT(1) DEFAULT 0,
    `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_site_date`   (`site_id`, `planned_date`),
    INDEX `idx_status`      (`status`),
    INDEX `idx_planned_date`(`planned_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add is_mdes_blocked to sites table
DROP PROCEDURE IF EXISTS fix_sites_cols;
DELIMITER //
CREATE PROCEDURE fix_sites_cols()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'sites'
          AND COLUMN_NAME  = 'is_mdes_blocked'
    ) THEN
        ALTER TABLE `sites`
            ADD COLUMN `is_mdes_blocked` TINYINT(1) DEFAULT 0 AFTER `is_active`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'sites'
          AND COLUMN_NAME  = 'last_test_status'
    ) THEN
        ALTER TABLE `sites`
            ADD COLUMN `last_test_status`  VARCHAR(20) DEFAULT NULL AFTER `is_mdes_blocked`,
            ADD COLUMN `last_test_time`    DATETIME DEFAULT NULL AFTER `last_test_status`,
            ADD COLUMN `last_test_message` TEXT DEFAULT NULL AFTER `last_test_time`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'sites'
          AND COLUMN_NAME  = 'is_active'
    ) THEN
        ALTER TABLE `sites`
            ADD COLUMN `is_active` TINYINT(1) DEFAULT 1;
    END IF;
END //
DELIMITER ;
CALL fix_sites_cols();
DROP PROCEDURE IF EXISTS fix_sites_cols;
