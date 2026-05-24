<?php
require_once __DIR__ . '/../includes/member_auth.php';
require_once __DIR__ . '/../includes/plan_manager.php';

if (memberAuth()->isLoggedIn()) {
    redirect('/member/dashboard.php');
}

$error   = '';
$success = '';
$inviteCode = trim($_GET['invite'] ?? $_POST['invite_code'] ?? '');
$inviteData = null;

// Validate invite code if provided
if ($inviteCode) {
    $inviteData = db()->fetchOne(
        "SELECT ic.*, p.name AS plan_name, p.price, p.is_trial
         FROM invite_codes ic
         JOIN plans p ON p.id = ic.plan_id
         WHERE ic.code = ? AND ic.is_active = 1
           AND (ic.expires_at IS NULL OR ic.expires_at > NOW())
           AND (ic.max_uses = 0 OR ic.used_count < ic.max_uses)",
        [$inviteCode]
    );
    if (!$inviteData) {
        $error = 'Invite Code ไม่ถูกต้องหรือหมดอายุ';
        $inviteCode = '';
    }
}

// Load active plans for public selection
$plans = planManager()->getAllActivePlans();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } elseif (!security()->rateLimit('member_register', 3, 300)) {
        // Max 3 registrations per IP per 5 minutes
        $error = 'สมัครสมาชิกเร็วเกินไป กรุณารอสักครู่';
        security()->logEvent('register_abuse', 'high', 'Registration rate limit exceeded');
    } else {
        $result = memberAuth()->register([
            'username'         => $_POST['username'] ?? '',
            'email'            => $_POST['email'] ?? '',
            'password'         => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
            'full_name'        => $_POST['full_name'] ?? '',
            'phone'            => $_POST['phone'] ?? '',
        ]);

        if ($result['success']) {
            $memberId = $result['member_id'];

            if ($inviteData) {
                // Apply invite code → verify immediately + activate plan
                db()->update('members', ['is_verified' => 1], 'id = ?', [$memberId]);

                if (!empty($inviteData['is_trial'])) {
                    planManager()->activateTrialPlan($memberId);
                } else {
                    planManager()->activateOrExtend($memberId, (int)$inviteData['plan_id'], (int)$inviteData['months_duration']);
                }

                // Record invite code use
                db()->insert('invite_code_uses', ['invite_code_id' => $inviteData['id'], 'member_id' => $memberId]);
                db()->query("UPDATE invite_codes SET used_count = used_count + 1 WHERE id = ?", [$inviteData['id']]);
                db()->update('members', ['invite_code_id' => $inviteData['id']], 'id = ?', [$memberId]);

                logEvent('info', 'register', 'Member registered via invite code', ['member_id' => $memberId, 'code' => $inviteCode]);

                $planLabel = !empty($inviteData['is_trial'])
                    ? 'Trial (5 บทความตลอดชีพ)'
                    : sanitize($inviteData['plan_name']) . ' ' . $inviteData['months_duration'] . ' เดือน';
                setFlash('success', "สมัครสมาชิกสำเร็จ! ได้รับแพ็คเกจ {$planLabel} เรียบร้อยแล้ว");
                redirect('/member/login.php');
            } else {
                // Auto-assign Trial plan
                planManager()->activateTrialPlan($memberId);
                $success = 'สมัครสมาชิกสำเร็จ! คุณได้รับแพ็คเกจ Trial ฟรี 5 บทความ กรุณารอการยืนยันจาก Admin เพื่อเปิดใช้งานบัญชี';
                logEvent('info', 'register', 'New member registered (pending approval, trial assigned)', ['member_id' => $memberId]);
                try {
                    require_once __DIR__ . '/../includes/telegram_client.php';
                    $newMember = db()->fetchOne("SELECT username, email FROM members WHERE id=?", [$memberId]);
                    (new TelegramClient())->notifyNewMember($newMember);
                } catch (Exception $e) {}
            }
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
    <title>สมัครสมาชิก - Member Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .register-card { background:#fff; border-radius:24px; box-shadow:0 25px 50px -12px rgba(0,0,0,.35); max-width:520px; width:100%; overflow:hidden; }
        .card-header-reg { background:linear-gradient(135deg,#6366F1,#8B5CF6); padding:30px 32px 24px; text-align:center; color:#fff; }
        .card-body-reg { padding:32px; }
        .brand-icon { width:56px; height:56px; background:rgba(255,255,255,.2); border-radius:16px;
            display:inline-flex; align-items:center; justify-content:center; font-size:24px; margin-bottom:12px; }
        .form-control { border-radius:10px; padding:11px 14px; border:2px solid #E2E8F0; font-size:14px; }
        .form-control:focus { border-color:#6366F1; box-shadow:0 0 0 4px rgba(99,102,241,.1); }
        .form-label { font-weight:600; font-size:13px; color:#374151; margin-bottom:5px; }
        .btn-register { background:linear-gradient(135deg,#6366F1,#8B5CF6); color:#fff; border:none;
            border-radius:10px; padding:12px; font-size:15px; font-weight:700; width:100%;
            box-shadow:0 4px 14px rgba(99,102,241,.4); transition:all .2s; }
        .btn-register:hover { transform:translateY(-1px); color:#fff; }
        .invite-badge { background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(52,211,153,.15));
            color:#047857; border-radius:12px; padding:12px 16px; border-left:4px solid #10B981; font-size:13px; }
        .alert-danger  { background:rgba(239,68,68,.1); color:#B91C1C; border:none; border-left:4px solid #EF4444; border-radius:10px; font-size:13px; }
        .alert-success { background:rgba(16,185,129,.1); color:#047857; border:none; border-left:4px solid #10B981; border-radius:10px; font-size:13px; }
        .input-group-text { border:2px solid #E2E8F0; border-left:none; border-radius:0 10px 10px 0; background:#f8f9fa; }
        .input-group .form-control { border-radius:10px 0 0 10px; }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="card-header-reg">
            <div class="brand-icon"><i class="fas fa-user-plus"></i></div>
            <h4 class="fw-bold mb-1">สมัครสมาชิก</h4>
            <p class="mb-0" style="opacity:.8;font-size:13px;">AI AutoPost SEO Member Portal</p>
        </div>

        <div class="card-body-reg">
            <?php if ($inviteData): ?>
            <div class="invite-badge mb-4">
                <i class="fas fa-ticket me-2"></i>
                <strong>Invite Code:</strong> <?= sanitize($inviteCode) ?> —
                ได้รับ <strong><?= sanitize($inviteData['plan_name']) ?></strong>
                (<?= $inviteData['months_duration'] ?> เดือน) ฟรี!
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger mb-3"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div>
                <div class="text-center mt-2">
                    <a href="/member/login.php" class="btn btn-register" style="display:inline-block;width:auto;padding:10px 28px;">
                        ไปหน้าเข้าสู่ระบบ
                    </a>
                </div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="invite_code" value="<?= sanitize($inviteCode) ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control"
                               placeholder="username (a-z, 0-9, _)"
                               value="<?= sanitize($_POST['username'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input type="text" name="full_name" class="form-control"
                               placeholder="ชื่อจริง"
                               value="<?= sanitize($_POST['full_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">อีเมล <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           placeholder="email@example.com"
                           value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="tel" name="phone" class="form-control"
                           placeholder="08x-xxx-xxxx"
                           value="<?= sanitize($_POST['phone'] ?? '') ?>">
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">รหัสผ่าน <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" placeholder="อย่างน้อย 8 ตัว" required>
                            <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd(this)">
                                <i class="fas fa-eye text-muted"></i>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="ยืนยันรหัสผ่าน" required>
                    </div>
                </div>

                <?php if (!$inviteData && !empty($plans)): ?>
                <div class="mb-4">
                    <label class="form-label">มี Invite Code?</label>
                    <input type="text" name="invite_code" class="form-control"
                           placeholder="กรอก Invite Code (ถ้ามี)"
                           value="">
                    <small class="text-muted">ถ้าไม่มี สมาชิกจะรอการยืนยันจาก Admin</small>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-register">
                    <i class="fas fa-user-plus me-2"></i>สมัครสมาชิก
                </button>
            </form>
            <?php endif; ?>

            <div class="text-center mt-3" style="font-size:13px;color:#64748B;">
                มีบัญชีแล้ว?
                <a href="/member/login.php" class="text-decoration-none" style="color:#6366F1;">เข้าสู่ระบบ</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePwd(el) {
            const input = el.closest('.input-group').querySelector('input');
            const icon  = el.querySelector('i');
            if (input.type === 'password') { input.type = 'text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
            else { input.type = 'password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
        }
    </script>
</body>
</html>
