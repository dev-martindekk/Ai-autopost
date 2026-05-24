-- AI AutoPost SEO System - Database Schema
-- =========================================
-- Version: 1.0
-- Created: 2024

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- =========================================
-- Admin Users Table
-- =========================================
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) DEFAULT NULL,
    `role` ENUM('super_admin', 'admin', 'editor') DEFAULT 'admin',
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login` DATETIME DEFAULT NULL,
    `login_attempts` INT DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: admin123 - CHANGE THIS!)
INSERT INTO `admin_users` (`username`, `email`, `password_hash`, `full_name`, `role`)
VALUES ('admin', 'admin@pigads.me', '$2y$10$YourHashedPasswordHere', 'System Administrator', 'super_admin');

-- =========================================
-- AI Settings Table
-- =========================================
CREATE TABLE IF NOT EXISTS `ai_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `provider_name` VARCHAR(50) NOT NULL UNIQUE COMMENT 'claude, openai, gemini, deepseek',
    `display_name` VARCHAR(100) NOT NULL,
    `api_key` TEXT NOT NULL COMMENT 'Encrypted API Key',
    `api_endpoint` VARCHAR(255) DEFAULT NULL,
    `default_model` VARCHAR(100) NOT NULL,
    `available_models` JSON DEFAULT NULL COMMENT 'List of available models',
    `temperature` DECIMAL(3,2) DEFAULT 0.70,
    `max_tokens` INT DEFAULT 4000,
    `top_p` DECIMAL(3,2) DEFAULT 1.00,
    `frequency_penalty` DECIMAL(3,2) DEFAULT 0.00,
    `presence_penalty` DECIMAL(3,2) DEFAULT 0.00,
    `is_primary` TINYINT(1) DEFAULT 0 COMMENT 'Primary provider to use',
    `is_enabled` TINYINT(1) DEFAULT 1,
    `monthly_limit` INT DEFAULT NULL COMMENT 'Monthly API call limit',
    `current_usage` INT DEFAULT 0,
    `last_test_status` ENUM('success', 'failed', 'pending') DEFAULT 'pending',
    `last_test_time` DATETIME DEFAULT NULL,
    `last_test_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_provider` (`provider_name`),
    INDEX `idx_primary` (`is_primary`),
    INDEX `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default AI providers
INSERT INTO `ai_settings` (`provider_name`, `display_name`, `api_key`, `api_endpoint`, `default_model`, `available_models`, `is_primary`) VALUES
('claude', 'Anthropic Claude', '', 'https://api.anthropic.com/v1/messages', 'claude-sonnet-4-20250514', '["claude-sonnet-4-20250514", "claude-3-5-sonnet-20241022", "claude-3-haiku-20240307"]', 1),
('openai', 'OpenAI GPT', '', 'https://api.openai.com/v1/chat/completions', 'gpt-4o', '["gpt-4o", "gpt-4o-mini", "gpt-4-turbo", "gpt-3.5-turbo"]', 0),
('gemini', 'Google Gemini', '', 'https://generativelanguage.googleapis.com/v1beta/models', 'gemini-1.5-pro', '["gemini-1.5-pro", "gemini-1.5-flash", "gemini-pro"]', 0),
('deepseek', 'DeepSeek', '', 'https://api.deepseek.com/v1/chat/completions', 'deepseek-chat', '["deepseek-chat", "deepseek-coder"]', 0);

-- =========================================
-- Telegram Settings Table
-- =========================================
CREATE TABLE IF NOT EXISTS `telegram_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_name` VARCHAR(50) NOT NULL DEFAULT 'default',
    `bot_token` VARCHAR(255) NOT NULL,
    `chat_id` VARCHAR(100) NOT NULL,
    `thread_id` VARCHAR(100) DEFAULT NULL COMMENT 'For group topics',
    `is_enabled` TINYINT(1) DEFAULT 1,
    `notify_on_post` TINYINT(1) DEFAULT 1 COMMENT 'Notify when article posted',
    `notify_on_error` TINYINT(1) DEFAULT 1 COMMENT 'Notify on errors',
    `notify_weekly_report` TINYINT(1) DEFAULT 1,
    `last_test_status` ENUM('success', 'failed', 'pending') DEFAULT 'pending',
    `last_test_time` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_setting` (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default telegram setting
INSERT INTO `telegram_settings` (`setting_name`, `bot_token`, `chat_id`)
VALUES ('default', '', '');

-- =========================================
-- Sites Table (WordPress Sites)
-- =========================================
CREATE TABLE IF NOT EXISTS `sites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `base_url` VARCHAR(255) NOT NULL,
    `wp_api_url` VARCHAR(255) NOT NULL COMMENT 'Full REST API URL',
    `wp_user` VARCHAR(100) NOT NULL,
    `wp_app_password` VARCHAR(255) NOT NULL COMMENT 'Encrypted',
    `main_topic` ENUM('slots', 'baccarat', 'lottery', 'casino', 'sports', 'poker', 'general') DEFAULT 'general',
    `main_topic_th` VARCHAR(50) DEFAULT NULL COMMENT 'Thai topic name',
    `homepage_keyword` VARCHAR(255) DEFAULT NULL COMMENT 'Primary keyword for homepage link anchor',
    `ban_words` TEXT DEFAULT NULL COMMENT 'Comma-separated banned words for content generation',
    `language_code` VARCHAR(10) DEFAULT 'th' COMMENT 'Primary language: th, vn, en, etc.',
    `country_code` VARCHAR(5) DEFAULT 'TH' COMMENT 'Country: TH, VN, etc.',
    `daily_article_limit` INT DEFAULT 3,
    `articles_posted_today` INT DEFAULT 0,
    `total_articles_posted` INT DEFAULT 0,
    `posting_enabled` TINYINT(1) DEFAULT 1,
    `posting_start_hour` TINYINT UNSIGNED DEFAULT 6 COMMENT 'Start posting hour (0-23)',
    `posting_end_hour` TINYINT UNSIGNED DEFAULT 22 COMMENT 'End posting hour (0-23)',
    `posting_interval_hours` TINYINT UNSIGNED DEFAULT 2 COMMENT 'Hours between posts',
    `auto_internal_link` TINYINT(1) DEFAULT 1,
    `default_category_id` INT DEFAULT NULL,
    `default_author_id` INT DEFAULT 1,
    `post_status` ENUM('publish', 'draft', 'pending') DEFAULT 'publish',
    `featured_image_enabled` TINYINT(1) DEFAULT 1,
    `last_test_status` ENUM('success', 'failed', 'pending') DEFAULT 'pending',
    `last_test_time` DATETIME DEFAULT NULL,
    `last_test_message` TEXT DEFAULT NULL,
    `last_post_time` DATETIME DEFAULT NULL,
    `priority` INT DEFAULT 10 COMMENT 'Lower = higher priority',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_posting_enabled` (`posting_enabled`),
    INDEX `idx_main_topic` (`main_topic`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_language` (`language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Keywords Table
-- =========================================
CREATE TABLE IF NOT EXISTS `keywords` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `owner_type` ENUM('admin', 'member') DEFAULT 'admin',
    `owner_id` INT UNSIGNED DEFAULT NULL,
    `site_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = all sites',
    `keyword` VARCHAR(255) NOT NULL,
    `keyword_type` ENUM('primary', 'secondary', 'long_tail', 'brand') DEFAULT 'primary',
    `topic` ENUM('slots', 'baccarat', 'lottery', 'casino', 'sports', 'poker', 'general', 'sports_betting') DEFAULT 'general',
    `language_code` VARCHAR(10) DEFAULT 'th' COMMENT 'Language: th, vn, en, etc.',
    `search_volume` INT DEFAULT NULL,
    `difficulty` INT DEFAULT NULL COMMENT '1-100',
    `traffic_potential` INT DEFAULT NULL,
    `search_intent` ENUM('informational', 'navigational', 'commercial', 'transactional') DEFAULT 'informational',
    `cpc` DECIMAL(10,2) DEFAULT NULL,
    `data_source` VARCHAR(50) DEFAULT NULL COMMENT 'ahrefs, dataforseo, manual',
    `last_updated` DATETIME DEFAULT NULL,
    `priority` INT DEFAULT 10,
    `usage_count` INT DEFAULT 0,
    `last_used` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_site` (`site_id`),
    INDEX `idx_topic` (`topic`),
    INDEX `idx_language` (`language_code`),
    INDEX `idx_active` (`is_active`),
    FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample keywords
INSERT INTO `keywords` (`keyword`, `keyword_type`, `topic`, `priority`) VALUES
('สล็อตออนไลน์', 'primary', 'slots', 1),
('สล็อต เว็บตรง', 'primary', 'slots', 2),
('สล็อต pg', 'primary', 'slots', 3),
('บาคาร่าออนไลน์', 'primary', 'baccarat', 1),
('บาคาร่า เว็บตรง', 'primary', 'baccarat', 2),
('สูตรบาคาร่า', 'secondary', 'baccarat', 5),
('หวยออนไลน์', 'primary', 'lottery', 1),
('แทงหวย', 'primary', 'lottery', 2),
('คาสิโนออนไลน์', 'primary', 'casino', 1),
('คาสิโน เว็บตรง', 'primary', 'casino', 2);

-- =========================================
-- Articles Table
-- =========================================
CREATE TABLE IF NOT EXISTS `articles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `wp_post_id` INT DEFAULT NULL COMMENT 'WordPress post ID after publishing',
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) DEFAULT NULL,
    `content` LONGTEXT NOT NULL,
    `excerpt` TEXT DEFAULT NULL,
    `meta_title` VARCHAR(255) DEFAULT NULL,
    `meta_description` VARCHAR(500) DEFAULT NULL,
    `primary_keyword` VARCHAR(255) DEFAULT NULL,
    `secondary_keywords` JSON DEFAULT NULL,
    `topic` ENUM('slots', 'baccarat', 'lottery', 'casino', 'sports', 'poker', 'general') DEFAULT 'general',
    `language_code` VARCHAR(10) DEFAULT 'th' COMMENT 'Article language: th, vn, en, etc.',
    `word_count` INT DEFAULT 0,
    `ai_provider` VARCHAR(50) DEFAULT NULL,
    `ai_model` VARCHAR(100) DEFAULT NULL,
    `ai_tokens_used` INT DEFAULT 0,
    `generation_time` DECIMAL(10,2) DEFAULT NULL COMMENT 'Seconds',
    `status` ENUM('draft', 'generated', 'published', 'failed', 'scheduled') DEFAULT 'draft',
    `post_url` VARCHAR(500) DEFAULT NULL,
    `featured_image_id` INT UNSIGNED DEFAULT NULL,
    `internal_links_count` INT DEFAULT 0,
    `scheduled_time` DATETIME DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_site` (`site_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_topic` (`topic`),
    INDEX `idx_published` (`published_at`),
    INDEX `idx_language` (`language_code`),
    FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Internal Links Table
-- =========================================
CREATE TABLE IF NOT EXISTS `internal_links` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `source_article_id` INT UNSIGNED NOT NULL,
    `target_article_id` INT UNSIGNED NOT NULL,
    `anchor_text` VARCHAR(255) NOT NULL,
    `link_position` ENUM('intro', 'body', 'conclusion') DEFAULT 'body',
    `is_active` TINYINT(1) DEFAULT 1,
    `click_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_site` (`site_id`),
    INDEX `idx_source` (`source_article_id`),
    INDEX `idx_target` (`target_article_id`),
    FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`source_article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`target_article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Outbound Links Table (External Links)
-- =========================================
CREATE TABLE IF NOT EXISTS `outbound_links` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `anchor_text` VARCHAR(255) NOT NULL COMMENT 'Keyword to use as anchor text',
    `title` VARCHAR(255) DEFAULT NULL COMMENT 'Link description',
    `is_active` TINYINT(1) DEFAULT 1,
    `use_count` INT DEFAULT 0 COMMENT 'Number of times used in articles',
    `last_used_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_site` (`site_id`),
    INDEX `idx_active` (`is_active`),
    FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Images Table
-- =========================================
CREATE TABLE IF NOT EXISTS `images` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED DEFAULT NULL,
    `article_id` INT UNSIGNED DEFAULT NULL,
    `wp_media_id` INT DEFAULT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_url` VARCHAR(500) DEFAULT NULL,
    `file_size` INT DEFAULT NULL,
    `mime_type` VARCHAR(50) DEFAULT NULL,
    `width` INT DEFAULT NULL,
    `height` INT DEFAULT NULL,
    `alt_text` VARCHAR(255) DEFAULT NULL,
    `title` VARCHAR(255) DEFAULT NULL,
    `source` ENUM('upload', 'ai_generated', 'stock', 'url') DEFAULT 'upload',
    `is_featured` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_site` (`site_id`),
    INDEX `idx_article` (`article_id`),
    FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Weekly Reports Table
-- =========================================
CREATE TABLE IF NOT EXISTS `weekly_reports` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_date` DATE NOT NULL,
    `week_start` DATE NOT NULL,
    `week_end` DATE NOT NULL,
    `total_articles` INT DEFAULT 0,
    `articles_by_site` JSON DEFAULT NULL,
    `articles_by_topic` JSON DEFAULT NULL,
    `total_words` INT DEFAULT 0,
    `avg_words_per_article` INT DEFAULT 0,
    `ai_tokens_used` INT DEFAULT 0,
    `ai_cost_estimate` DECIMAL(10,2) DEFAULT 0.00,
    `successful_posts` INT DEFAULT 0,
    `failed_posts` INT DEFAULT 0,
    `internal_links_created` INT DEFAULT 0,
    `report_content` LONGTEXT DEFAULT NULL,
    `telegram_sent` TINYINT(1) DEFAULT 0,
    `telegram_sent_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_week` (`week_start`),
    INDEX `idx_report_date` (`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- System Logs Table
-- =========================================
CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `log_type` ENUM('info', 'warning', 'error', 'debug', 'api_call') DEFAULT 'info',
    `category` VARCHAR(50) DEFAULT 'general',
    `message` TEXT NOT NULL,
    `context` JSON DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`log_type`),
    INDEX `idx_category` (`category`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Sessions Table (for secure login)
-- =========================================
CREATE TABLE IF NOT EXISTS `admin_sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `payload` TEXT NOT NULL,
    `last_activity` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_last_activity` (`last_activity`),
    FOREIGN KEY (`user_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- CSRF Tokens Table
-- =========================================
CREATE TABLE IF NOT EXISTS `csrf_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `used` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token` (`token`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Prompt Templates Table
-- =========================================
CREATE TABLE IF NOT EXISTS `prompt_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `topic` ENUM('slots', 'baccarat', 'lottery', 'casino', 'sports', 'poker', 'general') DEFAULT 'general',
    `template_type` ENUM('article', 'title', 'meta', 'excerpt') DEFAULT 'article',
    `language_code` VARCHAR(10) DEFAULT 'th' COMMENT 'Language: th, vn, en, etc.',
    `prompt_content` LONGTEXT NOT NULL,
    `variables` JSON DEFAULT NULL COMMENT 'Available variables like {keyword}, {site_name}',
    `is_default` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `usage_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_topic` (`topic`),
    INDEX `idx_type` (`template_type`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_language` (`language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default prompt templates (Thai)
INSERT INTO `prompt_templates` (`name`, `topic`, `template_type`, `language_code`, `prompt_content`, `variables`, `is_default`) VALUES
('Slots Article - Thai', 'slots', 'article', 'th', 'เขียนบทความ SEO ภาษาไทยเกี่ยวกับ {keyword} ความยาว 1500-2000 คำ\n\nข้อกำหนด:\n- หัวข้อดึงดูดและ SEO-friendly\n- เนื้อหาเป็นประโยชน์ ให้ข้อมูลครบถ้วน\n- ใช้ H2, H3 headings ที่เหมาะสม\n- รวม keyword หลักและ keyword รอง\n- tone เป็นมิตร เข้าใจง่าย\n- มี call-to-action ในตอนท้าย', '["keyword", "site_name", "secondary_keywords"]', 1),
('Baccarat Article - Thai', 'baccarat', 'article', 'th', 'เขียนบทความ SEO ภาษาไทยเกี่ยวกับ {keyword} ความยาว 1500-2000 คำ\n\nข้อกำหนด:\n- เน้นสูตร เทคนิค และวิธีเล่นบาคาร่า\n- หัวข้อดึงดูดและ SEO-friendly\n- ใช้ H2, H3 headings ที่เหมาะสม\n- ให้ตัวอย่างและคำอธิบายที่ชัดเจน\n- tone เป็นผู้เชี่ยวชาญแต่เข้าใจง่าย', '["keyword", "site_name", "secondary_keywords"]', 1),
('General Article - Thai', 'general', 'article', 'th', 'เขียนบทความ SEO ภาษาไทยเกี่ยวกับ {keyword} ความยาว 1500-2000 คำ\n\nข้อกำหนด:\n- หัวข้อดึงดูดและ SEO-friendly\n- เนื้อหาเป็นประโยชน์ ให้ข้อมูลครบถ้วน\n- ใช้ H2, H3 headings ที่เหมาะสม\n- tone เป็นมิตร เข้าใจง่าย', '["keyword", "site_name", "secondary_keywords"]', 1);

-- Insert Vietnamese prompt templates (Slots & Casino only)
INSERT INTO `prompt_templates` (`name`, `topic`, `template_type`, `language_code`, `prompt_content`, `variables`, `is_default`) VALUES
('Slots Article - Vietnamese', 'slots', 'article', 'vn', 'Viết bài viết SEO chuyên nghiệp bằng tiếng Việt về {keyword} với độ dài 1500-2000 từ.\n\nYêu cầu:\n- Tiêu đề hấp dẫn và thân thiện với SEO\n- Nội dung hữu ích, cung cấp thông tin đầy đủ\n- Sử dụng H2, H3 headings phù hợp\n- Kết hợp từ khóa chính và từ khóa phụ một cách tự nhiên\n- Giọng điệu thân thiện, dễ hiểu\n- Có call-to-action ở cuối bài\n- QUAN TRỌNG: Viết như người Việt bản địa, không dịch máy', '["keyword", "site_name", "secondary_keywords"]', 1),
('Casino Article - Vietnamese', 'casino', 'article', 'vn', 'Viết bài viết SEO chuyên nghiệp bằng tiếng Việt về {keyword} với độ dài 1500-2000 từ.\n\nYêu cầu:\n- Tập trung vào hướng dẫn, mẹo và chiến thuật chơi casino\n- Tiêu đề hấp dẫn và thân thiện với SEO\n- Sử dụng H2, H3 headings phù hợp\n- Cung cấp ví dụ và giải thích rõ ràng\n- Giọng điệu chuyên gia nhưng dễ hiểu\n- QUAN TRỌNG: Viết như người Việt bản địa, tránh dịch máy', '["keyword", "site_name", "secondary_keywords"]', 1);

-- =========================================
-- System Settings Table
-- =========================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` ENUM('string', 'int', 'bool', 'json') DEFAULT 'string',
    `description` VARCHAR(255) DEFAULT NULL,
    `is_public` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('site_name', 'AI AutoPost SEO', 'string', 'System name'),
('timezone', 'Asia/Bangkok', 'string', 'Default timezone'),
('articles_per_day_global', '10', 'int', 'Global daily article limit'),
('min_word_count', '1500', 'int', 'Minimum words per article'),
('max_word_count', '2500', 'int', 'Maximum words per article'),
('enable_auto_internal_links', '1', 'bool', 'Auto-create internal links'),
('internal_links_per_article', '3', 'int', 'Max internal links per article'),
('enable_featured_images', '1', 'bool', 'Auto-set featured images'),
('encryption_key', '', 'string', 'Key for API encryption (auto-generated)');
