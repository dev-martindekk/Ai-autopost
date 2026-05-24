<?php
/**
 * AI AutoPost SEO System - CTR Optimizer Job
 * ===========================================
 * Cron job to find and optimize low CTR articles
 *
 * Schedule: 0 8 * * 1 (Every Monday at 8 AM)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ctr_optimizer.php';

echo "=== AI AutoPost SEO - CTR Optimizer Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Configuration
$AUTO_APPLY = false; // Set to true to auto-apply optimizations
$MAX_OPTIMIZATIONS_PER_SITE = 5;

try {
    // Get all active sites
    $sites = db()->fetchAll("SELECT id, name FROM sites WHERE posting_enabled = 1");

    if (empty($sites)) {
        echo "No active sites found\n";
        exit(0);
    }

    $totalAnalyzed = 0;
    $totalOptimized = 0;

    foreach ($sites as $site) {
        echo "Analyzing: {$site['name']}\n";

        $optimizer = new CTROptimizer();

        // Find low CTR articles
        $lowCTR = $optimizer->findLowCTRArticles($site['id'], 20);

        if (empty($lowCTR)) {
            echo "  No low CTR articles found\n\n";
            continue;
        }

        echo "  Found " . count($lowCTR) . " articles needing optimization\n";

        // Analyze patterns
        $patterns = $optimizer->analyzeHighCTRPatterns($site['id']);
        echo "  Pattern analysis:\n";
        foreach ($patterns['recommendations'] as $rec) {
            echo "    - {$rec}\n";
        }

        // Process top candidates
        $processed = 0;
        foreach ($lowCTR as $article) {
            if ($processed >= $MAX_OPTIMIZATIONS_PER_SITE) break;

            $potential = $article['optimization_potential'];
            if ($potential['priority'] !== 'high') continue;

            echo "\n  Article #{$article['id']}: {$article['title']}\n";
            echo "    Current CTR: {$article['ctr']}%\n";
            echo "    Position: {$article['avg_position']}\n";
            echo "    Issues: " . implode(', ', $potential['factors']) . "\n";

            // Generate optimized meta
            $suggestions = $optimizer->generateOptimizedMeta($article['id']);

            if ($suggestions['success'] && !empty($suggestions['suggestions']['titles'])) {
                echo "    Suggested titles:\n";
                foreach ($suggestions['suggestions']['titles'] as $i => $title) {
                    echo "      " . ($i + 1) . ". {$title['text']}\n";
                }

                if ($AUTO_APPLY && !empty($suggestions['suggestions']['titles'][0])) {
                    $newTitle = $suggestions['suggestions']['titles'][0]['text'];
                    $newDesc = $suggestions['suggestions']['descriptions'][0]['text'] ?? $article['seo_description'];

                    $result = $optimizer->applyOptimization($article['id'], $newTitle, $newDesc);
                    if ($result['success']) {
                        echo "    ✓ Applied optimization\n";
                        $totalOptimized++;
                    }
                }
            } else {
                echo "    Could not generate suggestions\n";
            }

            $processed++;
            $totalAnalyzed++;

            // Rate limiting
            sleep(3);
        }

        echo "\n";
    }

    echo "=== Job Complete ===\n";
    echo "Total analyzed: {$totalAnalyzed}\n";
    echo "Total optimized: {$totalOptimized}\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

    logEvent('info', 'job', 'CTR optimizer completed', [
        'analyzed' => $totalAnalyzed,
        'optimized' => $totalOptimized
    ]);

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'CTR optimizer failed', ['error' => $e->getMessage()]);
    exit(1);
}
