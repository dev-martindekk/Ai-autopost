<?php
require_once __DIR__ . '/../includes/member_auth.php';

if (memberAuth()->isLoggedIn()) redirect('/member/dashboard.php');

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';

if (!$token) {
    redirect('/member/forgot_password.php');
}

// Validate token
$member = db()->fetchOne(
    "SELECT id FROM members WHERE reset_token = ? AND reset_token_expires > NOW() AND is_active = 1",
    [$token]
);

if (!$member) {
    $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุ';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $member) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session หมดอายุ';
    } else {
        $pwd     = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($pwd) < 8) {
            $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        } elseif ($pwd !== $confirm) {
            $error = 'รหัสผ่านไม่ตรงกัน';
        } else {
            db()->update('members', [
                'password_hash'       => password_hash($pwd, PASSWORD_DEFAULT),
                'reset_token'         => null,
                'reset_token_expires' => null,
                'login_attempts'      => 0,
                'locked_until'        => null,
            ], 'id = ?', [$member['id']]);

            logEvent('info', 'member_auth', 'Password reset completed', ['member_id' => $member['id']]);
            $success = 'รีเซ็ตรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบใหม่';
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รีเซ็ตรหัสผ่าน - Member Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background:linear-gradient(135deg,#667eea,#764ba2); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .card { border:none; border-radius:24px; box-shadow:0 25px 50px -12px rgba(0,0,0,.3); max-width:420px; width:100%; overflow:hidden; }
        .card-header { background:linear-gradient(135deg,#6366F1,#8B5CF6); padding:28px 30px; text-align:center; color:#fff; border:none; }
        .card-body { padding:30px; }
        .form-control { border-radius:10px; padding:12px 14px; border:2px solid #E2E8F0; font-size:14px; }
        .form-control:focus { border-color:#6366F1; box-shadow:0 0 0 4px rgba(99,102,241,.1); }
        .form-label { font-weight:600; font-size:13px; color:#374151; }
        .btn-submit { background:linear-gradient(135deg,#6366F1,#8B5CF6); color:#fff; border:none;
            border-radius:10px; padding:12px; width:100%; font-weight:700; }
        .input-group-text { border:2px solid #E2E8F0; border-left:none; border-radius:0 10px 10px 0; background:#f8f9fa; }
        .input-group .form-control { border-radius:10px 0 0 10px; }
        .alert-success { background:rgba(16,185,129,.1); color:#047857; border:none; border-left:4px solid #10B981; border-radius:10px; font-size:13px; }
        .alert-danger  { background:rgba(239,68,68,.1); color:#B91C1C; border:none; border-left:4px solid #EF4444; border-radius:10px; font-size:13px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <i class="fas fa-key fa-2x mb-2" style="opacity:.9;"></i>
            <h5 class="fw-bold mb-0">รีเซ็ตรหัสผ่าน</h5>
        </div>
        <div class="card-body">
            <?php if ($error):   ?><div class="alert alert-danger mb-3"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div><?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div>
                <a href="/member/login.php" class="btn btn-submit">ไปหน้าเข้าสู่ระบบ</a>
            <?php elseif ($member): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="mb-3">
                    <label class="form-label">รหัสผ่านใหม่</label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" placeholder="อย่างน้อย 8 ตัว" required>
                        <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd(this)">
                            <i class="fas fa-eye text-muted"></i>
                        </span>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="ยืนยันรหัสผ่าน" required>
                </div>
                <button type="submit" class="btn btn-submit">
                    <i class="fas fa-save me-2"></i>บันทึกรหัสผ่านใหม่
                </button>
            </form>
            <?php endif; ?>

            <div class="text-center mt-3" style="font-size:13px;">
                <a href="/member/login.php" class="text-decoration-none" style="color:#6366F1;">
                    <i class="fas fa-arrow-left me-1"></i>กลับหน้าเข้าสู่ระบบ
                </a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePwd(el) {
            const input = el.closest('.input-group').querySelector('input');
            const icon  = el.querySelector('i');
            if (input.type === 'password') { input.type='text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
            else { input.type='password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
        }
    </script>
</body>
</html>
