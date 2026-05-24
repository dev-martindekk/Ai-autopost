-- Create workers table for WorkerManager
-- Missing on production — queue processor crashes without it
CREATE TABLE IF NOT EXISTS workers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id       VARCHAR(120) NOT NULL UNIQUE,
    hostname        VARCHAR(100),
    pid             INT,
    status          ENUM('idle','processing','stopped') DEFAULT 'idle',
    current_job     VARCHAR(60) DEFAULT NULL,
    started_at      DATETIME NOT NULL,
    last_heartbeat  DATETIME NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_heartbeat (last_heartbeat),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
