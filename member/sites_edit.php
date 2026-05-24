<?php
$pageTitle = 'เพิ่ม / แก้ไขเว็บไซต์';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];
$siteId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$site     = null;
$isEdit   = false;
$errors   = [];

if ($siteId) {
    $site   = db()->fetchOne("SELECT * FROM sites WHERE id=? AND owner_type='member' AND owner_id=?", [$siteId, $memberId]);
    $isEdit = (bool)$site;
    if (!$site) {
        setFlash('error', 'ไม่พบเว็บไซต์นี้');
        redirect('/member/sites_list.php');
    }
}

// Quota check for new site
if (!$isEdit && $quota && !($quota['sites']['unlimited'] ?? false) && $quota['sites']['remaining'] <= 0) {
    setFlash('error', 'Quota sites เต็มแล้ว ไม่สามารถเพิ่มเว็บไซต์ได้');
    redirect('/member/sites_list.php');
}

$isTrial = planManager()->isTrial($memberId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $linkAction = $_POST['action'] ?? '';
    if (in_array($linkAction, ['add_link', 'delete_link', 'toggle_link'])) {
        $linkSiteId = (int)($_POST['site_id'] ?? $siteId);
        $owned = db()->fetchOne(
            "SELECT id FROM sites WHERE id=? AND owner_type='member' AND owner_id=?",
            [$linkSiteId, $memberId]
        );
        if (!$owned) {
            setFlash('error', 'ไม่มีสิทธิ์เข้าถึงเว็บไซต์นี้');
            redirect('/member/sites_edit.php?id=' . $siteId);
        }

        if ($linkAction === 'add_link') {
            $url        = trim($_POST['url'] ?? '');
            $anchorText = trim($_POST['anchor_text'] ?? '');
            $title      = trim($_POST['title'] ?? '');
            if (empty($url) || empty($anchorText)) {
                setFlash('error', 'กรุณากรอก URL และ Anchor Text');
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                setFlash('error', 'URL ไม่ถูกต้อง');
            } else {
                db()->insert('outbound_links', [
                    'site_id'     => $linkSiteId,
                    'url'         => $url,
                    'anchor_text' => $anchorText,
                    'title'       => $title ?: null,
                    'is_active'   => 1,
                ]);
                setFlash('success', 'เพิ่มลิงก์สำเร็จ');
            }
        }
        if ($linkAction === 'delete_link') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId) {
                db()->delete('outbound_links', 'id=? AND site_id=?', [$linkId, $linkSiteId]);
                setFlash('success', 'ลบลิงก์สำเร็จ');
            }
        }
        if ($linkAction === 'toggle_link') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId) {
                db()->query("UPDATE outbound_links SET is_active = NOT is_active WHERE id=? AND site_id=?", [$linkId, $linkSiteId]);
            }
        }
        redirect('/member/sites_edit.php?id=' . $linkSiteId . '#outbound-links');
    }

    $name         = trim($_POST['name'] ?? '');
    $baseUrl      = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $wpApiUrl     = rtrim(trim($_POST['wp_api_url'] ?? ''), '/');
    $wpUser       = trim($_POST['wp_user'] ?? '');
    $wpPass       = trim($_POST['wp_app_password'] ?? '');
    $topic        = $_POST['main_topic'] ?? 'general';
    $dailyLimit   = max(1, (int)($_POST['daily_article_limit'] ?? 1));
    $postStatus   = in_array($_POST['post_status'] ?? '', ['publish','draft','pending']) ? $_POST['post_status'] : 'publish';
    $postingEnabled     = ($isTrial) ? 0 : (isset($_POST['posting_enabled']) ? 1 : 0);
    $autoInternalLink   = isset($_POST['auto_internal_link']) ? 1 : 0;
    $featuredImageEnabled = isset($_POST['featured_image_enabled']) ? 1 : 0;
    $postingStartHour   = max(0, min(23, (int)($_POST['posting_start_hour'] ?? 6)));
    $postingEndHour     = max(0, min(23, (int)($_POST['posting_end_hour'] ?? 22)));
    $postingIntervalHours = max(1, min(12, (int)($_POST['posting_interval_hours'] ?? 2)));
    $postingDays        = !empty($_POST['posting_days']) ? implode(',', $_POST['posting_days']) : '1,2,3,4,5,6,7';
    $homepageKeyword    = trim($_POST['homepage_keyword'] ?? '') ?: null;
    $defaultAuthorId    = max(1, (int)($_POST['default_author_id'] ?? 1));
    $defaultCategoryId  = !empty($_POST['default_category_id']) ? (int)$_POST['default_category_id'] : null;
    $siteNiche          = trim($_POST['site_niche'] ?? '');
    $notes              = trim($_POST['notes'] ?? '');

    if (empty($name))    $errors[] = 'กรุณากรอกชื่อเว็บไซต์';
    if (empty($baseUrl)) $errors[] = 'กรุณากรอก URL เว็บไซต์';
    if (empty($wpUser))  $errors[] = 'กรุณากรอก WordPress Username';
    if (empty($wpPass) && !$isEdit) $errors[] = 'กรุณากรอก WordPress App Password';

    if (empty($errors)) {
        $topicRow = db()->fetchOne("SELECT name_th FROM topics WHERE slug=? LIMIT 1", [$topic]);
        $topicTh  = $topicRow['name_th'] ?? $topic;

        $data = [
            'name'                    => $name,
            'base_url'                => $baseUrl,
            'wp_api_url'              => $wpApiUrl ?: $baseUrl . '/wp-json',
            'wp_user'                 => $wpUser,
            'main_topic'              => $topic,
            'main_topic_th'           => $topicTh,
            'homepage_keyword'        => $homepageKeyword,
            'language_code'           => 'th',
            'country_code'            => 'TH',
            'daily_article_limit'     => $dailyLimit,
            'posting_days'            => $postingDays,
            'posting_enabled'         => $postingEnabled,
            'posting_start_hour'      => $postingStartHour,
            'posting_end_hour'        => $postingEndHour,
            'posting_interval_hours'  => $postingIntervalHours,
            'auto_internal_link'      => $autoInternalLink,
            'featured_image_enabled'  => $featuredImageEnabled,
            'post_status'             => $postStatus,
            'default_author_id'       => $defaultAuthorId,
            'default_category_id'     => $defaultCategoryId,
            'site_niche'              => $siteNiche ?: null,
            'notes'                   => $notes ?: null,
            'owner_type'              => 'member',
            'owner_id'                => $memberId,
        ];
        if (!empty($wpPass)) {
            $data['wp_app_password'] = encrypt($wpPass);
        }

        if ($isEdit) {
            db()->update('sites', $data, 'id = ?', [$siteId]);
            setFlash('success', 'อัพเดตเว็บไซต์เรียบร้อยแล้ว');
        } else {
            db()->insert('sites', $data);
            setFlash('success', 'เพิ่มเว็บไซต์เรียบร้อยแล้ว');
        }
        redirect('/member/sites_list.php');
    }
}

// Outbound links for this site
$outboundLinks = [];
if ($isEdit) {
    $outboundLinks = db()->fetchAll(
        "SELECT * FROM outbound_links WHERE site_id=? ORDER BY is_active DESC, use_count DESC, created_at DESC",
        [$siteId]
    );
}

// Topics: admin topics + member's own topics
$topics = db()->fetchAll(
    "SELECT slug, name_th FROM topics WHERE is_active=1
     AND (owner_type='admin' OR (owner_type='member' AND owner_id=?))
     ORDER BY owner_type DESC, sort_order ASC, name_th ASC",
    [$memberId]
);

$v = [
    'name'                    => $_POST['name'] ?? $site['name'] ?? '',
    'base_url'                => $_POST['base_url'] ?? $site['base_url'] ?? '',
    'wp_api_url'              => $_POST['wp_api_url'] ?? $site['wp_api_url'] ?? '',
    'wp_user'                 => $_POST['wp_user'] ?? $site['wp_user'] ?? '',
    'main_topic'              => $_POST['main_topic'] ?? $site['main_topic'] ?? 'general',
    'daily_article_limit'     => $_POST['daily_article_limit'] ?? $site['daily_article_limit'] ?? 1,
    'post_status'             => $_POST['post_status'] ?? $site['post_status'] ?? 'publish',
    'posting_enabled'         => $_POST['posting_enabled'] ?? $site['posting_enabled'] ?? 1,
    'auto_internal_link'      => $_POST['auto_internal_link'] ?? $site['auto_internal_link'] ?? 1,
    'featured_image_enabled'  => $_POST['featured_image_enabled'] ?? $site['featured_image_enabled'] ?? 1,
    'posting_start_hour'      => (int)(($_POST['posting_start_hour'] ?? $site['posting_start_hour'] ?? 6)),
    'posting_end_hour'        => (int)(($_POST['posting_end_hour'] ?? $site['posting_end_hour'] ?? 22)),
    'posting_interval_hours'  => (int)(($_POST['posting_interval_hours'] ?? $site['posting_interval_hours'] ?? 2)),
    'posting_days'            => $_POST['posting_days'] ?? explode(',', $site['posting_days'] ?? '1,2,3,4,5,6,7'),
    'homepage_keyword'        => $_POST['homepage_keyword'] ?? $site['homepage_keyword'] ?? '',
    'default_author_id'       => $_POST['default_author_id'] ?? $site['default_author_id'] ?? 1,
    'default_category_id'     => $_POST['default_category_id'] ?? $site['default_category_id'] ?? '',
    'site_niche'              => $_POST['site_niche'] ?? $site['site_niche'] ?? '',
    'notes'                   => $_POST['notes'] ?? $site['notes'] ?? '',
];
$currentDays = is_array($v['posting_days']) ? $v['posting_days'] : explode(',', $v['posting_days']);
?>

<div class="mb-4">
    <a href="/member/sites_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="row g-4">
        <!-- Left column -->
        <div class="col-lg-8">

            <!-- ข้อมูลเว็บไซต์ -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-globe me-2 text-primary"></i>ข้อมูลเว็บไซต์</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">ชื่อเว็บไซต์ <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= sanitize($v['name']) ?>" placeholder="My Blog" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL เว็บไซต์ <span class="text-danger">*</span></label>
                        <input type="url" name="base_url" class="form-control"
                               value="<?= sanitize($v['base_url']) ?>" placeholder="https://example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WordPress REST API URL</label>
                        <input type="url" name="wp_api_url" class="form-control"
                               value="<?= sanitize($v['wp_api_url']) ?>" placeholder="https://example.com/wp-json">
                        <small class="text-muted">ปล่อยว่างเพื่อใช้ค่า default (URL + /wp-json)</small>
                    </div>

                    <!-- Keyword หน้าแรก -->
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-link me-1"></i>Keyword หลักของหน้าแรก</label>
                        <textarea class="form-control" name="homepage_keyword" rows="3"
                                  placeholder="หน้าหลัก&#10;หน้าแรก&#10;กลับหน้าแรก"><?= sanitize($v['homepage_keyword']) ?></textarea>
                        <small class="text-muted">ใส่ได้หลายคำ บรรทัดละ 1 คำ — ระบบจะไล่ใช้ทีละคำเป็น Anchor Text ลิงก์กลับหน้าแรก (ช่วย SEO)</small>
                    </div>
                </div>
            </div>

            <?php if ($isEdit): ?>
            <!-- ลิงก์ขาออก -->
            <div class="card mb-4" id="outbound-links">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-external-link-alt me-2 text-primary"></i>ลิงก์ขาออก (<?= count($outboundLinks) ?>)</span>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addLinkModal">
                        <i class="fas fa-plus me-1"></i>เพิ่มลิงก์
                    </button>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($outboundLinks)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-link fa-2x mb-2 d-block"></i>ยังไม่มีลิงก์ขาออก
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Anchor Text</th><th>URL</th><th class="text-center" style="width:60px;">ใช้</th><th style="width:80px;"></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($outboundLinks as $link): ?>
                            <tr class="<?= !$link['is_active'] ? 'table-secondary' : '' ?>">
                                <td class="align-middle">
                                    <strong style="font-size:13px;"><?= sanitize($link['anchor_text']) ?></strong>
                                    <?php if ($link['title']): ?><br><small class="text-muted"><?= sanitize($link['title']) ?></small><?php endif; ?>
                                </td>
                                <td class="align-middle">
                                    <a href="<?= sanitize($link['url']) ?>" target="_blank" style="font-size:12px;">
                                        <?= mb_strimwidth(sanitize($link['url']), 0, 40, '...') ?>
                                        <i class="fas fa-external-link-alt fa-xs ms-1"></i>
                                    </a>
                                </td>
                                <td class="text-center align-middle"><span class="badge bg-info"><?= $link['use_count'] ?? 0 ?></span></td>
                                <td class="align-middle">
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            onclick="linkAction('toggle_link', <?= $link['id'] ?>)"
                                            title="เปิด/ปิด">
                                        <i class="fas fa-toggle-<?= $link['is_active'] ? 'on text-success' : 'off' ?>"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="linkAction('delete_link', <?= $link['id'] ?>, true)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-muted small">
                    <i class="fas fa-info-circle me-1"></i>AI สุ่มเลือก 1 ลิงก์/บทความ โดยเลือกลิงก์ที่ใช้น้อยที่สุดก่อน
                </div>
            </div>
            <?php endif; ?>

            <!-- WordPress Authentication -->
            <div class="card mb-4">
                <div class="card-header"><i class="fab fa-wordpress me-2"></i>การยืนยันตัวตน WordPress</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">WordPress Username <span class="text-danger">*</span></label>
                            <input type="text" name="wp_user" class="form-control"
                                   value="<?= sanitize($v['wp_user']) ?>" placeholder="admin" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">App Password <?= $isEdit ? '' : '<span class="text-danger">*</span>' ?></label>
                            <div class="input-group">
                                <input type="password" name="wp_app_password" id="wpAppPassword" class="form-control"
                                       placeholder="<?= $isEdit ? 'เว้นว่างถ้าไม่ต้องการเปลี่ยน' : 'xxxx xxxx xxxx xxxx xxxx xxxx' ?>"
                                       <?= $isEdit ? '' : 'required' ?>>
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted">สร้างได้จาก WP Admin → Users → Profile → Application Passwords</small>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-success btn-sm" id="testConnectionBtn">
                            <i class="fas fa-plug me-2"></i>ทดสอบการเชื่อมต่อ
                        </button>
                        <div id="connectionResult" class="mt-2"></div>
                    </div>
                </div>
            </div>

            <!-- ตั้งค่าการโพสต์ -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-cog me-2 text-warning"></i>ตั้งค่าการโพสต์</div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">หมวดหมู่หลัก</label>
                            <select name="main_topic" class="form-select">
                                <?php foreach ($topics as $t): ?>
                                <option value="<?= $t['slug'] ?>" <?= $v['main_topic'] === $t['slug'] ? 'selected' : '' ?>>
                                    <?= sanitize($t['name_th'] ?: $t['slug']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">บทความต่อวัน</label>
                            <input type="number" name="daily_article_limit" class="form-control"
                                   value="<?= (int)$v['daily_article_limit'] ?>" min="1" max="20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">สถานะโพสต์</label>
                            <select name="post_status" class="form-select">
                                <option value="publish" <?= $v['post_status'] === 'publish' ? 'selected' : '' ?>>เผยแพร่ทันที</option>
                                <option value="draft"   <?= $v['post_status'] === 'draft'   ? 'selected' : '' ?>>ฉบับร่าง</option>
                                <option value="pending" <?= $v['post_status'] === 'pending' ? 'selected' : '' ?>>รอตรวจสอบ</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Author ID</label>
                            <input type="number" name="default_author_id" class="form-control"
                                   value="<?= (int)$v['default_author_id'] ?>" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category ID (ถ้ามี)</label>
                            <input type="number" name="default_category_id" class="form-control"
                                   value="<?= $v['default_category_id'] ?>" placeholder="ปล่อยว่างถ้าไม่ระบุ">
                        </div>
                    </div>

                    <!-- ตารางเวลาโพสต์อัตโนมัติ -->
                    <div class="card bg-light border-0 mb-3" <?= $isTrial ? 'style="opacity:.5;pointer-events:none;"' : '' ?>>
                        <div class="card-body pb-2">
                            <h6 class="mb-3"><i class="fas fa-clock me-2 text-primary"></i>ตารางเวลาโพสต์อัตโนมัติ</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">เริ่มโพสต์ (ชั่วโมง)</label>
                                    <select class="form-select" name="posting_start_hour" id="startHour">
                                        <?php for ($h = 0; $h <= 23; $h++): ?>
                                        <option value="<?= $h ?>" <?= $v['posting_start_hour'] === $h ? 'selected' : '' ?>>
                                            <?= sprintf('%02d:00 น.', $h) ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">สิ้นสุดโพสต์ (ชั่วโมง)</label>
                                    <select class="form-select" name="posting_end_hour" id="endHour">
                                        <?php for ($h = 0; $h <= 23; $h++): ?>
                                        <option value="<?= $h ?>" <?= $v['posting_end_hour'] === $h ? 'selected' : '' ?>>
                                            <?= sprintf('%02d:00 น.', $h) ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">ความถี่</label>
                                    <select class="form-select" name="posting_interval_hours" id="intervalHours">
                                        <?php
                                        $intervals = [1=>'ทุก 1 ชม.',2=>'ทุก 2 ชม.',3=>'ทุก 3 ชม.',4=>'ทุก 4 ชม.',6=>'ทุก 6 ชม.',8=>'ทุก 8 ชม.',12=>'ทุก 12 ชม.'];
                                        foreach ($intervals as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $v['posting_interval_hours'] === $val ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="small text-muted mt-2" id="schedulePreview">
                                <?php
                                $sh = []; for ($h = $v['posting_start_hour']; $h <= $v['posting_end_hour']; $h += $v['posting_interval_hours']) $sh[] = sprintf('%02d:00', $h);
                                ?>
                                <i class="fas fa-info-circle me-1"></i>
                                จะโพสต์เวลา: <strong><?= implode(', ', $sh) ?></strong> น.
                                (<?= count($sh) ?> รอบ/วัน)
                            </div>
                        </div>
                    </div>

                    <!-- วันที่โพสต์ -->
                    <div class="mb-3" <?= $isTrial ? 'style="opacity:.5;pointer-events:none;"' : '' ?>>
                        <label class="form-label"><i class="fas fa-calendar-alt me-1"></i>วันที่โพสต์</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php
                            $dayNames = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];
                            foreach ($dayNames as $dayNum => $dayName): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="posting_days[]" value="<?= $dayNum ?>"
                                       id="day<?= $dayNum ?>"
                                       <?= in_array((string)$dayNum, $currentDays) ? 'checked' : '' ?>
                                       <?= $isTrial ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="day<?= $dayNum ?>"><?= $dayName ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">เลือกวันที่ต้องการให้ระบบโพสต์บทความอัตโนมัติ</small>
                    </div>
                </div>
            </div>

            <!-- Site Niche -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-bullseye me-2 text-primary"></i>คำอธิบาย Niche / กลุ่มเป้าหมาย
                    <span class="badge bg-primary ms-2 small">AI ใช้ข้อมูลนี้</span>
                </div>
                <div class="card-body">
                    <textarea name="site_niche" class="form-control" rows="4"
                              placeholder="อธิบายว่าเว็บนี้เน้นเนื้อหาอะไร กลุ่มเป้าหมายคือใคร โทนการเขียนแบบไหน&#10;&#10;ตัวอย่าง: เว็บรีวิวผลิตภัณฑ์ดูแลผิวสำหรับผู้หญิงไทย อายุ 25-40 ปี เน้นสินค้าคุ้มค่า&#10;ตัวอย่าง: บล็อกสอนทำอาหาร เขียนเป็นกันเอง เน้นสูตรง่ายๆ ทำได้ที่บ้าน"><?= sanitize($v['site_niche'] ?? '') ?></textarea>
                    <small class="text-muted mt-1 d-block">
                        <i class="fas fa-robot me-1"></i>AI จะใช้ข้อมูลนี้ในการสร้างบทความและ keywords ให้ตรงกับเนื้อหาเว็บของคุณ
                    </small>
                </div>
            </div>

            <!-- หมายเหตุ -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-sticky-note me-2"></i>หมายเหตุ</div>
                <div class="card-body">
                    <textarea name="notes" class="form-control" rows="3"
                              placeholder="บันทึกเพิ่มเติม..."><?= sanitize($v['notes']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-lg-4">
            <!-- สวิตช์ตั้งค่า -->
            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-sliders me-2 text-info"></i>การตั้งค่า</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="posting_enabled" id="postingEnabled"
                               <?= ($isTrial ? '' : ($v['posting_enabled'] ? 'checked' : '')) ?>
                               <?= $isTrial ? 'disabled' : '' ?>>
                        <label class="form-check-label fw-semibold" for="postingEnabled">
                            เปิดโพสต์อัตโนมัติ
                            <?php if ($isTrial): ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:10px;">
                                <i class="fas fa-lock me-1"></i>Trial
                            </span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="auto_internal_link" id="autoInternalLink"
                               <?= $v['auto_internal_link'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="autoInternalLink">สร้าง Internal Links อัตโนมัติ</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="featured_image_enabled" id="featuredImageEnabled"
                               <?= $v['featured_image_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="featuredImageEnabled">ตั้ง Featured Image อัตโนมัติ</label>
                    </div>
                </div>
            </div>

            <!-- ปุ่ม -->
            <div class="card">
                <div class="card-body d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i><?= $isEdit ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มเว็บไซต์' ?>
                    </button>
                    <a href="/member/sites_list.php" class="btn btn-outline-secondary">ยกเลิก</a>
                </div>
            </div>

            <?php if ($isEdit && !empty($site['base_url'])): ?>
            <div class="card mt-3">
                <div class="card-header"><i class="fas fa-info-circle me-2 text-muted"></i>ข้อมูลเว็บไซต์</div>
                <div class="card-body small">
                    <div class="mb-2">
                        <span class="text-muted">สร้างเมื่อ:</span><br>
                        <?= $site['created_at'] ? date('d/m/Y H:i', strtotime($site['created_at'])) : '-' ?>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted">อัพเดตล่าสุด:</span><br>
                        <?= $site['updated_at'] ? date('d/m/Y H:i', strtotime($site['updated_at'])) : '-' ?>
                    </div>
                    <a href="<?= sanitize($site['base_url']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm w-100 mt-1">
                        <i class="fas fa-external-link-alt me-1"></i>เปิดเว็บไซต์
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
// Schedule preview updater
function updateSchedulePreview() {
    const start = parseInt(document.getElementById('startHour').value);
    const end   = parseInt(document.getElementById('endHour').value);
    const interval = parseInt(document.getElementById('intervalHours').value);
    const times = [];
    for (let h = start; h <= end; h += interval) {
        times.push(String(h).padStart(2,'0') + ':00');
    }
    document.getElementById('schedulePreview').innerHTML =
        '<i class="fas fa-info-circle me-1"></i>จะโพสต์เวลา: <strong>' + times.join(', ') + '</strong> น. (' + times.length + ' รอบ/วัน)';
}
document.getElementById('startHour').addEventListener('change', updateSchedulePreview);
document.getElementById('endHour').addEventListener('change', updateSchedulePreview);
document.getElementById('intervalHours').addEventListener('change', updateSchedulePreview);

// Test WordPress connection
document.getElementById('testConnectionBtn').addEventListener('click', function() {
    const btn = this;
    const baseUrl = document.querySelector('[name="base_url"]').value;
    const wpUser  = document.querySelector('[name="wp_user"]').value;
    const wpPass  = document.querySelector('[name="wp_app_password"]').value;
    const result  = document.getElementById('connectionResult');

    if (!baseUrl || !wpUser) {
        result.innerHTML = '<div class="alert alert-warning py-2 mb-0">กรุณากรอก URL และ Username ก่อน</div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังทดสอบ...';
    result.innerHTML = '';

    const siteId = <?= $siteId ?: 0 ?>;
    const params = new URLSearchParams({ base_url: baseUrl, wp_user: wpUser, wp_pass: wpPass, site_id: siteId });

    fetch('/member/ajax/test_wp_connection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': '<?= $csrfToken ?>' },
        body: params
    })
    .then(r => r.json())
    .then(data => {
        result.innerHTML = data.success
            ? '<div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle me-1"></i>' + data.message + '</div>'
            : '<div class="alert alert-danger py-2 mb-0"><i class="fas fa-times-circle me-1"></i>' + data.message + '</div>';
    })
    .catch(() => {
        result.innerHTML = '<div class="alert alert-danger py-2 mb-0">เกิดข้อผิดพลาด กรุณาลองใหม่</div>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plug me-2"></i>ทดสอบการเชื่อมต่อ';
    });
});
</script>

<?php if ($isEdit): ?>
<!-- Add Link Modal (standalone form — outside main form) -->
<div class="modal fade" id="addLinkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="add_link">
                <input type="hidden" name="site_id" value="<?= $siteId ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>เพิ่มลิงก์ขาออก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">URL <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" name="url" required placeholder="https://example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Anchor Text <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="anchor_text" required placeholder="ข้อความแสดงลิงก์">
                        <small class="text-muted">คำที่ AI จะใช้เป็นข้อความลิงก์ในบทความ</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">คำอธิบาย <small class="text-muted">(ไม่บังคับ)</small></label>
                        <input type="text" class="form-control" name="title" placeholder="คำอธิบาย">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-plus me-2"></i>เพิ่มลิงก์</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function linkAction(action, linkId, confirmFirst) {
    if (confirmFirst && !confirm('ลบลิงก์นี้?')) return;
    const f = document.createElement('form');
    f.method = 'POST';
    [['csrf_token','<?= $csrfToken ?>'],['action',action],['site_id','<?= $siteId ?>'],['link_id',linkId]].forEach(([n,v])=>{
        const i = document.createElement('input');
        i.type='hidden'; i.name=n; i.value=v;
        f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
