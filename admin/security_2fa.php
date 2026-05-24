<?php
/**
 * AI AutoPost SEO System - 2FA Security Settings
 * ================================================
 */

require_once __DIR__ . '/../includes/auth.php';
auth()->requireAuth();

$user = auth()->getUser();
$userId = $user['id'];
$isEnabled = TwoFactorAuth::isEnabled($userId, 'admin');
$remainingCodes = $isEnabled ? TwoFactorAuth::getRemainingBackupCodesCount($userId, 'admin') : 0;

$error = '';
$success = '';
$setupData = null;
$backupCodes = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } else {
        switch ($action) {
            case 'setup':
                // Start 2FA setup
                $result = TwoFactorAuth::setup($userId, 'admin');
                if ($result['success']) {
                    $setupData = $result;
                } else {
                    $error = $result['message'];
                }
                break;

            case 'enable':
                // Enable 2FA with verification code
                $code = $_POST['code'] ?? '';
                $result = TwoFactorAuth::enable($userId, 'admin', $code);
                if ($result['success']) {
                    $success = $result['message'];
                    $backupCodes = $result['backup_codes'];
                    $isEnabled = true;
                    $remainingCodes = 10;
                } else {
                    $error = $result['message'];
                    // Re-show setup data
                    $setupData = TwoFactorAuth::setup($userId, 'admin');
                }
                break;

            case 'disable':
                // Disable 2FA
                $password = $_POST['password'] ?? '';
                $result = TwoFactorAuth::disable($userId, 'admin', $password);
                if ($result['success']) {
                    $success = $result['message'];
                    $isEnabled = false;
                } else {
                    $error = $result['message'];
                }
                break;

            case 'regenerate':
                // Regenerate backup codes
                $password = $_POST['password'] ?? '';
                $result = TwoFactorAuth::regenerateBackupCodes($userId, 'admin', $password);
                if ($result['success']) {
                    $success = $result['message'];
                    $backupCodes = $result['backup_codes'];
                    $remainingCodes = 10;
                } else {
                    $error = $result['message'];
                }
                break;
        }
    }
}

$csrfToken = generateCsrfToken();
$pageTitle = 'ความปลอดภัย 2FA';
require_once __DIR__ . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Header -->
            <div class="d-flex align-items-center mb-4">
                <a href="profile.php" class="btn btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h4 class="mb-0">
                        <i class="fas fa-shield-alt me-2 text-primary"></i>
                        การยืนยันตัวตนสองขั้นตอน (2FA)
                    </h4>
                    <small class="text-muted">เพิ่มความปลอดภัยให้บัญชีของคุณด้วย Google Authenticator</small>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= sanitize($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= sanitize($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Show Backup Codes after enable/regenerate -->
            <?php if ($backupCodes): ?>
                <div class="card border-warning mb-4">
                    <div class="card-header bg-warning text-dark">
                        <i class="fas fa-key me-2"></i>
                        <strong>Backup Codes - บันทึกไว้ในที่ปลอดภัย!</strong>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            ใช้รหัสเหล่านี้เมื่อไม่สามารถเข้าถึง Google Authenticator ได้ แต่ละรหัสใช้ได้เพียงครั้งเดียว
                        </p>
                        <div class="row g-2 mb-3">
                            <?php foreach ($backupCodes as $code): ?>
                                <div class="col-6 col-md-4">
                                    <code class="d-block p-2 bg-light rounded text-center"><?= $code ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" onclick="copyBackupCodes()">
                                <i class="fas fa-copy me-1"></i> คัดลอก
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="downloadBackupCodes()">
                                <i class="fas fa-download me-1"></i> ดาวน์โหลด
                            </button>
                        </div>
                    </div>
                </div>
                <script>
                    const backupCodes = <?= json_encode($backupCodes) ?>;

                    function copyBackupCodes() {
                        navigator.clipboard.writeText(backupCodes.join('\n'));
                        alert('คัดลอก Backup Codes แล้ว!');
                    }

                    function downloadBackupCodes() {
                        const text = "AI AutoPost SEO - Backup Codes\n" +
                                   "Generated: <?= date('Y-m-d H:i:s') ?>\n" +
                                   "================================\n\n" +
                                   backupCodes.join('\n');
                        const blob = new Blob([text], {type: 'text/plain'});
                        const a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = 'ai-autopost-backup-codes.txt';
                        a.click();
                    }
                </script>
            <?php endif; ?>

            <!-- 2FA Status Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <?php if ($isEnabled): ?>
                                <div class="bg-success bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="fas fa-lock fa-2x text-success"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1 text-success">2FA เปิดใช้งานอยู่</h5>
                                    <small class="text-muted">Backup codes เหลือ: <?= $remainingCodes ?> รหัส</small>
                                </div>
                            <?php else: ?>
                                <div class="bg-danger bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="fas fa-lock-open fa-2x text-danger"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1 text-danger">2FA ยังไม่เปิดใช้งาน</h5>
                                    <small class="text-muted">แนะนำให้เปิดเพื่อความปลอดภัย</small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isEnabled && !$setupData): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="setup">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus me-1"></i> ตั้งค่า 2FA
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Setup Flow -->
            <?php if ($setupData && !$isEnabled): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-qrcode me-2"></i>ตั้งค่า 2FA</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 text-center mb-4 mb-md-0">
                                <h6 class="mb-3">1. สแกน QR Code ด้วย Google Authenticator</h6>
                                <div id="qrcode" class="d-inline-block border rounded p-3 bg-white"></div>
                                <p class="mt-2 mb-0">
                                    <small class="text-muted">หรือใส่รหัสนี้:</small><br>
                                    <code class="user-select-all"><?= htmlspecialchars($setupData['secret']) ?></code>
                                </p>
                                <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
                                <script>
                                new QRCode(document.getElementById("qrcode"), {
                                    text: <?= json_encode($setupData['otpauth_uri'] ?? $setupData['qr_url']) ?>,
                                    width: 200,
                                    height: 200,
                                    colorDark: "#000000",
                                    colorLight: "#ffffff",
                                    correctLevel: QRCode.CorrectLevel.M
                                });
                                </script>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3">2. ใส่รหัส 6 หลักจากแอป</h6>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="enable">
                                    <div class="mb-3">
                                        <input type="text"
                                               name="code"
                                               class="form-control form-control-lg text-center"
                                               placeholder="000000"
                                               maxlength="6"
                                               pattern="[0-9]{6}"
                                               required
                                               autofocus>
                                    </div>
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-check me-1"></i> เปิดใช้งาน 2FA
                                    </button>
                                </form>

                                <hr class="my-4">

                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-mobile-alt me-2"></i>
                                    <strong>ต้องการแอป?</strong><br>
                                    <small>ดาวน์โหลด Google Authenticator:
                                        <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</a> |
                                        <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank">iOS</a>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Manage 2FA (when enabled) -->
            <?php if ($isEnabled): ?>
                <div class="row">
                    <!-- Regenerate Backup Codes -->
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-redo me-2"></i>สร้าง Backup Codes ใหม่</h6>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small">
                                    หากใช้ Backup Codes หมดหรือต้องการสร้างใหม่
                                </p>
                                <form method="POST" onsubmit="return confirm('Backup Codes เดิมจะใช้ไม่ได้อีก ต้องการดำเนินการต่อ?')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="regenerate">
                                    <div class="mb-3">
                                        <input type="password"
                                               name="password"
                                               class="form-control"
                                               placeholder="รหัสผ่านของคุณ"
                                               required>
                                    </div>
                                    <button type="submit" class="btn btn-warning w-100">
                                        <i class="fas fa-sync me-1"></i> สร้างใหม่
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Disable 2FA -->
                    <div class="col-md-6 mb-4">
                        <div class="card h-100 border-danger">
                            <div class="card-header bg-danger text-white">
                                <h6 class="mb-0"><i class="fas fa-shield-alt me-2"></i>ปิดการใช้งาน 2FA</h6>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small">
                                    <strong>คำเตือน:</strong> การปิด 2FA จะลดความปลอดภัยของบัญชี
                                </p>
                                <form method="POST" onsubmit="return confirm('ต้องการปิด 2FA จริงหรือไม่? บัญชีจะมีความปลอดภัยลดลง')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="disable">
                                    <div class="mb-3">
                                        <input type="password"
                                               name="password"
                                               class="form-control"
                                               placeholder="รหัสผ่านของคุณ"
                                               required>
                                    </div>
                                    <button type="submit" class="btn btn-outline-danger w-100">
                                        <i class="fas fa-lock-open me-1"></i> ปิด 2FA
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- How 2FA Works -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>2FA ทำงานอย่างไร?</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3 mb-md-0">
                            <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:60px;height:60px">
                                <i class="fas fa-key fa-lg text-primary"></i>
                            </div>
                            <h6>1. ป้อนรหัสผ่าน</h6>
                            <small class="text-muted">เข้าสู่ระบบด้วยรหัสผ่านปกติ</small>
                        </div>
                        <div class="col-md-4 text-center mb-3 mb-md-0">
                            <div class="bg-success bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:60px;height:60px">
                                <i class="fas fa-mobile-alt fa-lg text-success"></i>
                            </div>
                            <h6>2. เปิดแอป 2FA</h6>
                            <small class="text-muted">ดูรหัส 6 หลักจาก Google Authenticator</small>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="bg-info bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:60px;height:60px">
                                <i class="fas fa-check-circle fa-lg text-info"></i>
                            </div>
                            <h6>3. ยืนยันตัวตน</h6>
                            <small class="text-muted">ป้อนรหัสเพื่อเข้าใช้งาน</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
