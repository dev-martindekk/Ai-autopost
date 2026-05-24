<?php
$pageTitle = 'Keyword Research';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

// Get member's sites for keyword import
$sites = db()->fetchAll(
    "SELECT id, name FROM sites WHERE owner_type='member' AND owner_id=? ORDER BY name", [$memberId]
);

$flash = getFlash();

// Handle bulk import to keyword list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_keywords'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $siteId   = (int)($_POST['site_id'] ?? 0);
    $keywords = array_filter(array_map('trim', explode("\n", $_POST['keywords'] ?? '')));
    $topic    = trim($_POST['topic'] ?? 'general');

    $siteOk = db()->fetchColumn("SELECT COUNT(*) FROM sites WHERE id=? AND owner_type='member' AND owner_id=?", [$siteId, $memberId]);
    if ($siteOk && !empty($keywords)) {
        $imported = 0;
        foreach ($keywords as $kw) {
            $kw = substr($kw, 0, 255);
            if (strlen($kw) < 2) continue;
            $exists = db()->fetchColumn(
                "SELECT COUNT(*) FROM keywords WHERE site_id=? AND keyword=?", [$siteId, $kw]
            );
            if (!$exists) {
                db()->insert('keywords', [
                    'site_id'    => $siteId,
                    'keyword'    => $kw,
                    'topic'      => $topic,
                    'is_active'  => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $imported++;
            }
        }
        setFlash('success', "นำเข้า {$imported} keywords เรียบร้อยแล้ว");
        redirect('/member/keyword_research.php');
    }
}
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<style>
.kw-result-row { cursor:pointer; transition:background .15s; }
.kw-result-row:hover { background:#f8f9ff; }
.kw-result-row.selected { background:#EEF2FF; }
.diff-bar { height:6px; border-radius:3px; background:#e2e8f0; overflow:hidden; }
.diff-fill { height:100%; border-radius:3px; transition:width .4s; }
.vol-chip { font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; }
</style>

<div class="row g-4">

<!-- Left: Search -->
<div class="col-lg-5">

    <!-- Search Card -->
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-search me-2 text-primary"></i>ค้นหา Keyword</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Keyword หลัก</label>
                <div class="input-group">
                    <input type="text" id="kwInput" class="form-control"
                           placeholder="เช่น วิธีทำ SEO" maxlength="200">
                    <button class="btn btn-primary" id="searchBtn" onclick="doSearch()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label" style="font-size:12px;">ภาษา</label>
                    <select id="langCode" class="form-select form-select-sm">
                        <option value="th">ไทย (th)</option>
                        <option value="en">English (en)</option>
                        <option value="id">Indonesia (id)</option>
                        <option value="vi">Vietnamese (vi)</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:12px;">Location</label>
                    <select id="locationCode" class="form-select form-select-sm">
                        <option value="2764">Thailand</option>
                        <option value="2840">USA</option>
                        <option value="2356">India</option>
                        <option value="2360">Indonesia</option>
                        <option value="2704">Vietnam</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 mb-0">
                <div id="searchStatus" class="text-muted" style="font-size:12px;"></div>
            </div>
        </div>
    </div>

    <!-- Import Card -->
    <div class="card" id="importCard" style="display:none;">
        <div class="card-header"><i class="fas fa-file-import me-2 text-success"></i>นำเข้า Keywords ที่เลือก</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="import_keywords" value="1">

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;">เว็บไซต์</label>
                    <?php if (empty($sites)): ?>
                    <div class="text-muted small">ยังไม่มีเว็บไซต์ — <a href="/member/sites_list.php">เพิ่มก่อน</a></div>
                    <?php else: ?>
                    <select name="site_id" class="form-select form-select-sm" required>
                        <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;">Topic</label>
                    <input type="text" name="topic" class="form-control form-control-sm" value="general">
                </div>

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;">
                        Keywords ที่เลือก <span class="badge bg-primary" id="selectedCount">0</span>
                    </label>
                    <textarea name="keywords" id="selectedKeywords" class="form-control" rows="5"
                              placeholder="เลือก keywords จากตารางด้านขวา หรือพิมพ์เอง (แต่ละบรรทัด = 1 keyword)"
                              style="font-size:12px;"></textarea>
                </div>

                <button type="submit" class="btn btn-success w-100" <?= empty($sites) ? 'disabled' : '' ?>>
                    <i class="fas fa-file-import me-2"></i>นำเข้า Keyword List
                </button>
            </form>
        </div>
    </div>


</div>

<!-- Right: Results -->
<div class="col-lg-7">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-chart-bar me-2 text-warning"></i>ผลการค้นหา</span>
            <div id="resultActions" style="display:none;">
                <button class="btn btn-sm btn-outline-primary me-1" onclick="selectAll()">เลือกทั้งหมด</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">ล้าง</button>
            </div>
        </div>
        <div class="card-body p-0" id="resultsContainer">
            <div class="text-center py-5 text-muted" id="emptyState">
                <i class="fas fa-search fa-2x mb-2 d-block"></i>
                พิมพ์ keyword แล้วกดค้นหา
            </div>
        </div>
    </div>
</div>

</div>

<script>
let selectedKws = new Set();

async function doSearch() {
    const kw = document.getElementById('kwInput').value.trim();
    if (!kw) return;

    const btn = document.getElementById('searchBtn');
    const status = document.getElementById('searchStatus');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    status.textContent = 'กำลังค้นหา...';
    document.getElementById('resultsContainer').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><div class="mt-2 text-muted">กำลังวิเคราะห์ keyword...</div></div>';
    document.getElementById('resultActions').style.display = 'none';
    selectedKws.clear();
    updateImportList();

    try {
        const resp = await fetch('/member/ajax/keyword_research.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                keyword: kw,
                lang: document.getElementById('langCode').value,
                location: parseInt(document.getElementById('locationCode').value)
            })
        });
        const data = await resp.json();

        if (!data.success) {
            document.getElementById('resultsContainer').innerHTML =
                '<div class="alert alert-warning m-3"><i class="fas fa-exclamation-triangle me-2"></i>' + data.message + '</div>';
            status.textContent = '';
            return;
        }

        renderResults(data.results, kw);
        status.textContent = 'พบ ' + data.results.length + ' keywords';
        document.getElementById('resultActions').style.display = 'flex';
        document.getElementById('importCard').style.display = 'block';

    } catch(e) {
        document.getElementById('resultsContainer').innerHTML =
            '<div class="alert alert-danger m-3">เกิดข้อผิดพลาด: ' + e.message + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search"></i>';
    }
}

function renderResults(results, mainKw) {
    if (!results || !results.length) {
        document.getElementById('resultsContainer').innerHTML =
            '<div class="text-center py-4 text-muted">ไม่พบข้อมูล keyword นี้</div>';
        return;
    }

    const maxVol = Math.max(...results.map(r => r.volume || 0), 1);

    let html = '<div class="table-responsive"><table class="table table-sm mb-0">'
        + '<thead class="table-light"><tr>'
        + '<th style="width:30px;"><input type="checkbox" id="selectAllChk" onchange="selectAllToggle(this)"></th>'
        + '<th>Keyword</th>'
        + '<th class="text-end" style="width:90px;">Volume</th>'
        + '<th style="width:120px;">Difficulty</th>'
        + '<th class="text-end" style="width:60px;">CPC</th>'
        + '</tr></thead><tbody>';

    results.forEach(r => {
        const vol    = r.volume || 0;
        const diff   = r.difficulty || 0;
        const cpc    = r.cpc ? '$' + r.cpc.toFixed(2) : '—';
        const diffColor = diff >= 70 ? '#EF4444' : diff >= 40 ? '#F59E0B' : '#10B981';
        const volStr = vol >= 1000 ? (vol/1000).toFixed(1) + 'K' : vol.toString();
        const isMain = r.keyword === mainKw;
        html += `<tr class="kw-result-row" onclick="toggleKw('${r.keyword.replace(/'/g,"\\'").replace(/"/g,'&quot;')}', this)">
            <td><input type="checkbox" class="kw-chk" data-kw="${r.keyword.replace(/"/g,'&quot;')}" onchange="toggleKwChk(this)"></td>
            <td style="font-size:13px;">${escHtml(r.keyword)}${isMain ? ' <span class="badge bg-primary ms-1" style="font-size:9px;">หลัก</span>' : ''}</td>
            <td class="text-end"><span class="vol-chip bg-light text-dark">${volStr}</span></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="diff-bar flex-grow-1">
                        <div class="diff-fill" style="width:${diff}%;background:${diffColor};"></div>
                    </div>
                    <span style="font-size:11px;color:${diffColor};font-weight:700;width:24px;">${diff}</span>
                </div>
            </td>
            <td class="text-end text-muted" style="font-size:11px;">${cpc}</td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    document.getElementById('resultsContainer').innerHTML = html;
}

function toggleKw(kw, row) {
    if (event.target.type === 'checkbox') return;
    const chk = row.querySelector('.kw-chk');
    chk.checked = !chk.checked;
    if (chk.checked) { selectedKws.add(kw); row.classList.add('selected'); }
    else             { selectedKws.delete(kw); row.classList.remove('selected'); }
    updateImportList();
}

function toggleKwChk(chk) {
    const kw  = chk.dataset.kw;
    const row = chk.closest('tr');
    if (chk.checked) { selectedKws.add(kw); row.classList.add('selected'); }
    else             { selectedKws.delete(kw); row.classList.remove('selected'); }
    updateImportList();
}

function selectAll() {
    document.querySelectorAll('.kw-chk').forEach(c => {
        c.checked = true;
        c.closest('tr').classList.add('selected');
        selectedKws.add(c.dataset.kw);
    });
    const allChk = document.getElementById('selectAllChk');
    if (allChk) allChk.checked = true;
    updateImportList();
}

function selectAllToggle(chk) {
    document.querySelectorAll('.kw-chk').forEach(c => {
        c.checked = chk.checked;
        c.closest('tr').classList.toggle('selected', chk.checked);
        if (chk.checked) selectedKws.add(c.dataset.kw);
        else selectedKws.delete(c.dataset.kw);
    });
    updateImportList();
}

function clearSelection() {
    selectedKws.clear();
    document.querySelectorAll('.kw-chk').forEach(c => { c.checked = false; c.closest('tr').classList.remove('selected'); });
    const allChk = document.getElementById('selectAllChk');
    if (allChk) allChk.checked = false;
    updateImportList();
}

function updateImportList() {
    document.getElementById('selectedKeywords').value = [...selectedKws].join('\n');
    document.getElementById('selectedCount').textContent = selectedKws.size;
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('kwInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') doSearch();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
