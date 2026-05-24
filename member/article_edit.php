<?php
$pageTitle = 'แก้ไขบทความ';
require_once __DIR__ . '/header.php';

$memberId  = (int)$currentMember['id'];
$articleId = (int)($_GET['id'] ?? 0);

$article = db()->fetchOne(
    "SELECT * FROM articles WHERE id=? AND owner_type='member' AND owner_id=?",
    [$articleId, $memberId]
);

if (!$article) {
    setFlash('error', 'ไม่พบบทความนี้');
    redirect('/member/articles_list.php');
}

// ─── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $title           = trim($_POST['title'] ?? '');
    $content         = trim($_POST['content'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');

    if (empty($title)) {
        $error = 'กรุณากรอกชื่อบทความ';
    } elseif (empty($content)) {
        $error = 'กรุณากรอกเนื้อหาบทความ';
    } else {
        db()->update('articles', [
            'title'            => $title,
            'seo_title'        => mb_substr($title, 0, 60),
            'meta_title'       => mb_substr($title, 0, 60),
            'content'          => $content,
            'meta_description' => $metaDescription,
            'word_count'       => str_word_count(strip_tags($content)),
            'status'           => $article['status'] === 'published' ? 'generated' : $article['status'],
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id = ?', [$articleId]);

        setFlash('success', 'บันทึกการแก้ไขเรียบร้อยแล้ว');
        redirect('/member/article_view.php?id=' . $articleId);
    }
}

$flash = getFlash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mb-4">
    <i class="fas fa-<?= $flash['type'] === 'success' ? 'check' : 'exclamation' ?>-circle me-2"></i><?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?>
</div>
<?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/member/article_view.php?id=<?= $articleId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
    <h5 class="mb-0 fw-bold" style="color:#1E293B;">แก้ไขบทความ</h5>
    <?php if ($article['status'] === 'published'): ?>
    <span class="badge bg-warning text-dark" style="font-size:11px;">
        <i class="fas fa-exclamation-triangle me-1"></i>บทความนี้เผยแพร่แล้ว — การแก้ไขจะเปลี่ยนสถานะกลับเป็น "รอโพสต์"
    </span>
    <?php endif; ?>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="fas fa-pen me-2 text-primary"></i>เนื้อหาบทความ</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">ชื่อบทความ <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control"
                               value="<?= sanitize($article['title']) ?>" required
                               placeholder="หัวข้อบทความ">
                        <small class="text-muted">แนะนำไม่เกิน 60 ตัวอักษร</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Meta Description</label>
                        <textarea name="meta_description" class="form-control" rows="2"
                                  placeholder="คำอธิบายสำหรับ SEO (ไม่เกิน 160 ตัวอักษร)"
                                  maxlength="200"><?= sanitize($article['meta_description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">เนื้อหาบทความ (HTML) <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="togglePreview()">
                                    <i class="fas fa-eye me-1"></i>Preview
                                </button>
                            </div>
                        </div>
                        <textarea name="content" id="contentEditor" class="form-control font-monospace"
                                  rows="30" required style="font-size:13px;resize:vertical;"><?= htmlspecialchars($article['content']) ?></textarea>
                        <small class="text-muted">รองรับ HTML — ตาราง, หัวข้อ, รายการ ทั้งหมด</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>ข้อมูล</div>
                <div class="card-body py-2 px-3">
                    <?php $rows = [
                        ['Primary Keyword', $article['primary_keyword'] ?? '-'],
                        ['คำทั้งหมด',       $article['word_count'] ? number_format($article['word_count']) . ' คำ' : '-'],
                        ['สถานะ',          $article['status']],
                        ['สร้างเมื่อ',      date('d/m/Y H:i', strtotime($article['created_at']))],
                    ]; ?>
                    <?php foreach ($rows as [$label, $val]): ?>
                    <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #F1F5F9;">
                        <span class="text-muted" style="font-size:12px;"><?= $label ?></span>
                        <span class="fw-semibold" style="font-size:12px;text-align:right;"><?= sanitize((string)$val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="fas fa-save me-1"></i>บันทึก
                    </button>
                    <a href="/member/article_view.php?id=<?= $articleId ?>" class="btn btn-outline-secondary w-100" style="font-size:14px;">
                        ยกเลิก
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContent" class="article-content" style="font-size:14px;line-height:1.9;padding:8px;"></div>
            </div>
        </div>
    </div>
</div>

<style>
.article-content h2 { font-size:1.25rem; border-left:4px solid #6366F1; padding-left:12px; color:#1E293B; font-weight:700; margin:1.2rem 0 .6rem; }
.article-content h3 { font-size:1.1rem; color:#4F46E5; font-weight:700; margin:1rem 0 .5rem; }
.article-content p  { margin-bottom:1rem; }
.article-content ul,.article-content ol { padding-left:1.5rem; margin-bottom:1rem; }
.article-content table { width:100%; border-collapse:collapse; margin:1.25rem 0; border-radius:12px; overflow:hidden; box-shadow:0 1px 8px rgba(0,0,0,.08); font-size:13px; }
.article-content thead th { background:linear-gradient(135deg,#6366F1,#8B5CF6); color:#fff; font-weight:600; padding:12px 16px; text-align:left; border:none; font-size:12px; }
.article-content tbody tr:nth-child(even) { background:#F8FAFC; }
.article-content td { padding:11px 16px; border-bottom:1px solid #E2E8F0; vertical-align:middle; color:#475569; }
.article-content tbody tr:last-child td { border-bottom:none; }
</style>

<script>
function togglePreview() {
    const content = document.getElementById('contentEditor').value;
    document.getElementById('previewContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
