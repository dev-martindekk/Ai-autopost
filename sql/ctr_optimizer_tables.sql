-- CTR Optimizer Tables
-- ====================

-- Table to store article performance data (from GSC)
CREATE TABLE IF NOT EXISTS article_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    impressions INT DEFAULT 0,
    clicks INT DEFAULT 0,
    ctr DECIMAL(5,2) DEFAULT 0.00,
    avg_position DECIMAL(5,2) DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_article (article_id),
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store historical performance data
CREATE TABLE IF NOT EXISTS article_performance_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    date DATE NOT NULL,
    impressions INT DEFAULT 0,
    clicks INT DEFAULT 0,
    ctr DECIMAL(5,2) DEFAULT 0.00,
    avg_position DECIMAL(5,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_article_date (article_id, date),
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    INDEX idx_date (date),
    INDEX idx_article_date (article_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to track CTR optimizations
CREATE TABLE IF NOT EXISTS ctr_optimizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    old_title VARCHAR(255),
    new_title VARCHAR(255),
    old_description TEXT,
    new_description TEXT,
    ctr_before DECIMAL(5,2) DEFAULT NULL,
    ctr_after DECIMAL(5,2) DEFAULT NULL,
    impressions_before INT DEFAULT NULL,
    impressions_after INT DEFAULT NULL,
    status ENUM('applied', 'reverted', 'testing') DEFAULT 'applied',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    measured_at TIMESTAMP NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    INDEX idx_article (article_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for GSC integration
CREATE TABLE IF NOT EXISTS gsc_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    property_url VARCHAR(255) NOT NULL,
    client_id VARCHAR(255),
    client_secret TEXT,
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    last_sync TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_site (site_id),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for keyword opportunities from GSC
CREATE TABLE IF NOT EXISTS gsc_keyword_opportunities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    impressions INT DEFAULT 0,
    clicks INT DEFAULT 0,
    ctr DECIMAL(5,2) DEFAULT 0.00,
    avg_position DECIMAL(5,2) DEFAULT 0.00,
    opportunity_type ENUM('new_content', 'improve_existing', 'quick_win', 'long_term') DEFAULT 'new_content',
    has_article TINYINT(1) DEFAULT 0,
    article_id INT NULL,
    priority_score INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_site (site_id),
    INDEX idx_priority (priority_score DESC),
    INDEX idx_type (opportunity_type),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
