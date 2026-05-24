<?php
require_once __DIR__ . '/../includes/content_planner.php';

$pageTitle = 'Content Calendar';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

if (planManager()->isTrial($memberId)) {
    echo '<div class="card border-warning"><div class="card-body text-center py-5">
        <i class="fas fa-lock fa-3x text-warning mb-3 d-block"></i>
        <h5 class="fw-bold">ฟีเจอร์นี้ไม่พร้อมใช้งานใน Trial Plan</h5>
        <p class="text-muted">Content Calendar ใช้ได้เมื่ออัพเกรดเป็น Plan แบบชำระเงิน</p>
        <a href="/member/billing.php" class="btn btn-warning">
            <i class="fas fa-arrow-up me-2"></i>อัพเกรด Plan
        </a>
    </div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// Parameters
$selectedMonth = $_GET['month'] ?? date('Y-m');
$selectedSite  = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$monthStart    = $selectedMonth . '-01';
$monthEnd      = date('Y-m-t', strtotime($monthStart));

function getThaiMonthCal($m) {
    return ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
            'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'][$m];
}
$thaiMonthName = getThaiMonthCal((int)date('n', strtotime($monthStart))) . ' ' . (date('Y', strtotime($monthStart)) + 543);

// Sites for this member
$sites = db()->fetchAll(
    "SELECT id, name, main_topic, posting_days, daily_article_limit FROM sites
     WHERE owner_type='member' AND owner_id=? ORDER BY name",
    [$memberId]
);

// Replace used keywords
$planner = contentPlanner();
if ($selectedSite > 0) {
    $planner->replaceUsedKeywords($selectedSite);
} else {
    foreach ($sites as $s) { $planner->replaceUsedKeywords($s['id']); }
}

// Build plans query
$params = [$memberId, $monthStart, $monthEnd];
$siteFilter = '';
if ($selectedSite > 0) { $siteFilter = "AND cp.site_id = ?"; $params[] = $selectedSite; }

$plans = db()->fetchAll("
    SELECT cp.*, cp.is_manual, s.name as site_name, s.main_topic,
           a.title as article_title, a.post_url, a.status as article_status,
           k.search_volume, k.difficulty, k.traffic_potential, k.cpc, k.data_source
    FROM content_plan cp
    JOIN sites s ON cp.site_id = s.id AND s.owner_type='member' AND s.owner_id=?
    LEFT JOIN articles a ON cp.article_id = a.id
    LEFT JOIN keywords k ON cp.primary_keyword_id = k.id
    WHERE cp.planned_date BETWEEN ? AND ? {$siteFilter}
    ORDER BY cp.planned_date ASC, s.name ASC, cp.priority DESC
", $params);

// Deduplicate: one per site per day
$plansByDate = [];
$uniquePlans = [];
$seenSiteDate = [];
foreach ($plans as $plan) {
    $key = $plan['planned_date'] . '-' . $plan['site_id'];
    if (isset($seenSiteDate[$key])) continue;
    $seenSiteDate[$key] = true;
    $uniquePlans[] = $plan;
    $plansByDate[$plan['planned_date']][] = $plan;
}

// Stats
$statsParams = [$memberId, $monthStart, $monthEnd];
if ($selectedSite > 0) $statsParams[] = $selectedSite;
$stats = db()->fetchOne("
    SELECT COUNT(*) as total_planned,
           SUM(CASE WHEN cp.status='planned' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN cp.status='generated' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN cp.status='skipped' THEN 1 ELSE 0 END) as skipped
    FROM content_plan cp
    JOIN sites s ON cp.site_id=s.id AND s.owner_type='member' AND s.owner_id=?
    WHERE cp.planned_date BETWEEN ? AND ? " . ($selectedSite > 0 ? "AND cp.site_id=?" : "")
, $statsParams);

// Articles this month
$artParams = [$memberId, $monthStart, $monthEnd];
if ($selectedSite > 0) $artParams[] = $selectedSite;
$articlesThisMonth = db()->fetchAll("
    SELECT a.id, a.title, a.primary_keyword, a.post_url, a.created_at, a.word_count, a.status, s.name as site_name
    FROM articles a
    JOIN sites s ON a.site_id=s.id AND s.owner_type='member' AND s.owner_id=?
    WHERE DATE(a.created_at) BETWEEN ? AND ? " . ($selectedSite > 0 ? "AND a.site_id=?" : "") . "
    ORDER BY a.created_at DESC
", $artParams);

$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));
?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="fas fa-calendar-alt text-primary me-2"></i>Content Calendar</h4>
        <p class="text-muted mb-0">แผนเนื้อหาและ Keywords รายเดือน — <?= $thaiMonthName ?></p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#manualPlanModal">
            <i class="fas fa-pen me-1"></i>เพิ่มแผนเอง
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal">
            <i class="fas fa-magic me-1"></i>สร้างแผนอัตโนมัติ
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-3">
                <select class="form-select" onchange="location.href='?month=<?= $selectedMonth ?>&site_id='+this.value">
                    <option value="0">— ทุกเว็บไซต์ —</option>
                    <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $selectedSite == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-9">
                <div class="d-flex gap-2">
                    <a href="?month=<?= $prevMonth ?>&site_id=<?= $selectedSite ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-chevron-left"></i> เดือนก่อน
                    </a>
                    <a href="?month=<?= date('Y-m') ?>&site_id=<?= $selectedSite ?>" class="btn btn-outline-secondary btn-sm">เดือนนี้</a>
                    <a href="?month=<?= $nextMonth ?>&site_id=<?= $selectedSite ?>" class="btn btn-outline-secondary btn-sm">
                        เดือนหน้า <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-card-value"><?= $stats['total_planned'] ?? 0 ?></div>
            <div class="stat-card-label">แผนทั้งหมด</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-card-value"><?= $stats['pending'] ?? 0 ?></div>
            <div class="stat-card-label">รอดำเนินการ</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-success">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-value"><?= $stats['completed'] ?? 0 ?></div>
            <div class="stat-card-label">เสร็จแล้ว</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-gradient-info">
            <div class="stat-card-icon"><i class="fas fa-newspaper"></i></div>
            <div class="stat-card-value"><?= count($articlesThisMonth) ?></div>
            <div class="stat-card-label">บทความเดือนนี้</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-calendar">
            <i class="fas fa-calendar me-1"></i>ปฏิทิน
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-list">
            <i class="fas fa-list me-1"></i>รายการแผน
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-articles">
            <i class="fas fa-newspaper me-1"></i>บทความที่สร้าง (<?= count($articlesThisMonth) ?>)
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- Calendar View -->
    <div class="tab-pane fade show active" id="tab-calendar">
        <div class="card">
            <div class="card-body p-0">
                <!-- Day headers -->
                <div class="row g-0 bg-light border-bottom">
                    <?php foreach (['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัส','ศุกร์','เสาร์'] as $d): ?>
                    <div class="col text-center py-2 fw-bold border-end" style="font-size:12px;"><?= $d ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar Grid -->
                <div class="row g-0">
                <?php
                $firstDay    = (int)date('w', strtotime($monthStart)); // 0=Sun
                $daysInMonth = (int)date('t', strtotime($monthStart));
                $today       = date('Y-m-d');

                for ($i = 0; $i < $firstDay; $i++):
                ?>
                <div class="col border-end border-bottom p-2" style="min-height:110px;background:#f8f9fa;"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $date     = $selectedMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                    $isToday  = $date === $today;
                    $dayPlans = $plansByDate[$date] ?? [];
                ?>
                <div class="col border-end border-bottom p-2 <?= $isToday ? 'bg-primary bg-opacity-10' : '' ?>"
                     style="min-height:110px;overflow-y:auto;cursor:pointer;"
                     onclick="openManualPlan('<?= $date ?>')">
                    <div class="fw-bold mb-1 <?= $isToday ? 'text-primary' : '' ?>" style="font-size:13px;">
                        <?= $day ?>
                        <?php if ($isToday): ?><span class="badge bg-primary ms-1" style="font-size:9px;">วันนี้</span><?php endif; ?>
                    </div>
                    <?php foreach ($dayPlans as $plan):
                        $bg = 'bg-secondary bg-opacity-10';
                        if ($plan['status'] === 'generated') $bg = 'bg-success bg-opacity-25';
                        elseif (!empty($plan['is_manual'])) $bg = 'bg-warning bg-opacity-25 border border-warning';
                    ?>
                    <div class="small p-1 mb-1 rounded position-relative <?= $bg ?>"
                         title="<?= sanitize($plan['primary_keyword']) ?>"
                         onclick="event.stopPropagation();">
                        <div class="fw-bold text-truncate" style="font-size:10px;">
                            <?= sanitize($plan['site_name']) ?>
                            <?php if (!empty($plan['is_manual'])): ?>
                            <span class="badge bg-warning text-dark" style="font-size:8px;">Manual</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-truncate" style="font-size:10px;"><?= sanitize($plan['primary_keyword']) ?></div>
                        <?php if ($plan['status'] === 'planned'): ?>
                        <button class="btn btn-link btn-sm text-danger p-0 position-absolute"
                                style="top:2px;right:4px;font-size:10px;line-height:1;"
                                onclick="deletePlan(<?= $plan['id'] ?>)" title="ลบ">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                    if (($firstDay + $day) % 7 == 0 && $day < $daysInMonth):
                ?>
                </div><div class="row g-0">
                <?php
                    endif;
                endfor;

                $lastDay = (int)date('w', strtotime($monthEnd));
                for ($i = $lastDay + 1; $i < 7; $i++):
                ?>
                <div class="col border-end border-bottom p-2" style="min-height:110px;background:#f8f9fa;"></div>
                <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- List View -->
    <div class="tab-pane fade" id="tab-list">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>วันที่</th><th>เว็บไซต์</th><th>Keyword</th><th>Volume</th><th>Diff</th><th>Traffic</th><th>Intent</th><th>สถานะ</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($uniquePlans)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">
                            <i class="fas fa-calendar-times fa-3x mb-3 d-block"></i>
                            ยังไม่มีแผนเนื้อหาสำหรับเดือนนี้
                            <br><small>คลิก "สร้างแผนอัตโนมัติ" เพื่อให้ AI วางแผนเนื้อหา</small>
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($uniquePlans as $plan): ?>
                        <tr>
                            <td>
                                <strong><?= date('d', strtotime($plan['planned_date'])) ?></strong>
                                <small class="text-muted">/<?= date('m', strtotime($plan['planned_date'])) ?></small>
                            </td>
                            <td><span class="badge bg-secondary"><?= sanitize($plan['site_name']) ?></span></td>
                            <td>
                                <strong><?= sanitize($plan['primary_keyword']) ?></strong>
                                <?php if ($plan['data_source'] === 'ahrefs'): ?>
                                <span class="badge bg-info ms-1" style="font-size:9px;">Ahrefs</span>
                                <?php endif; ?>
                                <?php if ($plan['article_id']): ?>
                                <br><a href="<?= sanitize($plan['post_url'] ?? '#') ?>" target="_blank" class="small text-success">
                                    <i class="fas fa-external-link-alt"></i> ดูบทความ
                                </a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?= $plan['search_volume'] ? '<span class="fw-bold">'.number_format($plan['search_volume']).'</span>' : '<span class="text-muted">-</span>' ?>
                            </td>
                            <td>
                                <?php if ($plan['difficulty'] !== null):
                                    $dc = $plan['difficulty'] <= 30 ? 'success' : ($plan['difficulty'] <= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="badge bg-<?= $dc ?>"><?= $plan['difficulty'] ?></span>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?= $plan['traffic_potential'] ? '<span class="text-primary">'.number_format($plan['traffic_potential']).'</span>' : '<span class="text-muted">-</span>' ?>
                            </td>
                            <td>
                                <?php $ic = ['transactional'=>'success','commercial'=>'primary','informational'=>'secondary','navigational'=>'warning']; ?>
                                <span class="badge bg-<?= $ic[$plan['search_intent'] ?? ''] ?? 'secondary' ?>">
                                    <?= sanitize(substr($plan['search_intent'] ?? '-', 0, 3)) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($plan['status'] === 'generated'): ?>
                                <span class="badge bg-success">เสร็จ</span>
                                <?php elseif ($plan['status'] === 'skipped'): ?>
                                <span class="badge bg-danger">ข้าม</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">รอ</span>
                                <?php endif; ?>
                                <?php if (!empty($plan['is_manual'])): ?>
                                <span class="badge bg-warning text-dark" style="font-size:9px;">Manual</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($plan['status'] === 'planned'): ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="deletePlan(<?= $plan['id'] ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Articles View -->
    <div class="tab-pane fade" id="tab-articles">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>วันที่สร้าง</th><th>เว็บไซต์</th><th>Keyword</th><th>หัวข้อบทความ</th><th>คำ</th><th>สถานะ</th><th>ลิงก์</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($articlesThisMonth)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-newspaper fa-3x mb-3 d-block"></i>ยังไม่มีบทความที่สร้างในเดือนนี้
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($articlesThisMonth as $art): ?>
                        <tr>
                            <td style="font-size:12px;"><?= date('d/m H:i', strtotime($art['created_at'])) ?></td>
                            <td><span class="badge bg-secondary"><?= sanitize($art['site_name']) ?></span></td>
                            <td class="text-primary fw-bold" style="font-size:13px;"><?= sanitize($art['primary_keyword']) ?></td>
                            <td style="max-width:280px;font-size:13px;">
                                <a href="/member/article_view.php?id=<?= $art['id'] ?>" class="text-decoration-none">
                                    <?= sanitize(mb_substr($art['title'] ?? '', 0, 50)) ?>...
                                </a>
                            </td>
                            <td style="font-size:12px;"><?= number_format($art['word_count'] ?? 0) ?></td>
                            <td>
                                <?php $sc = ['published'=>'status-success','draft'=>'status-info','failed'=>'status-danger'][$art['status']] ?? 'status-warning'; ?>
                                <span class="status-badge <?= $sc ?>"><?= $art['status'] ?></span>
                            </td>
                            <td>
                                <?php if ($art['post_url']): ?>
                                <a href="<?= sanitize($art['post_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Legend -->
<div class="card mt-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-3" style="font-size:13px;">
            <span><span class="badge bg-warning text-dark">รอ</span> รอดำเนินการ</span>
            <span><span class="badge bg-success">เสร็จ</span> สร้างบทความแล้ว</span>
            <span><span class="badge bg-warning text-dark">Manual</span> เพิ่มเอง (ข้ามข้อจำกัดวันโพสต์)</span>
        </div>
    </div>
</div>

<!-- Manual Plan Modal -->
<div class="modal fade" id="manualPlanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen text-success me-2"></i>เพิ่มแผนเนื้อหาด้วยตนเอง</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="manualPlanForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เว็บไซต์ <span class="text-danger">*</span></label>
                        <select name="site_id" required class="form-select">
                            <option value="">เลือกเว็บไซต์</option>
                            <?php foreach ($sites as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วันที่ <span class="text-danger">*</span></label>
                        <input type="date" name="planned_date" required class="form-control"
                               value="<?= date('Y-m-d') ?>" id="manualPlanDate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Keyword <span class="text-danger">*</span></label>
                        <input type="text" name="keyword" required class="form-control" placeholder="เช่น วิธีทำ SEO, digital marketing">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภทเนื้อหา</label>
                            <select name="content_type" class="form-select">
                                <option value="article">บทความ</option>
                                <option value="review">รีวิว</option>
                                <option value="guide">คู่มือ</option>
                                <option value="tips">เคล็ดลับ</option>
                                <option value="news">ข่าว</option>
                                <option value="comparison">เปรียบเทียบ</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">เจตนาการค้นหา</label>
                            <select name="search_intent" class="form-select">
                                <option value="informational">ให้ข้อมูล</option>
                                <option value="transactional">ซื้อ/สมัคร</option>
                                <option value="commercial">เปรียบเทียบ/ตัดสินใจ</option>
                                <option value="navigational">หาเว็บเฉพาะ</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="รายละเอียดเพิ่มเติม (ไม่บังคับ)"></textarea>
                    </div>
                    <div id="manualPlanResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success" id="manualPlanBtn">
                        <i class="fas fa-plus me-1"></i>เพิ่มแผน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Generate Plan Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-magic text-primary me-2"></i>สร้างแผนเนื้อหาอัตโนมัติ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="generateForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เว็บไซต์ <span class="text-danger">*</span></label>
                        <select name="site_id" required class="form-select" id="generateSiteSelect">
                            <option value="">เลือกเว็บไซต์</option>
                            <?php foreach ($sites as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">จำนวนวัน</label>
                        <select name="days" class="form-select">
                            <option value="7">7 วัน (1 สัปดาห์)</option>
                            <option value="14">14 วัน (2 สัปดาห์)</option>
                            <option value="30" selected>30 วัน (1 เดือน)</option>
                        </select>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        AI จะวิเคราะห์ Keywords ที่มีพร้อมข้อมูล (Volume, Difficulty, Search Intent)
                        และวางแผนเนื้อหาให้อัตโนมัติ <strong>เฉพาะวันที่ตั้งค่าไว้ใน posting_days</strong> เท่านั้น
                    </div>
                    <div class="alert alert-secondary small d-none" id="postingDaysInfo">
                        <i class="fas fa-calendar-day me-1"></i>
                        <span id="postingDaysText"></span>
                    </div>
                    <div id="generateResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" id="generateBtn">
                        <i class="fas fa-magic me-1"></i>สร้างแผน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= $csrfToken ?>';
const dayNamesTh = {1:'จันทร์',2:'อังคาร',3:'พุธ',4:'พฤหัสบดี',5:'ศุกร์',6:'เสาร์',7:'อาทิตย์'};
const sitePostingData = {
    <?php foreach ($sites as $s): ?>
    <?= $s['id'] ?>: { posting_days: '<?= $s['posting_days'] ?? '1,2,3,4,5,6,7' ?>', daily_limit: <?= (int)($s['daily_article_limit'] ?? 1) ?> },
    <?php endforeach; ?>
};

function openManualPlan(date) {
    document.getElementById('manualPlanDate').value = date;
    new bootstrap.Modal(document.getElementById('manualPlanModal')).show();
}

async function deletePlan(planId) {
    if (!confirm('ต้องการลบรายการนี้?')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('plan_id', planId);
    formData.append('csrf_token', csrfToken);
    const res = await fetch('ajax/manual_plan.php', { method: 'POST', body: formData });
    const data = await res.json();
    data.success ? location.reload() : alert(data.message);
}

// Show posting days info
document.getElementById('generateSiteSelect').addEventListener('change', function() {
    const info = document.getElementById('postingDaysInfo');
    const text = document.getElementById('postingDaysText');
    if (this.value && sitePostingData[this.value]) {
        const d = sitePostingData[this.value];
        const days = d.posting_days.split(',').map(Number);
        text.textContent = days.length === 7
            ? 'วันโพสต์: ทุกวัน (' + d.daily_limit + ' บทความ/วัน)'
            : 'วันโพสต์: ' + days.map(x => dayNamesTh[x] || x).join(', ') + ' (' + d.daily_limit + ' บทความ/วัน)';
        info.classList.remove('d-none');
    } else {
        info.classList.add('d-none');
    }
});

// Manual plan submit
document.getElementById('manualPlanForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('manualPlanBtn');
    const result = document.getElementById('manualPlanResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
    result.innerHTML = '';
    try {
        const res  = await fetch('ajax/manual_plan.php', { method: 'POST', body: new FormData(this) });
        const data = await res.json();
        if (data.success) {
            result.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i>' + data.message + '</div>';
            setTimeout(() => location.reload(), 1500);
        } else {
            result.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>' + data.message + '</div>';
        }
    } catch (err) {
        result.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด: ' + err.message + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>เพิ่มแผน';
    }
});

// Generate plan submit
document.getElementById('generateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('generateBtn');
    const result = document.getElementById('generateResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังสร้างแผน...';
    result.innerHTML = '';
    try {
        const res  = await fetch('ajax/generate_plan.php', { method: 'POST', body: new FormData(this) });
        const data = await res.json();
        if (data.success) {
            result.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i>' + data.message + '</div>';
            setTimeout(() => location.reload(), 2000);
        } else {
            result.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>' + data.message + '</div>';
        }
    } catch (err) {
        result.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด: ' + err.message + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic me-1"></i>สร้างแผน';
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
