<?php
$pageTitle = 'Import Keywords';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

$importResult = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'กรุณาเลือกไฟล์ CSV';
    } else {
        $file = $_FILES['csv_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $errors[] = 'รองรับเฉพาะไฟล์ .csv เท่านั้น';
        }
    }

    if (empty($errors)) {
        // Check quota
        $currentCount = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM keywords WHERE owner_type='member' AND owner_id=? AND is_active=1",
            [$memberId]
        );
        $remaining = $quota['keywords']['unlimited'] ?? false
            ? PHP_INT_MAX
            : max(0, ($quota['keywords']['limit'] ?? 0) - $currentCount);

        if ($remaining <= 0) {
            $errors[] = 'Keyword quota เต็มแล้ว ไม่สามารถ import เพิ่มได้';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            $headers = fgetcsv($handle); // skip header row
            $headers = array_map('strtolower', array_map('trim', $headers ?? []));

            // Map column positions
            $kwCol  = array_search('keyword', $headers);
            $volPos = array_search('volume', $headers);
            if ($volPos === false) $volPos = array_search('search volume', $headers);
            $volCol = $volPos; // false if not found

            if ($kwCol === false) {
                $errors[] = 'ไม่พบคอลัมน์ "keyword" ในไฟล์ CSV';
            } else {
                $imported = 0;
                $skipped  = 0;
                $topic    = $_POST['topic'] ?? 'general';

                while (($row = fgetcsv($handle)) !== false && $imported < $remaining) {
                    $kw = trim($row[$kwCol] ?? '');
                    if (empty($kw) || mb_strlen($kw) > 255) { $skipped++; continue; }

                    $exists = db()->fetchColumn(
                        "SELECT COUNT(*) FROM keywords WHERE keyword=? AND owner_type='member' AND owner_id=?",
                        [$kw, $memberId]
                    );
                    if ($exists) { $skipped++; continue; }

                    db()->insert('keywords', [
                        'owner_type'   => 'member',
                        'owner_id'     => $memberId,
                        'keyword'      => $kw,
                        'keyword_type' => 'primary',
                        'topic'        => $topic,
                        'search_volume'=> ($volCol !== false && isset($row[$volCol])) ? (int)str_replace([',','.'], '', $row[$volCol]) : null,
                        'language_code'=> 'th',
                        'priority'     => 10,
                        'is_active'    => 1,
                    ]);
                    $imported++;
                }
                fclose($handle);

                $importResult = ['imported' => $imported, 'skipped' => $skipped];
                logEvent('info', 'keywords_import', 'Keywords imported', ['member_id' => $memberId, 'count' => $imported]);
            }
        }
    }
}

$topics = db()->fetchAll("SELECT slug, name_th FROM topics WHERE is_active=1 ORDER BY sort_order");
?>

<div class="mb-4">
    <a href="/member/keywords_list.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>กลับ
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($importResult): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle me-2"></i>
    Import สำเร็จ: <strong><?= $importResult['imported'] ?></strong> keywords
    <?php if ($importResult['skipped']): ?>
    (ข้าม <?= $importResult['skipped'] ?> รายการที่ซ้ำหรือไม่ถูกต้อง)
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="fas fa-file-import me-2 text-primary"></i>Import Keywords จาก CSV</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <div class="mb-3">
                        <label class="form-label">ไฟล์ CSV <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        <small class="text-muted">ขนาดสูงสุด 5MB รองรับ UTF-8</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">หมวดหมู่</label>
                        <select name="topic" class="form-select">
                            <?php foreach ($topics as $t): ?>
                            <option value="<?= $t['slug'] ?>"><?= sanitize($t['name_th'] ?: $t['slug']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($quota): ?>
                    <div class="alert alert-info mb-3" style="font-size:13px;">
                        <i class="fas fa-info-circle me-2"></i>
                        Quota: ใช้ไป <?= $quota['keywords']['used'] ?> /
                        <?= $quota['keywords']['unlimited'] ? '∞' : $quota['keywords']['limit'] ?>
                        (เหลือ <?= $quota['keywords']['unlimited'] ? '∞' : $quota['keywords']['remaining'] ?>)
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i>Import Keywords
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="fas fa-circle-info me-2 text-info"></i>รูปแบบไฟล์ CSV</div>
            <div class="card-body">
                <p style="font-size:13px;color:#64748B;">ไฟล์ CSV ต้องมีคอลัมน์ดังนี้:</p>
                <div style="background:#F8FAFC;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;">
                    keyword,volume<br>
                    วิธีทำ SEO,12000<br>
                    content marketing คืออะไร,8500<br>
                    เพิ่ม traffic เว็บไซต์,6200
                </div>
                <ul class="mt-3" style="font-size:12px;color:#64748B;">
                    <li>คอลัมน์ <code>keyword</code> จำเป็น</li>
                    <li>คอลัมน์ <code>volume</code> ไม่จำเป็น</li>
                    <li>รองรับ Ahrefs / SEMrush export</li>
                    <li>ข้าม keyword ที่ซ้ำอัตโนมัติ</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
