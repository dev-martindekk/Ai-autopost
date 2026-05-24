<?php
/**
 * AI AutoPost SEO System - User Profile
 * ======================================
 */

$pageTitle = 'โปรไฟล์';
require_once __DIR__ . '/header.php';

// Get current user
$user = db()->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$_SESSION['user_id']]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Session หมดอายุ');
        redirect(ADMIN_URL . '/profile.php');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if (empty($fullName)) {
                setFlash('error', 'กรุณากรอกชื่อ');
            } else {
                db()->update('admin_users', [
                    'full_name' => $fullName,
                    'email' => $email
                ], 'id = ?', [$_SESSION['user_id']]);

                setFlash('success', 'อัพเดทโปรไฟล์สำเร็จ');
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!password_verify($currentPassword, $user['password_hash'])) {
                setFlash('error', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
            } elseif (strlen($newPassword) < 8) {
                setFlash('error', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
            } elseif ($newPassword !== $confirmPassword) {
                setFlash('error', 'รหัสผ่านใหม่ไม่ตรงกัน');
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                db()->update('admin_users', [
                    'password_hash' => $newHash
                ], 'id = ?', [$_SESSION['user_id']]);

                setFlash('success', 'เปลี่ยนรหัสผ่านสำเร็จ');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    redirect(ADMIN_URL . '/profile.php');
}

// Refresh user data
$user = db()->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$_SESSION['user_id']]);
?>

<div class="page-header">
    <h1 class="page-title">โปรไฟล์ของฉัน</h1>
    <p class="page-subtitle">จัดการข้อมูลส่วนตัวและรหัสผ่าน</p>
</div>

<div class="row">
    <!-- Profile Info -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user me-2"></i>ข้อมูลโปรไฟล์
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= sanitize($user['username']) ?>" disabled>
                        <small class="text-muted">ไม่สามารถเปลี่ยน username ได้</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name"
                               value="<?= sanitize($user['full_name'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">อีเมล</label>
                        <input type="email" class="form-control" name="email"
                               value="<?= sanitize($user['email'] ?? '') ?>"
                               placeholder="your@email.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control"
                               value="<?= ucfirst($user['role']) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">สร้างเมื่อ</label>
                        <input type="text" class="form-control"
                               value="<?= date('d/m/Y H:i', strtotime($user['created_at'])) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">เข้าสู่ระบบล่าสุด</label>
                        <input type="text" class="form-control"
                               value="<?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-' ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>บันทึก
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-lock me-2"></i>เปลี่ยนรหัสผ่าน
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="new_password"
                               required minlength="8">
                        <small class="text-muted">อย่างน้อย 8 ตัวอักษร</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="confirm_password"
                               required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน
                    </button>
                </form>
            </div>
        </div>

        <!-- 2FA Security -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-shield-alt me-2"></i>ความปลอดภัย 2FA</span>
                <?php
                $is2FAEnabled = TwoFactorAuth::isEnabled($_SESSION['user_id'], 'admin');
                ?>
                <?php if ($is2FAEnabled): ?>
                    <span class="badge bg-success">เปิดใช้งาน</span>
                <?php else: ?>
                    <span class="badge bg-danger">ไม่ได้เปิด</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    การยืนยันตัวตนสองขั้นตอน (2FA) ช่วยเพิ่มความปลอดภัยให้บัญชีของคุณ
                    โดยต้องใช้รหัสจาก Google Authenticator ทุกครั้งที่เข้าสู่ระบบ
                </p>
                <a href="security_2fa.php" class="btn btn-outline-primary">
                    <i class="fas fa-cog me-2"></i>ตั้งค่า 2FA
                </a>
            </div>
        </div>

        <!-- Session Info -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-info-circle me-2"></i>ข้อมูล Session
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Session ID</td>
                        <td><code><?= substr(session_id(), 0, 16) ?>...</code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">IP Address</td>
                        <td><code><?= $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">User Agent</td>
                        <td>
                            <small class="text-break">
                                <?= sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)) ?>...
                            </small>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Login Time</td>
                        <td><?= isset($_SESSION['login_time']) ? date('d/m/Y H:i:s', $_SESSION['login_time']) : '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Activity Log -->
<div class="card">
    <div class="card-header">
        <i class="fas fa-history me-2"></i>กิจกรรมล่าสุด
    </div>
    <div class="card-body p-0">
        <?php
        $activities = db()->fetchAll("
            SELECT * FROM system_logs
            WHERE context LIKE ?
            ORDER BY created_at DESC
            LIMIT 10
        ", ['%"user_id":' . $_SESSION['user_id'] . '%']);
        ?>

        <?php if (empty($activities)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-history fa-2x mb-2"></i>
                <p class="mb-0">ไม่พบกิจกรรมล่าสุด</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>กิจกรรม</th>
                            <th>Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td>
                                    <small><?= date('d/m/Y H:i:s', strtotime($activity['created_at'])) ?></small>
                                </td>
                                <td><?= sanitize($activity['message']) ?></td>
                                <td>
                                    <?php
                                    $levelClass = match($activity['log_type'] ?? 'info') {
                                        'error' => 'danger',
                                        'warning' => 'warning',
                                        'info' => 'info',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $levelClass ?>"><?= strtoupper($activity['log_type'] ?? 'info') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
