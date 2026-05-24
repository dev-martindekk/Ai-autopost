-- fix_topics_owner.sql
-- Adds owner_type and owner_id columns to topics table
-- Required for member-owned topics (SaaS multi-tenant feature)
-- ============================================================================

DROP PROCEDURE IF EXISTS fix_topics_owner;
DELIMITER //
CREATE PROCEDURE fix_topics_owner()
BEGIN
    -- Add owner_type column
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'topics'
          AND COLUMN_NAME  = 'owner_type'
    ) THEN
        ALTER TABLE `topics`
            ADD COLUMN `owner_type` ENUM('admin','member') NOT NULL DEFAULT 'admin' AFTER `slug`;
    END IF;

    -- Add owner_id column
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'topics'
          AND COLUMN_NAME  = 'owner_id'
    ) THEN
        ALTER TABLE `topics`
            ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL AFTER `owner_type`;
    END IF;

    -- Add index if not exists
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'topics'
          AND INDEX_NAME   = 'idx_topics_owner'
    ) THEN
        ALTER TABLE `topics`
            ADD INDEX `idx_topics_owner` (`owner_type`, `owner_id`);
    END IF;
END //
DELIMITER ;
CALL fix_topics_owner();
DROP PROCEDURE IF EXISTS fix_topics_owner;

-- Make sure existing topics are marked as admin-owned
UPDATE `topics` SET `owner_type` = 'admin', `owner_id` = NULL WHERE `owner_type` IS NULL OR `owner_type` = 'admin';
