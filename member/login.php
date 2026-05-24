<?php
require_once __DIR__ . '/../includes/member_auth.php';

if (memberAuth()->isLoggedIn()) {
    redirect('/member/dashboard.php');
}

$error = '';
$inviteCode = $_GET['invite'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } elseif (!security()->rateLimit('member_login', 10, 60)) {
        // Max 10 login attempts per IP per minute
        $error = 'พยายาม Login เร็วเกินไป กรุณารอสักครู่';
        security()->logEvent('login_abuse', 'high', 'Login rate limit exceeded');
    } else {
        $result = memberAuth()->login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['success']) {
            redirect($result['redirect']);
        } else {
            $error = $result['message'];
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
    <title>เข้าสู่ระบบ - Member Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .login-card { background:#fff; border-radius:24px; box-shadow:0 25px 50px -12px rgba(0,0,0,.35); max-width:420px; width:100%; overflow:hidden; }
        .login-header { background:linear-gradient(135deg,#6366F1,#8B5CF6); padding:36px 32px 28px; text-align:center; color:#fff; }
        .login-body { padding:32px; }
        .brand-icon { width:64px; height:64px; background:rgba(255,255,255,.2); border-radius:18px;
            display:inline-flex; align-items:center; justify-content:center; font-size:28px; margin-bottom:14px; }
        .form-control { border-radius:12px; padding:12px 16px; border:2px solid #E2E8F0; font-size:14px; }
        .form-control:focus { border-color:#6366F1; box-shadow:0 0 0 4px rgba(99,102,241,.1); }
        .form-label { font-weight:600; font-size:13px; color:#374151; }
        .btn-login { background:linear-gradient(135deg,#6366F1,#8B5CF6); color:#fff; border:none;
            border-radius:12px; padding:13px; font-size:15px; font-weight:700; width:100%;
            box-shadow:0 4px 14px rgba(99,102,241,.4); transition:all .2s; }
        .btn-login:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(99,102,241,.5); color:#fff; }
        .input-group-text { border-radius:0 12px 12px 0; border:2px solid #E2E8F0; border-left:none; background:#f8f9fa; }
        .input-group .form-control { border-radius:12px 0 0 12px; }
        .alert-danger { background:rgba(239,68,68,.1); color:#B91C1C; border:none; border-left:4px solid #EF4444; border-radius:10px; font-size:14px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="brand-icon"><i class="fas fa-robot"></i></div>
            <h4 class="fw-bold mb-1">Member Portal</h4>
            <p class="mb-0" style="opacity:.8;font-size:14px;">AI AutoPost SEO System</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger mb-3"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="mb-3">
                    <label class="form-label">ชื่อผู้ใช้ / อีเมล</label>
                    <input type="text" name="username" class="form-control" placeholder="username หรือ email"
                           value="<?= sanitize($_POST['username'] ?? '') ?>" autofocus required>
                </div>
                <div class="mb-4">
                    <label class="form-label">รหัสผ่าน</label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" placeholder="รหัสผ่าน" required>
                        <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd(this)">
                            <i class="fas fa-eye text-muted"></i>
                        </span>
                    </div>
                </div>
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
                </button>
            </form>

            <hr class="my-4">
            <div class="text-center" style="font-size:13px;color:#64748B;">
                <a href="/member/forgot_password.php" class="text-decoration-none" style="color:#6366F1;">ลืมรหัสผ่าน?</a>
                <span class="mx-2">|</span>
                <a href="/member/register.php<?= $inviteCode ? '?invite='.$inviteCode : '' ?>" class="text-decoration-none" style="color:#6366F1;">
                    สมัครสมาชิก
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePwd(el) {
            const input = el.closest('.input-group').querySelector('input');
            const icon  = el.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye','fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash','fa-eye');
            }
        }
    </script>
</body>
</html>
