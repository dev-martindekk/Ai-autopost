<?php
$pageTitle = 'ตั้งค่าการชำระเงิน';
require_once __DIR__ . '/header.php';

auth()->requireRole('admin');

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $bankName      = trim($_POST['bank_name'] ?? '');
    $accountName   = trim($_POST['account_name'] ?? '');
    $accountNumber = trim($_POST['account_number'] ?? '');
    $promptPay     = trim($_POST['prompt_pay'] ?? '');
    $note          = trim($_POST['payment_note'] ?? '');

    // QR code upload
    $qrFilename = getSetting('payment_qr_image', '');
    if (!empty($_FILES['qr_image']['name'])) {
        $file  = $_FILES['qr_image'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'อัพโหลดไฟล์ไม่สำเร็จ';
        } elseif (!isset($allowed[$mime])) {
            $errors[] = 'รองรับเฉพาะ JPG, PNG, WebP';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'ขนาดไฟล์ต้องไม่เกิน 5MB';
        } else {
            $ext      = $allowed[$mime];
            $newFile  = 'qr_' . time() . '.' . $ext;
            $savePath = __DIR__ . '/../uploads/payment/' . $newFile;
            if (move_uploaded_file($file['tmp_name'], $savePath)) {
                // Delete old file
                if ($qrFilename && file_exists(__DIR__ . '/../uploads/payment/' . $qrFilename)) {
                    @unlink(__DIR__ . '/../uploads/payment/' . $qrFilename);
                }
                $qrFilename = $newFile;
            } else {
                $errors[] = 'ไม่สามารถบันทึกไฟล์ได้';
            }
        }
    }

    // Delete QR if requested
    if (isset($_POST['delete_qr']) && $qrFilename) {
        @unlink(__DIR__ . '/../uploads/payment/' . $qrFilename);
        $qrFilename = '';
    }

    if (empty($errors)) {
        $settings = [
            'payment_qr_image'      => $qrFilename,
            'payment_bank_name'     => $bankName,
            'payment_account_name'  => $accountName,
            'payment_account_number'=> $accountNumber,
            'payment_prompt_pay'    => $promptPay,
            'payment_note'          => $note,
        ];
        foreach ($settings as $key => $val) {
            $exists = db()->fetchColumn("SELECT COUNT(*) FROM system_settings WHERE setting_key=?", [$key]);
            if ($exists) {
                db()->query("UPDATE system_settings SET setting_value=? WHERE setting_key=?", [$val, $key]);
            } else {
                db()->insert('system_settings', ['setting_key' => $key, 'setting_value' => $val, 'setting_type' => 'string']);
            }
        }
        $success = 'บันทึกการตั้งค่าเรียบร้อยแล้ว';
    }
}

// Load current settings
$cfg = [
    'qr_image'       => getSetting('payment_qr_image', ''),
    'bank_name'      => getSetting('payment_bank_name', ''),
    'account_name'   => getSetting('payment_account_name', ''),
    'account_number' => getSetting('payment_account_number', ''),
    'prompt_pay'     => getSetting('payment_prompt_pay', ''),
    'note'           => getSetting('payment_note', ''),
];
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="fas fa-qrcode me-2 text-primary"></i>ข้อมูลการชำระเงิน</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <div class="mb-3">
                        <label class="form-label">ชื่อธนาคาร / PromptPay</label>
                        <input type="text" name="bank_name" class="form-control"
                               value="<?= sanitize($cfg['bank_name']) ?>"
                               placeholder="เช่น กสิกรไทย, PromptPay">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อบัญชี</label>
                        <input type="text" name="account_name" class="form-control"
                               value="<?= sanitize($cfg['account_name']) ?>"
                               placeholder="ชื่อเจ้าของบัญชี">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">เลขบัญชี</label>
                            <input type="text" name="account_number" class="form-control"
                                   value="<?= sanitize($cfg['account_number']) ?>"
                                   placeholder="xxx-x-xxxxx-x">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เลข PromptPay</label>
                            <input type="text" name="prompt_pay" class="form-control"
                                   value="<?= sanitize($cfg['prompt_pay']) ?>"
                                   placeholder="เบอร์โทร / เลขบัตรประชาชน">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">หมายเหตุ / ข้อความแสดงให้ลูกค้า</label>
                        <textarea name="payment_note" class="form-control" rows="3"
                                  placeholder="เช่น โอนแล้วส่งสลิปได้เลย อนุมัติภายใน 24 ชั่วโมง"><?= sanitize($cfg['note']) ?></textarea>
                    </div>

                    <hr class="my-4">

                    <label class="form-label"><i class="fas fa-qrcode me-1"></i>รูป QR Code สำหรับสแกนโอน</label>
                    <?php if ($cfg['qr_image']): ?>
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <img src="/uploads/payment/<?= sanitize($cfg['qr_image']) ?>"
                             alt="QR Code" style="height:100px;border-radius:8px;border:1px solid #E2E8F0;">
                        <div>
                            <div class="text-success small mb-1"><i class="fas fa-check-circle me-1"></i>มี QR Code แล้ว</div>
                            <button type="submit" name="delete_qr" value="1"
                                    class="btn btn-outline-danger btn-sm"
                                    onclick="return confirm('ลบ QR Code?')">
                                <i class="fas fa-trash me-1"></i>ลบ QR Code
                            </button>
                        </div>
                    </div>
                    <p class="text-muted small">อัพโหลดใหม่เพื่อแทนที่:</p>
                    <?php endif; ?>
                    <input type="file" name="qr_image" class="form-control mb-1" accept="image/*"
                           onchange="previewQR(this)">
                    <small class="text-muted">JPG, PNG, WebP — สูงสุด 5MB</small>
                    <div id="qrPreview" class="mt-2" style="display:none;">
                        <img id="qrPreviewImg" src="" alt="preview"
                             style="height:120px;border-radius:8px;border:1px solid #E2E8F0;">
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Preview -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="fas fa-eye me-2 text-info"></i>ตัวอย่างที่ลูกค้าเห็น</div>
            <div class="card-body">
                <?php if ($cfg['bank_name'] || $cfg['account_number'] || $cfg['qr_image']): ?>
                <div class="text-center mb-3">
                    <?php if ($cfg['qr_image']): ?>
                    <img src="/uploads/payment/<?= sanitize($cfg['qr_image']) ?>"
                         alt="QR Code" style="max-width:180px;border-radius:12px;border:1px solid #E2E8F0;" class="mb-3">
                    <br>
                    <?php endif; ?>
                    <?php if ($cfg['bank_name']): ?>
                    <span class="badge bg-primary mb-2"><?= sanitize($cfg['bank_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($cfg['account_name']): ?>
                <div class="d-flex justify-content-between mb-2" style="font-size:13px;">
                    <span class="text-muted">ชื่อบัญชี</span>
                    <span class="fw-bold"><?= sanitize($cfg['account_name']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($cfg['account_number']): ?>
                <div class="d-flex justify-content-between mb-2" style="font-size:13px;">
                    <span class="text-muted">เลขบัญชี</span>
                    <span class="fw-bold font-monospace"><?= sanitize($cfg['account_number']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($cfg['prompt_pay']): ?>
                <div class="d-flex justify-content-between mb-2" style="font-size:13px;">
                    <span class="text-muted">PromptPay</span>
                    <span class="fw-bold font-monospace"><?= sanitize($cfg['prompt_pay']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($cfg['note']): ?>
                <div class="alert alert-info mt-3 mb-0" style="font-size:12px;">
                    <i class="fas fa-info-circle me-1"></i><?= nl2br(sanitize($cfg['note'])) ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-4 text-muted" style="font-size:13px;">
                    <i class="fas fa-credit-card fa-2x mb-2 d-block"></i>
                    ยังไม่มีข้อมูลการชำระเงิน<br>กรอกฟอร์มแล้วบันทึกเพื่อแสดงให้ลูกค้า
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function previewQR(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('qrPreviewImg').src = e.target.result;
            document.getElementById('qrPreview').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
