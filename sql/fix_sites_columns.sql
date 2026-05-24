-- fix_sites_columns.sql
-- Adds missing columns to sites table: posting_days, site_niche
-- Also widens main_topic from ENUM to VARCHAR to support dynamic topics
-- Compatible with MySQL 8.0 (no DELIMITER — uses PREPARE pattern)
-- ============================================================================

-- 1. posting_days
SET @col1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'posting_days');
SET @sql1 = IF(@col1 = 0,
    'ALTER TABLE `sites` ADD COLUMN `posting_days` VARCHAR(50) DEFAULT ''1,2,3,4,5,6,7'' AFTER `daily_article_limit`',
    'SELECT "posting_days already exists"');
PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;

-- 2. site_niche
SET @col2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'site_niche');
SET @sql2 = IF(@col2 = 0,
    'ALTER TABLE `sites` ADD COLUMN `site_niche` TEXT DEFAULT NULL AFTER `notes`',
    'SELECT "site_niche already exists"');
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- 3. Widen main_topic from ENUM to VARCHAR(50) so any topic slug can be stored
SET @col3 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'main_topic' AND DATA_TYPE = 'enum');
SET @sql3 = IF(@col3 > 0,
    'ALTER TABLE `sites` MODIFY COLUMN `main_topic` VARCHAR(50) DEFAULT ''general''',
    'SELECT "main_topic already VARCHAR"');
PREPARE s3 FROM @sql3; EXECUTE s3; DEALLOCATE PREPARE s3;

SELECT 'fix_sites_columns.sql done' AS result;
