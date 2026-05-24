<?php
/**
 * AI AutoPost SEO System - Content Planner Job
 * =============================================
 * Cron job to generate weekly content plans for all sites
 *
 * Schedule: 0 0 * * 0 (Every Sunday at midnight)
 * Or: 0 6 * * 1 (Every Monday at 6 AM)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/content_planner.php';

echo "=== AI AutoPost SEO - Content Planner Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $planner = new ContentPlanner();

    // Get all active sites
    $sites = db()->fetchAll("
        SELECT * FROM sites
        WHERE posting_enabled = 1
        ORDER BY id
    ");

    if (empty($sites)) {
        echo "No active sites found\n";
        exit(0);
    }

    echo "Found " . count($sites) . " active site(s)\n\n";

    $totalPlanned = 0;

    foreach ($sites as $site) {
        echo "Processing: {$site['name']}\n";

        // Calculate how many posting days in next 7 days based on posting_days setting
        $postingDays = explode(',', $site['posting_days'] ?? '1,2,3,4,5,6,7');
        $postingDays = array_map('intval', $postingDays);
        $postingDatesCount = 0;
        $today = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
        for ($i = 0; $i < 7; $i++) {
            $d = clone $today;
            $d->modify("+{$i} days");
            if (in_array((int)$d->format('N'), $postingDays)) {
                $postingDatesCount++;
            }
        }

        $dayNamesTh = [1=>'จ.',2=>'อ.',3=>'พ.',4=>'พฤ.',5=>'ศ.',6=>'ส.',7=>'อา.'];
        $postingDayNames = implode(' ', array_map(fn($d) => $dayNamesTh[$d] ?? $d, $postingDays));
        echo "  Posting days: {$postingDayNames} ({$postingDatesCount} days in next 7 days)\n";

        if ($postingDatesCount === 0) {
            echo "  No posting days in the next 7 days, skipping\n\n";
            continue;
        }

        // Check existing plan for next 7 days
        $existingPlan = db()->fetchOne("
            SELECT COUNT(*) as count FROM content_plan
            WHERE site_id = ?
            AND planned_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status = 'planned'
        ", [$site['id']]);

        $existingCount = $existingPlan['count'] ?? 0;
        $articlesPerDay = (int)($site['daily_article_limit'] ?? 1);
        $neededArticles = ($postingDatesCount * $articlesPerDay) - $existingCount;

        if ($neededArticles <= 0) {
            echo "  Already has enough planned content ({$existingCount} articles for {$postingDatesCount} posting days)\n\n";
            continue;
        }

        echo "  Need to plan: {$neededArticles} articles ({$postingDatesCount} days × {$articlesPerDay}/day - {$existingCount} existing)\n";

        // Generate plan
        $result = $planner->planForSite($site['id'], 7);

        if ($result['success']) {
            echo "  ✓ Created plan: {$result['saved_count']} articles\n";
            $totalPlanned += $result['saved_count'];

            // Log each planned article
            if (!empty($result['plan'])) {
                foreach ($result['plan'] as $plan) {
                    echo "    - {$plan['date']}: {$plan['primary_keyword']} ({$plan['content_type']})\n";
                }
            }
        } else {
            echo "  ✗ Failed: {$result['message']}\n";
        }

        echo "\n";

        // Small delay between sites
        sleep(2);
    }

    echo "=== Job Complete ===\n";
    echo "Total articles planned: {$totalPlanned}\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

    logEvent('info', 'job', 'Content planner job completed', [
        'sites_processed' => count($sites),
        'articles_planned' => $totalPlanned
    ]);

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Content planner job failed', ['error' => $e->getMessage()]);
    exit(1);
}
