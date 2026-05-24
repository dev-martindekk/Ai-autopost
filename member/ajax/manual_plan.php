<?php
/**
 * Member AJAX: Manual Content Plan - Add / Delete
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/member_auth.php';

header('Content-Type: application/json');

if (!memberAuth()->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
$memberId = (int)memberAuth()->getMember()['id'];

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF token invalid']); exit;
}

require_once __DIR__ . '/../../includes/plan_manager.php';
if (planManager()->isTrial($memberId)) {
    echo json_encode(['success' => false, 'message' => 'Content Calendar ไม่พร้อมใช้งานใน Trial Plan']); exit;
}

$action = $_POST['action'] ?? 'add';

if ($action === 'delete') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    if ($planId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการ']); exit;
    }
    $plan = db()->fetchOne("
        SELECT cp.id FROM content_plan cp
        JOIN sites s ON cp.site_id = s.id AND s.owner_type='member' AND s.owner_id=?
        WHERE cp.id = ?
    ", [$memberId, $planId]);
    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการหรือไม่มีสิทธิ์']); exit;
    }
    db()->delete('content_plan', 'id = ?', [$planId]);
    echo json_encode(['success' => true, 'message' => 'ลบรายการเรียบร้อย']); exit;
}

// Add manual plan
$siteId      = (int)($_POST['site_id'] ?? 0);
$plannedDate = trim($_POST['planned_date'] ?? '');
$keyword     = trim($_POST['keyword'] ?? '');
$contentType = $_POST['content_type'] ?? 'article';
$searchIntent = $_POST['search_intent'] ?? 'informational';
$notes       = trim($_POST['notes'] ?? '');

if ($siteId <= 0) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเลือกเว็บไซต์']); exit;
}
if (empty($plannedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedDate)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุวันที่ให้ถูกต้อง']); exit;
}
if (empty($keyword)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุ Keyword']); exit;
}

$site = db()->fetchOne(
    "SELECT id, name FROM sites WHERE id=? AND owner_type='member' AND owner_id=?",
    [$siteId, $memberId]
);
if (!$site) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบเว็บไซต์หรือไม่มีสิทธิ์']); exit;
}

$existing = db()->fetchOne(
    "SELECT id FROM content_plan WHERE site_id=? AND planned_date=? AND primary_keyword=?",
    [$siteId, $plannedDate, $keyword]
);
if ($existing) {
    echo json_encode(['success' => false, 'message' => 'มี Keyword นี้ในวันเดียวกันของเว็บนี้แล้ว']); exit;
}

$month = substr($plannedDate, 0, 7);
db()->insert('content_plan', [
    'site_id'        => $siteId,
    'month'          => $month,
    'planned_date'   => $plannedDate,
    'primary_keyword'=> $keyword,
    'content_type'   => $contentType,
    'search_intent'  => $searchIntent,
    'priority'       => 10,
    'ai_reasoning'   => $notes ?: 'กำหนดเอง (Manual)',
    'status'         => 'planned',
    'is_manual'      => 1,
]);

echo json_encode([
    'success' => true,
    'message' => "เพิ่มแผน \"{$keyword}\" สำหรับ {$site['name']} วันที่ {$plannedDate} เรียบร้อย",
]);
