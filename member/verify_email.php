<?php
require_once __DIR__ . '/../includes/member_auth.php';

$token  = trim($_GET['token'] ?? '');
$result = $token ? memberAuth()->verifyEmail($token) : ['success' => false, 'message' => 'Token ไม่ถูกต้อง'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยืนยันอีเมล - Member Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background:linear-gradient(135deg,#667eea,#764ba2); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .card { border:none; border-radius:24px; box-shadow:0 25px 50px -12px rgba(0,0,0,.3); max-width:400px; width:100%; text-align:center; overflow:hidden; }
        .card-body { padding:40px 30px; }
        .icon-circle { width:80px; height:80px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:32px; margin-bottom:20px; }
        .btn-go { background:linear-gradient(135deg,#6366F1,#8B5CF6); color:#fff; border:none;
            border-radius:10px; padding:11px 28px; font-weight:700; text-decoration:none; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-body">
            <?php if ($result['success']): ?>
                <div class="icon-circle bg-success bg-opacity-10 text-success mx-auto">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h4 class="fw-bold mb-2">ยืนยันอีเมลสำเร็จ!</h4>
                <p class="text-muted mb-4" style="font-size:14px;">บัญชีของคุณพร้อมใช้งานแล้ว</p>
            <?php else: ?>
                <div class="icon-circle bg-danger bg-opacity-10 text-danger mx-auto">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h4 class="fw-bold mb-2">เกิดข้อผิดพลาด</h4>
                <p class="text-muted mb-4" style="font-size:14px;"><?= sanitize($result['message']) ?></p>
            <?php endif; ?>
            <a href="/member/login.php" class="btn btn-go">
                <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
            </a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
