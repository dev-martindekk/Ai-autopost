<?php
$pageTitle = 'จัดการ Staff';
require_once __DIR__ . '/header.php';

auth()->requireRole('super_admin');

// ต้องเป็น super_admin เท่านั้น
if (!auth()->isSuperAdmin()) {
    setFlash('error', 'เฉพาะ Super Admin เท่านั้น');
    redirect(ADMIN_URL . '/index.php');
}

$errors = [];

// Handle create staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_staff'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');

    if (empty($username)) $errors[] = 'กรุณากรอก Username';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
    if (strlen($password) < 8) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัว';

    if (empty($errors)) {
        $exists = db()->fetchOne("SELECT id FROM admin_users WHERE username=? OR email=?", [$username, $email]);
        if ($exists) {
            $errors[] = 'Username หรือ Email นี้ถูกใช้งานแล้ว';
        } else {
            db()->insert('admin_users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => $fullName ?: null,
                'role'          => 'staff',
                'is_active'     => 1,
            ]);
            $successMsg = "สร้าง Staff account สำเร็จ: {$username}";
        }
    }
}

// Handle toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $toggleId = (int)$_POST['toggle_id'];
    // Prevent disabling self
    if ($toggleId !== auth()->getUserId()) {
        db()->query("UPDATE admin_users SET is_active=NOT is_active WHERE id=? AND role='staff'", [$toggleId]);
    }
    redirect(ADMIN_URL . '/staff_list.php');
}

$staff = db()->fetchAll(
    "SELECT au.*, (SELECT COUNT(*) FROM payment_slips WHERE reviewed_by=au.id) AS slips_reviewed
     FROM admin_users au WHERE au.role IN ('staff','admin','super_admin')
     ORDER BY au.role DESC, au.created_at ASC"
);
?>

<div class="row g-4">
    <!-- Create Staff Form -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-user-plus me-2 text-primary"></i>เพิ่ม Staff</div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-3"><ul class="mb-0 ps-3"><?php foreach($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <?php if (isset($successMsg)): ?>
                <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><?= sanitize($successMsg) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="create_staff" value="1">

                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="staff_user" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input type="text" name="full_name" class="form-control" placeholder="ชื่อจริง">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">รหัสผ่าน <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="อย่างน้อย 8 ตัว" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-user-shield me-2"></i>สร้าง Staff Account
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>สิทธิ์แต่ละ Role</div>
            <div class="card-body" style="font-size:12px;">
                <div class="mb-2"><span class="badge bg-danger me-1">super_admin</span>ทุกอย่าง รวมถึง Staff management</div>
                <div class="mb-2"><span class="badge bg-gradient-primary me-1">admin</span>ทุกอย่าง ยกเว้นจัดการ Staff</div>
                <div><span class="badge bg-warning me-1">staff</span>ตรวจสลิป, ดูสมาชิก, ดูสถิติ</div>
            </div>
        </div>
    </div>

    <!-- Staff List -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fas fa-user-shield me-2 text-warning"></i>รายชื่อ Admin & Staff</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr><th>ผู้ใช้</th><th>Role</th><th>ตรวจสลิป</th><th>Login ล่าสุด</th><th>สถานะ</th><th>จัดการ</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff as $s): ?>
                            <?php $isSelf = ($s['id'] === auth()->getUserId()); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold" style="font-size:13px;">
                                        <?= sanitize($s['username']) ?>
                                        <?php if ($isSelf): ?> <span class="badge bg-info ms-1" style="font-size:9px;">คุณ</span><?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= sanitize($s['email']) ?></small>
                                </td>
                                <td>
                                    <?php $roleColors = ['super_admin'=>'danger','admin'=>'primary','staff'=>'warning','editor'=>'secondary']; ?>
                                    <span class="badge bg-<?= $roleColors[$s['role']] ?? 'secondary' ?>">
                                        <?= $s['role'] ?>
                                    </span>
                                </td>
                                <td><?= number_format($s['slips_reviewed']) ?></td>
                                <td><small class="text-muted"><?= $s['last_login'] ? date('d/m/y H:i', strtotime($s['last_login'])) : '-' ?></small></td>
                                <td>
                                    <?php if ($s['is_active']): ?>
                                        <span class="status-badge status-success">Active</span>
                                    <?php else: ?>
                                        <span class="status-badge status-danger">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$isSelf && $s['role'] === 'staff'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="toggle_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-<?= $s['is_active'] ? 'warning' : 'success' ?>"
                                                title="<?= $s['is_active'] ? 'Disable' : 'Enable' ?>">
                                            <i class="fas fa-<?= $s['is_active'] ? 'ban' : 'check' ?>"></i>
                                        </button>
                                    </form>
                                    <?php elseif ($isSelf): ?>
                                    <span class="text-muted" style="font-size:11px;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
