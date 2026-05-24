<?php
$pageTitle = 'Keywords';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

// Load topics (admin + member's own)
$topics = db()->fetchAll(
    "SELECT slug, name_th FROM topics WHERE is_active=1
     AND (owner_type='admin' OR (owner_type='member' AND owner_id=?))
     ORDER BY sort_order ASC, name_th ASC",
    [$memberId]
);
$topicMap = array_column($topics, 'name_th', 'slug');

// Quota
$currentCount = (int)db()->fetchColumn(
    "SELECT COUNT(*) FROM keywords WHERE owner_type='member' AND owner_id=? AND is_active=1",
    [$memberId]
);
$kwLimit    = $quota['keywords']['limit'] ?? 0;
$unlimited  = $quota['keywords']['unlimited'] ?? false;
$remaining  = $unlimited ? PHP_INT_MAX : max(0, $kwLimit - $currentCount);

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // Delete single
    if ($action === 'delete') {
        $id = (int)($_POST['delete_id'] ?? 0);
        $kw = db()->fetchOne("SELECT id FROM keywords WHERE id=? AND owner_type='member' AND owner_id=?", [$id, $memberId]);
        if ($kw) db()->update('keywords', ['is_active' => 0], 'id = ?', [$id]);
        redirect('/member/keywords_list.php');
    }

    // Bulk delete
    if ($action === 'bulk_delete' && !empty($_POST['selected'])) {
        $ids = array_map('intval', (array)$_POST['selected']);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->query(
                "UPDATE keywords SET is_active=0 WHERE id IN ({$ph}) AND owner_type='member' AND owner_id=?",
                array_merge($ids, [$memberId])
            );
        }
        redirect('/member/keywords_list.php');
    }

    // Add manually
    if ($action === 'add_manual') {
        $raw   = trim($_POST['keywords_text'] ?? '');
        $topic = trim($_POST['topic'] ?? 'general');
        $kwType = in_array($_POST['keyword_type'] ?? '', ['primary','secondary','long_tail','brand'])
                  ? $_POST['keyword_type'] : 'primary';
        $priority = max(1, min(100, (int)($_POST['priority'] ?? 10)));

        $lines  = array_filter(array_map('trim', explode("\n", $raw)));
        $added  = 0;
        $dupes  = 0;

        foreach ($lines as $line) {
            if (empty($line) || mb_strlen($line) > 255) continue;
            if ($added >= $remaining) break;

            $exists = db()->fetchColumn(
                "SELECT COUNT(*) FROM keywords WHERE keyword=? AND owner_type='member' AND owner_id=?",
                [$line, $memberId]
            );
            if ($exists) { $dupes++; continue; }

            db()->insert('keywords', [
                'owner_type'    => 'member',
                'owner_id'      => $memberId,
                'keyword'       => $line,
                'keyword_type'  => $kwType,
                'topic'         => $topic,
                'language_code' => 'th',
                'priority'      => $priority,
                'is_active'     => 1,
            ]);
            $added++;
        }

        $msg = "เพิ่มสำเร็จ {$added} keywords";
        if ($dupes) $msg .= " (ข้าม {$dupes} ซ้ำ)";
        setFlash('success', $msg);
        redirect('/member/keywords_list.php');
    }

    // Import CSV (Ahrefs)
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'กรุณาเลือกไฟล์ CSV');
            redirect('/member/keywords_list.php');
        }
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            setFlash('error', 'รองรับเฉพาะไฟล์ .csv');
            redirect('/member/keywords_list.php');
        }
        if ($remaining <= 0) {
            setFlash('error', 'Keyword quota เต็มแล้ว');
            redirect('/member/keywords_list.php');
        }

        $topic  = trim($_POST['csv_topic'] ?? 'general');
        $kwType = in_array($_POST['csv_keyword_type'] ?? '', ['primary','secondary','long_tail','brand'])
                  ? $_POST['csv_keyword_type'] : 'primary';

        $handle  = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($handle);
        $headers = array_map('strtolower', array_map('trim', $headers ?? []));

        $kwCol  = array_search('keyword', $headers);
        $volCol = false;
        foreach (['volume','search volume','search_volume'] as $v) {
            $pos = array_search($v, $headers);
            if ($pos !== false) { $volCol = $pos; break; }
        }
        $diffCol = false;
        foreach (['difficulty','kd','keyword difficulty'] as $v) {
            $pos = array_search($v, $headers);
            if ($pos !== false) { $diffCol = $pos; break; }
        }

        if ($kwCol === false) {
            fclose($handle);
            setFlash('error', 'ไม่พบคอลัมน์ "keyword" ในไฟล์');
            redirect('/member/keywords_list.php');
        }

        $imported = 0; $skipped = 0;
        while (($row = fgetcsv($handle)) !== false && $imported < $remaining) {
            $kw = trim($row[$kwCol] ?? '');
            if (empty($kw) || mb_strlen($kw) > 255) { $skipped++; continue; }

            $exists = db()->fetchColumn(
                "SELECT COUNT(*) FROM keywords WHERE keyword=? AND owner_type='member' AND owner_id=?",
                [$kw, $memberId]
            );
            if ($exists) { $skipped++; continue; }

            $vol  = ($volCol !== false && isset($row[$volCol])) ? (int)str_replace([',','.',' '], '', $row[$volCol]) : null;
            $diff = ($diffCol !== false && isset($row[$diffCol])) ? (int)$row[$diffCol] : null;

            db()->insert('keywords', [
                'owner_type'    => 'member',
                'owner_id'      => $memberId,
                'keyword'       => $kw,
                'keyword_type'  => $kwType,
                'topic'         => $topic,
                'language_code' => 'th',
                'search_volume' => $vol,
                'difficulty'    => $diff,
                'data_source'   => 'ahrefs',
                'priority'      => 10,
                'is_active'     => 1,
            ]);
            $imported++;
        }
        fclose($handle);

        $msg = "Import สำเร็จ {$imported} keywords";
        if ($skipped) $msg .= " (ข้าม {$skipped} ซ้ำ/ไม่ถูกต้อง)";
        setFlash('success', $msg);
        redirect('/member/keywords_list.php');
    }
}

// ─── Load keywords ─────────────────────────────────────────────────────────────
$filterTopic = $_GET['topic'] ?? '';
$params = [$memberId];
$topicWhere = '';
if ($filterTopic) {
    $topicWhere = "AND topic = ?";
    $params[] = $filterTopic;
}

$keywords = db()->fetchAll(
    "SELECT * FROM keywords WHERE owner_type='member' AND owner_id=? AND is_active=1 {$topicWhere}
     ORDER BY priority DESC, created_at DESC",
    $params
);

$flash = getFlash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>">
    <i class="fas fa-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?> me-2"></i>
    <?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">Keywords</h4>
        <p class="text-muted mb-0">
            <?= $currentCount ?> keywords
            <?php if (!$unlimited && $kwLimit > 0): ?>
            / <?= $kwLimit ?> (เหลือ <?= $quota['keywords']['remaining'] ?>)
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addManualModal">
            <i class="fas fa-plus me-2"></i>เพิ่ม Keyword
        </button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importCsvModal">
            <i class="fas fa-file-import me-2"></i>Import Ahrefs CSV
        </button>
    </div>
</div>

<!-- Topic filter tabs -->
<?php if (count($topics) > 1): ?>
<div class="mb-3">
    <div class="d-flex gap-2 flex-wrap">
        <a href="/member/keywords_list.php" class="btn btn-sm <?= !$filterTopic ? 'btn-primary' : 'btn-outline-secondary' ?>">
            ทั้งหมด
        </a>
        <?php foreach ($topics as $t): ?>
        <?php $cnt = (int)db()->fetchColumn("SELECT COUNT(*) FROM keywords WHERE owner_type='member' AND owner_id=? AND is_active=1 AND topic=?", [$memberId, $t['slug']]); ?>
        <a href="/member/keywords_list.php?topic=<?= urlencode($t['slug']) ?>"
           class="btn btn-sm <?= $filterTopic === $t['slug'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= sanitize($t['name_th'] ?: $t['slug']) ?>
            <span class="badge bg-white text-dark ms-1"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Keywords table -->
<?php if (empty($keywords)): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="fas fa-key"></i>
            <h5>ยังไม่มี Keywords<?= $filterTopic ? " ในหมวด \"$filterTopic\"" : '' ?></h5>
            <p>เพิ่ม keyword ด้วยมือ หรือ import จากไฟล์ Ahrefs CSV</p>
            <button class="btn btn-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addManualModal">
                <i class="fas fa-plus me-1"></i>เพิ่ม Keyword
            </button>
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importCsvModal">
                <i class="fas fa-file-import me-1"></i>Import CSV
            </button>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-key me-2 text-primary"></i>Keywords (<?= count($keywords) ?>)</span>
        <form method="POST" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="bulk_delete">
            <button type="submit" class="btn btn-sm btn-danger" id="bulkDeleteBtn" style="display:none;"
                    onclick="return confirm('ลบ keywords ที่เลือก?')">
                <i class="fas fa-trash me-1"></i>ลบที่เลือก
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="kwTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th>Keyword</th>
                        <th>ประเภท</th>
                        <th>หมวดหมู่</th>
                        <th class="text-end">Search Vol.</th>
                        <th class="text-end">KD</th>
                        <th>Priority</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keywords as $kw): ?>
                    <tr>
                        <td><input type="checkbox" name="selected[]" value="<?= $kw['id'] ?>" class="form-check-input kw-check" form="bulkForm"></td>
                        <td class="fw-semibold" style="color:#1E293B;font-size:13px;"><?= sanitize($kw['keyword']) ?></td>
                        <td>
                            <?php $typeBadge = ['primary'=>'bg-primary','secondary'=>'bg-info','long_tail'=>'bg-success','brand'=>'bg-warning text-dark']; ?>
                            <span class="badge <?= $typeBadge[$kw['keyword_type']] ?? 'bg-secondary' ?>"><?= $kw['keyword_type'] ?></span>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= sanitize($topicMap[$kw['topic']] ?? $kw['topic'] ?? '-') ?></span></td>
                        <td class="text-end"><?= $kw['search_volume'] ? number_format($kw['search_volume']) : '-' ?></td>
                        <td class="text-end">
                            <?php if ($kw['difficulty']): ?>
                            <span class="badge <?= $kw['difficulty'] < 30 ? 'bg-success' : ($kw['difficulty'] < 60 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $kw['difficulty'] ?>
                            </span>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td><?= $kw['priority'] ?></td>
                        <td>
                            <?php if ($kw['last_used']): ?>
                                <span class="status-badge status-info">ใช้แล้ว</span>
                            <?php else: ?>
                                <span class="status-badge status-success">พร้อมใช้</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบ keyword นี้?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= $kw['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ─── Modal: เพิ่ม Keyword ด้วยมือ ─────────────────────────────────────────── -->
<div class="modal fade" id="addManualModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="add_manual">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>เพิ่ม Keywords ด้วยมือ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Keywords <span class="text-danger">*</span></label>
                        <textarea class="form-control font-monospace" name="keywords_text" rows="8" required
                                  placeholder="ใส่ keyword 1 บรรทัดต่อ 1 keyword&#10;&#10;ตัวอย่าง:&#10;วิธีดูแลผิว&#10;ครีมกันแดดที่ดีที่สุด&#10;วิตามินซีบำรุงผิว"></textarea>
                        <small class="text-muted">1 บรรทัด = 1 keyword, เหลือ quota <?= $unlimited ? '∞' : $remaining ?></small>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">หมวดหมู่ <span class="text-danger">*</span></label>
                            <select name="topic" class="form-select" required>
                                <?php foreach ($topics as $t): ?>
                                <option value="<?= $t['slug'] ?>"><?= sanitize($t['name_th'] ?: $t['slug']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ประเภท Keyword</label>
                            <select name="keyword_type" class="form-select">
                                <option value="primary">Primary</option>
                                <option value="secondary">Secondary</option>
                                <option value="long_tail">Long Tail</option>
                                <option value="brand">Brand</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <input type="number" name="priority" class="form-control" value="10" min="1" max="100">
                            <small class="text-muted">สูงกว่า = ถูกใช้ก่อน</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-plus me-2"></i>เพิ่ม Keywords</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Import Ahrefs CSV ─────────────────────────────────────────────── -->
<div class="modal fade" id="importCsvModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="import_csv">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Import Keywords จาก Ahrefs CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">ไฟล์ CSV <span class="text-danger">*</span></label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                            <small class="text-muted">รองรับ UTF-8, สูงสุด 5MB</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">หมวดหมู่ <span class="text-danger">*</span></label>
                            <select name="csv_topic" class="form-select" required>
                                <?php foreach ($topics as $t): ?>
                                <option value="<?= $t['slug'] ?>"><?= sanitize($t['name_th'] ?: $t['slug']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ประเภท Keyword</label>
                            <select name="csv_keyword_type" class="form-select">
                                <option value="primary">Primary</option>
                                <option value="secondary">Secondary</option>
                                <option value="long_tail">Long Tail</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 p-3 rounded" style="background:#F8FAFC;border:1px solid #E2E8F0;">
                        <p class="small fw-semibold mb-2"><i class="fas fa-info-circle me-1 text-info"></i>รูปแบบ CSV ที่รองรับ</p>
                        <p class="small text-muted mb-2">คอลัมน์ที่อ่านได้อัตโนมัติ:</p>
                        <ul class="small text-muted mb-2 ps-3">
                            <li><code>keyword</code> — คำค้นหา <span class="text-danger">(จำเป็น)</span></li>
                            <li><code>volume</code> หรือ <code>search volume</code> — Search Volume</li>
                            <li><code>difficulty</code> หรือ <code>kd</code> — Keyword Difficulty</li>
                        </ul>
                        <div style="font-family:monospace;font-size:11px;background:#fff;padding:8px;border-radius:4px;border:1px solid #E2E8F0;">
                            keyword,volume,difficulty<br>
                            วิธีดูแลผิว,12000,25<br>
                            ครีมกันแดด spf50,8500,40
                        </div>
                        <?php if ($quota): ?>
                        <p class="small text-muted mt-2 mb-0">
                            <i class="fas fa-database me-1"></i>
                            Quota: ใช้ไป <?= $currentCount ?> /
                            <?= $unlimited ? '∞' : $kwLimit ?>
                            (เหลือ <?= $unlimited ? '∞' : $remaining ?>)
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-2"></i>Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $pageScripts = <<<'PAGESCRIPT'
<script>
$(document).ready(function() {
    if ($('#kwTable tbody tr').length > 0) {
        $('#kwTable').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            order: [[6,'desc']],
            pageLength: 25,
            columnDefs: [{ orderable: false, targets: [0,8] }]
        });
    }

    $('#selectAll').on('change', function() {
        $('.kw-check').prop('checked', this.checked);
        toggleBulk();
    });
    $(document).on('change', '.kw-check', toggleBulk);
    function toggleBulk() {
        $('#bulkDeleteBtn').toggle($('.kw-check:checked').length > 0);
    }
});
</script>
PAGESCRIPT; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
