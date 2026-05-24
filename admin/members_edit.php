<?php
$pageTitle = 'แก้ไขสมาชิก';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/../includes/default_prompts.php';
require_once __DIR__ . '/header.php';

auth()->requireRole('staff');

$memberId = (int)($_GET['id'] ?? 0);
$member   = db()->fetchOne("SELECT * FROM members WHERE id=?", [$memberId]);

if (!$member) {
    setFlash('error', 'ไม่พบสมาชิกนี้');
    redirect(ADMIN_URL . '/members_list.php');
}

$plans   = planManager()->getAllActivePlans();
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'update_plan') {
        $planId  = (int)$_POST['plan_id'];
        $months  = max(1, (int)$_POST['months']);
        $extend  = isset($_POST['extend']); // extend vs set new

        if ($planId) {
            if ($extend) {
                planManager()->activateOrExtend($memberId, $planId, $months);
                $success = "ต่ออายุ Plan สำเร็จ ({$months} เดือน)";
            } else {
                // Set expiry from now
                db()->query(
                    "UPDATE member_plans SET status='expired' WHERE member_id=? AND status='active'",
                    [$memberId]
                );
                planManager()->activateOrExtend($memberId, $planId, $months);
                $success = "เปลี่ยน Plan สำเร็จ";
            }
            $member = db()->fetchOne("SELECT * FROM members WHERE id=?", [$memberId]);
        }

    } elseif ($action === 'reset_quota') {
        db()->query(
            "UPDATE member_plans SET articles_used_this_month=0, tokens_used_this_month=0, quota_reset_at=CURDATE()
             WHERE member_id=? AND status='active'",
            [$memberId]
        );
        $success = 'Reset quota เรียบร้อยแล้ว';

    } elseif ($action === 'verify') {
        db()->update('members', ['is_verified' => 1], 'id = ?', [$memberId]);
        $member = db()->fetchOne("SELECT * FROM members WHERE id=?", [$memberId]);
        $success = 'ยืนยัน member เรียบร้อยแล้ว';

    } elseif ($action === 'toggle_active') {
        db()->query("UPDATE members SET is_active = NOT is_active WHERE id=?", [$memberId]);
        $member = db()->fetchOne("SELECT * FROM members WHERE id=?", [$memberId]);
        $success = 'เปลี่ยนสถานะเรียบร้อยแล้ว';

    } elseif ($action === 'update_content_type') {
        $newType = in_array($_POST['content_type'] ?? '', ['general', 'gambling'])
                   ? $_POST['content_type'] : 'general';
        $oldType = db()->fetchColumn(
            "SELECT setting_value FROM member_settings WHERE member_id=? AND setting_key='content_type'",
            [$memberId]
        ) ?: 'general';

        // Upsert setting
        $existing = db()->fetchOne(
            "SELECT id FROM member_settings WHERE member_id=? AND setting_key='content_type'",
            [$memberId]
        );
        if ($existing) {
            db()->update('member_settings', ['setting_value' => $newType], 'id = ?', [$existing['id']]);
        } else {
            db()->insert('member_settings', [
                'member_id'     => $memberId,
                'setting_key'   => 'content_type',
                'setting_value' => $newType,
                'setting_type'  => 'string',
            ]);
        }

        if ($newType === 'gambling' && $oldType !== 'gambling') {
            // เปลี่ยนเป็นพนัน — insert gambling prompts
            $gp = getGamblingPrompts();
            foreach ($gp as $type => $content) {
                $pt = db()->fetchOne(
                    "SELECT id FROM prompt_templates WHERE template_type=? AND owner_type='member' AND owner_id=?",
                    [$type, $memberId]
                );
                $data = [
                    'name'           => ($type === 'system' ? 'System' : 'Article') . " Prompt — Gambling (Member #{$memberId})",
                    'template_type'  => $type,
                    'topic'          => 'casino',
                    'language_code'  => 'th',
                    'prompt_content' => $content,
                    'owner_type'     => 'member',
                    'owner_id'       => $memberId,
                    'is_default'     => 0,
                    'is_active'      => 1,
                ];
                if ($pt) db()->update('prompt_templates', $data, 'id = ?', [$pt['id']]);
                else     db()->insert('prompt_templates', $data);
            }
            $success = 'เปลี่ยนเป็น Gambling — ตั้งค่า Prompts พนันอัตโนมัติแล้ว';
        } elseif ($newType === 'general' && $oldType !== 'general') {
            // เปลี่ยนเป็นทั่วไป — ลบ gambling prompts ออก (fall back to system default)
            db()->query(
                "DELETE FROM prompt_templates
                 WHERE owner_type='member' AND owner_id=? AND template_type IN ('system','article')",
                [$memberId]
            );
            $success = 'เปลี่ยนเป็นทั่วไป — Prompts รีเซ็ตกลับค่าเริ่มต้นของระบบแล้ว';
        } else {
            $success = 'บันทึกประเภทสมาชิกแล้ว';
        }
    }
}

$currentSub  = planManager()->getMemberPlan($memberId);
$quota       = $memberId ? planManager()->getQuota($memberId) : null;
$contentType = db()->fetchColumn(
    "SELECT setting_value FROM member_settings WHERE member_id=? AND setting_key='content_type'",
    [$memberId]
) ?: 'general';
$isGambling  = ($contentType === 'gambling');
$slips      = db()->fetchAll(
    "SELECT ps.*, p.name AS plan_name FROM payment_slips ps
     JOIN plans p ON p.id=ps.plan_id WHERE ps.member_id=? ORDER BY ps.created_at DESC LIMIT 10",
    [$memberId]
);
?>

<div class="mb-3">
    <a href="<?= ADMIN_URL ?>/members_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Member Info -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-user me-2 text-primary"></i>ข้อมูลสมาชิก</div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div style="width:64px;height:64px;background:linear-gradient(135deg,#6366F1,#8B5CF6);
                        border-radius:16px;display:inline-flex;align-items:center;justify-content:center;
                        color:#fff;font-size:24px;font-weight:700;">
                        <?= strtoupper(substr($member['username'],0,1)) ?>
                    </div>
                    <h6 class="fw-bold mt-2 mb-0"><?= sanitize($member['username']) ?></h6>
                    <small class="text-muted"><?= sanitize($member['email']) ?></small>
                </div>

                <?php $infoRows = [
                    ['ชื่อ', $member['full_name'] ?: '-'],
                    ['เบอร์', $member['phone'] ?: '-'],
                    ['สมัครเมื่อ', date('d/m/Y H:i', strtotime($member['created_at']))],
                    ['Login ล่าสุด', $member['last_login'] ? date('d/m/Y H:i', strtotime($member['last_login'])) : '-'],
                ]; ?>
                <?php foreach ($infoRows as [$label, $val]): ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted" style="font-size:12px;"><?= $label ?></span>
                    <span style="font-size:12px;font-weight:600;"><?= sanitize($val) ?></span>
                </div>
                <?php endforeach; ?>

                <div class="d-flex gap-2 mt-3 flex-wrap">
                    <?php if (!$member['is_verified']): ?>
                    <form method="POST" class="flex-fill">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="verify">
                        <button type="submit" class="btn btn-success btn-sm w-100">
                            <i class="fas fa-check me-1"></i>Verify
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" class="flex-fill">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <button type="submit" class="btn btn-<?= $member['is_active'] ? 'warning' : 'success' ?> btn-sm w-100">
                            <i class="fas fa-<?= $member['is_active'] ? 'ban' : 'check' ?> me-1"></i>
                            <?= $member['is_active'] ? 'Suspend' : 'Activate' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Quota -->
        <?php if ($quota && $quota['plan_name']): ?>
        <div class="card mt-3">
            <div class="card-header">
                <i class="fas fa-gauge-high me-2 text-warning"></i>
                <?= !empty($quota['is_trial']) ? 'Trial Quota (ตลอดชีพ)' : 'Quota เดือนนี้' ?>
            </div>
            <div class="card-body">
                <?php foreach (['articles'=>'บทความ','sites'=>'Sites','keywords'=>'Keywords'] as $key=>$label): ?>
                <?php $q = $quota[$key]; ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span style="font-size:12px;"><?= $label ?></span>
                        <span style="font-size:12px;font-weight:600;">
                            <?= $q['used'] ?> / <?= $q['unlimited'] ? '∞' : $q['limit'] ?>
                        </span>
                    </div>
                    <?php if (!$q['unlimited']): ?>
                    <?php $pct = $q['limit'] > 0 ? min(100, round($q['used']/$q['limit']*100)) : 0; ?>
                    <div class="progress">
                        <div class="progress-bar bg-<?= $pct>=90?'danger':($pct>=70?'warning':'success') ?>"
                             style="width:<?= $pct ?>%;"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="reset_quota">
                    <button type="submit" class="btn btn-outline-warning btn-sm w-100"
                            onclick="return confirm('Reset quota เดือนนี้?')">
                        <i class="fas fa-rotate me-1"></i>Reset Quota
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Content Type -->
        <div class="card mt-3">
            <div class="card-header"><i class="fas fa-layer-group me-2 text-secondary"></i>ประเภทสมาชิก</div>
            <div class="card-body">
                <div class="mb-3 text-center">
                    <?php if ($isGambling): ?>
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2"
                         style="width:52px;height:52px;background:rgba(239,68,68,.1);">
                        <i class="fas fa-dice fa-xl text-danger"></i>
                    </div>
                    <div class="fw-bold" style="color:#B91C1C;">ลูกค้าเว็บพนัน</div>
                    <small class="text-muted">ใช้ Gambling Prompts</small>
                    <?php else: ?>
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2"
                         style="width:52px;height:52px;background:rgba(16,185,129,.1);">
                        <i class="fas fa-globe fa-xl text-success"></i>
                    </div>
                    <div class="fw-bold" style="color:#047857;">ลูกค้าทั่วไป</div>
                    <small class="text-muted">ใช้ General Prompts</small>
                    <?php endif; ?>
                </div>

                <form method="POST" onsubmit="return confirmTypeChange(this)">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_content_type">
                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="content_type" value="general"
                                   id="typeGeneral" <?= !$isGambling ? 'checked' : '' ?>>
                            <label class="form-check-label" for="typeGeneral">
                                <i class="fas fa-globe text-success me-1"></i>ทั่วไป
                                <small class="text-muted d-block ms-4">General Prompts (ค่าเริ่มต้น)</small>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="content_type" value="gambling"
                                   id="typeGambling" <?= $isGambling ? 'checked' : '' ?>>
                            <label class="form-check-label" for="typeGambling">
                                <i class="fas fa-dice text-danger me-1"></i>พนัน
                                <small class="text-muted d-block ms-4">Gambling Prompts (สล็อต/บาคาร่า/คาสิโน)</small>
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-save me-1"></i>บันทึกประเภท
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <!-- Plan Management -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-crown me-2 text-warning"></i>จัดการ Plan</div>
            <div class="card-body">
                <?php if ($currentSub): ?>
                <?php $subIsTrial = !empty($currentSub['is_trial']); ?>
                <div class="alert alert-<?= $subIsTrial ? 'warning' : 'info' ?> mb-3" style="font-size:13px;">
                    <?php if ($subIsTrial): ?>
                    <i class="fas fa-flask me-2"></i>
                    Plan ปัจจุบัน: <strong>Trial</strong> — ใช้ได้ตลอดชีพ (ไม่รองรับ Auto-posting)
                    <?php else: ?>
                    <i class="fas fa-info-circle me-2"></i>
                    Plan ปัจจุบัน: <strong><?= sanitize($currentSub['plan_name']) ?></strong>
                    — หมดอายุ <strong><?= date('d/m/Y', strtotime($currentSub['expires_at'])) ?></strong>
                    (<?= ceil((strtotime($currentSub['expires_at'])-time())/86400) ?> วัน)
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_plan">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Plan</label>
                            <select name="plan_id" class="form-select">
                                <?php foreach ($plans as $plan): if (!empty($plan['is_trial'])) continue; ?>
                                <option value="<?= $plan['id'] ?>" <?= ($member['plan_id']==$plan['id']) ? 'selected' : '' ?>>
                                    <?= sanitize($plan['name']) ?> — ฿<?= number_format($plan['price'],0) ?>/เดือน
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">จำนวน (เดือน)</label>
                            <input type="number" name="months" class="form-control" value="1" min="1" max="24">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="extend" id="extendCheck" checked>
                                <label class="form-check-label" for="extendCheck" style="font-size:13px;">
                                    ต่อจากวันหมดอายุเดิม
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-save me-1"></i>บันทึก Plan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment History -->
        <div class="card">
            <div class="card-header"><i class="fas fa-history me-2 text-info"></i>ประวัติการชำระเงิน</div>
            <div class="card-body p-0">
                <?php if (empty($slips)): ?>
                <div class="text-center py-4 text-muted" style="font-size:13px;">ยังไม่มีประวัติ</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Plan</th><th>จำนวน</th><th>สถานะ</th><th>วันที่</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($slips as $slip): ?>
                        <tr>
                            <td><?= sanitize($slip['plan_name']) ?> (<?= $slip['months_to_add'] ?> เดือน)</td>
                            <td class="fw-bold">฿<?= number_format($slip['amount'],0) ?></td>
                            <td>
                                <?php $smap=['pending'=>'warning','approved'=>'success','rejected'=>'danger']; ?>
                                <span class="status-badge status-<?= $smap[$slip['status']] ?>"><?= $slip['status'] ?></span>
                            </td>
                            <td><small><?= date('d/m/y H:i', strtotime($slip['created_at'])) ?></small></td>
                            <td>
                                <a href="<?= ADMIN_URL ?>/slips_review.php?id=<?= $slip['id'] ?>" class="btn btn-xs btn-outline-primary">
                                    <i class="fas fa-eye"></i>
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
</div>

<script>
function confirmTypeChange(form) {
    const selected = form.querySelector('input[name="content_type"]:checked').value;
    const current  = <?= json_encode($contentType) ?>;
    if (selected === current) return true;
    if (selected === 'gambling') {
        return confirm('เปลี่ยนเป็น "ลูกค้าพนัน"\nระบบจะติดตั้ง Gambling Prompts ให้อัตโนมัติ\nดำเนินการต่อ?');
    } else {
        return confirm('เปลี่ยนเป็น "ลูกค้าทั่วไป"\nGambling Prompts จะถูกลบออก AI จะใช้ค่าเริ่มต้นทั่วไปแทน\nดำเนินการต่อ?');
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
