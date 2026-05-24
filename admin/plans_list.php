<?php
$pageTitle = 'จัดการ Plans';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/header.php';

auth()->requireRole('admin');

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $deleteId = (int)$_POST['delete_id'];

    $inUse = db()->fetchColumn("SELECT COUNT(*) FROM member_plans WHERE plan_id = ?", [$deleteId]);
    if ($inUse > 0) {
        $errorMsg = 'ไม่สามารถลบ plan ที่มีสมาชิกใช้งานอยู่ได้';
    } else {
        db()->delete('plans', 'id = ?', [$deleteId]);
        $successMsg = 'ลบ plan เรียบร้อยแล้ว';
    }
}

// Handle toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $toggleId = (int)$_POST['toggle_id'];
    db()->query("UPDATE plans SET is_active = NOT is_active WHERE id = ?", [$toggleId]);
    $successMsg = 'อัพเดตสถานะเรียบร้อยแล้ว';
}

$plans = db()->fetchAll(
    "SELECT p.*,
            (SELECT COUNT(*) FROM member_plans mp WHERE mp.plan_id = p.id AND mp.status = 'active' AND mp.expires_at > NOW()) AS active_members
     FROM plans p ORDER BY p.sort_order ASC, p.id ASC"
);
?>

<?php if (isset($successMsg)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($successMsg) ?></div>
<?php endif; ?>
<?php if (isset($errorMsg)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($errorMsg) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">จัดการ Plans</h4>
        <p class="text-muted mb-0">กำหนด quota และราคาสำหรับแต่ละแพ็คเกจ</p>
    </div>
    <a href="plans_edit.php" class="btn btn-primary">
        <i class="fas fa-plus me-2"></i>สร้าง Plan ใหม่
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0" id="plansTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ชื่อ Plan</th>
                        <th>ราคา/เดือน</th>
                        <th>บทความ/เดือน</th>
                        <th>Sites</th>
                        <th>Keywords</th>
                        <th>สมาชิก Active</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h5>ยังไม่มี Plan</h5>
                                <p>สร้าง Plan แรกเพื่อเริ่มต้น</p>
                                <a href="plans_edit.php" class="btn btn-primary btn-sm">สร้าง Plan</a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><?= $plan['id'] ?></td>
                        <td>
                            <div class="fw-semibold" style="color:#1E293B;"><?= sanitize($plan['name']) ?></div>
                            <small class="text-muted"><?= sanitize($plan['slug']) ?></small>
                        </td>
                        <td>
                            <span class="fw-bold text-success">฿<?= number_format($plan['price'], 0) ?></span>
                        </td>
                        <td>
                            <?php if ($plan['articles_per_month'] == 0): ?>
                                <span class="badge bg-gradient-success">Unlimited</span>
                            <?php else: ?>
                                <?= number_format($plan['articles_per_month']) ?> บทความ
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($plan['max_sites']) ?> site<?= $plan['max_sites'] > 1 ? 's' : '' ?></td>
                        <td>
                            <?php if ($plan['max_keywords'] == 0): ?>
                                <span class="badge bg-gradient-success">Unlimited</span>
                            <?php else: ?>
                                <?= number_format($plan['max_keywords']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-gradient-primary"><?= number_format($plan['active_members']) ?></span>
                        </td>
                        <td>
                            <?php if ($plan['is_active']): ?>
                                <span class="status-badge status-success">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <a href="plans_edit.php?id=<?= $plan['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="toggle_id" value="<?= $plan['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-<?= $plan['is_active'] ? 'warning' : 'success' ?>"
                                            title="<?= $plan['is_active'] ? 'ปิดการใช้งาน' : 'เปิดการใช้งาน' ?>">
                                        <i class="fas fa-<?= $plan['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                    </button>
                                </form>
                                <?php if ($plan['active_members'] == 0): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('ลบ plan นี้?')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="delete_id" value="<?= $plan['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php $pageScripts = <<<'PAGESCRIPT'
<script>
$(document).ready(function() {
    $('#plansTable').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
        order: [[0, 'asc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [8] }]
    });
});
</script>
PAGESCRIPT; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
