<?php
/**
 * AI AutoPost SEO System - Generate Weekly Report API
 * ====================================================
 */

require_once __DIR__ . '/../../includes/auth.php';

// Require authentication
auth()->requireAuth();

// Set JSON response header
header('Content-Type: application/json');

try {
    // Get statistics for this week
    $startOfWeek = date('Y-m-d', strtotime('monday this week'));
    $endOfWeek = date('Y-m-d', strtotime('sunday this week'));

    $stats = db()->fetchOne("
        SELECT
            COUNT(*) as articles_generated,
            SUM(status = 'published') as articles_published,
            SUM(status = 'failed') as articles_failed,
            SUM(ai_tokens_used) as total_tokens,
            AVG(generation_time) as avg_generation_time
        FROM articles
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", [$startOfWeek, $endOfWeek]);

    // Get articles by site
    $siteStats = db()->fetchAll("
        SELECT s.name as site_name, COUNT(a.id) as article_count
        FROM sites s
        LEFT JOIN articles a ON s.id = a.site_id AND DATE(a.created_at) BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY article_count DESC
    ", [$startOfWeek, $endOfWeek]);

    // Get articles by topic
    $topicStats = db()->fetchAll("
        SELECT topic, COUNT(*) as count
        FROM articles
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY topic
    ", [$startOfWeek, $endOfWeek]);

    // Create report
    $reportData = [
        'articles_generated' => (int)($stats['articles_generated'] ?? 0),
        'articles_published' => (int)($stats['articles_published'] ?? 0),
        'articles_failed' => (int)($stats['articles_failed'] ?? 0),
        'total_tokens' => (int)($stats['total_tokens'] ?? 0),
        'avg_generation_time' => round($stats['avg_generation_time'] ?? 0, 2),
        'site_breakdown' => $siteStats,
        'topic_breakdown' => $topicStats
    ];

    // Check if report for this week already exists
    $existingReport = db()->fetchOne("
        SELECT id FROM weekly_reports WHERE report_date = ?
    ", [$endOfWeek]);

    if ($existingReport) {
        // Update existing report
        db()->update('weekly_reports', [
            'articles_generated' => $reportData['articles_generated'],
            'articles_published' => $reportData['articles_published'],
            'articles_failed' => $reportData['articles_failed'],
            'report_data' => json_encode($reportData)
        ], 'id = ?', [$existingReport['id']]);

        $reportId = $existingReport['id'];
    } else {
        // Create new report
        $reportId = db()->insert('weekly_reports', [
            'report_date' => $endOfWeek,
            'articles_generated' => $reportData['articles_generated'],
            'articles_published' => $reportData['articles_published'],
            'articles_failed' => $reportData['articles_failed'],
            'report_data' => json_encode($reportData),
            'telegram_sent' => 0
        ]);
    }

    logEvent('info', 'reports', 'Weekly report generated', ['report_id' => $reportId]);

    jsonResponse([
        'success' => true,
        'message' => 'สร้างรายงานสำเร็จ',
        'report_id' => $reportId
    ]);

} catch (Exception $e) {
    logEvent('error', 'reports', 'Failed to generate report', ['error' => $e->getMessage()]);

    jsonResponse([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการสร้างรายงาน กรุณาลองใหม่อีกครั้ง'
    ], 500);
}
