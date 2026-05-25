<?php
$pageTitle = 'ตรวจสลิป';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/../includes/auth.php';
auth()->requireRole('staff');
require_once __DIR__ . '/header.php';

$slipId = (int)($_GET['id'] ?? 0);
$slip   = db()->fetchOne(
    "SELECT ps.*, m.username, m.email, m.full_name, p.name AS plan_name, p.price AS plan_price
     FROM payment_slips ps
     JOIN members m ON m.id=ps.member_id
     JOIN plans p ON p.id=ps.plan_id
     WHERE ps.id=?",
    [$slipId]
);

if (!$slip) {
    setFlash('error', 'ไม่พบสลิปนี้');
    redirect(ADMIN_URL . '/slips_list.php');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $action     = $_POST['action'] ?? '';
    $reviewNote = trim($_POST['review_note'] ?? '');
    $adminId    = auth()->getUserId();

    if ($slip['status'] !== 'pending') {
        $error = 'สลิปนี้ได้รับการตรวจสอบแล้ว';
    } elseif ($action === 'approve') {
        try {
            db()->beginTransaction();

            // Update slip
            db()->update('payment_slips', [
                'status'      => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_note' => $reviewNote ?: null,
            ], 'id = ?', [$slipId]);

            // Activate/extend member plan
            $ok = planManager()->activateOrExtend((int)$slip['member_id'], (int)$slip['plan_id'], (int)$slip['months_to_add']);

            if (!$ok) throw new Exception('Failed to activate plan');

            db()->commit();

            // Send Telegram notification
            try {
                require_once __DIR__ . '/../includes/telegram_client.php';
                $tg = new TelegramClient();
                $msg = "✅ อนุมัติสลิปแล้ว\n"
                     . "👤 สมาชิก: {$slip['username']}\n"
                     . "📦 Plan: {$slip['plan_name']} ({$slip['months_to_add']} เดือน)\n"
                     . "💰 จำนวน: ฿" . number_format($slip['amount'], 0) . "\n"
                     . "👨‍💼 อนุมัติโดย: " . auth()->getUser()['username'];
                $tg->sendMessage($msg);
            } catch (Exception $e) {}

            $success = 'อนุมัติสลิปเรียบร้อยแล้ว';
            $slip    = db()->fetchOne("SELECT ps.*, m.username, m.email, m.full_name, p.name AS plan_name, p.price AS plan_price FROM payment_slips ps JOIN members m ON m.id=ps.member_id JOIN plans p ON p.id=ps.plan_id WHERE ps.id=?", [$slipId]);

        } catch (Exception $e) {
            db()->rollback();
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            logEvent('error', 'slip_review', 'Approve failed', ['slip_id' => $slipId, 'error' => $e->getMessage()]);
        }

    } elseif ($action === 'reject') {
        if (empty($reviewNote)) {
            $error = 'กรุณาระบุเหตุผลที่ปฏิเสธ';
        } else {
            db()->update('payment_slips', [
                'status'      => 'rejected',
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_note' => $reviewNote,
            ], 'id = ?', [$slipId]);

            $success = 'ปฏิเสธสลิปเรียบร้อยแล้ว';
            $slip    = db()->fetchOne("SELECT ps.*, m.username, m.email, m.full_name, p.name AS plan_name, p.price AS plan_price FROM payment_slips ps JOIN members m ON m.id=ps.member_id JOIN plans p ON p.id=ps.plan_id WHERE ps.id=?", [$slipId]);
        }
    }
}

// Slip image path
$slipImageUrl = '/uploads/slips/' . basename($slip['slip_file']);
?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="<?= ADMIN_URL ?>/slips_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
    <?php $smap=['pending'=>'warning','approved'=>'success','rejected'=>'danger']; ?>
    <span class="status-badge status-<?= $smap[$slip['status']] ?> fs-6"><?= $slip['status'] ?></span>
</div>

<?php if ($error):   ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div><?php endif; ?>

<div class="row g-4">
    <!-- Slip Image -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-image me-2 text-primary"></i>รูปสลิป</div>
            <div class="card-body text-center">
                <img src="<?= $slipImageUrl ?>" alt="Slip"
                     style="max-width:100%;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,.15);"
                     onerror="this.src='https://via.placeholder.com/400x600?text=Image+Not+Found'">
            </div>
        </div>
    </div>

    <!-- Details + Review -->
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>รายละเอียด</div>
            <div class="card-body">
                <?php $rows = [
                    ['สมาชิก', $slip['username'] . ' (' . $slip['email'] . ')'],
                    ['Plan', $slip['plan_name']],
                    ['จำนวนเดือน', $slip['months_to_add'] . ' เดือน'],
                    ['ยอดชำระ', '฿' . number_format($slip['amount'], 0)],
                    ['ธนาคาร', $slip['bank_name'] ?: '-'],
                    ['วันที่โอน', $slip['transfer_date'] ?: '-'],
                    ['หมายเหตุ', $slip['note_from_member'] ?: '-'],
                    ['วันที่ส่ง', date('d/m/Y H:i', strtotime($slip['created_at']))],
                ]; ?>
                <?php foreach ($rows as [$label, $val]): ?>
                <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom:1px solid #F8FAFC;">
                    <span class="text-muted" style="font-size:12px;"><?= $label ?></span>
                    <span style="font-size:13px;font-weight:600;text-align:right;max-width:60%;"><?= sanitize($val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($slip['status'] === 'pending'): ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-gavel me-2 text-warning"></i>ตัดสินใจ</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">หมายเหตุ (จำเป็นสำหรับการปฏิเสธ)</label>
                    <textarea id="reviewNote" class="form-control" rows="3"
                              placeholder="หมายเหตุหรือเหตุผล..."></textarea>
                </div>
                <div class="d-flex gap-3">
                    <form method="POST" class="flex-fill" onsubmit="return confirmAction('approve', this)">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="review_note" id="approveNote">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-check me-2"></i>อนุมัติ
                        </button>
                    </form>
                    <form method="POST" class="flex-fill" onsubmit="return confirmAction('reject', this)">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="review_note" id="rejectNote">
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="fas fa-times me-2"></i>ปฏิเสธ
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-clipboard-check me-2"></i>ผลการตรวจสอบ</div>
            <div class="card-body">
                <p style="font-size:13px;"><strong>หมายเหตุ:</strong> <?= sanitize($slip['review_note'] ?: '-') ?></p>
                <p style="font-size:12px;" class="text-muted mb-0">
                    ตรวจสอบเมื่อ <?= $slip['reviewed_at'] ? date('d/m/Y H:i', strtotime($slip['reviewed_at'])) : '-' ?>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmAction(action, form) {
    const note = document.getElementById('reviewNote').value.trim();
    if (action === 'reject' && !note) {
        alert('กรุณาระบุเหตุผลที่ปฏิเสธ');
        return false;
    }
    const msg = action === 'approve' ? 'อนุมัติสลิปนี้?' : 'ปฏิเสธสลิปนี้?';
    if (!confirm(msg)) return false;

    document.getElementById(action + 'Note').value = note;
    return true;
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
