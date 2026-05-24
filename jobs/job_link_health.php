<?php
/**
 * AI AutoPost SEO System - Link Health Check Job
 * ===============================================
 * Cron job to check for broken links
 *
 * Schedule: 0 4 * * 0 (Every Sunday at 4 AM)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/link_checker.php';

echo "=== AI AutoPost SEO - Link Health Check Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $checker = new LinkChecker();

    // Get all active sites
    $sites = db()->fetchAll("SELECT * FROM sites WHERE posting_enabled = 1");

    if (empty($sites)) {
        echo "No active sites found\n";
        exit(0);
    }

    $totalBroken = 0;

    foreach ($sites as $site) {
        echo "Checking site: {$site['name']}\n";

        // Check internal links
        $result = $checker->checkSiteLinks($site['id'], 30);

        echo "  Total articles checked: {$result['total_articles']}\n";
        echo "  Total links found: {$result['total_links']}\n";
        echo "  Broken links: {$result['broken_count']}\n";
        echo "  Health score: {$result['health_score']}%\n";

        if (!empty($result['articles_with_issues'])) {
            echo "  Articles with issues:\n";
            foreach ($result['articles_with_issues'] as $article) {
                echo "    - {$article['title']}: " . count($article['broken_links']) . " broken\n";
            }
        }

        $totalBroken += $result['broken_count'];

        // Check internal link records
        $brokenInternal = $checker->findBrokenInternalLinks($site['id']);
        if (!empty($brokenInternal)) {
            echo "  Broken internal link records: " . count($brokenInternal) . "\n";
        }

        echo "\n";
    }

    echo "=== Job Complete ===\n";
    echo "Total broken links found: {$totalBroken}\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

    // Log if there are issues
    if ($totalBroken > 0) {
        logEvent('warning', 'job', 'Link health check found issues', [
            'broken_links' => $totalBroken
        ]);
    }

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Link health check failed', ['error' => $e->getMessage()]);
    exit(1);
}
