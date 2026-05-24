-- fix_topics_owner.sql
-- Adds owner_type and owner_id columns to topics table
-- MySQL 8.0 compatible (no DELIMITER)
-- ============================================================================

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='topics' AND COLUMN_NAME='owner_type');
SET @q = IF(@s=0,'ALTER TABLE `topics` ADD COLUMN `owner_type` ENUM(''admin'',''member'') NOT NULL DEFAULT ''admin'' AFTER `slug`','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='topics' AND COLUMN_NAME='owner_id');
SET @q = IF(@s=0,'ALTER TABLE `topics` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL AFTER `owner_type`','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='topics' AND INDEX_NAME='idx_topics_owner');
SET @q = IF(@s=0,'ALTER TABLE `topics` ADD INDEX `idx_topics_owner` (`owner_type`,`owner_id`)','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE `topics` SET `owner_type`='admin', `owner_id`=NULL WHERE `owner_type` IS NULL OR `owner_type`='admin';

SELECT 'fix_topics_owner.sql done' AS result;
