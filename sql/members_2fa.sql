-- AI AutoPost SEO System - Members & 2FA Schema
-- ==============================================
-- Version: 1.0
-- Created: 2024-12

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- =========================================
-- Members Table (สมาชิกทั่วไป)
-- =========================================
CREATE TABLE IF NOT EXISTS `members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `avatar_url` VARCHAR(500) DEFAULT NULL,
    `membership_type` ENUM('free', 'basic', 'premium', 'vip') DEFAULT 'free',
    `membership_expires` DATETIME DEFAULT NULL,
    `credits` INT DEFAULT 0 COMMENT 'เครดิตสำหรับใช้งาน',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_verified` TINYINT(1) DEFAULT 0,
    `verification_token` VARCHAR(64) DEFAULT NULL,
    `verification_expires` DATETIME DEFAULT NULL,
    `reset_token` VARCHAR(64) DEFAULT NULL,
    `reset_token_expires` DATETIME DEFAULT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `login_attempts` INT DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `two_factor_enabled` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_membership` (`membership_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Two-Factor Authentication Table
-- =========================================
CREATE TABLE IF NOT EXISTS `two_factor_auth` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `user_type` ENUM('admin', 'member') NOT NULL DEFAULT 'admin',
    `secret_key` VARCHAR(32) NOT NULL COMMENT 'TOTP Secret Key (encrypted)',
    `is_enabled` TINYINT(1) DEFAULT 0,
    `recovery_codes` TEXT DEFAULT NULL COMMENT 'JSON array of hashed recovery codes',
    `last_used_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_2fa` (`user_id`, `user_type`),
    INDEX `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- 2FA Backup Codes Table
-- =========================================
CREATE TABLE IF NOT EXISTS `two_factor_backup_codes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `user_type` ENUM('admin', 'member') NOT NULL DEFAULT 'admin',
    `code_hash` VARCHAR(255) NOT NULL,
    `is_used` TINYINT(1) DEFAULT 0,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`, `user_type`),
    INDEX `idx_code` (`code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Member Sessions Table
-- =========================================
CREATE TABLE IF NOT EXISTS `member_sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `device_info` JSON DEFAULT NULL,
    `payload` TEXT NOT NULL,
    `last_activity` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_member` (`member_id`),
    INDEX `idx_last_activity` (`last_activity`),
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- 2FA Login Attempts (Pending Verification)
-- =========================================
CREATE TABLE IF NOT EXISTS `pending_2fa_logins` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT UNSIGNED NOT NULL,
    `user_type` ENUM('admin', 'member') NOT NULL DEFAULT 'admin',
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `attempts` INT DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token` (`token`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- Add 2FA column to admin_users if not exists
-- =========================================
-- Use procedure to add column safely
DROP PROCEDURE IF EXISTS add_2fa_column;
DELIMITER //
CREATE PROCEDURE add_2fa_column()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'admin_users'
        AND COLUMN_NAME = 'two_factor_enabled'
    ) THEN
        ALTER TABLE `admin_users` ADD COLUMN `two_factor_enabled` TINYINT(1) DEFAULT 0 AFTER `locked_until`;
    END IF;
END //
DELIMITER ;
CALL add_2fa_column();
DROP PROCEDURE IF EXISTS add_2fa_column;

-- =========================================
-- Member Activity Logs
-- =========================================
CREATE TABLE IF NOT EXISTS `member_activity_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'login, logout, profile_update, 2fa_enable, etc.',
    `description` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_member` (`member_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
