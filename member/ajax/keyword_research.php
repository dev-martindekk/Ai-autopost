<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/member_auth.php';

header('Content-Type: application/json');

if (php_sapi_name() === 'cli') { http_response_code(403); exit; }
if (!memberAuth()->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$keyword = trim($input['keyword'] ?? '');

if (empty($keyword)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุ keyword']);
    exit;
}

echo json_encode([
    'success' => true,
    'source'  => 'simulated',
    'results' => generateSimulatedResults($keyword),
]);

function generateSimulatedResults(string $keyword): array {
    $base = [
        ['suffix' => '',               'vol' => 8100,  'diff' => 48, 'cpc' => 1.20],
        ['suffix' => ' คืออะไร',       'vol' => 4400,  'diff' => 32, 'cpc' => 0.85],
        ['suffix' => ' วิธีทำ',        'vol' => 3600,  'diff' => 28, 'cpc' => 1.05],
        ['suffix' => ' ตัวอย่าง',      'vol' => 2900,  'diff' => 22, 'cpc' => 0.65],
        ['suffix' => ' ราคา',          'vol' => 2400,  'diff' => 40, 'cpc' => 1.80],
        ['suffix' => ' 2025',          'vol' => 1900,  'diff' => 35, 'cpc' => 0.90],
        ['suffix' => ' ฟรี',           'vol' => 1600,  'diff' => 25, 'cpc' => 0.55],
        ['suffix' => ' เบื้องต้น',     'vol' => 1300,  'diff' => 18, 'cpc' => 0.70],
        ['suffix' => ' ดีที่สุด',      'vol' => 1100,  'diff' => 52, 'cpc' => 2.10],
        ['suffix' => ' ออนไลน์',       'vol' => 880,   'diff' => 38, 'cpc' => 1.35],
        ['suffix' => ' มือใหม่',       'vol' => 720,   'diff' => 15, 'cpc' => 0.45],
        ['suffix' => ' ขั้นตอน',       'vol' => 590,   'diff' => 20, 'cpc' => 0.60],
        ['suffix' => 'สำหรับธุรกิจ',   'vol' => 480,   'diff' => 44, 'cpc' => 2.50],
        ['suffix' => ' เปรียบเทียบ',   'vol' => 390,   'diff' => 36, 'cpc' => 1.60],
        ['suffix' => ' แนะนำ',         'vol' => 320,   'diff' => 30, 'cpc' => 0.95],
    ];

    $results = [];
    foreach ($base as $b) {
        $results[] = [
            'keyword'    => $keyword . $b['suffix'],
            'volume'     => $b['vol'] + rand(-200, 200),
            'difficulty' => max(5, min(100, $b['diff'] + rand(-5, 5))),
            'cpc'        => round($b['cpc'] + (rand(-20, 20) / 100), 2),
        ];
    }
    return $results;
}
