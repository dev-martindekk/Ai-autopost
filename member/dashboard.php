<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

// Stats
try {
    $totalArticles  = db()->fetchColumn("SELECT COUNT(*) FROM articles WHERE owner_type='member' AND owner_id=?", [$memberId]);
    $pubArticles    = db()->fetchColumn("SELECT COUNT(*) FROM articles WHERE owner_type='member' AND owner_id=? AND status='published'", [$memberId]);
    $totalSites     = db()->fetchColumn("SELECT COUNT(*) FROM sites WHERE owner_type='member' AND owner_id=?", [$memberId]);
    $totalKeywords  = db()->fetchColumn("SELECT COUNT(*) FROM keywords WHERE owner_type='member' AND owner_id=? AND is_active=1", [$memberId]);

    $recentArticles = db()->fetchAll(
        "SELECT a.title, a.status, a.created_at, s.name AS site_name
         FROM articles a
         LEFT JOIN sites s ON s.id = a.site_id
         WHERE a.owner_type='member' AND a.owner_id=?
         ORDER BY a.created_at DESC LIMIT 8",
        [$memberId]
    );
} catch (Exception $e) {
    $totalArticles = $pubArticles = $totalSites = $totalKeywords = 0;
    $recentArticles = [];
}

$hasPlan = $quota && $quota['plan_name'];
?>

<!-- No plan warning -->
<?php if (!$hasPlan): ?>
<div class="alert alert-warning mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i>
    บัญชีของคุณยังไม่มี Plan ที่ active
    <a href="/member/billing.php" class="alert-link ms-2">อัพโหลดสลิปเพื่อเปิดใช้งาน →</a>
</div>
<?php endif; ?>

<!-- Quota Cards -->
<?php if ($hasPlan): ?>
<div class="row g-3 mb-4">
    <?php
    $qItems = [
        ['label'=>'บทความเดือนนี้', 'q'=>$quota['articles'], 'icon'=>'fas fa-file-alt', 'grad'=>'bg-gradient-primary'],
        ['label'=>'เว็บไซต์',        'q'=>$quota['sites'],    'icon'=>'fas fa-globe',    'grad'=>'bg-gradient-success'],
        ['label'=>'Keywords',        'q'=>$quota['keywords'], 'icon'=>'fas fa-key',      'grad'=>'bg-gradient-warning'],
        ['label'=>'วันที่เหลือ',     'q'=>null, 'icon'=>'fas fa-clock', 'grad'=>'bg-gradient-info', 'custom'=>$quota['days_left'].' วัน'],
    ];
    foreach ($qItems as $item):
        $q = $item['q'];
    ?>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card <?= $item['grad'] ?>">
            <div class="stat-card-icon"><i class="<?= $item['icon'] ?>"></i></div>
            <div class="stat-card-value">
                <?php if (isset($item['custom'])): ?>
                    <?= $item['custom'] ?>
                <?php elseif ($q['unlimited']): ?>
                    <i class="fas fa-infinity" style="font-size:24px;"></i>
                <?php else: ?>
                    <?= $q['remaining'] ?>/<small style="font-size:18px;"><?= $q['limit'] ?></small>
                <?php endif; ?>
            </div>
            <div class="stat-card-label"><?= $item['label'] ?></div>
            <?php if ($q && !$q['unlimited']): ?>
            <div class="progress mt-2" style="height:4px;background:rgba(255,255,255,.2);">
                <?php $pct = $q['limit'] > 0 ? min(100, round($q['used']/$q['limit']*100)) : 0; ?>
                <div class="progress-bar bg-white" style="width:<?= 100 - $pct ?>%;"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <?php $summaryItems = [
        ['label'=>'บทความทั้งหมด','value'=>$totalArticles, 'icon'=>'fas fa-file-alt','color'=>'#6366F1'],
        ['label'=>'Published',    'value'=>$pubArticles,   'icon'=>'fas fa-globe-asia','color'=>'#10B981'],
        ['label'=>'เว็บไซต์',     'value'=>$totalSites,   'icon'=>'fas fa-server',  'color'=>'#F59E0B'],
        ['label'=>'Keywords',     'value'=>$totalKeywords, 'icon'=>'fas fa-key',     'color'=>'#06B6D4'],
    ]; ?>
    <?php foreach ($summaryItems as $s): ?>
    <div class="col-6 col-xl-3">
        <div class="card">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;border-radius:12px;background:<?= $s['color'] ?>20;
                    display:flex;align-items:center;justify-content:center;color:<?= $s['color'] ?>;font-size:18px;">
                    <i class="<?= $s['icon'] ?>"></i>
                </div>
                <div>
                    <div style="font-size:22px;font-weight:800;color:#1E293B;"><?= number_format($s['value']) ?></div>
                    <div style="font-size:11px;color:#64748B;"><?= $s['label'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Recent Articles + Quick Actions -->
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-clock me-2 text-primary"></i>บทความล่าสุด</span>
                <a href="/member/articles_list.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentArticles)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h5>ยังไม่มีบทความ</h5>
                    <p>เพิ่มเว็บไซต์และ keywords เพื่อเริ่มสร้างบทความ</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr>
                            <th>ชื่อบทความ</th><th>เว็บไซต์</th><th>สถานะ</th><th>วันที่</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($recentArticles as $art): ?>
                        <tr>
                            <td style="max-width:240px;">
                                <div class="text-truncate fw-semibold" style="color:#1E293B;"><?= sanitize($art['title']) ?></div>
                            </td>
                            <td><small class="text-muted"><?= sanitize($art['site_name'] ?? '-') ?></small></td>
                            <td>
                                <?php $statusMap = ['published'=>'success','generated'=>'info','draft'=>'warning','failed'=>'danger','scheduled'=>'secondary']; ?>
                                <span class="status-badge status-<?= $statusMap[$art['status']] ?? 'warning' ?>">
                                    <?= $art['status'] ?>
                                </span>
                            </td>
                            <td><small class="text-muted"><?= date('d/m/y', strtotime($art['created_at'])) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</div>
            <div class="card-body d-grid gap-2">
                <a href="/member/sites_list.php" class="btn btn-outline-primary text-start">
                    <i class="fas fa-plus me-2"></i>เพิ่มเว็บไซต์
                </a>
                <a href="/member/keywords_list.php" class="btn btn-outline-primary text-start">
                    <i class="fas fa-key me-2"></i>จัดการ Keywords
                </a>
                <a href="/member/articles_list.php" class="btn btn-outline-primary text-start">
                    <i class="fas fa-file-alt me-2"></i>ดูบทความทั้งหมด
                </a>
                <a href="/member/billing.php" class="btn btn-outline-warning text-start">
                    <i class="fas fa-credit-card me-2"></i>Plan & ชำระเงิน
                </a>
            </div>
        </div>

        <?php if ($hasPlan): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fas fa-box me-2 text-success"></i>Plan ของฉัน</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted" style="font-size:13px;">Plan</span>
                    <span class="fw-semibold"><?= sanitize($quota['plan_name']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted" style="font-size:13px;">หมดอายุ</span>
                    <span class="fw-semibold <?= $quota['days_left'] <= 7 ? 'text-danger' : '' ?>">
                        <?= date('d/m/Y', strtotime($quota['expires_at'])) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted" style="font-size:13px;">เหลืออีก</span>
                    <span class="fw-semibold <?= $quota['days_left'] <= 7 ? 'text-danger' : 'text-success' ?>">
                        <?= $quota['days_left'] ?> วัน
                    </span>
                </div>
                <?php if ($quota['days_left'] <= 7): ?>
                <a href="/member/billing.php" class="btn btn-warning btn-sm mt-3 w-100">
                    <i class="fas fa-exclamation-triangle me-1"></i>ต่ออายุ Plan
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
