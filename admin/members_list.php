<?php
$pageTitle = 'จัดการสมาชิก';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/../includes/default_prompts.php';
require_once __DIR__ . '/header.php';

auth()->requireRole('staff');

// ─── Insert gambling prompts for a new member ─────────────────────────────────
function insertGamblingPrompts(int $memberId): void {
    $gamblingPrompts = getGamblingPrompts();
    $types = [
        'system'  => $gamblingPrompts['system'],
        'article' => $gamblingPrompts['article'],
    ];
    foreach ($types as $type => $content) {
        $existing = db()->fetchOne(
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
        if ($existing) {
            db()->update('prompt_templates', $data, 'id = ?', [$existing['id']]);
        } else {
            db()->insert('prompt_templates', $data);
        }
    }
}

// ─── Handle delete member ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_member') {
    auth()->requireRole('admin');
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $deleteId = (int)($_POST['member_id'] ?? 0);

    if ($deleteId) {
        $member = db()->fetchOne("SELECT id, username FROM members WHERE id=?", [$deleteId]);
        if ($member) {
            // Cascade delete all member data
            db()->delete('invite_code_uses',    'member_id = ?', [$deleteId]);
            db()->delete('member_activity_logs', 'member_id = ?', [$deleteId]);
            db()->delete('member_plans',         'member_id = ?', [$deleteId]);
            db()->delete('member_sessions',      'member_id = ?', [$deleteId]);
            db()->delete('payment_slips',        'member_id = ?', [$deleteId]);
            db()->delete('member_settings',      'member_id = ?', [$deleteId]);
            db()->delete('prompt_templates',     "owner_type='member' AND owner_id = ?", [$deleteId]);

            // Delete member's sites + cascade
            $siteIds = db()->fetchAll("SELECT id FROM sites WHERE owner_type='member' AND owner_id=?", [$deleteId]);
            foreach ($siteIds as $site) {
                db()->delete('keywords',      'site_id = ?', [$site['id']]);
                db()->delete('content_plan',  'site_id = ?', [$site['id']]);
                db()->delete('outbound_links', 'site_id = ?', [$site['id']]);
                db()->delete('articles',      "owner_type='member' AND site_id = ?", [$site['id']]);
            }
            db()->delete('sites', "owner_type='member' AND owner_id = ?", [$deleteId]);

            db()->delete('members', 'id = ?', [$deleteId]);

            logEvent('info', 'members', 'Member deleted by admin', ['member_id' => $deleteId, 'username' => $member['username']]);
            setFlash('success', "ลบสมาชิก \"{$member['username']}\" เรียบร้อยแล้ว");
        } else {
            setFlash('error', 'ไม่พบสมาชิก');
        }
    }
    redirect('/admin/members_list.php');
}

// ─── Handle AJAX toggle ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_member_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $toggleId = (int)$_POST['toggle_member_id'];
    db()->query("UPDATE members SET is_active = NOT is_active WHERE id=?", [$toggleId]);
    jsonResponse(['success' => true]);
}

// ─── Handle create member ─────────────────────────────────────────────────────
$createErrors = [];
$createSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_member') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $username    = trim($_POST['username'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $password    = $_POST['password'] ?? '';
    $fullName    = trim($_POST['full_name'] ?? '');
    $contentType = in_array($_POST['content_type'] ?? '', ['general', 'gambling'])
                   ? $_POST['content_type'] : 'general';

    // Validation
    if (strlen($username) < 3 || strlen($username) > 50) {
        $createErrors[] = 'Username ต้องมี 3-50 ตัวอักษร';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $createErrors[] = 'Username ใช้ได้เฉพาะ a-z, 0-9, underscore';
    } elseif (db()->fetchColumn("SELECT COUNT(*) FROM members WHERE username=?", [$username])) {
        $createErrors[] = 'Username นี้ถูกใช้แล้ว';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $createErrors[] = 'Email ไม่ถูกต้อง';
    } elseif (db()->fetchColumn("SELECT COUNT(*) FROM members WHERE email=?", [$email])) {
        $createErrors[] = 'Email นี้ถูกใช้แล้ว';
    }

    if (strlen($password) < 8) {
        $createErrors[] = 'Password ต้องมีอย่างน้อย 8 ตัวอักษร';
    }

    if (empty($createErrors)) {
        try {
            $memberId = db()->insert('members', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => $fullName ?: null,
                'is_active'     => 1,
                'is_verified'   => 1,
            ]);

            // Save content_type
            db()->insert('member_settings', [
                'member_id'     => $memberId,
                'setting_key'   => 'content_type',
                'setting_value' => $contentType,
                'setting_type'  => 'string',
            ]);

            // Auto-insert gambling prompts
            if ($contentType === 'gambling') {
                insertGamblingPrompts((int)$memberId);
            }

            // Auto-assign Trial plan
            planManager()->activateTrialPlan((int)$memberId);

            setFlash('success', "สร้างสมาชิก \"{$username}\" เรียบร้อยแล้ว (Trial plan assigned)" .
                ($contentType === 'gambling' ? ' + Gambling Prompts' : ''));
            redirect('/admin/members_list.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $msg = $e->getMessage();
                if (stripos($msg, 'username') !== false) {
                    $createErrors[] = 'Username นี้ถูกใช้แล้ว';
                } elseif (stripos($msg, 'email') !== false) {
                    $createErrors[] = 'Email นี้ถูกใช้แล้ว';
                } else {
                    $createErrors[] = 'ข้อมูลซ้ำกับสมาชิกที่มีอยู่แล้ว';
                }
            } else {
                $createErrors[] = 'เกิดข้อผิดพลาดในการสร้างสมาชิก กรุณาลองใหม่';
            }
        }
    }
}

// ─── Load members ─────────────────────────────────────────────────────────────
$members = db()->fetchAll(
    "SELECT m.id, m.username, m.email, m.full_name, m.is_active, m.is_verified,
            m.plan_id, m.plan_expires_at, m.created_at, m.last_login,
            p.name AS plan_name, COALESCE(p.is_trial, 0) AS is_trial,
            (SELECT COUNT(*) FROM articles a WHERE a.owner_type='member' AND a.owner_id=m.id) AS article_count,
            (SELECT COUNT(*) FROM sites s WHERE s.owner_type='member' AND s.owner_id=m.id) AS site_count,
            (SELECT setting_value FROM member_settings ms
             WHERE ms.member_id=m.id AND ms.setting_key='content_type' LIMIT 1) AS content_type
     FROM members m
     LEFT JOIN plans p ON p.id = m.plan_id
     ORDER BY m.created_at DESC"
);

$flash = getFlash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>">
    <i class="fas fa-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?> me-2"></i>
    <?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<?php if (!empty($createErrors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($createErrors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">จัดการสมาชิก</h4>
        <p class="text-muted mb-0"><?= number_format(count($members)) ?> สมาชิกทั้งหมด</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createMemberModal">
        <i class="fas fa-user-plus me-2"></i>เพิ่มสมาชิก
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0" id="membersTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>สมาชิก</th>
                        <th>ประเภท</th>
                        <th>Plan</th>
                        <th>หมดอายุ</th>
                        <th>บทความ</th>
                        <th>Sites</th>
                        <th>สถานะ</th>
                        <th>สมัครเมื่อ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                    <?php
                    $isMemberTrial = !empty($m['is_trial']);
                    $planExpired = !$isMemberTrial && $m['plan_expires_at'] && strtotime($m['plan_expires_at']) < time();
                    $daysLeft    = (!$isMemberTrial && $m['plan_expires_at']) ? ceil((strtotime($m['plan_expires_at']) - time()) / 86400) : null;
                    $isGambling  = ($m['content_type'] ?? '') === 'gambling';
                    ?>
                    <tr>
                        <td><?= $m['id'] ?></td>
                        <td>
                            <div class="fw-semibold" style="color:#1E293B;"><?= sanitize($m['username']) ?></div>
                            <small class="text-muted"><?= sanitize($m['email']) ?></small>
                        </td>
                        <td>
                            <?php if ($isGambling): ?>
                            <span class="badge" style="background:rgba(239,68,68,.15);color:#B91C1C;">
                                <i class="fas fa-dice me-1"></i>พนัน
                            </span>
                            <?php else: ?>
                            <span class="badge" style="background:rgba(16,185,129,.15);color:#047857;">
                                <i class="fas fa-globe me-1"></i>ทั่วไป
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['plan_name']): ?>
                            <?php if ($isMemberTrial): ?>
                            <span class="badge" style="background:rgba(245,158,11,.2);color:#92400E;">
                                <i class="fas fa-flask me-1"></i>Trial
                            </span>
                            <?php else: ?>
                            <span class="badge bg-gradient-primary"><?= sanitize($m['plan_name']) ?></span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="badge bg-secondary">ไม่มี Plan</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isMemberTrial): ?>
                            <span class="text-muted" style="font-size:11px;">ตลอดชีพ</span>
                            <?php elseif ($m['plan_expires_at']): ?>
                            <span class="<?= $planExpired ? 'text-danger fw-bold' : ($daysLeft <= 7 ? 'text-warning fw-bold' : '') ?>">
                                <?= date('d/m/y', strtotime($m['plan_expires_at'])) ?>
                                <?php if (!$planExpired && $daysLeft !== null): ?>
                                <small class="d-block text-muted"><?= $daysLeft ?> วัน</small>
                                <?php elseif ($planExpired): ?>
                                <small class="d-block text-danger">หมดอายุ</small>
                                <?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($m['article_count']) ?></td>
                        <td><?= number_format($m['site_count']) ?></td>
                        <td>
                            <?php if ($m['is_active']): ?>
                                <span class="status-badge status-success">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-danger">Suspended</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= date('d/m/y', strtotime($m['created_at'])) ?></small></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= ADMIN_URL ?>/members_edit.php?id=<?= $m['id'] ?>" class="btn btn-xs btn-outline-primary" title="แก้ไข">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button class="btn btn-xs btn-<?= $m['is_active'] ? 'warning' : 'success' ?> toggle-member"
                                        data-id="<?= $m['id'] ?>"
                                        title="<?= $m['is_active'] ? 'Suspend' : 'Activate' ?>">
                                    <i class="fas fa-<?= $m['is_active'] ? 'ban' : 'check' ?>"></i>
                                </button>
                                <?php if (auth()->hasRole('admin')): ?>
                                <button class="btn btn-xs btn-outline-danger delete-member"
                                        data-id="<?= $m['id'] ?>"
                                        data-username="<?= sanitize($m['username']) ?>"
                                        data-articles="<?= (int)$m['article_count'] ?>"
                                        data-sites="<?= (int)$m['site_count'] ?>"
                                        title="ลบ">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Member Modal -->
<div class="modal fade" id="createMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="create_member">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>เพิ่มสมาชิกใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Content Type Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">ประเภทสมาชิก <span class="text-danger">*</span></label>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="d-block" style="cursor:pointer;" onclick="selectType('general')">
                                    <input type="radio" name="content_type" value="general" class="d-none content-type-radio" checked>
                                    <div class="content-type-card p-3 rounded" id="card-general"
                                         style="border:2px solid #6366F1;background:rgba(99,102,241,.05);cursor:pointer;transition:all .2s;">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center"
                                                 style="width:44px;height:44px;background:rgba(16,185,129,.1);">
                                                <i class="fas fa-globe text-success fa-lg"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold" style="color:#1E293B;">ลูกค้าทั่วไป</div>
                                                <small class="text-muted">เนื้อหา SEO ทั่วไป ไม่จำกัดหมวดหมู่</small>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="d-block" style="cursor:pointer;" onclick="selectType('gambling')">
                                    <input type="radio" name="content_type" value="gambling" class="d-none content-type-radio">
                                    <div class="content-type-card p-3 rounded" id="card-gambling"
                                         style="border:2px solid #E2E8F0;background:#fff;cursor:pointer;transition:all .2s;">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center"
                                                 style="width:44px;height:44px;background:rgba(239,68,68,.1);">
                                                <i class="fas fa-dice text-danger fa-lg"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold" style="color:#1E293B;">ลูกค้าเว็บพนัน</div>
                                                <small class="text-muted">Prompt พนัน (สล็อต/บาคาร่า/คาสิโน) ถูกตั้งอัตโนมัติ</small>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <div id="gamblingNotice" class="alert alert-warning mt-3 mb-0 d-none" style="font-size:13px;">
                            <i class="fas fa-info-circle me-2"></i>
                            ระบบจะสร้าง <strong>System Prompt</strong> และ <strong>Article Prompt</strong> เฉพาะพนันออนไลน์ให้อัตโนมัติ สมาชิกนี้จะเขียนเนื้อหาสล็อต บาคาร่า คาสิโนได้ทันที
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control"
                                   value="<?= sanitize($_POST['username'] ?? '') ?>"
                                   placeholder="เช่น john_doe" pattern="[a-zA-Z0-9_]+" required>
                            <small class="text-muted">a-z, 0-9, _ เท่านั้น</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ชื่อ-นามสกุล</label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?= sanitize($_POST['full_name'] ?? '') ?>"
                                   placeholder="ชื่อจริง (ไม่บังคับ)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= sanitize($_POST['email'] ?? '') ?>"
                                   placeholder="email@example.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="password" id="newPwdField" class="form-control"
                                       placeholder="อย่างน้อย 8 ตัวอักษร" required minlength="8">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd()">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus me-2"></i>สร้างสมาชิก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Member Modal -->
<div class="modal fade" id="deleteMemberModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="delete_member">
                <input type="hidden" name="member_id" id="deleteMemberId">
                <div class="modal-header bg-danger bg-opacity-10 border-danger">
                    <h5 class="modal-title text-danger"><i class="fas fa-trash me-2"></i>ลบสมาชิก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">คุณแน่ใจหรือไม่ที่จะลบสมาชิก <strong id="deleteUsername" class="text-danger"></strong>?</p>
                    <div class="alert alert-warning py-2 mb-2" style="font-size:13px;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        ข้อมูลที่จะถูกลบถาวร:
                        <span id="deleteSummary"></span>
                    </div>
                    <p class="text-muted mb-0" style="font-size:12px;">การกระทำนี้ไม่สามารถยกเลิกได้</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>ลบถาวร
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
$(document).ready(function() {
    $('#membersTable').DataTable({
        language: { emptyTable:"ไม่มีข้อมูล", info:"แสดง _START_-_END_ จาก _TOTAL_ รายการ", infoEmpty:"0 รายการ", infoFiltered:"(กรอง _MAX_)", lengthMenu:"แสดง _MENU_", loadingRecords:"กำลังโหลด...", processing:"กำลังดำเนินการ...", search:"ค้นหา:", zeroRecords:"ไม่พบข้อมูล", paginate:{first:"หน้าแรก",last:"หน้าสุดท้าย",next:"ถัดไป",previous:"ก่อนหน้า"} },
        order: [[8,'desc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [9] }]
    });

    $(document).on('click', '.delete-member', function() {
        const btn = $(this);
        const memberId  = btn.data('id');
        const username  = btn.data('username');
        const articles  = btn.data('articles');
        const sites     = btn.data('sites');

        $('#deleteMemberId').val(memberId);
        $('#deleteUsername').text(username);

        const parts = [];
        if (articles > 0) parts.push(articles + ' บทความ');
        if (sites > 0)    parts.push(sites + ' เว็บไซต์');
        parts.push('Settings, Sessions, Plans');
        $('#deleteSummary').text(' ' + parts.join(', '));

        new bootstrap.Modal(document.getElementById('deleteMemberModal')).show();
    });

    $(document).on('click', '.toggle-member', function() {
        const btn = $(this);
        const memberId = btn.data('id');
        if (!confirm('เปลี่ยนสถานะสมาชิกนี้?')) return;

        $.post('', {
            csrf_token: '<?= $csrfToken ?>',
            toggle_member_id: memberId
        }).done(function(r) {
            if (r.success) location.reload();
        });
    });

    <?php if (!empty($createErrors)): ?>
    new bootstrap.Modal(document.getElementById('createMemberModal')).show();
    <?php endif; ?>
});

function selectType(type) {
    document.querySelector(`input[name="content_type"][value="${type}"]`).checked = true;
    ['general','gambling'].forEach(t => {
        const card = document.getElementById('card-' + t);
        if (t === type) {
            card.style.border = '2px solid #6366F1';
            card.style.background = 'rgba(99,102,241,.05)';
        } else {
            card.style.border = '2px solid #E2E8F0';
            card.style.background = '#fff';
        }
    });
    document.getElementById('gamblingNotice').classList.toggle('d-none', type !== 'gambling');
}

function togglePwd() {
    const f = document.getElementById('newPwdField');
    const i = document.getElementById('eyeIcon');
    if (f.type === 'password') { f.type = 'text'; i.className = 'fas fa-eye-slash'; }
    else { f.type = 'password'; i.className = 'fas fa-eye'; }
}
</script>
<?php $pageScripts = ob_get_clean(); ?>

<?php require_once __DIR__ . '/footer.php'; ?>
