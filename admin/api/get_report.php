<?php
/**
 * AI AutoPost SEO System - Get Report Details API
 * ================================================
 */

require_once __DIR__ . '/../../includes/auth.php';

// Require authentication
auth()->requireAuth();

// Set JSON response header
header('Content-Type: application/json');

$reportId = (int)($_GET['id'] ?? 0);

if (!$reportId) {
    jsonResponse(['success' => false, 'message' => 'Report ID required'], 400);
}

try {
    $report = db()->fetchOne("SELECT * FROM weekly_reports WHERE id = ?", [$reportId]);

    if (!$report) {
        jsonResponse(['success' => false, 'message' => 'Report not found'], 404);
    }

    $reportData = json_decode($report['report_data'], true) ?: [];

    // Build HTML for report details
    $html = '<div class="row">';

    // Summary stats
    $html .= '<div class="col-md-6 mb-3">';
    $html .= '<h6>สรุปบทความ</h6>';
    $html .= '<table class="table table-sm">';
    $html .= '<tr><td>บทความที่สร้าง</td><td><strong>' . number_format($report['articles_generated']) . '</strong></td></tr>';
    $html .= '<tr><td>เผยแพร่สำเร็จ</td><td><span class="text-success">' . number_format($report['articles_published']) . '</span></td></tr>';
    $html .= '<tr><td>ล้มเหลว</td><td><span class="text-danger">' . number_format($report['articles_failed']) . '</span></td></tr>';
    $html .= '</table>';
    $html .= '</div>';

    // AI Usage
    $html .= '<div class="col-md-6 mb-3">';
    $html .= '<h6>การใช้งาน AI</h6>';
    $html .= '<table class="table table-sm">';
    $html .= '<tr><td>Tokens ใช้ทั้งหมด</td><td><strong>' . number_format($reportData['total_tokens'] ?? 0) . '</strong></td></tr>';
    $html .= '<tr><td>เวลาเฉลี่ย/บทความ</td><td>' . ($reportData['avg_generation_time'] ?? 0) . ' วินาที</td></tr>';
    $html .= '</table>';
    $html .= '</div>';

    // Site breakdown
    if (!empty($reportData['site_breakdown'])) {
        $html .= '<div class="col-12 mb-3">';
        $html .= '<h6>แยกตามเว็บไซต์</h6>';
        $html .= '<table class="table table-sm">';
        $html .= '<thead><tr><th>เว็บไซต์</th><th class="text-end">บทความ</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($reportData['site_breakdown'] as $site) {
            $html .= '<tr><td>' . htmlspecialchars($site['site_name']) . '</td><td class="text-end">' . $site['article_count'] . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    // Topic breakdown
    if (!empty($reportData['topic_breakdown'])) {
        $topics = [
            'general' => 'ทั่วไป',
            'technology' => 'เทคโนโลยี',
            'business' => 'ธุรกิจ',
            'lifestyle' => 'ไลฟ์สไตล์',
            'health' => 'สุขภาพ',
            'travel' => 'ท่องเที่ยว'
        ];

        $html .= '<div class="col-12">';
        $html .= '<h6>แยกตามหมวดหมู่</h6>';
        $html .= '<div class="row">';
        foreach ($reportData['topic_breakdown'] as $topic) {
            $topicName = $topics[$topic['topic']] ?? $topic['topic'];
            $html .= '<div class="col-4 text-center mb-2">';
            $html .= '<span class="badge bg-secondary">' . $topicName . '</span>';
            $html .= '<div class="fw-bold">' . $topic['count'] . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';

    // Report meta
    $html .= '<hr>';
    $html .= '<small class="text-muted">';
    $html .= 'รายงานวันที่: ' . date('d/m/Y', strtotime($report['report_date']));
    $html .= ' | สร้างเมื่อ: ' . date('d/m/Y H:i', strtotime($report['created_at']));
    if ($report['telegram_sent']) {
        $html .= ' | <span class="text-success"><i class="fas fa-check"></i> ส่ง Telegram แล้ว</span>';
    }
    $html .= '</small>';

    jsonResponse([
        'success' => true,
        'html' => $html,
        'report' => $report
    ]);

} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], 500);
}
