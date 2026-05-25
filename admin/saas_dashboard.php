<?php
$pageTitle = 'SaaS Dashboard';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/../includes/auth.php';
auth()->requireRole('staff');
require_once __DIR__ . '/header.php';

$stats = planManager()->getSaasStats();

// Revenue chart data (last 6 months)
$revenueChart = db()->fetchAll(
    "SELECT DATE_FORMAT(reviewed_at, '%Y-%m') AS month,
            SUM(amount) AS revenue,
            COUNT(*) AS count
     FROM payment_slips
     WHERE status='approved' AND reviewed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY month ORDER BY month ASC"
);

// Recent slips pending
$pendingSlips = db()->fetchAll(
    "SELECT ps.*, m.username, m.email, p.name AS plan_name
     FROM payment_slips ps
     JOIN members m ON m.id = ps.member_id
     JOIN plans p ON p.id = ps.plan_id
     WHERE ps.status = 'pending'
     ORDER BY ps.created_at ASC
     LIMIT 10"
);

// New members
$newMembers = db()->fetchAll(
    "SELECT m.id, m.username, m.email, m.created_at, p.name AS plan_name, COALESCE(p.is_trial, 0) AS is_trial
     FROM members m
     LEFT JOIN plans p ON p.id = m.plan_id
     WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY m.created_at DESC LIMIT 8"
);
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['label'=>'สมาชิกทั้งหมด',      'value'=>number_format($stats['total_members']),   'icon'=>'fas fa-users',         'grad'=>'bg-gradient-primary'],
        ['label'=>'Paid Active',          'value'=>number_format($stats['active_subs']),     'icon'=>'fas fa-crown',         'grad'=>'bg-gradient-success'],
        ['label'=>'Trial Members',        'value'=>number_format($stats['trial_members']),   'icon'=>'fas fa-flask',         'grad'=>'bg-gradient-warning'],
        ['label'=>'สลิปรออนุมัติ',       'value'=>number_format($stats['pending_slips']),   'icon'=>'fas fa-receipt',       'grad'=>'bg-gradient-danger'],
        ['label'=>'รายได้เดือนนี้',      'value'=>'฿'.number_format($stats['revenue_this_month'],0), 'icon'=>'fas fa-baht-sign', 'grad'=>'bg-gradient-info'],
    ];
    foreach ($cards as $c):
    ?>
    <div class="col-sm-6 col-xl-auto flex-xl-fill">
        <div class="stat-card <?= $c['grad'] ?>">
            <div class="stat-card-icon"><i class="<?= $c['icon'] ?>"></i></div>
            <div class="stat-card-value"><?= $c['value'] ?></div>
            <div class="stat-card-label"><?= $c['label'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <!-- Pending Slips -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-receipt me-2 text-warning"></i>สลิปรออนุมัติ
                    <?php if ($stats['pending_slips'] > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $stats['pending_slips'] ?></span>
                    <?php endif; ?>
                </span>
                <a href="<?= ADMIN_URL ?>/slips_list.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pendingSlips)): ?>
                <div class="empty-state" style="padding:30px;">
                    <i class="fas fa-check-circle" style="font-size:2rem;color:#10B981;background:none;-webkit-text-fill-color:initial;"></i>
                    <p class="mt-2 mb-0 text-muted" style="font-size:13px;">ไม่มีสลิปรออนุมัติ</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr>
                            <th>สมาชิก</th><th>Plan</th><th>จำนวน</th><th>วันที่</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($pendingSlips as $slip): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold" style="font-size:13px;"><?= sanitize($slip['username']) ?></div>
                                <small class="text-muted"><?= sanitize($slip['email']) ?></small>
                            </td>
                            <td><span class="badge bg-gradient-primary"><?= sanitize($slip['plan_name']) ?></span></td>
                            <td class="fw-bold text-success">฿<?= number_format($slip['amount'],0) ?></td>
                            <td><small><?= date('d/m/y H:i', strtotime($slip['created_at'])) ?></small></td>
                            <td>
                                <a href="<?= ADMIN_URL ?>/slips_review.php?id=<?= $slip['id'] ?>" class="btn btn-xs btn-warning">
                                    ตรวจสอบ
                                </a>
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

    <!-- New Members + Quick Stats -->
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-user-plus me-2 text-success"></i>สมาชิกใหม่ (7 วัน)</span>
                <span class="badge bg-success"><?= number_format($stats['new_members_today']) ?> วันนี้</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($newMembers)): ?>
                <div class="text-center py-4 text-muted" style="font-size:13px;">ไม่มีสมาชิกใหม่</div>
                <?php else: ?>
                <div style="max-height:280px;overflow-y:auto;">
                    <?php foreach ($newMembers as $m): ?>
                    <div class="d-flex align-items-center gap-3 px-4 py-2" style="border-bottom:1px solid #F1F5F9;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#6366F1,#8B5CF6);
                            border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;">
                            <?= strtoupper(substr($m['username'],0,1)) ?>
                        </div>
                        <div class="flex-fill">
                            <div style="font-size:13px;font-weight:600;color:#1E293B;"><?= sanitize($m['username']) ?></div>
                            <small class="text-muted"><?= date('d/m/y H:i', strtotime($m['created_at'])) ?></small>
                        </div>
                        <?php if ($m['plan_name']): ?>
                        <?php if (!empty($m['is_trial'])): ?>
                        <span class="badge" style="font-size:10px;background:rgba(245,158,11,.2);color:#92400E;">
                            <i class="fas fa-flask me-1"></i>Trial
                        </span>
                        <?php else: ?>
                        <span class="badge bg-gradient-primary" style="font-size:10px;"><?= sanitize($m['plan_name']) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="badge bg-secondary" style="font-size:10px;">ไม่มี Plan</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Quick Links</div>
            <div class="card-body d-grid gap-2">
                <a href="<?= ADMIN_URL ?>/members_list.php" class="btn btn-outline-primary btn-sm text-start">
                    <i class="fas fa-users me-2"></i>จัดการสมาชิก
                </a>
                <a href="<?= ADMIN_URL ?>/slips_list.php" class="btn btn-outline-warning btn-sm text-start">
                    <i class="fas fa-receipt me-2"></i>ตรวจสลิปทั้งหมด
                </a>
                <a href="<?= ADMIN_URL ?>/invite_codes.php" class="btn btn-outline-success btn-sm text-start">
                    <i class="fas fa-ticket me-2"></i>Invite Codes
                </a>
                <a href="<?= ADMIN_URL ?>/plans_list.php" class="btn btn-outline-secondary btn-sm text-start">
                    <i class="fas fa-box-open me-2"></i>จัดการ Plans
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
