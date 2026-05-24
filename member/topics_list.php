<?php
$pageTitle = 'หมวดหมู่';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];
$flash    = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $slug   = trim(strtolower(preg_replace('/[^a-z0-9_]/', '', $_POST['slug'] ?? '')));
        $nameTh = trim($_POST['name_th'] ?? '');

        if (empty($slug) || empty($nameTh)) {
            setFlash('error', 'กรุณากรอก Slug และชื่อภาษาไทย');
        } elseif (strlen($slug) < 2) {
            setFlash('error', 'Slug ต้องมีอย่างน้อย 2 ตัวอักษร');
        } else {
            // Check slug unique across ALL owners (avoid JOIN ambiguity in articles)
            $existing = db()->fetchColumn("SELECT id FROM topics WHERE slug=?", [$slug]);
            if ($existing) {
                setFlash('error', "Slug '{$slug}' มีอยู่แล้ว กรุณาใช้ชื่ออื่น");
            } else {
                db()->insert('topics', [
                    'owner_type' => 'member',
                    'owner_id'   => $memberId,
                    'slug'       => $slug,
                    'name_th'    => $nameTh,
                    'sort_order' => 10,
                    'is_active'  => 1,
                ]);
                setFlash('success', "เพิ่มหมวดหมู่ '{$nameTh}' สำเร็จ");
            }
        }

    } elseif ($action === 'delete') {
        $topicId = (int)($_POST['topic_id'] ?? 0);
        $topic = db()->fetchOne(
            "SELECT id, name_th FROM topics WHERE id=? AND owner_type='member' AND owner_id=?",
            [$topicId, $memberId]
        );
        if ($topic) {
            db()->delete('topics', 'id=?', [$topicId]);
            setFlash('success', "ลบหมวดหมู่ '{$topic['name_th']}' สำเร็จ");
        } else {
            setFlash('error', 'ไม่พบหมวดหมู่หรือไม่มีสิทธิ์ลบ');
        }

    } elseif ($action === 'toggle') {
        $topicId = (int)($_POST['topic_id'] ?? 0);
        $topic = db()->fetchOne(
            "SELECT id FROM topics WHERE id=? AND owner_type='member' AND owner_id=?",
            [$topicId, $memberId]
        );
        if ($topic) {
            db()->query("UPDATE topics SET is_active = NOT is_active WHERE id=?", [$topicId]);
            setFlash('success', 'อัปเดตสถานะสำเร็จ');
        }
    }

    redirect('/member/topics_list.php');
}

// Member's own topics
$myTopics = db()->fetchAll(
    "SELECT * FROM topics WHERE owner_type='member' AND owner_id=? ORDER BY sort_order ASC, name_th ASC",
    [$memberId]
);

// System topics (admin-owned, read-only)
$systemTopics = db()->fetchAll(
    "SELECT * FROM topics WHERE owner_type='admin' ORDER BY sort_order ASC, name_th ASC"
);
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>">
    <?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- My Topics -->
    <div class="col-lg-8">
        <!-- Member's own -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-tags me-2 text-primary"></i>หมวดหมู่ของฉัน (<?= count($myTopics) ?>)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($myTopics)): ?>
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <h5>ยังไม่มีหมวดหมู่</h5>
                    <p>เพิ่มหมวดหมู่แรกของคุณด้านขวา</p>
                </div>
                <?php else: ?>
                <table class="table mb-0">
                    <thead>
                        <tr><th>Slug</th><th>ชื่อภาษาไทย</th><th>สถานะ</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myTopics as $t): ?>
                    <tr>
                        <td><code style="font-size:12px;"><?= sanitize($t['slug']) ?></code></td>
                        <td style="font-size:13px;font-weight:500;"><?= sanitize($t['name_th']) ?></td>
                        <td>
                            <?php if ($t['is_active']): ?>
                            <span class="status-badge status-success">Active</span>
                            <?php else: ?>
                            <span class="status-badge status-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
                                <button class="btn btn-outline-secondary btn-sm" title="เปิด/ปิด">
                                    <i class="fas fa-toggle-<?= $t['is_active'] ? 'on' : 'off' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline ms-1">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm"
                                        onclick="return confirm('ลบหมวดหมู่ \'<?= sanitize($t['name_th']) ?>\'?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- System topics (read-only) -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-shield-alt me-2 text-secondary"></i>หมวดหมู่ระบบ
                <small class="text-muted ms-2">(อ่านอย่างเดียว)</small>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr><th>Slug</th><th>ชื่อภาษาไทย</th><th>สถานะ</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($systemTopics as $t): ?>
                    <tr>
                        <td><code style="font-size:12px;"><?= sanitize($t['slug']) ?></code></td>
                        <td style="font-size:13px;"><?= sanitize($t['name_th']) ?></td>
                        <td>
                            <?php if ($t['is_active']): ?>
                            <span class="status-badge status-success">Active</span>
                            <?php else: ?>
                            <span class="status-badge status-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Topic -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-plus me-2 text-success"></i>เพิ่มหมวดหมู่</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Slug <span class="text-danger">*</span></label>
                        <input type="text" name="slug" class="form-control"
                               placeholder="เช่น gaming, travel"
                               pattern="[a-z0-9_]+" minlength="2" required>
                        <small class="text-muted">ตัวพิมพ์เล็ก, ตัวเลข, _ เท่านั้น</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">ชื่อภาษาไทย <span class="text-danger">*</span></label>
                        <input type="text" name="name_th" class="form-control"
                               placeholder="เช่น เกม, ท่องเที่ยว" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-plus me-2"></i>เพิ่มหมวดหมู่
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>หมายเหตุ</div>
            <div class="card-body">
                <ul class="small ps-3 mb-0" style="line-height:1.9;">
                    <li>หมวดหมู่ของคุณเห็นได้เฉพาะคุณเท่านั้น</li>
                    <li>Slug ต้องไม่ซ้ำกับที่มีอยู่ในระบบ</li>
                    <li>ลบได้เฉพาะหมวดหมู่ที่คุณสร้างเอง</li>
                    <li>หมวดหมู่ระบบแก้ไขได้โดย Admin เท่านั้น</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
