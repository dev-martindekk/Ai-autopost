<?php
/**
 * Member AJAX: Generate Content Plan
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/member_auth.php';
require_once __DIR__ . '/../../includes/content_planner.php';

header('Content-Type: application/json');

if (!memberAuth()->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
$memberId = (int)memberAuth()->getMember()['id'];

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token']); exit;
}

require_once __DIR__ . '/../../includes/plan_manager.php';
if (planManager()->isTrial($memberId)) {
    echo json_encode(['success' => false, 'message' => 'Content Calendar ไม่พร้อมใช้งานใน Trial Plan']); exit;
}

$siteId = (int)($_POST['site_id'] ?? 0);
$days   = (int)($_POST['days'] ?? 30);

if ($siteId <= 0) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเลือกเว็บไซต์']); exit;
}

$site = db()->fetchOne(
    "SELECT id FROM sites WHERE id=? AND owner_type='member' AND owner_id=?",
    [$siteId, $memberId]
);
if (!$site) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบเว็บไซต์หรือไม่มีสิทธิ์']); exit;
}

if ($days < 1 || $days > 90) {
    echo json_encode(['success' => false, 'message' => 'จำนวนวันต้องอยู่ระหว่าง 1-90 วัน']); exit;
}

try {
    $planner = new ContentPlanner();
    $result  = $planner->planForSite($siteId, $days);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
