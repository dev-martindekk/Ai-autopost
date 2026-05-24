<?php
/**
 * AI AutoPost SEO System - Article Refresh Job
 * =============================================
 * Cron job to refresh old articles
 *
 * Schedule: 0 3 * * * (Every day at 3 AM)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/content_refresher.php';

echo "=== AI AutoPost SEO - Article Refresh Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Configuration
$daysOldThreshold = 60;  // Refresh articles older than this
$articlesPerRun = 3;     // Max articles to refresh per run

try {
    $refresher = new ContentRefresher();

    // Get all active sites
    $sites = db()->fetchAll("SELECT * FROM sites WHERE posting_enabled = 1");

    if (empty($sites)) {
        echo "No active sites found\n";
        exit(0);
    }

    $totalRefreshed = 0;

    foreach ($sites as $site) {
        echo "Processing site: {$site['name']}\n";

        // Find stale articles
        $staleArticles = $refresher->findStaleArticles($site['id'], $daysOldThreshold, $articlesPerRun);

        if (empty($staleArticles)) {
            echo "  No stale articles found\n\n";
            continue;
        }

        echo "  Found " . count($staleArticles) . " stale article(s)\n";

        foreach ($staleArticles as $article) {
            echo "  Refreshing: {$article['title']}\n";
            echo "    Days old: {$article['days_since_published']}\n";

            // Alternate between full refresh and quick refresh
            if ($article['days_since_published'] > 90) {
                // Full refresh for very old articles
                $result = $refresher->refresh($article['id']);
            } else {
                // Quick refresh for moderately old articles
                $result = $refresher->quickRefresh($article['id']);
            }

            if ($result['success']) {
                echo "    ✓ {$result['message']}\n";
                if (!empty($result['changes'])) {
                    foreach ($result['changes'] as $change) {
                        echo "      - {$change}\n";
                    }
                }
                $totalRefreshed++;
            } else {
                echo "    ✗ {$result['message']}\n";
            }

            // Delay between articles
            sleep(5);
        }

        echo "\n";
    }

    echo "=== Job Complete ===\n";
    echo "Total articles refreshed: {$totalRefreshed}\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

    logEvent('info', 'job', 'Article refresh job completed', [
        'articles_refreshed' => $totalRefreshed
    ]);

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Article refresh job failed', ['error' => $e->getMessage()]);
    exit(1);
}
