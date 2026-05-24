<?php
$pageTitle = 'Invite Codes';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/header.php';

auth()->requireRole('admin');

$errors = [];

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_code'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $planId      = (int)$_POST['plan_id'];
    $months      = max(1, (int)$_POST['months_duration']);
    $maxUses     = max(0, (int)$_POST['max_uses']);
    $description = trim($_POST['description'] ?? '');
    $expiresAt   = trim($_POST['expires_at'] ?? '');
    $customCode  = strtoupper(trim($_POST['custom_code'] ?? ''));

    if (!$planId) {
        $errors[] = 'กรุณาเลือก Plan';
    } else {
        $code = $customCode ?: strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));

        // Check uniqueness
        $exists = db()->fetchColumn("SELECT COUNT(*) FROM invite_codes WHERE code=?", [$code]);
        if ($exists) {
            $errors[] = "Code '{$code}' ถูกใช้งานแล้ว";
        } else {
            db()->insert('invite_codes', [
                'code'           => $code,
                'plan_id'        => $planId,
                'months_duration'=> $months,
                'max_uses'       => $maxUses,
                'expires_at'     => $expiresAt ?: null,
                'created_by'     => auth()->getUserId(),
                'description'    => $description ?: null,
                'is_active'      => 1,
            ]);
            $successMsg = "สร้าง Invite Code: <strong>{$code}</strong> เรียบร้อยแล้ว";
        }
    }
}

// Handle toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    db()->query("UPDATE invite_codes SET is_active=NOT is_active WHERE id=?", [(int)$_POST['toggle_id']]);
    redirect(ADMIN_URL . '/invite_codes.php');
}

$plans = planManager()->getAllActivePlans();
$codes = db()->fetchAll(
    "SELECT ic.*, p.name AS plan_name, au.username AS created_by_name
     FROM invite_codes ic
     JOIN plans p ON p.id=ic.plan_id
     JOIN admin_users au ON au.id=ic.created_by
     ORDER BY ic.created_at DESC"
);
?>

<div class="row g-4">
    <!-- Create Form -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-plus me-2 text-primary"></i>สร้าง Invite Code</div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-3"><ul class="mb-0 ps-3"><?php foreach($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <?php if (isset($successMsg)): ?>
                <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><?= $successMsg ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="create_code" value="1">

                    <div class="mb-3">
                        <label class="form-label">Plan <span class="text-danger">*</span></label>
                        <select name="plan_id" class="form-select" required>
                            <option value="">เลือก Plan</option>
                            <?php foreach ($plans as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?> — ฿<?= number_format($p['price'],0) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ระยะเวลา (เดือน)</label>
                        <input type="number" name="months_duration" class="form-control" value="1" min="1" max="24">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">จำนวนครั้งที่ใช้ได้</label>
                        <input type="number" name="max_uses" class="form-control" value="1" min="0">
                        <small class="text-muted">0 = ไม่จำกัด</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Code (ปล่อยว่างเพื่อสุ่ม)</label>
                        <input type="text" name="custom_code" class="form-control" placeholder="PROMO2024"
                               style="text-transform:uppercase;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วันหมดอายุ</label>
                        <input type="date" name="expires_at" class="form-control"
                               min="<?= date('Y-m-d') ?>">
                        <small class="text-muted">ปล่อยว่างไม่มีวันหมดอายุ</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">หมายเหตุ</label>
                        <input type="text" name="description" class="form-control" placeholder="สำหรับ...">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-ticket me-2"></i>สร้าง Code
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Code List -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fas fa-list me-2 text-info"></i>Invite Codes ทั้งหมด</div>
            <div class="card-body p-0">
                <?php if (empty($codes)): ?>
                <div class="empty-state">
                    <i class="fas fa-ticket"></i>
                    <h5>ยังไม่มี Invite Code</h5>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0" id="codesTable">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Plan</th>
                                <th>ระยะ</th>
                                <th>ใช้แล้ว</th>
                                <th>หมดอายุ</th>
                                <th>สถานะ</th>
                                <th>สร้างโดย</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($codes as $code): ?>
                            <?php $isExpired = $code['expires_at'] && strtotime($code['expires_at']) < time(); ?>
                            <tr>
                                <td>
                                    <span class="fw-bold" style="font-size:13px;color:#6366F1;letter-spacing:0.5px;"><?= sanitize($code['code']) ?></span>
                                    <?php if ($code['description']): ?>
                                    <div><small class="text-muted"><?= sanitize($code['description']) ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-gradient-primary"><?= sanitize($code['plan_name']) ?></span></td>
                                <td><?= $code['months_duration'] ?> เดือน</td>
                                <td>
                                    <?= $code['used_count'] ?> /
                                    <?= $code['max_uses'] == 0 ? '∞' : $code['max_uses'] ?>
                                </td>
                                <td>
                                    <?php if ($code['expires_at']): ?>
                                    <span class="<?= $isExpired ? 'text-danger' : '' ?>">
                                        <?= date('d/m/y', strtotime($code['expires_at'])) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted">ไม่มี</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$code['is_active'] || $isExpired): ?>
                                        <span class="status-badge status-danger">Inactive</span>
                                    <?php else: ?>
                                        <span class="status-badge status-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= sanitize($code['created_by_name']) ?></small></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="toggle_id" value="<?= $code['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-<?= $code['is_active'] ? 'warning' : 'success' ?>"
                                                title="<?= $code['is_active'] ? 'ปิด' : 'เปิด' ?>">
                                            <i class="fas fa-<?= $code['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php $pageScripts = <<<'PAGESCRIPT'
<script>
$(document).ready(function() {
    $('#codesTable').DataTable({
        order: [[0,'asc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [7] }],
        language: {
            search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
            info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
            infoEmpty: "ไม่มีข้อมูล", zeroRecords: "ไม่พบข้อมูล",
            paginate: { first:"แรก", last:"สุดท้าย", next:"ถัดไป", previous:"ก่อนหน้า" }
        }
    });
    $('input[name="custom_code"]').on('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
    });
});
</script>
PAGESCRIPT; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
