-- fix_missing_columns.sql
-- Creates content_plan table and adds missing columns to sites
-- MySQL 8.0 compatible (no DELIMITER)
-- ============================================================================

-- 1. content_plan table
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

-- 2. sites.is_mdes_blocked
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='is_mdes_blocked');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `is_mdes_blocked` TINYINT(1) DEFAULT 0','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. sites.last_test_status / last_test_time / last_test_message
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='last_test_status');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `last_test_status` VARCHAR(20) DEFAULT NULL','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='last_test_time');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `last_test_time` DATETIME DEFAULT NULL','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='last_test_message');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `last_test_message` TEXT DEFAULT NULL','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. sites.is_active
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='is_active');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'fix_missing_columns.sql done' AS result;
