-- fix_owner_columns.sql
-- Adds owner_type/owner_id to articles, sites, prompt_templates
-- Fixes prompt_templates.template_type enum to include 'system'
-- MySQL 8.0 compatible (no DELIMITER)
-- ============================================================================

-- ── articles ──────────────────────────────────────────────────────────────
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='articles' AND COLUMN_NAME='owner_type');
SET @q = IF(@s=0,'ALTER TABLE `articles` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT ''admin''','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='articles' AND COLUMN_NAME='owner_id');
SET @q = IF(@s=0,'ALTER TABLE `articles` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='articles' AND INDEX_NAME='idx_articles_owner');
SET @q = IF(@s=0,'ALTER TABLE `articles` ADD INDEX `idx_articles_owner` (`owner_type`,`owner_id`)','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- ── sites ─────────────────────────────────────────────────────────────────
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='owner_type');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT ''admin''','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME='owner_id');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND INDEX_NAME='idx_sites_owner');
SET @q = IF(@s=0,'ALTER TABLE `sites` ADD INDEX `idx_sites_owner` (`owner_type`,`owner_id`)','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- ── prompt_templates ──────────────────────────────────────────────────────
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prompt_templates' AND COLUMN_NAME='owner_type');
SET @q = IF(@s=0,'ALTER TABLE `prompt_templates` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT ''admin''','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prompt_templates' AND COLUMN_NAME='owner_id');
SET @q = IF(@s=0,'ALTER TABLE `prompt_templates` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prompt_templates' AND INDEX_NAME='idx_pt_owner');
SET @q = IF(@s=0,'ALTER TABLE `prompt_templates` ADD INDEX `idx_pt_owner` (`owner_type`,`owner_id`)','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- Fix template_type enum to include 'system'
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prompt_templates' AND COLUMN_NAME='template_type' AND COLUMN_TYPE LIKE '%system%');
SET @q = IF(@s=0,'ALTER TABLE `prompt_templates` MODIFY COLUMN `template_type` ENUM(''system'',''article'',''title'',''meta'',''excerpt'') DEFAULT ''article''','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'fix_owner_columns.sql done' AS result;
