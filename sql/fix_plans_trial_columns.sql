-- fix_plans_trial_columns.sql
-- Safely add is_trial and trial_articles_total to plans table if missing

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='is_trial');
SET @q = IF(@s=0,'ALTER TABLE `plans` ADD COLUMN `is_trial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `price`','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='trial_articles_total');
SET @q = IF(@s=0,'ALTER TABLE `plans` ADD COLUMN `trial_articles_total` INT NOT NULL DEFAULT 0 AFTER `is_trial`','SELECT 1');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- Insert trial plan if not exists
INSERT IGNORE INTO `plans` (`name`, `slug`, `description`, `price`, `is_trial`, `trial_articles_total`, `articles_per_month`, `max_sites`, `max_keywords`, `sort_order`, `is_active`)
VALUES ('Trial', 'trial', 'ทดลองใช้ฟรี 5 บทความ', 0, 1, 5, 5, 1, 50, 0, 1);

SELECT 'fix_plans_trial_columns.sql done' AS result;
