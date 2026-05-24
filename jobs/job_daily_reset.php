<?php
/**
 * AI AutoPost SEO System - Daily Reset Job
 * =========================================
 * Runs at midnight Thai time (Asia/Bangkok)
 * Resets daily article counters for all sites
 *
 * Schedule: 0 0 * * * (Every day at midnight)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== Daily Reset Job ===\n";
echo "Time: " . date('Y-m-d H:i:s') . " (Asia/Bangkok)\n";

try {
    // Reset daily article counters for all sites
    $result = db()->query("UPDATE sites SET articles_posted_today = 0 WHERE articles_posted_today > 0");
    $affected = $result->rowCount();
    echo "Reset articles_posted_today for {$affected} site(s)\n";

    logEvent('info', 'job', 'Daily reset completed', [
        'sites_reset' => $affected
    ]);

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Daily reset failed', ['error' => $e->getMessage()]);
}

echo "Done.\n";
