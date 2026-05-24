<?php
$pageTitle = 'ความปลอดภัย';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

// Revoke a session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'revoke_session') {
        $sessionId = trim($_POST['session_id'] ?? '');
        if ($sessionId) {
            db()->query(
                "DELETE FROM member_sessions WHERE id=? AND member_id=?",
                [$sessionId, $memberId]
            );
            setFlash('success', 'ยกเลิก session เรียบร้อยแล้ว');
        }
        redirect('/member/security_2fa.php');
    }

    if ($action === 'revoke_all_other') {
        $currentSessionId = session_id();
        db()->query(
            "DELETE FROM member_sessions WHERE member_id=? AND id != ?",
            [$memberId, $currentSessionId]
        );
        setFlash('success', 'ยกเลิก session อื่นทั้งหมดเรียบร้อยแล้ว');
        redirect('/member/security_2fa.php');
    }
}

// Load active sessions
$sessions = db()->fetchAll(
    "SELECT id, ip_address, user_agent, device_info, last_activity, created_at
     FROM member_sessions
     WHERE member_id = ?
     ORDER BY last_activity DESC",
    [$memberId]
);

$currentSessionId = session_id();
$flash = getFlash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<div class="row g-4">

<!-- Left: Sessions -->
<div class="col-lg-8">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-laptop me-2 text-primary"></i>Session ที่ใช้งานอยู่ <span class="badge bg-secondary"><?= count($sessions) ?></span></span>
            <?php if (count($sessions) > 1): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('ยกเลิก session อื่นทั้งหมด?')">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="revoke_all_other">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-sign-out-alt me-1"></i>ออกจาก Session อื่นทั้งหมด
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($sessions)): ?>
            <div class="text-center py-4 text-muted">ไม่พบข้อมูล Session</div>
            <?php else: ?>
            <?php foreach ($sessions as $s):
                $isCurrent = ($s['id'] === $currentSessionId);
                $device = json_decode($s['device_info'] ?? '{}', true) ?: [];
                $lastActive = time() - $s['last_activity'];
                if ($lastActive < 60)          $ago = 'เมื่อกี้';
                elseif ($lastActive < 3600)    $ago = round($lastActive/60) . ' นาทีที่แล้ว';
                elseif ($lastActive < 86400)   $ago = round($lastActive/3600) . ' ชั่วโมงที่แล้ว';
                else                           $ago = round($lastActive/86400) . ' วันที่แล้ว';
                $ua = $s['user_agent'] ?? '';
                $browser = 'Unknown Browser';
                if (stripos($ua,'Chrome') !== false && stripos($ua,'Edg') === false) $browser = 'Chrome';
                elseif (stripos($ua,'Firefox') !== false) $browser = 'Firefox';
                elseif (stripos($ua,'Safari') !== false && stripos($ua,'Chrome') === false) $browser = 'Safari';
                elseif (stripos($ua,'Edg') !== false) $browser = 'Edge';
                $os = 'Unknown OS';
                if (stripos($ua,'Windows') !== false) $os = 'Windows';
                elseif (stripos($ua,'Mac') !== false) $os = 'macOS';
                elseif (stripos($ua,'Linux') !== false) $os = 'Linux';
                elseif (stripos($ua,'Android') !== false) $os = 'Android';
                elseif (stripos($ua,'iPhone') !== false || stripos($ua,'iPad') !== false) $os = 'iOS';
            ?>
            <div class="d-flex align-items-center px-4 py-3 border-bottom <?= $isCurrent ? 'bg-light' : '' ?>">
                <div class="me-3" style="font-size:28px;color:#6366F1;">
                    <i class="fas <?= stripos($ua,'Mobile') !== false || stripos($ua,'Android') !== false || stripos($ua,'iPhone') !== false ? 'fa-mobile-alt' : 'fa-laptop' ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="font-size:14px;">
                        <?= sanitize($browser) ?> บน <?= sanitize($os) ?>
                        <?php if ($isCurrent): ?>
                        <span class="badge bg-success ms-2" style="font-size:10px;">Session ปัจจุบัน</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:12px;">
                        <i class="fas fa-map-marker-alt me-1"></i><?= sanitize($s['ip_address']) ?>
                        &nbsp;·&nbsp;
                        <i class="fas fa-clock me-1"></i><?= $ago ?>
                        &nbsp;·&nbsp;
                        <span title="<?= sanitize(date('d/m/Y H:i:s', (int)$s['last_activity'])) ?>">
                            เข้าสู่ระบบ <?= date('d/m/Y', strtotime($s['created_at'])) ?>
                        </span>
                    </div>
                </div>
                <?php if (!$isCurrent): ?>
                <form method="POST" class="ms-3">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="revoke_session">
                    <input type="hidden" name="session_id" value="<?= sanitize($s['id']) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="ยกเลิก session นี้">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Right: 2FA + Quick links -->
<div class="col-lg-4">

    <!-- 2FA Status -->
    <div class="card mb-3">
        <div class="card-header"><i class="fas fa-shield-alt me-2 text-success"></i>การยืนยันตัวตน 2 ขั้น (2FA)</div>
        <div class="card-body">
            <?php
            require_once __DIR__ . '/../includes/two_factor_auth.php';
            $is2fa = TwoFactorAuth::isEnabled($memberId, 'member');
            ?>
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <div class="fw-semibold">2FA <?= $is2fa ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></div>
                    <small class="text-muted">TOTP Authenticator App</small>
                </div>
                <span class="badge bg-<?= $is2fa ? 'success' : 'secondary' ?> fs-6">
                    <?= $is2fa ? 'ON' : 'OFF' ?>
                </span>
            </div>
            <a href="/member/profile.php#security" class="btn btn-outline-primary btn-sm w-100">
                <i class="fas fa-cog me-2"></i><?= $is2fa ? 'จัดการ 2FA' : 'เปิดใช้งาน 2FA' ?>
            </a>
        </div>
    </div>

    <!-- Security Links -->
    <div class="card">
        <div class="card-header"><i class="fas fa-lock me-2 text-warning"></i>การตั้งค่าความปลอดภัย</div>
        <div class="list-group list-group-flush">
            <a href="/member/profile.php" class="list-group-item list-group-item-action">
                <i class="fas fa-key me-2 text-primary"></i>เปลี่ยนรหัสผ่าน
            </a>
            <a href="/member/profile.php" class="list-group-item list-group-item-action">
                <i class="fas fa-user me-2 text-info"></i>แก้ไขโปรไฟล์
            </a>
            <a href="/member/logout.php" class="list-group-item list-group-item-action text-danger">
                <i class="fas fa-right-from-bracket me-2"></i>ออกจากระบบทุก Device
            </a>
        </div>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
