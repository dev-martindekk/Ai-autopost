<?php
/**
 * AI AutoPost SEO System - Weekly Report Job
 * ==========================================
 * Cron job to generate and send weekly reports
 *
 * Schedule: 0 9 * * 0 (Every Sunday at 9am)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/telegram_client.php';

echo "=== AI AutoPost SEO - Weekly Report Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Calculate week range
    $weekEnd = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('-6 days'));

    echo "Report Period: {$weekStart} to {$weekEnd}\n\n";

    // Get statistics
    $stats = db()->fetchOne("
        SELECT
            COUNT(*) as total_articles,
            SUM(status = 'published') as successful_posts,
            SUM(status = 'failed') as failed_posts,
            SUM(word_count) as total_words,
            AVG(word_count) as avg_words,
            SUM(ai_tokens_used) as total_tokens
        FROM articles
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", [$weekStart, $weekEnd]);

    // Get articles by site
    $articlesBySite = db()->fetchAll("
        SELECT s.name, COUNT(a.id) as count
        FROM sites s
        LEFT JOIN articles a ON s.id = a.site_id AND DATE(a.created_at) BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY count DESC
    ", [$weekStart, $weekEnd]);

    // Get articles by topic
    $articlesByTopic = db()->fetchAll("
        SELECT topic, COUNT(*) as count
        FROM articles
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY topic
    ", [$weekStart, $weekEnd]);

    // Get internal links created
    $linksCreated = db()->fetchColumn("
        SELECT COUNT(*) FROM internal_links
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", [$weekStart, $weekEnd]) ?? 0;

    // Estimate AI cost (rough estimate)
    $tokenCost = 0;
    $totalTokens = $stats['total_tokens'] ?? 0;
    // Assuming average cost of $0.003 per 1K tokens
    $aiCostEstimate = ($totalTokens / 1000) * 0.003;

    // Prepare report data
    $reportData = [
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'total_articles' => $stats['total_articles'] ?? 0,
        'successful_posts' => $stats['successful_posts'] ?? 0,
        'failed_posts' => $stats['failed_posts'] ?? 0,
        'total_words' => $stats['total_words'] ?? 0,
        'avg_words_per_article' => round($stats['avg_words'] ?? 0),
        'ai_tokens_used' => $totalTokens,
        'ai_cost_estimate' => round($aiCostEstimate, 2),
        'internal_links_created' => $linksCreated,
        'articles_by_site' => json_encode(array_column($articlesBySite, 'count', 'name')),
        'articles_by_topic' => json_encode(array_column($articlesByTopic, 'count', 'topic'))
    ];

    // Print report
    echo "=== Weekly Report Summary ===\n";
    echo "Total Articles: {$reportData['total_articles']}\n";
    echo "Successful Posts: {$reportData['successful_posts']}\n";
    echo "Failed Posts: {$reportData['failed_posts']}\n";
    echo "Total Words: " . number_format($reportData['total_words']) . "\n";
    echo "Avg Words/Article: {$reportData['avg_words_per_article']}\n";
    echo "AI Tokens Used: " . number_format($totalTokens) . "\n";
    echo "Estimated Cost: \${$reportData['ai_cost_estimate']}\n";
    echo "Internal Links: {$linksCreated}\n\n";

    echo "By Site:\n";
    foreach ($articlesBySite as $site) {
        echo "  - {$site['name']}: {$site['count']}\n";
    }

    echo "\nBy Topic:\n";
    foreach ($articlesByTopic as $topic) {
        $topicName = TOPICS[$topic['topic']] ?? $topic['topic'];
        echo "  - {$topicName}: {$topic['count']}\n";
    }

    // Save report to database
    $existingReport = db()->fetchOne("SELECT id FROM weekly_reports WHERE week_start = ?", [$weekStart]);

    if ($existingReport) {
        db()->update('weekly_reports', $reportData, 'id = ?', [$existingReport['id']]);
        $reportId = $existingReport['id'];
    } else {
        $reportData['report_date'] = date('Y-m-d');
        $reportId = db()->insert('weekly_reports', $reportData);
    }

    echo "\nReport saved (ID: {$reportId})\n";

    // Send via Telegram
    $telegram = new TelegramClient();

    $telegramSettings = db()->fetchOne("SELECT * FROM telegram_settings WHERE setting_name = 'default'");

    if ($telegramSettings && $telegramSettings['notify_weekly_report'] && $telegram->isEnabled()) {
        echo "\nSending Telegram notification...\n";

        $result = $telegram->sendWeeklyReport($reportData);

        if ($result['success']) {
            // Update report as sent
            db()->update('weekly_reports', [
                'telegram_sent' => 1,
                'telegram_sent_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$reportId]);

            echo "Telegram notification sent successfully\n";
        } else {
            echo "Failed to send Telegram: {$result['message']}\n";
        }
    } else {
        echo "\nTelegram notifications disabled or not configured\n";
    }

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Weekly report job failed', ['error' => $e->getMessage()]);
}

echo "\n=== Job Complete ===\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

logEvent('info', 'job', 'Weekly report job completed');
