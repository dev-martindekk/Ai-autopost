<?php
/**
 * AI AutoPost SEO System - Cleanup Job
 * =====================================
 * Cron job to clean old logs and temporary data
 *
 * Schedule: 0 1 * * * (Every day at 1am)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== AI AutoPost SEO - Cleanup Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 1. Clean old system logs (older than 30 days)
    echo "Cleaning old system logs...\n";
    $deletedLogs = db()->delete(
        'system_logs',
        'created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
    );
    echo "  Deleted {$deletedLogs} log entries\n";

    // 2. Clean old CSRF tokens (older than 1 day)
    echo "Cleaning expired CSRF tokens...\n";
    $deletedTokens = db()->delete(
        'csrf_tokens',
        'expires_at < NOW() OR used = 1'
    );
    echo "  Deleted {$deletedTokens} tokens\n";

    // 3. Clean expired sessions (older than session lifetime)
    echo "Cleaning expired sessions...\n";
    $sessionLifetime = SESSION_LIFETIME;
    $deletedSessions = db()->delete(
        'admin_sessions',
        'last_activity < ?',
        [time() - $sessionLifetime]
    );
    echo "  Deleted {$deletedSessions} sessions\n";

    // 4. Reset daily article counters (if not already reset)
    $currentHour = (int) date('H');
    if ($currentHour < 2) {
        echo "Resetting daily article counters...\n";
        db()->query("UPDATE sites SET articles_posted_today = 0");
        echo "  Done\n";
    }

    // 5. Reset monthly AI usage counters on 1st of month
    $currentDay = (int) date('j');
    if ($currentDay === 1) {
        echo "Resetting monthly AI usage counters...\n";
        db()->query("UPDATE ai_settings SET current_usage = 0");
        echo "  Done\n";
    }

    // 6. Clean old log files
    echo "Cleaning old log files...\n";
    $logDir = LOGS_PATH;
    $logFiles = glob($logDir . '/*.log');
    $maxLogAge = 30 * 24 * 60 * 60; // 30 days
    $deletedFiles = 0;

    foreach ($logFiles as $logFile) {
        if (is_file($logFile) && (time() - filemtime($logFile)) > $maxLogAge) {
            if (unlink($logFile)) {
                $deletedFiles++;
            }
        }
    }
    echo "  Deleted {$deletedFiles} old log files\n";

    // 7. Rotate current log file if too large (> 50MB)
    echo "Checking log file sizes...\n";
    foreach ($logFiles as $logFile) {
        if (is_file($logFile) && filesize($logFile) > 50 * 1024 * 1024) {
            $rotatedFile = $logFile . '.' . date('Y-m-d');
            rename($logFile, $rotatedFile);
            echo "  Rotated: " . basename($logFile) . "\n";
        }
    }

    // 8. Clean failed articles older than 7 days
    echo "Cleaning old failed articles...\n";
    $deletedArticles = db()->delete(
        'articles',
        "status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    echo "  Deleted {$deletedArticles} failed articles\n";

    // 9. Optimize tables (monthly)
    if ($currentDay === 1) {
        echo "Optimizing database tables...\n";
        $tables = ['articles', 'system_logs', 'internal_links', 'images'];
        foreach ($tables as $table) {
            db()->query("OPTIMIZE TABLE {$table}");
        }
        echo "  Done\n";
    }

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Cleanup job failed', ['error' => $e->getMessage()]);
}

echo "\n=== Job Complete ===\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

logEvent('info', 'job', 'Cleanup job completed');
