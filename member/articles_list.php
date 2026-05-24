<?php
$pageTitle = 'บทความของฉัน';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

$statusFilter = $_GET['status'] ?? '';

$params = [$memberId];
$whereClauses = ["a.owner_type='member'", "a.owner_id=?"];
if ($statusFilter) {
    $whereClauses[] = "a.status=?";
    $params[] = $statusFilter;
}
$where = implode(' AND ', $whereClauses);

$articles = db()->fetchAll(
    "SELECT a.id, a.title, a.status, a.word_count, a.primary_keyword,
            a.ai_tokens_used, a.published_at, a.created_at, a.topic,
            s.name AS site_name, s.base_url AS site_url,
            COALESCE(t.name_th, a.topic) AS topic_name
     FROM articles a
     LEFT JOIN sites s ON s.id = a.site_id
     LEFT JOIN topics t ON t.slug = a.topic
     WHERE {$where}
     ORDER BY a.created_at DESC
     LIMIT 500",
    $params
);

$statusCounts = db()->fetchAll(
    "SELECT status, COUNT(*) AS cnt FROM articles WHERE owner_type='member' AND owner_id=? GROUP BY status",
    [$memberId]
);
$counts = array_column($statusCounts, 'cnt', 'status');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">บทความของฉัน</h4>
        <p class="text-muted mb-0">รวมทั้งหมด <?= array_sum($counts) ?> บทความ</p>
    </div>
</div>

<!-- Status filter tabs -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <?php
    $tabs = [
        '' => ['label'=>'ทั้งหมด', 'count'=>array_sum($counts), 'color'=>'primary'],
        'published' => ['label'=>'Published', 'count'=>$counts['published']??0, 'color'=>'success'],
        'generated' => ['label'=>'Generated',  'count'=>$counts['generated']??0, 'color'=>'info'],
        'draft'     => ['label'=>'Draft',      'count'=>$counts['draft']??0,     'color'=>'warning'],
        'failed'    => ['label'=>'Failed',     'count'=>$counts['failed']??0,    'color'=>'danger'],
    ];
    foreach ($tabs as $val => $tab):
        $active = ($statusFilter === $val);
    ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm btn-<?= $active ? $tab['color'] : 'outline-'.$tab['color'] ?>">
        <?= $tab['label'] ?>
        <span class="badge bg-<?= $active ? 'white text-'.$tab['color'] : $tab['color'] ?> ms-1"><?= $tab['count'] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($articles)): ?>
        <div class="empty-state">
            <i class="fas fa-file-alt"></i>
            <h5>ไม่มีบทความ</h5>
            <p>ยังไม่มีบทความในหมวดนี้</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" id="artTable">
                <thead>
                    <tr>
                        <th>ชื่อบทความ</th>
                        <th>เว็บไซต์</th>
                        <th>หมวดหมู่</th>
                        <th>สถานะ</th>
                        <th>คำ</th>
                        <th>Tokens</th>
                        <th>วันที่สร้าง</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($articles as $art): ?>
                    <tr>
                        <td style="max-width:260px;">
                            <div class="fw-semibold text-truncate" style="color:#1E293B;" title="<?= sanitize($art['title']) ?>">
                                <?= sanitize($art['title']) ?>
                            </div>
                            <?php if ($art['primary_keyword']): ?>
                            <small class="text-muted"><?= sanitize($art['primary_keyword']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($art['site_url']): ?>
                            <a href="<?= sanitize($art['site_url']) ?>" target="_blank" class="text-decoration-none" style="font-size:12px;color:#6366F1;">
                                <?= sanitize($art['site_name'] ?? $art['site_url']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:12px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($art['topic_name']): ?>
                            <span class="badge bg-light text-dark border" style="font-size:11px;"><?= sanitize($art['topic_name']) ?></span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:12px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $statusMap = ['published'=>'success','generated'=>'info','draft'=>'warning','failed'=>'danger','scheduled'=>'secondary']; ?>
                            <span class="status-badge status-<?= $statusMap[$art['status']] ?? 'warning' ?>">
                                <?= $art['status'] ?>
                            </span>
                        </td>
                        <td><?= $art['word_count'] ? number_format($art['word_count']) : '-' ?></td>
                        <td><?= $art['ai_tokens_used'] ? number_format($art['ai_tokens_used']) : '-' ?></td>
                        <td><small><?= date('d/m/y H:i', strtotime($art['created_at'])) ?></small></td>
                        <td>
                            <a href="/member/article_view.php?id=<?= $art['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#artTable').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
        order: [[5,'desc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [7] }]
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
