<?php
/**
 * AI AutoPost SEO System - GSC Sync Job
 * ======================================
 * Cron job to sync Google Search Console data
 *
 * Schedule: 0 6 * * * (Every day at 6 AM)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gsc_client.php';

echo "=== AI AutoPost SEO - GSC Sync Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Get all sites with GSC configured
    $sites = db()->fetchAll("
        SELECT s.id, s.name, gc.property_url
        FROM sites s
        JOIN gsc_credentials gc ON s.id = gc.site_id
        WHERE gc.is_enabled = 1
    ");

    if (empty($sites)) {
        echo "No sites with GSC configured\n";
        exit(0);
    }

    $totalSynced = 0;
    $totalOpportunities = 0;

    foreach ($sites as $site) {
        echo "Processing: {$site['name']}\n";
        echo "  Property: {$site['property_url']}\n";

        $gsc = new GSCClient($site['id']);

        if (!$gsc->isConfigured()) {
            echo "  SKIPPED: GSC not properly configured\n\n";
            continue;
        }

        // Sync performance data
        echo "  Syncing performance data...\n";
        $syncResult = $gsc->syncPerformanceData(7);

        if ($syncResult['success']) {
            echo "  Synced: {$syncResult['synced']} articles\n";
            $totalSynced += $syncResult['synced'];
        } else {
            echo "  ERROR: {$syncResult['error']}\n";
        }

        // Find and save keyword opportunities
        echo "  Finding keyword opportunities...\n";
        $oppResult = $gsc->saveKeywordOpportunities();

        if ($oppResult['success']) {
            echo "  Saved: {$oppResult['saved']} opportunities\n";
            echo "    Quick wins: {$oppResult['summary']['quick_wins']}\n";
            echo "    Improve existing: {$oppResult['summary']['improve_existing']}\n";
            echo "    New content: {$oppResult['summary']['new_content']}\n";
            echo "    Long term: {$oppResult['summary']['long_term']}\n";
            $totalOpportunities += $oppResult['saved'];
        } else {
            echo "  ERROR: {$oppResult['error']}\n";
        }

        echo "\n";

        // Rate limiting between sites
        sleep(2);
    }

    echo "=== Job Complete ===\n";
    echo "Total articles synced: {$totalSynced}\n";
    echo "Total opportunities saved: {$totalOpportunities}\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

    logEvent('info', 'job', 'GSC sync completed', [
        'sites' => count($sites),
        'articles_synced' => $totalSynced,
        'opportunities' => $totalOpportunities
    ]);

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'GSC sync failed', ['error' => $e->getMessage()]);
    exit(1);
}
