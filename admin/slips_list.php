<?php
$pageTitle = 'ตรวจสลิป';
require_once __DIR__ . '/header.php';

auth()->requireRole('staff');

$statusFilter = $_GET['status'] ?? 'pending';

$params = [];
$where  = '1=1';
if ($statusFilter) {
    $where .= ' AND ps.status=?';
    $params[] = $statusFilter;
}

$slips = db()->fetchAll(
    "SELECT ps.*, m.username, m.email, p.name AS plan_name,
            au.username AS reviewed_by_name
     FROM payment_slips ps
     JOIN members m ON m.id = ps.member_id
     JOIN plans p ON p.id = ps.plan_id
     LEFT JOIN admin_users au ON au.id = ps.reviewed_by
     WHERE {$where}
     ORDER BY ps.created_at DESC",
    $params
);

$counts = db()->fetchAll(
    "SELECT status, COUNT(*) AS cnt FROM payment_slips GROUP BY status"
);
$countMap = array_column($counts, 'cnt', 'status');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">ตรวจสลิปการชำระเงิน</h4>
    </div>
</div>

<!-- Status tabs -->
<div class="d-flex gap-2 mb-4">
    <?php foreach (['pending'=>['label'=>'รอตรวจ','color'=>'warning'],'approved'=>['label'=>'อนุมัติแล้ว','color'=>'success'],'rejected'=>['label'=>'ปฏิเสธ','color'=>'danger'],''=>['label'=>'ทั้งหมด','color'=>'primary']] as $val=>$tab): ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm btn-<?= $statusFilter===$val ? $tab['color'] : 'outline-'.$tab['color'] ?>">
        <?= $tab['label'] ?>
        <?php $c = $val === '' ? array_sum($countMap) : ($countMap[$val]??0); ?>
        <span class="badge bg-<?= $statusFilter===$val ? 'white text-'.$tab['color'] : $tab['color'] ?> ms-1"><?= $c ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($slips)): ?>
        <div class="empty-state">
            <i class="fas fa-receipt"></i>
            <h5>ไม่มีสลิป</h5>
            <p>ไม่มีสลิปในหมวดนี้</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" id="slipsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>สมาชิก</th>
                        <th>Plan</th>
                        <th>จำนวน</th>
                        <th>เดือน</th>
                        <th>สถานะ</th>
                        <th>วันที่ส่ง</th>
                        <th>ตรวจโดย</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slips as $slip): ?>
                    <tr class="<?= $slip['status']==='pending' ? 'table-warning' : '' ?>">
                        <td><?= $slip['id'] ?></td>
                        <td>
                            <div class="fw-semibold" style="font-size:13px;"><?= sanitize($slip['username']) ?></div>
                            <small class="text-muted"><?= sanitize($slip['email']) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-gradient-primary"><?= sanitize($slip['plan_name']) ?></span>
                        </td>
                        <td class="fw-bold <?= $slip['status']==='approved' ? 'text-success' : '' ?>">
                            ฿<?= number_format($slip['amount'],0) ?>
                        </td>
                        <td><?= $slip['months_to_add'] ?> เดือน</td>
                        <td>
                            <?php $smap=['pending'=>'warning','approved'=>'success','rejected'=>'danger']; ?>
                            <span class="status-badge status-<?= $smap[$slip['status']] ?>"><?= $slip['status'] ?></span>
                        </td>
                        <td><small><?= date('d/m/y H:i', strtotime($slip['created_at'])) ?></small></td>
                        <td>
                            <small class="text-muted"><?= sanitize($slip['reviewed_by_name'] ?? '-') ?></small>
                        </td>
                        <td>
                            <a href="<?= ADMIN_URL ?>/slips_review.php?id=<?= $slip['id'] ?>" class="btn btn-xs btn-<?= $slip['status']==='pending' ? 'warning' : 'outline-primary' ?>">
                                <?= $slip['status']==='pending' ? 'ตรวจ' : 'ดู' ?>
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

<script>
$(document).ready(function() {
    $('#slipsTable').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
        order: [[6,'desc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [8] }]
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
