<?php
$pageTitle = 'ตั้งค่า Email (SMTP)';
require_once __DIR__ . '/../includes/auth.php';
auth()->requireRole('admin');
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/mailer.php';

$flash = getFlash();

// ─── Save SMTP settings ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    setSetting('smtp_host',      trim($_POST['smtp_host'] ?? ''));
    setSetting('smtp_port',      (int)($_POST['smtp_port'] ?? 587));
    setSetting('smtp_user',      trim($_POST['smtp_user'] ?? ''));
    setSetting('smtp_from',      trim($_POST['smtp_from'] ?? ''));
    setSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? 'AI AutoPost SEO'));
    setSetting('smtp_secure',    in_array($_POST['smtp_secure'] ?? '', ['tls','ssl','none']) ? $_POST['smtp_secure'] : 'tls');
    if (!empty($_POST['smtp_pass'])) {
        setSetting('smtp_pass', trim($_POST['smtp_pass']));
    }

    setFlash('success', 'บันทึกการตั้งค่า SMTP เรียบร้อยแล้ว');
    redirect('/admin/settings_email.php');
}

// ─── Test send ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $testTo = trim($_POST['test_email'] ?? '');
    if (filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $ok = sendEmail($testTo, $testTo, '[Test] AI AutoPost SEO Email', '
            <h2>ทดสอบระบบ Email</h2>
            <p>ถ้าคุณได้รับอีเมลนี้แสดงว่าการตั้งค่า SMTP ถูกต้องแล้ว</p>
            <p style="color:#6B7280;font-size:12px;">ส่งจาก AI AutoPost SEO · ' . date('Y-m-d H:i:s') . '</p>
        ');
        setFlash($ok ? 'success' : 'error', $ok ? "ส่ง Test Email ไปที่ {$testTo} สำเร็จ" : 'ส่งไม่ได้ — ตรวจสอบ SMTP settings และ PHP error log');
    } else {
        setFlash('error', 'Email ทดสอบไม่ถูกต้อง');
    }
    redirect('/admin/settings_email.php');
}

// ─── Load settings ────────────────────────────────────────────────────────
$smtp = [
    'host'      => getSetting('smtp_host', ''),
    'port'      => getSetting('smtp_port', 587),
    'user'      => getSetting('smtp_user', ''),
    'from'      => getSetting('smtp_from', ''),
    'from_name' => getSetting('smtp_from_name', 'AI AutoPost SEO'),
    'secure'    => getSetting('smtp_secure', 'tls'),
];
$isConfigured = !empty($smtp['host']) && !empty($smtp['user']);

// ─── Pending password resets ──────────────────────────────────────────────
$pendingResets = db()->fetchAll("
    SELECT id, username, email, full_name, reset_token, reset_token_expires
    FROM members
    WHERE reset_token IS NOT NULL AND reset_token_expires > NOW()
    ORDER BY reset_token_expires ASC
");
$baseUrl = rtrim(BASE_URL, '/');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mb-4">
    <i class="fas fa-<?= $flash['type'] === 'success' ? 'check' : 'exclamation' ?>-circle me-2"></i><?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Pending resets alert -->
<?php if ($pendingResets): ?>
<div class="alert alert-warning mb-4">
    <i class="fas fa-clock me-2"></i>
    <strong>มี <?= count($pendingResets) ?> คำขอรีเซ็ตรหัสผ่านที่รอดำเนินการ</strong> — ดูด้านล่างแล้วส่งลิงก์ให้ผู้ใช้ทาง LINE/WhatsApp
</div>
<?php endif; ?>

<div class="row g-4">

<!-- SMTP Config Card -->
<div class="col-lg-7">
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-envelope-open-text me-2 text-primary"></i>ตั้งค่า SMTP Email</span>
        <?php if ($isConfigured): ?>
        <span class="badge bg-success">ตั้งค่าแล้ว</span>
        <?php else: ?>
        <span class="badge bg-secondary">ยังไม่ได้ตั้งค่า</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save">

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label">SMTP Host <span class="text-danger">*</span></label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= sanitize($smtp['host']) ?>"
                           placeholder="smtp.gmail.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= (int)$smtp['port'] ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Encryption</label>
                <select name="smtp_secure" class="form-select">
                    <option value="tls"  <?= $smtp['secure']==='tls'  ? 'selected':'' ?>>TLS (Port 587 — แนะนำ)</option>
                    <option value="ssl"  <?= $smtp['secure']==='ssl'  ? 'selected':'' ?>>SSL (Port 465)</option>
                    <option value="none" <?= $smtp['secure']==='none' ? 'selected':'' ?>>None (Port 25 — ไม่แนะนำ)</option>
                </select>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Username (Email) <span class="text-danger">*</span></label>
                    <input type="email" name="smtp_user" class="form-control" value="<?= sanitize($smtp['user']) ?>"
                           placeholder="your@gmail.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password / App Password</label>
                    <input type="password" name="smtp_pass" class="form-control"
                           placeholder="<?= $isConfigured ? '(ไม่เปลี่ยนถ้าว่าง)' : 'App Password' ?>">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">From Email</label>
                    <input type="email" name="smtp_from" class="form-control" value="<?= sanitize($smtp['from']) ?>"
                           placeholder="noreply@yourdomain.com (ว่าง = ใช้ username)">
                </div>
                <div class="col-md-6">
                    <label class="form-label">From Name</label>
                    <input type="text" name="smtp_from_name" class="form-control" value="<?= sanitize($smtp['from_name']) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>บันทึก</button>
        </form>

        <?php if ($isConfigured): ?>
        <hr class="my-4">
        <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="test">
            <input type="email" name="test_email" class="form-control form-control-sm" placeholder="test@example.com" required style="max-width:260px;">
            <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-paper-plane me-1"></i>ส่ง Test Email</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Quick Guide -->
<div class="col-lg-5">
<div class="card">
    <div class="card-header"><i class="fas fa-book me-2 text-info"></i>คำแนะนำการตั้งค่า</div>
    <div class="card-body" style="font-size:13px;">
        <p class="fw-semibold mb-2">Gmail (แนะนำ)</p>
        <ul class="text-muted mb-3" style="padding-left:18px;">
            <li>Host: <code>smtp.gmail.com</code> · Port: <code>587</code> · TLS</li>
            <li>Username: Gmail ของคุณ</li>
            <li>Password: <strong>App Password</strong> (ไม่ใช่รหัส Gmail ปกติ)</li>
            <li>สร้าง App Password: Google Account → Security → 2FA → App Passwords</li>
        </ul>
        <p class="fw-semibold mb-2">Outlook / Office365</p>
        <ul class="text-muted mb-3" style="padding-left:18px;">
            <li>Host: <code>smtp.office365.com</code> · Port: <code>587</code> · TLS</li>
        </ul>
        <p class="fw-semibold mb-2">ถ้ายังไม่มี SMTP</p>
        <p class="text-muted">ดูลิงก์รีเซ็ตในส่วน "รอดำเนินการ" ด้านล่าง แล้วส่งให้ผู้ใช้ทาง LINE/WhatsApp แทน</p>
    </div>
</div>
</div>

</div>

<!-- Pending Resets Table -->
<?php if ($pendingResets): ?>
<div class="card mt-4">
    <div class="card-header"><i class="fas fa-key me-2 text-warning"></i>ลิงก์รีเซ็ตรหัสผ่านที่รอดำเนินการ (<?= count($pendingResets) ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>สมาชิก</th>
                        <th>Email</th>
                        <th>หมดอายุ</th>
                        <th>ลิงก์รีเซ็ต (คัดลอกส่งให้ผู้ใช้)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingResets as $pr):
                        $link = $baseUrl . '/member/reset_password.php?token=' . $pr['reset_token'];
                        $exp  = strtotime($pr['reset_token_expires']);
                        $mins = round(($exp - time()) / 60);
                    ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($pr['username']) ?></strong>
                            <?php if ($pr['full_name']): ?>
                            <br><small class="text-muted"><?= sanitize($pr['full_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;"><?= sanitize($pr['email']) ?></td>
                        <td>
                            <span class="badge <?= $mins < 30 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                <?= $mins ?> นาที
                            </span>
                        </td>
                        <td>
                            <div class="input-group input-group-sm" style="max-width:360px;">
                                <input type="text" class="form-control" value="<?= sanitize($link) ?>" readonly id="link-<?= $pr['id'] ?>">
                                <button class="btn btn-outline-secondary" onclick="copyLink('link-<?= $pr['id'] ?>')">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function copyLink(id) {
    const el = document.getElementById(id);
    el.select();
    document.execCommand('copy');
    const btn = el.nextElementSibling;
    btn.innerHTML = '<i class="fas fa-check text-success"></i>';
    setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 2000);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
