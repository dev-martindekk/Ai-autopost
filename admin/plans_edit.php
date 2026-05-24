<?php
$pageTitle = 'สร้าง / แก้ไข Plan';
require_once __DIR__ . '/../includes/plan_manager.php';
require_once __DIR__ . '/header.php';

auth()->requireRole('admin');

$planId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$plan   = $planId ? planManager()->getPlan($planId) : null;
$isEdit = (bool)$plan;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $name              = trim($_POST['name'] ?? '');
    $slug              = trim($_POST['slug'] ?? '');
    $description       = trim($_POST['description'] ?? '');
    $price             = (float)($_POST['price'] ?? 0);
    $articlesPerMonth  = (int)($_POST['articles_per_month'] ?? 30);
    $maxSites          = (int)($_POST['max_sites'] ?? 1);
    $maxKeywords       = (int)($_POST['max_keywords'] ?? 200);
    $maxTokens         = (int)($_POST['max_tokens_per_month'] ?? 0);
    $sortOrder         = (int)($_POST['sort_order'] ?? 10);
    $isActive          = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($name))  $errors[] = 'กรุณากรอกชื่อ Plan';
    if (empty($slug))  $errors[] = 'กรุณากรอก Slug';
    if ($price < 0)    $errors[] = 'ราคาต้องไม่ติดลบ';
    if ($maxSites < 1) $errors[] = 'จำนวน Sites ต้องมีอย่างน้อย 1';

    // Slug uniqueness
    if (empty($errors)) {
        $existing = db()->fetchOne(
            "SELECT id FROM plans WHERE slug = ? AND id != ?",
            [$slug, $planId]
        );
        if ($existing) $errors[] = "Slug '{$slug}' ถูกใช้งานแล้ว";
    }

    if (empty($errors)) {
        $data = [
            'name'                => $name,
            'slug'                => $slug,
            'description'         => $description ?: null,
            'price'               => $price,
            'articles_per_month'  => $articlesPerMonth,
            'max_sites'           => $maxSites,
            'max_keywords'        => $maxKeywords,
            'max_tokens_per_month'=> $maxTokens,
            'sort_order'          => $sortOrder,
            'is_active'           => $isActive,
        ];

        if ($isEdit) {
            db()->update('plans', $data, 'id = ?', [$planId]);
            $successMsg = 'อัพเดต Plan เรียบร้อยแล้ว';
        } else {
            db()->insert('plans', $data);
            $successMsg = 'สร้าง Plan เรียบร้อยแล้ว';
            header('Location: plans_list.php');
            exit;
        }

        // Refresh plan data
        $plan = planManager()->getPlan($planId ?: db()->lastInsertId());
    }
}

// Default values for new plan
$v = [
    'name'                 => $plan['name'] ?? '',
    'slug'                 => $plan['slug'] ?? '',
    'description'          => $plan['description'] ?? '',
    'price'                => $plan['price'] ?? '',
    'articles_per_month'   => $plan['articles_per_month'] ?? 30,
    'max_sites'            => $plan['max_sites'] ?? 1,
    'max_keywords'         => $plan['max_keywords'] ?? 200,
    'max_tokens_per_month' => $plan['max_tokens_per_month'] ?? 0,
    'sort_order'           => $plan['sort_order'] ?? 10,
    'is_active'            => $plan['is_active'] ?? 1,
];
?>

<div class="mb-4">
    <a href="plans_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle me-2"></i>
        <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if (isset($successMsg)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= sanitize($successMsg) ?></div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="row g-4">
        <!-- Main Info -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-box me-2 text-primary"></i>ข้อมูล Plan
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อ Plan <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= sanitize($v['name']) ?>" placeholder="เช่น Starter, Pro, Agency"
                                   oninput="autoSlug(this.value)" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Slug <span class="text-danger">*</span></label>
                            <input type="text" name="slug" id="slugInput" class="form-control"
                                   value="<?= sanitize($v['slug']) ?>" placeholder="starter"
                                   pattern="[a-z0-9_-]+" required>
                            <small class="text-muted">ตัวอักษรพิมพ์เล็ก ตัวเลข - _ เท่านั้น</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">คำอธิบาย</label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="คำอธิบายแพ็คเกจสั้นๆ"><?= sanitize($v['description']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quota Settings -->
            <div class="card mt-4">
                <div class="card-header">
                    <i class="fas fa-gauge-high me-2 text-warning"></i>กำหนด Quota
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">บทความ / เดือน</label>
                            <div class="input-group">
                                <input type="number" name="articles_per_month" class="form-control"
                                       value="<?= $v['articles_per_month'] ?>" min="0">
                                <span class="input-group-text">บทความ</span>
                            </div>
                            <small class="text-muted">0 = ไม่จำกัด</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">จำนวน Sites สูงสุด</label>
                            <div class="input-group">
                                <input type="number" name="max_sites" class="form-control"
                                       value="<?= $v['max_sites'] ?>" min="1">
                                <span class="input-group-text">sites</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">จำนวน Keywords สูงสุด</label>
                            <div class="input-group">
                                <input type="number" name="max_keywords" class="form-control"
                                       value="<?= $v['max_keywords'] ?>" min="0">
                                <span class="input-group-text">keywords</span>
                            </div>
                            <small class="text-muted">0 = ไม่จำกัด</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">AI Tokens / เดือน</label>
                            <div class="input-group">
                                <input type="number" name="max_tokens_per_month" class="form-control"
                                       value="<?= $v['max_tokens_per_month'] ?>" min="0">
                                <span class="input-group-text">tokens</span>
                            </div>
                            <small class="text-muted">0 = ไม่จำกัด</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Settings -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-sliders me-2 text-info"></i>การตั้งค่า
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">ราคา / เดือน (บาท)</label>
                        <div class="input-group">
                            <span class="input-group-text">฿</span>
                            <input type="number" name="price" class="form-control"
                                   value="<?= $v['price'] ?>" min="0" step="1" placeholder="499">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ลำดับแสดงผล</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= $v['sort_order'] ?>" min="1">
                        <small class="text-muted">น้อย = แสดงก่อน</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                   <?= $v['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="isActive">เปิดใช้งาน</label>
                        </div>
                        <small class="text-muted">Plan ที่ปิดจะไม่แสดงให้สมาชิกเห็น</small>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i><?= $isEdit ? 'บันทึกการแก้ไข' : 'สร้าง Plan' ?>
                        </button>
                        <a href="plans_list.php" class="btn btn-outline-secondary">ยกเลิก</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function autoSlug(name) {
    const slugInput = document.getElementById('slugInput');
    if (slugInput.dataset.edited) return;
    slugInput.value = name.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
}

document.getElementById('slugInput').addEventListener('input', function() {
    this.dataset.edited = '1';
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
