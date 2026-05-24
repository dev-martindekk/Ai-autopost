<?php
$pageTitle = 'Queue Manager';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/queue_manager.php';

$memberId = (int)$currentMember['id'];

// Get member's site IDs
$memberSiteIds = db()->fetchAll(
    "SELECT id FROM sites WHERE owner_type='member' AND owner_id=?", [$memberId]
);
$siteIds = array_column($memberSiteIds, 'id');

if (empty($siteIds)) {
    echo '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>ยังไม่มีเว็บไซต์ <a href="/member/sites_edit.php">เพิ่มเว็บไซต์</a></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$placeholders = implode(',', array_fill(0, count($siteIds), '?'));

$flash = getFlash();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $jobId  = (int)($_POST['job_id'] ?? 0);

    // Verify job belongs to member's sites
    $jobCheck = $jobId ? db()->fetchOne(
        "SELECT id FROM job_queue WHERE id=? AND site_id IN ({$placeholders})", array_merge([$jobId], $siteIds)
    ) : null;

    if ($action === 'cancel_job' && $jobCheck) {
        queue()->cancel($jobId);
        setFlash('success', "ยกเลิก Job #{$jobId} แล้ว");
    } elseif ($action === 'retry_job' && $jobCheck) {
        queue()->retry($jobId);
        setFlash('success', "Retry Job #{$jobId} แล้ว");
    } elseif ($action === 'add_generate') {
        $addSiteId = (int)($_POST['site_id'] ?? 0);
        if (in_array($addSiteId, $siteIds)) {
            if (planManager()->isTrial($memberId)) {
                setFlash('error', 'แพ็คเกจ Trial ไม่รองรับ Auto-posting กรุณาอัพเกรด Plan');
            } elseif (planManager()->canUse($memberId, 'articles')) {
                queue()->push('generate_article', ['site_id' => $addSiteId], $addSiteId);
                setFlash('success', 'เพิ่ม Job สร้างบทความลง Queue แล้ว');
            } else {
                setFlash('error', 'Quota บทความเดือนนี้เต็มแล้ว');
            }
        }
    }
    redirect('/member/queue_manager.php');
}

// Get jobs for member's sites
$jobs = db()->fetchAll(
    "SELECT jq.*, s.name as site_name
     FROM job_queue jq
     LEFT JOIN sites s ON jq.site_id = s.id
     WHERE jq.site_id IN ({$placeholders})
     ORDER BY jq.created_at DESC
     LIMIT 100",
    $siteIds
);

// Stats
$jobStats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
foreach ($jobs as $j) {
    if (isset($jobStats[$j['status']])) $jobStats[$j['status']]++;
}

$sites = db()->fetchAll("SELECT id, name FROM sites WHERE owner_type='member' AND owner_id=? ORDER BY name", [$memberId]);
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Pending', $jobStats['pending'], 'bg-gradient-warning', 'fa-clock'],
        ['Processing', $jobStats['processing'], 'bg-gradient-info', 'fa-spinner'],
        ['Completed', $jobStats['completed'], 'bg-gradient-success', 'fa-check-circle'],
        ['Failed', $jobStats['failed'], 'bg-gradient-danger', 'fa-times-circle'],
    ] as [$label, $val, $bg, $icon]): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card <?= $bg ?>">
            <div class="stat-card-icon"><i class="fas <?= $icon ?>"></i></div>
            <div class="stat-card-value"><?= $val ?></div>
            <div class="stat-card-label"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <!-- Jobs Table -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2 text-primary"></i>Jobs ล่าสุด</span>
                <button onclick="location.reload()" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-sync me-1"></i>รีเฟรช
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($jobs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><h5>ยังไม่มี Jobs</h5></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr>
                            <th>#</th><th>Type</th><th>เว็บไซต์</th><th>สถานะ</th><th>สร้างเมื่อ</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $job):
                            $statusColors = ['pending'=>'warning','processing'=>'info','completed'=>'success','failed'=>'danger','locked'=>'secondary'];
                            $sc = $statusColors[$job['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td style="font-size:12px;">#<?= $job['id'] ?></td>
                            <td><code style="font-size:11px;"><?= sanitize($job['job_type']) ?></code></td>
                            <td style="font-size:12px;"><?= sanitize($job['site_name'] ?? '-') ?></td>
                            <td><span class="badge bg-<?= $sc ?>"><?= $job['status'] ?></span></td>
                            <td style="font-size:11px;"><?= timeAgo($job['created_at']) ?></td>
                            <td>
                                <?php if ($job['status'] === 'failed'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="retry_job">
                                    <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                    <button class="btn btn-warning btn-sm" title="Retry">
                                        <i class="fas fa-redo"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array($job['status'], ['pending','failed'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="cancel_job">
                                    <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" title="Cancel" onclick="return confirm('ยกเลิก Job นี้?')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($job['status'] === 'failed' && $job['error_message']): ?>
                                <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" title="<?= sanitize($job['error_message']) ?>">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                <?php endif; ?>
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

    <!-- Add Job -->
    <div class="col-lg-3">
        <div class="card">
            <div class="card-header"><i class="fas fa-plus me-2 text-success"></i>เพิ่ม Job</div>
            <div class="card-body">
                <?php
                ?>
                <?php if (planManager()->isTrial($memberId)): ?>
                <div class="alert alert-warning" style="font-size:12px;">
                    <i class="fas fa-flask me-1"></i>
                    <strong>Trial Plan</strong><br>
                    Auto-posting ไม่พร้อมใช้งาน<br>
                    <a href="/member/billing.php#upgradeSection" class="alert-link">อัพเกรด Plan &rarr;</a>
                </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="add_generate">
                    <div class="mb-3">
                        <label class="form-label">เว็บไซต์</label>
                        <select name="site_id" class="form-select" required>
                            <option value="">เลือกเว็บไซต์</option>
                            <?php foreach ($sites as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ประเภท</label>
                        <input type="text" class="form-control" value="Generate Article" readonly style="background:#f8f9fa;">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-plus me-2"></i>เพิ่ม Job
                    </button>
                </form>
                <?php
                try { $quota = $quota ?? planManager()->getQuota($memberId); } catch (Exception $e) { $quota = null; }
                $artQ = $quota['articles'] ?? null;
                ?>
                <hr>
                <div class="text-center" style="font-size:12px;color:#64748B;">
                    <i class="fas fa-newspaper me-1"></i>
                    Quota: <?= $artQ ? ($artQ['unlimited'] ? '∞' : "{$artQ['remaining']} เหลือ / {$artQ['limit']}") : '-' ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Refresh every 30 seconds if there are pending/processing jobs
<?php if ($jobStats['pending'] > 0 || $jobStats['processing'] > 0): ?>
setTimeout(() => location.reload(), 30000);
<?php endif; ?>
var tooltipElems = document.querySelectorAll('[data-bs-toggle="tooltip"]');
tooltipElems.forEach(el => new bootstrap.Tooltip(el));
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
