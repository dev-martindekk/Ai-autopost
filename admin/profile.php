<?php
$pageTitle = 'โปรไฟล์';
require_once __DIR__ . '/header.php';

$user     = db()->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$_SESSION['user_id']]);
$userId   = (int)$_SESSION['user_id'];

// 2FA state
$isEnabled     = TwoFactorAuth::isEnabled($userId, 'admin');
$remainingCodes = $isEnabled ? TwoFactorAuth::getRemainingBackupCodesCount($userId, 'admin') : 0;
$setupData     = null;
$backupCodes   = null;
$tfaError      = '';
$tfaSuccess    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Session หมดอายุ กรุณาลองใหม่');
        redirect(ADMIN_URL . '/profile.php');
    }

    $action = $_POST['action'] ?? '';

    // ── Profile & password actions (redirect after) ───────────────────────────
    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        if (empty($fullName)) {
            setFlash('error', 'กรุณากรอกชื่อ');
        } else {
            db()->update('admin_users', ['full_name' => $fullName, 'email' => $email], 'id = ?', [$userId]);
            setFlash('success', 'อัพเดทโปรไฟล์สำเร็จ');
        }
        redirect(ADMIN_URL . '/profile.php');
    }

    if ($action === 'change_password') {
        $cur  = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if (!password_verify($cur, $user['password_hash'])) {
            setFlash('error', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
        } elseif ($new !== $conf) {
            setFlash('error', 'รหัสผ่านใหม่ไม่ตรงกัน');
        } else {
            db()->update('admin_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$userId]);
            setFlash('success', 'เปลี่ยนรหัสผ่านสำเร็จ');
        }
        redirect(ADMIN_URL . '/profile.php');
    }

    // ── 2FA actions (no redirect — need to show QR / backup codes inline) ────
    if ($action === 'setup') {
        $result = TwoFactorAuth::setup($userId, 'admin');
        if ($result['success']) {
            $setupData = $result;
        } else {
            $tfaError = $result['message'];
        }
    } elseif ($action === 'enable') {
        $result = TwoFactorAuth::enable($userId, 'admin', $_POST['code'] ?? '');
        if ($result['success']) {
            $tfaSuccess    = $result['message'];
            $backupCodes   = $result['backup_codes'];
            $isEnabled     = true;
            $remainingCodes = 10;
        } else {
            $tfaError  = $result['message'];
            $setupData = TwoFactorAuth::setup($userId, 'admin');
        }
    } elseif ($action === 'disable') {
        $result = TwoFactorAuth::disable($userId, 'admin', $_POST['password'] ?? '');
        if ($result['success']) {
            $tfaSuccess = $result['message'];
            $isEnabled  = false;
        } else {
            $tfaError = $result['message'];
        }
    } elseif ($action === 'regenerate') {
        $result = TwoFactorAuth::regenerateBackupCodes($userId, 'admin', $_POST['password'] ?? '');
        if ($result['success']) {
            $tfaSuccess    = $result['message'];
            $backupCodes   = $result['backup_codes'];
            $remainingCodes = 10;
        } else {
            $tfaError = $result['message'];
        }
    }
}

// Refresh user after possible update
$user = db()->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$userId]);
?>

<div class="page-header">
    <h1 class="page-title">โปรไฟล์ของฉัน</h1>
    <p class="page-subtitle">จัดการข้อมูลส่วนตัว รหัสผ่าน และความปลอดภัย</p>
</div>

<div class="row g-4">
    <!-- ── Profile Info ─────────────────────────────────────────────────────── -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-user me-2"></i>ข้อมูลโปรไฟล์</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action"     value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= sanitize($user['username']) ?>" disabled>
                        <small class="text-muted">ไม่สามารถเปลี่ยน username ได้</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name"
                               value="<?= sanitize($user['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">อีเมล</label>
                        <input type="email" class="form-control" name="email"
                               value="<?= sanitize($user['email'] ?? '') ?>" placeholder="your@email.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" disabled>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">สร้างเมื่อ</label>
                            <input type="text" class="form-control"
                                   value="<?= date('d/m/Y H:i', strtotime($user['created_at'])) ?>" disabled>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Login ล่าสุด</label>
                            <input type="text" class="form-control"
                                   value="<?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-' ?>" disabled>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>บันทึก
                    </button>
                </form>
            </div>
        </div>

        <!-- Session Info -->
        <div class="card mt-4">
            <div class="card-header"><i class="fas fa-info-circle me-2"></i>ข้อมูล Session</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted ps-3">Session ID</td>
                        <td><code><?= substr(session_id(), 0, 16) ?>...</code></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">IP Address</td>
                        <td><code><?= sanitize($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Login Time</td>
                        <td><?= isset($_SESSION['login_time']) ? date('d/m/Y H:i:s', $_SESSION['login_time']) : '-' ?></td>
                    </tr>
                    <tr class="border-0">
                        <td class="text-muted ps-3">User Agent</td>
                        <td><small class="text-break"><?= sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80)) ?>...</small></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Password ─────────────────────────────────────────────────────────── -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-lock me-2"></i>เปลี่ยนรหัสผ่าน</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action"     value="change_password">

                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="new_password" required minlength="8">
                        <small class="text-muted">อย่างน้อย 8 ตัวอักษร</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── 2FA Section ──────────────────────────────────────────────────────────── -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-shield-alt me-2"></i>การยืนยันตัวตนสองขั้นตอน (2FA)</span>
        <?php if ($isEnabled): ?>
            <span class="badge bg-success"><i class="fas fa-check me-1"></i>เปิดใช้งาน</span>
        <?php else: ?>
            <span class="badge bg-danger"><i class="fas fa-times me-1"></i>ปิดอยู่</span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <?php if ($tfaError): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($tfaError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($tfaSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= sanitize($tfaSuccess) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($backupCodes): ?>
            <!-- Backup Codes display -->
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>บันทึก Backup Codes ไว้ในที่ปลอดภัย!</strong> แต่ละรหัสใช้ได้เพียงครั้งเดียว
            </div>
            <div class="row g-2 mb-3">
                <?php foreach ($backupCodes as $code): ?>
                    <div class="col-6 col-md-3">
                        <code class="d-block p-2 bg-light rounded text-center"><?= $code ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2 mb-4">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyBackupCodes()">
                    <i class="fas fa-copy me-1"></i>คัดลอก
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadBackupCodes()">
                    <i class="fas fa-download me-1"></i>ดาวน์โหลด
                </button>
            </div>
            <script>
            const backupCodes = <?= json_encode($backupCodes) ?>;
            function copyBackupCodes() {
                navigator.clipboard.writeText(backupCodes.join('\n'));
                alert('คัดลอก Backup Codes แล้ว!');
            }
            function downloadBackupCodes() {
                const text = "AI AutoPost SEO - Backup Codes\nGenerated: <?= date('Y-m-d H:i:s') ?>\n\n" + backupCodes.join('\n');
                const a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob([text], {type:'text/plain'}));
                a.download = 'backup-codes.txt';
                a.click();
            }
            </script>
        <?php endif; ?>

        <?php if (!$isEnabled && !$setupData): ?>
            <!-- 2FA disabled — invite to enable -->
            <div class="row align-items-center">
                <div class="col-md-8">
                    <p class="mb-2">การยืนยันตัวตนสองขั้นตอนช่วยปกป้องบัญชีของคุณด้วยรหัส 6 หลักจาก Google Authenticator
                    ที่เปลี่ยนทุก 30 วินาที</p>
                    <div class="d-flex gap-4 text-muted small">
                        <span><i class="fas fa-check text-success me-1"></i>ป้องกัน password leak</span>
                        <span><i class="fas fa-check text-success me-1"></i>ใช้งานง่าย</span>
                        <span><i class="fas fa-check text-success me-1"></i>ฟรี ไม่ต้องใช้ SMS</span>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="setup">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-shield-alt me-2"></i>เปิดใช้งาน 2FA
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($setupData && !$isEnabled): ?>
            <!-- QR Setup -->
            <div class="row g-4">
                <div class="col-md-5 text-center">
                    <p class="fw-semibold mb-3">1. สแกน QR Code ด้วย Google Authenticator</p>
                    <div id="qrcode" class="d-inline-block border rounded p-3 bg-white"></div>
                    <p class="mt-2 mb-0 small text-muted">หรือกรอกรหัสนี้แทน:</p>
                    <code class="user-select-all"><?= htmlspecialchars($setupData['secret']) ?></code>
                    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
                    <script>
                    new QRCode(document.getElementById("qrcode"), {
                        text: <?= json_encode($setupData['otpauth_uri'] ?? $setupData['qr_url']) ?>,
                        width: 180, height: 180,
                        colorDark: "#000", colorLight: "#fff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                    </script>
                </div>
                <div class="col-md-7">
                    <p class="fw-semibold mb-3">2. ป้อนรหัส 6 หลักเพื่อยืนยัน</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="enable">
                        <div class="mb-3">
                            <input type="text" name="code"
                                   class="form-control form-control-lg text-center fw-bold"
                                   placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                   required autofocus style="letter-spacing:.3em;font-size:1.5rem;">
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-check me-2"></i>เปิดใช้งาน 2FA
                        </button>
                    </form>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-mobile-alt me-2"></i>
                        ดาวน์โหลด Google Authenticator:
                        <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</a> |
                        <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank">iOS</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isEnabled): ?>
            <!-- Manage enabled 2FA -->
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 rounded bg-success bg-opacity-10 text-center">
                        <i class="fas fa-lock fa-2x text-success mb-2"></i>
                        <div class="fw-semibold text-success">2FA เปิดอยู่</div>
                        <small class="text-muted">Backup codes เหลือ <?= $remainingCodes ?> รหัส</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title"><i class="fas fa-redo me-2 text-warning"></i>สร้าง Backup Codes ใหม่</h6>
                            <form method="POST" onsubmit="return confirm('Backup Codes เดิมจะใช้ไม่ได้อีก ยืนยัน?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="regenerate">
                                <input type="password" name="password" class="form-control form-control-sm mb-2"
                                       placeholder="รหัสผ่านของคุณ" required>
                                <button type="submit" class="btn btn-warning btn-sm w-100">
                                    <i class="fas fa-sync me-1"></i>สร้างใหม่
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger"><i class="fas fa-lock-open me-2"></i>ปิด 2FA</h6>
                            <form method="POST" onsubmit="return confirm('ปิด 2FA จะลดความปลอดภัยของบัญชี ยืนยัน?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="disable">
                                <input type="password" name="password" class="form-control form-control-sm mb-2"
                                       placeholder="รหัสผ่านของคุณ" required>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                    <i class="fas fa-lock-open me-1"></i>ปิดใช้งาน
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── Activity Log ──────────────────────────────────────────────────────────── -->
<div class="card mt-4">
    <div class="card-header"><i class="fas fa-history me-2"></i>กิจกรรมล่าสุด</div>
    <div class="card-body p-0">
        <?php
        $activities = db()->fetchAll(
            "SELECT * FROM system_logs WHERE context LIKE ? ORDER BY created_at DESC LIMIT 10",
            ['%"user_id":' . $userId . '%']
        );
        ?>
        <?php if (empty($activities)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-history fa-2x mb-2"></i>
                <p class="mb-0">ไม่พบกิจกรรมล่าสุด</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>เวลา</th><th>กิจกรรม</th><th>Level</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $a): ?>
                            <tr>
                                <td><small><?= date('d/m/Y H:i:s', strtotime($a['created_at'])) ?></small></td>
                                <td><?= sanitize($a['message']) ?></td>
                                <td>
                                    <?php $lc = match($a['log_type'] ?? 'info') {
                                        'error' => 'danger', 'warning' => 'warning', 'info' => 'info', default => 'secondary'
                                    }; ?>
                                    <span class="badge bg-<?= $lc ?>"><?= strtoupper($a['log_type'] ?? 'info') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
