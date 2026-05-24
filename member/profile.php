<?php
$pageTitle = 'โปรไฟล์ & ความปลอดภัย';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/two_factor_auth.php';

$memberId = (int)$currentMember['id'];
$errors   = [];
$success  = '';
$error    = '';

$is2faEnabled   = TwoFactorAuth::isEnabled($memberId, 'member');
$remainingCodes = $is2faEnabled ? TwoFactorAuth::getRemainingBackupCodesCount($memberId, 'member') : 0;
$setupData      = null;
$backupCodes    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } elseif ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');

        db()->update('members', [
            'full_name' => $fullName ?: null,
            'phone'     => $phone ?: null,
        ], 'id = ?', [$memberId]);

        $success = 'อัพเดตข้อมูลเรียบร้อยแล้ว';
        $currentMember = db()->fetchOne("SELECT * FROM members WHERE id=?", [$memberId]);

    } elseif ($action === 'change_password') {
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd     = $_POST['new_password'] ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPwd, $currentMember['password_hash'])) {
            $errors[] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } elseif (strlen($newPwd) < 8) {
            $errors[] = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
        } elseif ($newPwd !== $confirmPwd) {
            $errors[] = 'รหัสผ่านใหม่ไม่ตรงกัน';
        }

        if (empty($errors)) {
            db()->update('members', ['password_hash' => password_hash($newPwd, PASSWORD_DEFAULT)], 'id = ?', [$memberId]);
            $success = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
        }

    } elseif ($action === 'setup') {
        $result = TwoFactorAuth::setup($memberId, 'member');
        if ($result['success']) $setupData = $result;
        else $error = $result['message'];

    } elseif ($action === 'enable') {
        $result = TwoFactorAuth::enable($memberId, 'member', $_POST['code'] ?? '');
        if ($result['success']) {
            $success = $result['message'];
            $backupCodes = $result['backup_codes'];
            $is2faEnabled = true;
            $remainingCodes = 10;
        } else {
            $error = $result['message'];
            $setupData = TwoFactorAuth::setup($memberId, 'member');
        }

    } elseif ($action === 'disable') {
        $result = TwoFactorAuth::disable($memberId, 'member', $_POST['password'] ?? '');
        if ($result['success']) { $success = $result['message']; $is2faEnabled = false; }
        else $error = $result['message'];

    } elseif ($action === 'regenerate') {
        $result = TwoFactorAuth::regenerateBackupCodes($memberId, 'member', $_POST['password'] ?? '');
        if ($result['success']) { $success = $result['message']; $backupCodes = $result['backup_codes']; $remainingCodes = 10; }
        else $error = $result['message'];
    }
}
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div>
<?php endif; ?>

<!-- Profile & Password -->
<div class="row g-4 mb-4">
    <!-- Profile Info -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-user me-2 text-primary"></i>ข้อมูลส่วนตัว</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= sanitize($currentMember['username']) ?>" readonly style="background:#F8FAFC;">
                        <small class="text-muted">ไม่สามารถเปลี่ยน username ได้</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= sanitize($currentMember['email']) ?>" readonly style="background:#F8FAFC;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?= sanitize($currentMember['full_name'] ?? '') ?>" placeholder="ชื่อจริง">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">เบอร์โทรศัพท์</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= sanitize($currentMember['phone'] ?? '') ?>" placeholder="08x-xxx-xxxx">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>บันทึกข้อมูล
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password + Account Info -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-lock me-2 text-warning"></i>เปลี่ยนรหัสผ่าน</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านปัจจุบัน</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านใหม่</label>
                        <input type="password" name="new_password" class="form-control" placeholder="อย่างน้อย 8 ตัวอักษร" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>ข้อมูลบัญชี</div>
            <div class="card-body">
                <?php $rows = [
                    ['สมัครเมื่อ',   !empty($currentMember['created_at'])  ? date('d/m/Y H:i', strtotime($currentMember['created_at']))  : '-'],
                    ['Login ล่าสุด', !empty($currentMember['last_login'])   ? date('d/m/Y H:i', strtotime($currentMember['last_login']))   : '-'],
                    ['สถานะ',        $currentMember['is_active']  ? 'Active'    : 'Suspended'],
                    ['ยืนยัน Email', $currentMember['is_verified'] ? 'ยืนยันแล้ว' : 'ยังไม่ยืนยัน'],
                ]; ?>
                <?php foreach ($rows as [$label, $val]): ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted" style="font-size:12px;"><?= $label ?></span>
                    <span style="font-size:12px;font-weight:600;"><?= sanitize($val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 2FA Section -->
<h5 class="mb-3"><i class="fas fa-shield-alt me-2 text-primary"></i>การยืนยันตัวตนสองขั้นตอน (2FA)</h5>

<?php if ($backupCodes): ?>
<div class="card border-warning mb-4">
    <div class="card-header" style="background:#FEF3C7;color:#92400E;">
        <i class="fas fa-key me-2"></i><strong>Backup Codes — บันทึกไว้ในที่ปลอดภัย!</strong>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">ใช้รหัสเหล่านี้เมื่อไม่สามารถเข้าถึง Google Authenticator ได้ แต่ละรหัสใช้ได้เพียงครั้งเดียว</p>
        <div class="row g-2 mb-3">
            <?php foreach ($backupCodes as $code): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <code class="d-block p-2 bg-light rounded text-center"><?= $code ?></code>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" onclick="copyBackupCodes()"><i class="fas fa-copy me-1"></i>คัดลอก</button>
            <button class="btn btn-outline-secondary btn-sm" onclick="downloadBackupCodes()"><i class="fas fa-download me-1"></i>ดาวน์โหลด</button>
        </div>
    </div>
</div>
<script>
const backupCodes = <?= json_encode($backupCodes) ?>;
function copyBackupCodes() { navigator.clipboard.writeText(backupCodes.join('\n')); alert('คัดลอก Backup Codes แล้ว!'); }
function downloadBackupCodes() {
    const text = "AI AutoPost SEO - Member Backup Codes\nGenerated: <?= date('Y-m-d H:i:s') ?>\n================================\n\n" + backupCodes.join('\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text], {type:'text/plain'}));
    a.download = 'backup-codes.txt'; a.click();
}
</script>
<?php endif; ?>

<!-- 2FA Status -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <?php if ($is2faEnabled): ?>
                <div class="rounded-circle p-3 me-3" style="background:rgba(16,185,129,.1);">
                    <i class="fas fa-lock fa-2x text-success"></i>
                </div>
                <div>
                    <h5 class="mb-1 text-success">2FA เปิดใช้งานอยู่</h5>
                    <small class="text-muted">Backup codes เหลือ: <?= $remainingCodes ?> รหัส</small>
                </div>
                <?php else: ?>
                <div class="rounded-circle p-3 me-3" style="background:rgba(239,68,68,.1);">
                    <i class="fas fa-lock-open fa-2x text-danger"></i>
                </div>
                <div>
                    <h5 class="mb-1 text-danger">2FA ยังไม่เปิดใช้งาน</h5>
                    <small class="text-muted">แนะนำให้เปิดเพื่อความปลอดภัย</small>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!$is2faEnabled && !$setupData): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="setup">
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i>ตั้งค่า 2FA</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Setup Flow -->
<?php if ($setupData && !$is2faEnabled): ?>
<div class="card mb-4">
    <div class="card-header"><i class="fas fa-qrcode me-2"></i><strong>ตั้งค่า 2FA</strong></div>
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
                    width: 200, height: 200, colorDark: "#000000", colorLight: "#ffffff",
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
                        <input type="text" name="code" class="form-control form-control-lg text-center"
                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-check me-1"></i>เปิดใช้งาน 2FA</button>
                </form>
                <hr>
                <div class="alert alert-info mb-0" style="font-size:13px;">
                    <i class="fas fa-mobile-alt me-2"></i><strong>ต้องการแอป?</strong><br>
                    ดาวน์โหลด Google Authenticator:
                    <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</a> |
                    <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank">iOS</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Manage when 2FA enabled -->
<?php if ($is2faEnabled): ?>
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="fas fa-redo me-2"></i>สร้าง Backup Codes ใหม่</div>
            <div class="card-body">
                <p class="text-muted small">หากใช้ Backup Codes หมดหรือต้องการสร้างใหม่</p>
                <form method="POST" onsubmit="return confirm('Backup Codes เดิมจะใช้ไม่ได้อีก ต้องการดำเนินการต่อ?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="regenerate">
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="รหัสผ่านของคุณ" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100"><i class="fas fa-sync me-1"></i>สร้างใหม่</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 border-danger">
            <div class="card-header text-danger"><i class="fas fa-shield-alt me-2"></i>ปิดการใช้งาน 2FA</div>
            <div class="card-body">
                <p class="text-muted small"><strong>คำเตือน:</strong> การปิด 2FA จะลดความปลอดภัยของบัญชี</p>
                <form method="POST" onsubmit="return confirm('ต้องการปิด 2FA จริงหรือไม่?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="disable">
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="รหัสผ่านของคุณ" required>
                    </div>
                    <button type="submit" class="btn btn-outline-danger w-100"><i class="fas fa-lock-open me-1"></i>ปิด 2FA</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- How 2FA works -->
<div class="card">
    <div class="card-header"><i class="fas fa-info-circle me-2"></i>2FA ทำงานอย่างไร?</div>
    <div class="card-body">
        <div class="row text-center">
            <?php foreach ([
                ['fa-key',        'primary', '1. ป้อนรหัสผ่าน',  'เข้าสู่ระบบด้วยรหัสผ่านปกติ'],
                ['fa-mobile-alt', 'success', '2. เปิดแอป 2FA',   'ดูรหัส 6 หลักจาก Google Authenticator'],
                ['fa-check-circle','info',   '3. ยืนยันตัวตน',   'ป้อนรหัสเพื่อเข้าใช้งาน'],
            ] as [$icon, $color, $title, $desc]): ?>
            <div class="col-md-4 mb-3 mb-md-0">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2"
                     style="width:60px;height:60px;background:rgba(var(--bs-<?= $color ?>-rgb),.1)">
                    <i class="fas <?= $icon ?> fa-lg text-<?= $color ?>"></i>
                </div>
                <h6><?= $title ?></h6>
                <small class="text-muted"><?= $desc ?></small>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
