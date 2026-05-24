<?php
require_once __DIR__ . '/../includes/member_auth.php';

if (memberAuth()->isLoggedIn()) {
    redirect('/member/dashboard.php');
}

$token = $_GET['token'] ?? '';
$error = '';

$pending = TwoFactorAuth::getPendingLogin($token);
if (!$pending || $pending['user_type'] !== 'member') {
    setFlash('error', 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่');
    redirect('/member/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } else {
        $result = memberAuth()->completeLoginWith2FA($token, $code);
        if ($result['success']) {
            redirect($result['redirect']);
        } else {
            $error = $result['message'];
            $pending = TwoFactorAuth::getPendingLogin($token);
            if (!$pending) {
                setFlash('error', 'พยายามยืนยันตัวตนผิดพลาดหลายครั้ง กรุณาเข้าสู่ระบบใหม่');
                redirect('/member/login.php');
            }
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
    <title>ยืนยันตัวตน 2FA - Member Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%); min-height: 100vh;
               display: flex; align-items: center; justify-content: center; }
        .verify-container { background: white; border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); max-width: 420px; width: 100%; overflow: hidden; }
        .verify-header { background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
            padding: 40px 30px; text-align: center; color: white; }
        .shield-icon { width: 80px; height: 80px; background: rgba(255,255,255,.2); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 36px; }
        .verify-body { padding: 40px 30px; }
        .code-input { letter-spacing: 8px; font-size: 24px; text-align: center; font-family: monospace; }
        .divider { display: flex; align-items: center; margin: 20px 0; }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid #e0e0e0; }
        .divider span { padding: 0 10px; color: #888; font-size: 12px; }
        .timer { font-size: 12px; color: #888; }
        .alert { border-radius: 10px; border: none; }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-header">
            <div class="shield-icon"><i class="fas fa-shield-alt"></i></div>
            <h1 style="font-size:22px;font-weight:600;margin-bottom:5px;">ยืนยันตัวตน 2FA</h1>
            <p style="font-size:14px;opacity:.9;margin:0;">ป้อนรหัสจาก Google Authenticator</p>
        </div>
        <div class="verify-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="verifyForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="mb-4">
                    <label class="form-label text-muted small">รหัส 6 หลัก</label>
                    <input type="text" name="code" class="form-control form-control-lg code-input"
                           placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                           inputmode="numeric" autocomplete="one-time-code" required autofocus>
                    <div class="timer mt-2 text-center"><i class="fas fa-clock me-1"></i>รหัสเปลี่ยนทุก 30 วินาที</div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3" style="padding:13px;font-size:15px;font-weight:700;border-radius:10px;">
                    <i class="fas fa-check me-2"></i>ยืนยัน
                </button>
            </form>

            <div class="divider"><span>หรือ</span></div>

            <form method="POST" id="backupForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <p class="text-muted small text-center mb-2">ไม่สามารถเข้าถึงแอปได้? ใช้ Backup Code</p>
                <div class="input-group mb-3">
                    <input type="text" name="code" class="form-control" placeholder="XXXX-XXXX" maxlength="9">
                    <button type="submit" class="btn btn-outline-secondary">ใช้</button>
                </div>
            </form>

            <div class="text-center mt-4">
                <a href="/member/login.php" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>กลับหน้าเข้าสู่ระบบ</a>
            </div>
        </div>
    </div>
    <script>
        document.querySelector('.code-input').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) setTimeout(() => document.getElementById('verifyForm').submit(), 100);
        });
        document.querySelector('#backupForm input').addEventListener('input', function() {
            let v = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
            if (v.length > 4) v = v.slice(0, 4) + '-' + v.slice(4, 8);
            this.value = v;
        });
    </script>
</body>
</html>
