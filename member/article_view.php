<?php
$pageTitle = 'ดูบทความ';
require_once __DIR__ . '/header.php';

$memberId  = (int)$currentMember['id'];
$articleId = (int)($_GET['id'] ?? 0);

$article = db()->fetchOne(
    "SELECT a.*, s.name AS site_name, s.base_url AS site_url
     FROM articles a
     LEFT JOIN sites s ON s.id = a.site_id
     WHERE a.id=? AND a.owner_type='member' AND a.owner_id=?",
    [$articleId, $memberId]
);

if (!$article) {
    setFlash('error', 'ไม่พบบทความนี้');
    redirect('/member/articles_list.php');
}
?>

<div class="mb-4">
    <a href="/member/articles_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
    <?php if ($article['post_url']): ?>
    <a href="<?= sanitize($article['post_url']) ?>" target="_blank" class="btn btn-success btn-sm ms-2">
        <i class="fas fa-external-link-alt me-1"></i>ดูบนเว็บไซต์
    </a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="fw-bold mb-1" style="color:#1E293B;"><?= sanitize($article['title']) ?></h5>
                        <?php if ($article['primary_keyword']): ?>
                        <small class="text-muted"><i class="fas fa-key me-1"></i><?= sanitize($article['primary_keyword']) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php $statusMap = ['published'=>'success','generated'=>'info','draft'=>'warning','failed'=>'danger']; ?>
                    <span class="status-badge status-<?= $statusMap[$article['status']] ?? 'warning' ?>">
                        <?= $article['status'] ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if ($article['meta_description']): ?>
                <div class="alert alert-info mb-4" style="font-size:13px;">
                    <strong>Meta Description:</strong> <?= sanitize($article['meta_description']) ?>
                </div>
                <?php endif; ?>

                <div style="font-size:14px;line-height:1.8;color:#334155;">
                    <?= nl2br(sanitize(mb_substr(strip_tags($article['content']), 0, 3000))) ?>
                    <?php if (mb_strlen(strip_tags($article['content'])) > 3000): ?>
                    <span class="text-muted">... (แสดงแค่ 3,000 ตัวอักษรแรก)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>ข้อมูลบทความ</div>
            <div class="card-body">
                <?php $rows = [
                    ['เว็บไซต์', $article['site_name'] ?? '-'],
                    ['คำทั้งหมด', $article['word_count'] ? number_format($article['word_count']).' คำ' : '-'],
                    ['AI Tokens', $article['ai_tokens_used'] ? number_format($article['ai_tokens_used']) : '-'],
                    ['AI Model', $article['ai_model'] ?? '-'],
                    ['สร้างเมื่อ', date('d/m/Y H:i', strtotime($article['created_at']))],
                    ['เผยแพร่เมื่อ', $article['published_at'] ? date('d/m/Y H:i', strtotime($article['published_at'])) : '-'],
                ]; ?>
                <?php foreach ($rows as [$label, $val]): ?>
                <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom:1px solid #F1F5F9;">
                    <span class="text-muted" style="font-size:12px;"><?= $label ?></span>
                    <span class="fw-semibold" style="font-size:12px;max-width:60%;text-align:right;"><?= sanitize((string)$val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($article['secondary_keywords']): ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-tags me-2 text-warning"></i>Secondary Keywords</div>
            <div class="card-body">
                <?php $secKws = json_decode($article['secondary_keywords'], true) ?? []; ?>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach (array_slice($secKws, 0, 20) as $kw): ?>
                    <span class="badge bg-light text-dark" style="font-size:11px;"><?= sanitize($kw) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
