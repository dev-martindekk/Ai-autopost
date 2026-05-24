<?php
require_once __DIR__ . '/../includes/member_auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if (memberAuth()->isLoggedIn()) redirect('/member/dashboard.php');

$success    = '';
$error      = '';
$emailSent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            $member = db()->fetchOne(
                "SELECT id, username, full_name FROM members WHERE email = ? AND is_active = 1",
                [$email]
            );
            if ($member) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                db()->update('members', [
                    'reset_token'         => $token,
                    'reset_token_expires' => $expiresAt,
                ], 'id = ?', [$member['id']]);

                $baseUrl   = rtrim(getSetting('base_url', BASE_URL), '/');
                $resetLink = $baseUrl . '/member/reset_password.php?token=' . $token;
                $name      = $member['full_name'] ?: $member['username'];

                $emailSent = sendPasswordResetEmail($email, $name, $resetLink);

                logEvent('info', 'member_auth', 'Password reset requested', [
                    'member_id'  => $member['id'],
                    'email_sent' => $emailSent,
                ]);
            }
            // Always show same message (prevent email enumeration)
            $success   = 'ถ้าอีเมลนี้มีในระบบ ลิงก์รีเซ็ตรหัสผ่านจะถูกส่งให้ภายในไม่กี่นาที';
            $emailSent = $emailSent || !$member; // hide fallback if email not found
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
    <title>ลืมรหัสผ่าน - Member Portal</title>
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
        .alert-success { background:rgba(16,185,129,.1); color:#047857; border:none; border-left:4px solid #10B981; border-radius:10px; font-size:13px; }
        .alert-danger  { background:rgba(239,68,68,.1); color:#B91C1C; border:none; border-left:4px solid #EF4444; border-radius:10px; font-size:13px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <i class="fas fa-lock-open fa-2x mb-2" style="opacity:.9;"></i>
            <h5 class="fw-bold mb-0">ลืมรหัสผ่าน</h5>
        </div>
        <div class="card-body">
            <?php if ($error):   ?><div class="alert alert-danger mb-3"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div><?php endif; ?>
            <?php if ($success): ?>
            <div class="alert alert-success mb-3">
                <i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?>
            </div>
            <?php if (!$emailSent): ?>
            <div class="alert alert-warning mb-3" style="font-size:13px;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>ระบบยังไม่ได้ตั้งค่า Email</strong> — กรุณาติดต่อ Admin เพื่อขอลิงก์รีเซ็ตรหัสผ่าน
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$success): ?>
            <p class="text-muted mb-4" style="font-size:13px;">กรอกอีเมลที่ใช้สมัคร เราจะส่งลิงก์รีเซ็ตรหัสผ่านให้</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="mb-3">
                    <label class="form-label">อีเมล</label>
                    <input type="email" name="email" class="form-control" placeholder="email@example.com" autofocus required>
                </div>
                <button type="submit" class="btn btn-submit">
                    <i class="fas fa-paper-plane me-2"></i>ส่งลิงก์รีเซ็ต
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
</body>
</html>
