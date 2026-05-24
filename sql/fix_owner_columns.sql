-- fix_owner_columns.sql
-- Adds owner_type/owner_id to multi-tenant tables (articles, sites, prompt_templates)
-- Fixes prompt_templates.template_type enum to include 'system'
-- ============================================================================

DROP PROCEDURE IF EXISTS fix_owner_cols;

DELIMITER //
CREATE PROCEDURE fix_owner_cols()
BEGIN
    -- ── articles ──────────────────────────────────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'owner_type'
    ) THEN
        ALTER TABLE `articles` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT 'admin';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'owner_id'
    ) THEN
        ALTER TABLE `articles` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND INDEX_NAME = 'idx_articles_owner'
    ) THEN
        ALTER TABLE `articles` ADD INDEX `idx_articles_owner` (`owner_type`, `owner_id`);
    END IF;

    -- ── sites ────────────────────────────────────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'owner_type'
    ) THEN
        ALTER TABLE `sites` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT 'admin';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'owner_id'
    ) THEN
        ALTER TABLE `sites` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND INDEX_NAME = 'idx_sites_owner'
    ) THEN
        ALTER TABLE `sites` ADD INDEX `idx_sites_owner` (`owner_type`, `owner_id`);
    END IF;

    -- ── prompt_templates ─────────────────────────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prompt_templates' AND COLUMN_NAME = 'owner_type'
    ) THEN
        ALTER TABLE `prompt_templates` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT 'admin';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prompt_templates' AND COLUMN_NAME = 'owner_id'
    ) THEN
        ALTER TABLE `prompt_templates` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prompt_templates' AND INDEX_NAME = 'idx_pt_owner'
    ) THEN
        ALTER TABLE `prompt_templates` ADD INDEX `idx_pt_owner` (`owner_type`, `owner_id`);
    END IF;

    -- Fix template_type enum to include 'system'
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'prompt_templates'
          AND COLUMN_NAME = 'template_type'
          AND COLUMN_TYPE LIKE '%system%'
    ) THEN
        ALTER TABLE `prompt_templates`
            MODIFY COLUMN `template_type` ENUM('system','article','title','meta','excerpt') DEFAULT 'article';
    END IF;

END //
DELIMITER ;

CALL fix_owner_cols();
DROP PROCEDURE IF EXISTS fix_owner_cols;
