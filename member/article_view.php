<?php
$pageTitle = 'ดูบทความ';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/wordpress_client.php';
require_once __DIR__ . '/../includes/helpers.php';

$memberId  = (int)$currentMember['id'];
$articleId = (int)($_GET['id'] ?? 0);

$article = db()->fetchOne(
    "SELECT a.*, s.name AS site_name, s.base_url AS site_url, s.wp_api_url, s.wp_user, s.wp_app_password,
            s.default_category_id, s.default_author_id, s.post_status, s.main_topic
     FROM articles a
     LEFT JOIN sites s ON s.id = a.site_id
     WHERE a.id=? AND a.owner_type='member' AND a.owner_id=?",
    [$articleId, $memberId]
);

if (!$article) {
    setFlash('error', 'ไม่พบบทความนี้');
    redirect('/member/articles_list.php');
}

// ─── Action: Publish to WordPress ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    if (empty($article['wp_api_url']) || empty($article['wp_user'])) {
        setFlash('error', 'เว็บไซต์นี้ยังไม่ได้ตั้งค่า WordPress API');
    } elseif ($article['status'] === 'published') {
        setFlash('error', 'บทความนี้โพสต์แล้ว');
    } else {
        try {
            $wp = new WordPressClient($article);
            $contentWithSchema = appendFaqSchema($article['content']);
            $postData = [
                'title'          => $article['title'],
                'content'        => $contentWithSchema,
                'status'         => $article['post_status'] ?? 'publish',
                'excerpt'        => $article['excerpt'] ?? '',
                'slug'           => $article['slug'] ?? '',
                'category_id'    => $article['default_category_id'],
                'author_id'      => $article['default_author_id'],
                'focus_keyword'  => $article['primary_keyword'],
                'meta_title'     => $article['meta_title'] ?? '',
                'meta_description' => $article['meta_description'] ?? '',
            ];
            if ($article['featured_image_id']) {
                $postData['featured_media'] = (int)$article['featured_image_id'];
            }
            $postResult = $wp->createPost($postData);
            if ($postResult['success']) {
                db()->update('articles', [
                    'wp_post_id'   => $postResult['post_id'],
                    'post_url'     => $postResult['post_url'],
                    'status'       => 'published',
                    'published_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$articleId]);
                db()->query("UPDATE sites SET articles_posted_today=articles_posted_today+1, total_articles_posted=total_articles_posted+1, last_post_time=NOW() WHERE id=?", [$article['site_id']]);
                setFlash('success', 'โพสต์บทความสำเร็จ! <a href="' . htmlspecialchars($postResult['post_url']) . '" target="_blank">ดูบทความ →</a>');
            } else {
                setFlash('error', 'โพสต์ไม่สำเร็จ: ' . $postResult['message']);
            }
        } catch (Exception $e) {
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    redirect('/member/article_view.php?id=' . $articleId);
}

// ─── Action: Generate Featured Image ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_image') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    if (empty($article['wp_api_url'])) {
        setFlash('error', 'เว็บไซต์นี้ยังไม่ได้ตั้งค่า WordPress API');
    } else {
        try {
            require_once __DIR__ . '/../includes/image_generator.php';
            $imageGen = new ImageGenerator();
            $imageResult = $imageGen->generateAndDownload(
                $article['title'],
                $article['main_topic'] ?? 'general',
                $article['primary_keyword'] ?? ''
            );
            if ($imageResult['success']) {
                $wp = new WordPressClient($article);
                $altText = $imageGen->generateAltText($article['title'], $article['primary_keyword'] ?? '', $article['main_topic'] ?? '');
                $uploadResult = $wp->uploadMedia($imageResult['filepath'], $imageResult['filename'], $altText);
                @unlink($imageResult['filepath']);
                if ($uploadResult['success']) {
                    db()->update('articles', ['featured_image_id' => $uploadResult['media_id']], 'id = ?', [$articleId]);
                    setFlash('success', 'สร้าง Featured Image สำเร็จ! (WP Media ID: ' . $uploadResult['media_id'] . ')');
                } else {
                    setFlash('error', 'อัปโหลดรูปไปยัง WordPress ไม่สำเร็จ: ' . $uploadResult['message']);
                }
            } else {
                setFlash('error', 'สร้างรูปไม่สำเร็จ: ' . $imageResult['message']);
            }
        } catch (Exception $e) {
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    redirect('/member/article_view.php?id=' . $articleId);
}

// ─── Action: Delete Article ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    db()->query("DELETE FROM article_keywords WHERE article_id = ?", [$articleId]);
    db()->query("DELETE FROM articles WHERE id = ? AND owner_type='member' AND owner_id=?", [$articleId, $memberId]);
    setFlash('success', 'ลบบทความเรียบร้อยแล้ว');
    redirect('/member/articles_list.php');
}

// ─── Re-fetch article after actions ──────────────────────────────────────────
$article = db()->fetchOne(
    "SELECT a.*, s.name AS site_name, s.base_url AS site_url, s.wp_api_url, s.wp_user, s.wp_app_password,
            s.default_category_id, s.default_author_id, s.post_status, s.main_topic
     FROM articles a
     LEFT JOIN sites s ON s.id = a.site_id
     WHERE a.id=? AND a.owner_type='member' AND a.owner_id=?",
    [$articleId, $memberId]
);

$flash = getFlash();
$statusMap = ['published' => 'success', 'generated' => 'info', 'draft' => 'warning', 'failed' => 'danger'];
$statusLabel = ['published' => 'เผยแพร่แล้ว', 'generated' => 'รอโพสต์', 'draft' => 'Draft', 'failed' => 'Failed'];

// ─── Prepare safe HTML content (preserves tables / headings) ─────────────────
$allowedTags = '<p><br><h1><h2><h3><h4><h5><h6><ul><ol><li>'
             . '<table><thead><tbody><tfoot><tr><th><td><colgroup><col>'
             . '<strong><em><b><i><u><s><mark><a><blockquote><pre><code>'
             . '<hr><span><div><section><figure><figcaption><img>'
             . '<sup><sub>';
$safeContent = strip_tags($article['content'], $allowedTags);
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mb-4">
    <i class="fas fa-<?= $flash['type'] === 'success' ? 'check' : 'exclamation' ?>-circle me-2"></i><?= $flash['message'] ?>
</div>
<?php endif; ?>

<!-- Action Buttons Bar -->
<div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
    <a href="/member/articles_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>

    <?php if ($article['post_url']): ?>
    <a href="<?= sanitize($article['post_url']) ?>" target="_blank" class="btn btn-success btn-sm">
        <i class="fas fa-external-link-alt me-1"></i>ดูบนเว็บไซต์
    </a>
    <?php endif; ?>

    <a href="/member/article_edit.php?id=<?= $articleId ?>" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-pen me-1"></i>แก้ไข
    </a>

    <?php if ($article['status'] !== 'published' && !empty($article['wp_api_url'])): ?>
    <form method="POST" class="d-inline" onsubmit="return confirm('โพสต์บทความนี้ไปยัง WordPress?')">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="publish">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fab fa-wordpress me-1"></i>โพสต์บทความ
        </button>
    </form>
    <?php endif; ?>

    <?php if (!empty($article['wp_api_url'])): ?>
    <form method="POST" class="d-inline" id="genImageForm">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="generate_image">
        <button type="submit" class="btn btn-outline-info btn-sm" onclick="this.disabled=true;this.innerHTML='<i class=\'fas fa-spinner fa-spin me-1\'></i>กำลังสร้าง...';this.form.submit();">
            <i class="fas fa-image me-1"></i><?= $article['featured_image_id'] ? 'สร้าง Featured Image ใหม่' : 'สร้าง Featured Image' ?>
        </button>
    </form>
    <?php endif; ?>

    <form method="POST" class="d-inline ms-auto" onsubmit="return confirm('ลบบทความนี้? ไม่สามารถกู้คืนได้')">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger btn-sm">
            <i class="fas fa-trash me-1"></i>ลบบทความ
        </button>
    </form>
</div>

<div class="row g-4">
<!-- ─── Content Column ──────────────────────────────────────────────────────── -->
<div class="col-lg-8">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-start gap-2">
            <div class="flex-grow-1">
                <h5 class="fw-bold mb-1" style="color:#1E293B;"><?= sanitize($article['title']) ?></h5>
                <?php if ($article['primary_keyword']): ?>
                <small class="text-muted"><i class="fas fa-key me-1"></i><?= sanitize($article['primary_keyword']) ?></small>
                <?php endif; ?>
            </div>
            <span class="status-badge status-<?= $statusMap[$article['status']] ?? 'warning' ?> flex-shrink-0">
                <?= $statusLabel[$article['status']] ?? $article['status'] ?>
            </span>
        </div>
        <div class="card-body">
            <?php if ($article['meta_description']): ?>
            <div class="alert alert-info mb-4" style="font-size:13px;">
                <strong>Meta Description:</strong> <?= sanitize($article['meta_description']) ?>
            </div>
            <?php endif; ?>

            <!-- Article HTML content — tables, headings, lists all rendered -->
            <div class="article-content" style="font-size:14px;line-height:1.9;color:#334155;">
                <?= $safeContent ?>
            </div>
        </div>
    </div>
</div>

<!-- ─── Sidebar Column ─────────────────────────────────────────────────────── -->
<div class="col-lg-4">

    <!-- Featured Image Card -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-image me-2 text-primary"></i>Featured Image</span>
            <?php if ($article['featured_image_id']): ?>
            <span class="badge bg-success" style="font-size:10px;">มีรูปแล้ว</span>
            <?php else: ?>
            <span class="badge bg-secondary" style="font-size:10px;">ยังไม่มีรูป</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-3">
            <?php if ($article['featured_image_id']): ?>
            <div class="text-center mb-3">
                <!-- Try loading the WP featured image as a background hint -->
                <div style="background:linear-gradient(135deg,#6366F1,#8B5CF6);border-radius:12px;padding:32px 16px;color:white;text-align:center;">
                    <i class="fas fa-image fa-3x mb-2" style="opacity:.7;"></i>
                    <div style="font-size:12px;opacity:.9;">WP Media ID: <?= (int)$article['featured_image_id'] ?></div>
                </div>
            </div>
            <?php if ($article['site_url']): ?>
            <a href="<?= sanitize(rtrim($article['site_url'],'/')) ?>/wp-admin/upload.php" target="_blank"
               class="btn btn-outline-secondary btn-sm w-100 mb-2" style="font-size:12px;">
                <i class="fas fa-external-link-alt me-1"></i>ดูใน WordPress Media Library
            </a>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-center py-3 text-muted" style="font-size:13px;">
                <i class="fas fa-image fa-2x mb-2 d-block" style="opacity:.3;"></i>
                ยังไม่มี Featured Image<br>
                <small>กด "สร้าง Featured Image" ด้านบน</small>
            </div>
            <?php endif; ?>

            <?php if (!empty($article['wp_api_url'])): ?>
            <form method="POST" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="generate_image">
                <button type="submit" class="btn btn-outline-info btn-sm w-100"
                        onclick="this.disabled=true;this.innerHTML='<i class=\'fas fa-spinner fa-spin me-1\'></i>กำลังสร้าง...';this.form.submit();"
                        style="font-size:12px;">
                    <i class="fas fa-wand-magic-sparkles me-1"></i>
                    <?= $article['featured_image_id'] ? 'สร้างใหม่ด้วย AI' : 'สร้างด้วย AI' ?>
                </button>
            </form>
            <?php else: ?>
            <p class="text-muted mt-2 mb-0" style="font-size:11px;">
                <i class="fas fa-info-circle me-1"></i>ตั้งค่า WordPress API ก่อนสร้างรูป
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Article Info Card -->
    <div class="card mb-3">
        <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>ข้อมูลบทความ</div>
        <div class="card-body py-2 px-3">
            <?php $rows = [
                ['เว็บไซต์',    $article['site_name'] ?? '-'],
                ['คำทั้งหมด',   $article['word_count'] ? number_format($article['word_count']) . ' คำ' : '-'],
                ['AI Tokens',   $article['ai_tokens_used'] ? number_format($article['ai_tokens_used']) : '-'],
                ['AI Model',    $article['ai_model'] ?? '-'],
                ['Quality Score', $article['quality_score'] ? $article['quality_score'] . '/100' : '-'],
                ['สร้างเมื่อ',   date('d/m/Y H:i', strtotime($article['created_at']))],
                ['เผยแพร่เมื่อ', $article['published_at'] ? date('d/m/Y H:i', strtotime($article['published_at'])) : '-'],
                ['WP Post ID',  $article['wp_post_id'] ?? '-'],
            ]; ?>
            <?php foreach ($rows as [$label, $val]): ?>
            <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #F1F5F9;">
                <span class="text-muted" style="font-size:12px;"><?= $label ?></span>
                <span class="fw-semibold" style="font-size:12px;max-width:58%;text-align:right;word-break:break-all;"><?= sanitize((string)$val) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Keywords Card -->
    <?php if ($article['secondary_keywords']): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-tags me-2 text-warning"></i>Secondary Keywords</div>
        <div class="card-body">
            <?php $secKws = json_decode($article['secondary_keywords'], true) ?? []; ?>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach (array_slice($secKws, 0, 30) as $kw): ?>
                <span class="badge bg-light text-dark" style="font-size:11px;"><?= sanitize($kw) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /col -->
</div><!-- /row -->

<style>
/* ─── Article content styling ─────────────────────────────────────────────── */
.article-content h1,.article-content h2,.article-content h3,
.article-content h4,.article-content h5,.article-content h6 {
    color:#1E293B; font-weight:700; margin-top:1.5rem; margin-bottom:.75rem;
}
.article-content h2 { font-size:1.25rem; border-left:4px solid #6366F1; padding-left:12px; }
.article-content h3 { font-size:1.1rem; color:#4F46E5; }
.article-content p  { margin-bottom:1rem; }
.article-content ul,.article-content ol { padding-left:1.5rem; margin-bottom:1rem; }
.article-content li { margin-bottom:.4rem; }
.article-content blockquote {
    border-left:4px solid #E2E8F0; padding:12px 16px; margin:1rem 0;
    background:#F8FAFC; border-radius:0 8px 8px 0; font-style:italic; color:#64748B;
}
.article-content pre,.article-content code {
    background:#F1F5F9; border-radius:6px; padding:2px 6px; font-size:13px;
}
.article-content pre { padding:16px; overflow-x:auto; margin-bottom:1rem; }
.article-content hr { border:none; border-top:2px solid #F1F5F9; margin:1.5rem 0; }
.article-content img { max-width:100%; border-radius:8px; margin:8px 0; }
/* ─── Table styling ─────────────────────────────────────────────────────── */
.article-content table {
    width:100%; border-collapse:collapse; margin:1.25rem 0;
    border-radius:12px; overflow:hidden;
    box-shadow:0 1px 8px rgba(0,0,0,.08);
    font-size:13px;
}
.article-content thead th {
    background:linear-gradient(135deg,#6366F1,#8B5CF6);
    color:#fff; font-weight:600; padding:12px 16px;
    text-align:left; border:none;
    font-size:12px; letter-spacing:.4px;
}
.article-content tbody tr:nth-child(even) { background:#F8FAFC; }
.article-content tbody tr:hover { background:rgba(99,102,241,.05); }
.article-content td,.article-content th[scope="row"] {
    padding:11px 16px; border-bottom:1px solid #E2E8F0;
    vertical-align:middle; color:#475569;
}
.article-content tbody tr:last-child td { border-bottom:none; }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
