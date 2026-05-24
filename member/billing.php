<?php
$pageTitle = 'Plan & ชำระเงิน';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];
$plans    = planManager()->getAllActivePlans();
$errors   = [];
$success  = '';

// Handle slip upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $planId      = (int)$_POST['plan_id'];
    $months      = max(1, (int)$_POST['months']);
    $amount      = (float)$_POST['amount'];
    $bankName    = trim($_POST['bank_name'] ?? '');
    $transferDate= trim($_POST['transfer_date'] ?? '');
    $note        = trim($_POST['note_from_member'] ?? '');

    if (!$planId)    $errors[] = 'กรุณาเลือก Plan';
    if ($amount <= 0) $errors[] = 'กรุณากรอกจำนวนเงิน';

    if (!isset($_FILES['slip_file']) || $_FILES['slip_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'กรุณาอัพโหลดรูปสลิป';
    } else {
        $file    = $_FILES['slip_file'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            $errors[] = 'รองรับเฉพาะรูป JPG, PNG, WebP เท่านั้น';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'ขนาดไฟล์ต้องไม่เกิน 5MB';
        }
    }

    if (empty($errors)) {
        // Check for pending slip already
        $pending = db()->fetchColumn(
            "SELECT COUNT(*) FROM payment_slips WHERE member_id=? AND status='pending'",
            [$memberId]
        );
        if ($pending > 0) {
            $errors[] = 'คุณมีสลิปที่รอการอนุมัติอยู่แล้ว กรุณารอการตรวจสอบก่อน';
        }
    }

    if (empty($errors)) {
        // Save file
        $ext      = $allowed[$mime];
        $filename = 'slip_' . $memberId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $savePath = __DIR__ . '/../uploads/slips/' . $filename;

        if (!move_uploaded_file($_FILES['slip_file']['tmp_name'], $savePath)) {
            $errors[] = 'ไม่สามารถบันทึกไฟล์ได้ กรุณาลองใหม่';
        } else {
            db()->insert('payment_slips', [
                'member_id'        => $memberId,
                'plan_id'          => $planId,
                'amount'           => $amount,
                'months_to_add'    => $months,
                'slip_file'        => $filename,
                'bank_name'        => $bankName ?: null,
                'transfer_date'    => $transferDate ?: null,
                'note_from_member' => $note ?: null,
                'status'           => 'pending',
            ]);

            // Telegram notification
            try {
                require_once __DIR__ . '/../includes/telegram_client.php';
                $plan = planManager()->getPlan($planId);
                $tg   = new TelegramClient();
                $tg->notifyPaymentSlip($currentMember, $plan, $months, (float)$amount);
            } catch (Exception $e) {}

            logEvent('info', 'billing', 'Slip uploaded', ['member_id' => $memberId, 'amount' => $amount]);
            setFlash('success', 'ส่งสลิปเรียบร้อยแล้ว! กรุณารอการตรวจสอบจาก Admin ภายใน 24 ชั่วโมง');
            redirect('/member/billing.php');
        }
    }
}

$flash   = getFlash();
$success = $flash && $flash['type'] === 'success' ? $flash['message'] : '';
$isTrial = $quota['is_trial'] ?? false;

// Paid plans only (exclude trial from upgrade selection)
$paidPlans = array_filter($plans, fn($p) => empty($p['is_trial']));

// Load payment config
$paymentCfg = [
    'qr_image'       => getSetting('payment_qr_image', ''),
    'bank_name'      => getSetting('payment_bank_name', ''),
    'account_name'   => getSetting('payment_account_name', ''),
    'account_number' => getSetting('payment_account_number', ''),
    'prompt_pay'     => getSetting('payment_prompt_pay', ''),
    'note'           => getSetting('payment_note', ''),
];
$hasPaymentInfo = $paymentCfg['bank_name'] || $paymentCfg['account_number'] || $paymentCfg['qr_image'] || $paymentCfg['prompt_pay'];

// Payment history
$slips = db()->fetchAll(
    "SELECT ps.*, p.name AS plan_name FROM payment_slips ps
     JOIN plans p ON p.id=ps.plan_id WHERE ps.member_id=?
     ORDER BY ps.created_at DESC LIMIT 20",
    [$memberId]
);
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Current Plan -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-crown me-2 text-warning"></i>Plan ปัจจุบัน</div>
            <div class="card-body">
                <?php if ($quota && $quota['plan_name']): ?>
                <div class="text-center mb-3">
                    <div class="stat-card-icon <?= $isTrial ? 'bg-gradient-warning' : 'bg-gradient-primary' ?> d-inline-flex mx-auto mb-2">
                        <i class="fas <?= $isTrial ? 'fa-flask' : 'fa-crown' ?>"></i>
                    </div>
                    <h5 class="fw-bold"><?= sanitize($quota['plan_name']) ?></h5>
                    <?php if ($isTrial): ?>
                    <span class="badge bg-warning text-dark">ทดลองใช้ฟรี</span>
                    <?php else: ?>
                    <div class="<?= $quota['days_left'] <= 7 ? 'text-danger' : 'text-muted' ?>" style="font-size:13px;">
                        หมดอายุ <?= date('d/m/Y', strtotime($quota['expires_at'])) ?>
                        (<?= $quota['days_left'] ?> วัน)
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($isTrial): ?>
                <?php $trialUsed = $quota['articles']['used']; $trialTotal = $quota['articles']['limit']; ?>
                <?php $trialPct = $trialTotal > 0 ? min(100, round($trialUsed/$trialTotal*100)) : 0; ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1" style="font-size:12px;">
                        <span>บทความที่ใช้ไป (ตลอดชีพ)</span>
                        <span class="fw-bold"><?= $trialUsed ?> / <?= $trialTotal ?></span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar <?= $trialPct >= 100 ? 'bg-danger' : ($trialPct >= 60 ? 'bg-warning' : 'bg-success') ?>"
                             style="width:<?= $trialPct ?>%;"></div>
                    </div>
                    <small class="text-muted">เหลือ <?= max(0, $trialTotal - $trialUsed) ?> บทความ</small>
                </div>
                <?php if ($trialPct >= 100): ?>
                <div class="alert alert-danger" style="font-size:12px;">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <strong>โควต้าหมดแล้ว!</strong> อัพเกรด Plan เพื่อสร้างบทความเพิ่ม
                </div>
                <?php elseif ($trialPct >= 60): ?>
                <div class="alert alert-warning" style="font-size:12px;">
                    <i class="fas fa-clock me-1"></i>ใกล้หมดโควต้า อัพเกรดก่อนหมด!
                </div>
                <?php endif; ?>
                <div class="d-grid mt-2">
                    <a href="#upgradeSection" class="btn btn-primary btn-sm">
                        <i class="fas fa-arrow-up me-1"></i>อัพเกรดแพ็คเกจ
                    </a>
                </div>
                <?php else: ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1" style="font-size:12px;">
                        <span>บทความเดือนนี้</span>
                        <span><?= $quota['articles']['used'] ?> / <?= $quota['articles']['unlimited'] ? '∞' : $quota['articles']['limit'] ?></span>
                    </div>
                    <?php if (!$quota['articles']['unlimited']): ?>
                    <div class="progress">
                        <?php $pct = $quota['articles']['limit'] > 0 ? min(100, round($quota['articles']['used']/$quota['articles']['limit']*100)) : 0; ?>
                        <div class="progress-bar bg-primary" style="width:<?= $pct ?>%;"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($quota['days_left'] <= 14): ?>
                <div class="alert alert-warning mt-3" style="font-size:12px;">
                    <i class="fas fa-clock me-1"></i>Plan ใกล้หมดอายุ อย่าลืมต่ออายุ!
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-3">
                    <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                    <p class="text-muted" style="font-size:13px;">ยังไม่มี Plan ที่ active</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Plans pricing -->
        <div class="card">
            <div class="card-header"><i class="fas fa-box-open me-2 text-primary"></i>แพ็คเกจ</div>
            <div class="card-body p-0">
                <?php foreach ($paidPlans as $plan): ?>
                <div class="px-4 py-3" style="border-bottom:1px solid #F1F5F9;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold" style="font-size:14px;"><?= sanitize($plan['name']) ?></div>
                            <small class="text-muted">
                                <?= $plan['articles_per_month'] ?: '∞' ?> บทความ /
                                <?= $plan['max_sites'] ?> sites /
                                <?= $plan['max_keywords'] ? $plan['max_keywords'] . ' keywords' : 'keywords ∞' ?>
                            </small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success">฿<?= number_format($plan['price'],0) ?></div>
                            <small class="text-muted">/เดือน</small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Upload Slip -->
    <div class="col-lg-8" id="upgradeSection">

        <?php if ($isTrial): ?>
        <div class="alert alert-info mb-4">
            <i class="fas fa-flask me-2"></i>
            <strong>คุณกำลังใช้แพ็คเกจ Trial</strong> — ฟรี <?= $quota['articles']['limit'] ?> บทความตลอดชีพ,
            ไม่รองรับ Auto-posting อัพเกรดเพื่อรับโควต้ารายเดือนและฟีเจอร์ครบ!
        </div>
        <?php endif; ?>

        <?php if ($hasPaymentInfo): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-university me-2 text-primary"></i>ข้อมูลการชำระเงิน</div>
            <div class="card-body">
                <div class="row align-items-center g-4">
                    <?php if ($paymentCfg['qr_image']): ?>
                    <div class="col-auto text-center">
                        <img src="/uploads/payment/<?= sanitize($paymentCfg['qr_image']) ?>"
                             alt="QR Code"
                             style="max-width:160px;border-radius:12px;border:1px solid #E2E8F0;cursor:pointer;"
                             onclick="enlargeQR(this)">
                        <div class="text-muted mt-1" style="font-size:11px;">คลิกเพื่อขยาย</div>
                    </div>
                    <?php endif; ?>
                    <div class="col">
                        <?php if ($paymentCfg['bank_name']): ?>
                        <div class="mb-3">
                            <span class="badge bg-primary fs-6 px-3 py-2">
                                <i class="fas fa-university me-1"></i><?= sanitize($paymentCfg['bank_name']) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="row g-2">
                            <?php if ($paymentCfg['account_name']): ?>
                            <div class="col-12">
                                <div class="p-2 rounded" style="background:#F8FAFC;">
                                    <div class="text-muted" style="font-size:11px;">ชื่อบัญชี</div>
                                    <div class="fw-bold" style="font-size:15px;"><?= sanitize($paymentCfg['account_name']) ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($paymentCfg['account_number']): ?>
                            <div class="col-md-6">
                                <div class="p-2 rounded" style="background:#F8FAFC;">
                                    <div class="text-muted" style="font-size:11px;">เลขบัญชี</div>
                                    <div class="fw-bold font-monospace d-flex align-items-center gap-2">
                                        <?= sanitize($paymentCfg['account_number']) ?>
                                        <button class="btn btn-xs btn-outline-secondary py-0 px-1"
                                                onclick="copyText('<?= sanitize($paymentCfg['account_number']) ?>')"
                                                title="คัดลอก"><i class="fas fa-copy" style="font-size:10px;"></i></button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($paymentCfg['prompt_pay']): ?>
                            <div class="col-md-6">
                                <div class="p-2 rounded" style="background:#F8FAFC;">
                                    <div class="text-muted" style="font-size:11px;">PromptPay</div>
                                    <div class="fw-bold font-monospace d-flex align-items-center gap-2">
                                        <?= sanitize($paymentCfg['prompt_pay']) ?>
                                        <button class="btn btn-xs btn-outline-secondary py-0 px-1"
                                                onclick="copyText('<?= sanitize($paymentCfg['prompt_pay']) ?>')"
                                                title="คัดลอก"><i class="fas fa-copy" style="font-size:10px;"></i></button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($paymentCfg['note']): ?>
                        <div class="alert alert-info mt-3 mb-0" style="font-size:13px;">
                            <i class="fas fa-info-circle me-2"></i><?= nl2br(sanitize($paymentCfg['note'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-upload me-2 text-success"></i>อัพโหลดสลิปการชำระเงิน</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Plan ที่ต้องการ <span class="text-danger">*</span></label>
                            <select name="plan_id" class="form-select" id="planSelect" required>
                                <option value="">เลือก Plan</option>
                                <?php foreach ($paidPlans as $plan): ?>
                                <option value="<?= $plan['id'] ?>" data-price="<?= $plan['price'] ?>">
                                    <?= sanitize($plan['name']) ?> — ฿<?= number_format($plan['price'],0) ?>/เดือน
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">จำนวนเดือน</label>
                            <input type="number" name="months" class="form-control" id="monthsInput" value="1" min="1" max="12">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ยอดรวม</label>
                            <div class="form-control fw-bold text-success" id="totalAmount">฿0</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">จำนวนเงินที่โอน (บาท) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" id="amountInput"
                                   placeholder="0" min="1" step="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ธนาคาร</label>
                            <select name="bank_name" class="form-select">
                                <option value="">เลือกธนาคาร</option>
                                <?php foreach (['PromptPay','กสิกรไทย','ไทยพาณิชย์','กรุงเทพ','กรุงไทย','ทหารไทยธนชาต','ออมสิน'] as $bank): ?>
                                <option><?= $bank ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">วันที่โอน</label>
                            <input type="date" name="transfer_date" class="form-control" max="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">รูปสลิป <span class="text-danger">*</span></label>
                        <input type="file" name="slip_file" class="form-control" accept="image/*" required
                               onchange="previewSlip(this)">
                        <small class="text-muted">JPG, PNG, WebP — ขนาดสูงสุด 5MB</small>
                    </div>

                    <!-- Preview -->
                    <div id="slipPreview" class="mb-3" style="display:none;">
                        <img id="previewImg" src="" alt="Preview"
                             style="max-height:200px;border-radius:8px;box-shadow:var(--shadow);">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                        <textarea name="note_from_member" class="form-control" rows="2"
                                  placeholder="ข้อมูลเพิ่มเติม..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane me-2"></i>ส่งสลิปเพื่อตรวจสอบ
                    </button>
                </form>
            </div>
        </div>

        <!-- History -->
        <div class="card">
            <div class="card-header"><i class="fas fa-history me-2 text-info"></i>ประวัติการชำระเงิน</div>
            <div class="card-body p-0">
                <?php if (empty($slips)): ?>
                <div class="text-center py-4 text-muted" style="font-size:13px;">ยังไม่มีประวัติ</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Plan</th><th>จำนวน</th><th>เดือน</th><th>สถานะ</th><th>วันที่</th></tr></thead>
                        <tbody>
                        <?php foreach ($slips as $slip): ?>
                        <tr>
                            <td><?= sanitize($slip['plan_name']) ?></td>
                            <td class="fw-bold">฿<?= number_format($slip['amount'],0) ?></td>
                            <td><?= $slip['months_to_add'] ?> เดือน</td>
                            <td>
                                <?php $smap=['pending'=>'warning','approved'=>'success','rejected'=>'danger']; ?>
                                <span class="status-badge status-<?= $smap[$slip['status']] ?>"><?= $slip['status'] ?></span>
                                <?php if ($slip['status']==='rejected' && $slip['review_note']): ?>
                                <div><small class="text-danger"><?= sanitize($slip['review_note']) ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td><small><?= date('d/m/y H:i', strtotime($slip['created_at'])) ?></small></td>
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

<script>
function previewSlip(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('slipPreview').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function calcTotal() {
    const planSelect = document.getElementById('planSelect');
    const months     = parseInt(document.getElementById('monthsInput').value) || 1;
    const price      = parseFloat(planSelect.options[planSelect.selectedIndex]?.dataset.price || 0);
    const total      = price * months;
    document.getElementById('totalAmount').textContent = '฿' + total.toLocaleString();
    document.getElementById('amountInput').value = total || '';
}

document.getElementById('planSelect').addEventListener('change', calcTotal);
document.getElementById('monthsInput').addEventListener('input', calcTotal);

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        if (typeof toastr !== 'undefined') toastr.success('คัดลอกแล้ว!');
        else alert('คัดลอกแล้ว: ' + text);
    });
}

function enlargeQR(img) {
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:pointer;';
    modal.innerHTML = `<img src="${img.src}" style="max-width:90vw;max-height:90vh;border-radius:16px;">`;
    modal.onclick = () => modal.remove();
    document.body.appendChild(modal);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
