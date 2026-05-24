<?php
$pageTitle = 'เว็บไซต์ของฉัน';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $deleteId = (int)$_POST['delete_id'];
    // Verify ownership
    $site = db()->fetchOne("SELECT id FROM sites WHERE id=? AND owner_type='member' AND owner_id=?", [$deleteId, $memberId]);
    if ($site) {
        db()->delete('sites', 'id = ?', [$deleteId]);
        setFlash('success', 'ลบเว็บไซต์เรียบร้อยแล้ว');
    }
    redirect('/member/sites_list.php');
}

$sites = db()->fetchAll(
    "SELECT s.*,
            (SELECT COUNT(*) FROM articles a WHERE a.site_id=s.id AND a.owner_type='member' AND a.owner_id=?) AS article_count
     FROM sites s
     WHERE s.owner_type='member' AND s.owner_id=?
     ORDER BY s.created_at DESC",
    [$memberId, $memberId]
);

$canAdd = !$quota || $quota['sites']['remaining'] > 0 || ($quota['sites']['unlimited'] ?? false);
?>

<?php if (isset($_SESSION['flash'])): $flash = getFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
    <i class="fas fa-<?= $flash['type'] === 'success' ? 'check' : 'exclamation' ?>-circle me-2"></i><?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">เว็บไซต์ของฉัน</h4>
        <?php if ($quota): ?>
        <p class="text-muted mb-0">ใช้ไป <?= $quota['sites']['used'] ?> / <?= $quota['sites']['unlimited'] ? '∞' : $quota['sites']['limit'] ?> sites</p>
        <?php endif; ?>
    </div>
    <?php if ($canAdd): ?>
    <a href="/member/sites_edit.php" class="btn btn-primary">
        <i class="fas fa-plus me-2"></i>เพิ่มเว็บไซต์
    </a>
    <?php else: ?>
    <button class="btn btn-secondary" disabled title="Sites เต็มแล้ว">
        <i class="fas fa-lock me-2"></i>Quota เต็ม
    </button>
    <?php endif; ?>
</div>

<?php if (empty($sites)): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="fas fa-globe-asia"></i>
            <h5>ยังไม่มีเว็บไซต์</h5>
            <p>เพิ่มเว็บไซต์ WordPress เพื่อเริ่มสร้างบทความอัตโนมัติ</p>
            <?php if ($canAdd): ?>
            <a href="/member/sites_edit.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i>เพิ่มเว็บไซต์แรก
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($sites as $site): ?>
    <?php
    $isMdesBlocked = !empty($site['is_mdes_blocked']);
    $isTestFailed  = $site['last_test_status'] === 'failed' && !$isMdesBlocked;
    if ($isMdesBlocked) {
        $badgeClass = 'bg-danger';
        $badgeText  = '<i class="fas fa-ban me-1"></i>Blocked';
    } elseif (!$site['posting_enabled']) {
        $badgeClass = 'bg-secondary';
        $badgeText  = 'Paused';
    } elseif ($isTestFailed) {
        $badgeClass = 'bg-warning text-dark';
        $badgeText  = '<i class="fas fa-exclamation-triangle me-1"></i>Error';
    } else {
        $badgeClass = 'bg-success';
        $badgeText  = 'Active';
    }
    ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100 <?= $isMdesBlocked ? 'border-danger' : '' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h6 class="fw-bold mb-1" style="color:#1E293B;"><?= sanitize($site['name']) ?></h6>
                        <a href="<?= sanitize($site['base_url']) ?>" target="_blank"
                           class="text-muted text-decoration-none" style="font-size:12px;">
                            <i class="fas fa-external-link-alt me-1"></i><?= sanitize($site['base_url']) ?>
                        </a>
                    </div>
                    <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                </div>
                <?php if ($isMdesBlocked): ?>
                <div class="alert alert-danger py-2 mb-3" style="font-size:12px;">
                    <i class="fas fa-ban me-1"></i>
                    เว็บไซต์นี้ถูกบล็อกโดยกระทรวงดิจิทัลฯ (MDES)
                </div>
                <?php endif; ?>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div style="background:#F8FAFC;border-radius:8px;padding:8px 10px;text-align:center;">
                            <div style="font-size:18px;font-weight:700;color:#1E293B;"><?= number_format($site['article_count']) ?></div>
                            <div style="font-size:10px;color:#64748B;">บทความ</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background:#F8FAFC;border-radius:8px;padding:8px 10px;text-align:center;">
                            <div style="font-size:18px;font-weight:700;color:#1E293B;"><?= $site['daily_article_limit'] ?></div>
                            <div style="font-size:10px;color:#64748B;">บทความ/วัน</div>
                        </div>
                    </div>
                </div>

                <div style="font-size:12px;color:#64748B;" class="mb-3">
                    <i class="fas fa-language me-1"></i><?= strtoupper($site['language_code'] ?? 'TH') ?>
                    &nbsp;|&nbsp;
                    <i class="fas fa-tag me-1"></i><?= sanitize($site['main_topic'] ?? 'general') ?>
                </div>

                <div class="d-flex gap-2">
                    <a href="/member/sites_edit.php?id=<?= $site['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                        <i class="fas fa-edit me-1"></i>แก้ไข
                    </a>
                    <button class="btn btn-sm btn-outline-secondary test-conn-btn"
                            data-id="<?= $site['id'] ?>" title="ทดสอบการเชื่อมต่อ">
                        <i class="fas fa-plug"></i>
                    </button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('ลบเว็บไซต์นี้? บทความที่เชื่อมไว้จะยังคงอยู่')">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="delete_id" value="<?= $site['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
                <?php if ($site['last_test_time']): ?>
                <div class="mt-2 text-muted" style="font-size:11px;">
                    <i class="fas fa-clock me-1"></i>ทดสอบล่าสุด: <?= date('d/m/y H:i', strtotime($site['last_test_time'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.test-conn-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const siteId = this.dataset.id;
        const icon   = this.querySelector('i');
        this.disabled = true;
        icon.className = 'fas fa-spinner fa-spin';

        try {
            const fd = new FormData();
            fd.append('site_id', siteId);
            const resp = await fetch('/member/ajax/test_wp_connection.php', { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.mdes_blocked) {
                alert('⛔ เว็บไซต์นี้ถูกบล็อกโดยกระทรวงดิจิทัลฯ (MDES)');
            } else if (data.success) {
                alert('✅ เชื่อมต่อสำเร็จ: ' + (data.site_name || data.message));
            } else {
                alert('❌ เชื่อมต่อไม่ได้: ' + data.message);
            }
            location.reload();
        } catch(e) {
            alert('เกิดข้อผิดพลาด: ' + e.message);
        } finally {
            this.disabled = false;
            icon.className = 'fas fa-plug';
        }
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
