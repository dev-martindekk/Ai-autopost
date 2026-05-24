<?php
/**
 * AI AutoPost SEO System - System Logs
 * =====================================
 */

require_once __DIR__ . '/../includes/auth.php';
auth()->requireAuth();

$pageTitle = 'System Logs';
require_once __DIR__ . '/header.php';

// Handle clear logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Session หมดอายุ');
        redirect(ADMIN_URL . '/logs.php');
    }

    if ($_POST['action'] === 'clear_old') {
        $days = (int)($_POST['days'] ?? 30);
        db()->query("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        setFlash('success', "ลบ logs เก่ากว่า {$days} วันเรียบร้อย");
        redirect(ADMIN_URL . '/logs.php');
    }
}

// Get filter parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$level = $_GET['level'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where = [];
$params = [];

if ($level) {
    $where[] = "log_type = ?";
    $params[] = $level;
}

if ($search) {
    $where[] = "(message LIKE ? OR context LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get logs
$totalLogs = db()->fetchOne("SELECT COUNT(*) as cnt FROM system_logs {$whereClause}", $params)['cnt'];
$logs = db()->fetchAll("
    SELECT * FROM system_logs
    {$whereClause}
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
", array_merge($params, [$perPage, $offset]));

$totalPages = ceil($totalLogs / $perPage);

// Get level counts
$levelCounts = db()->fetchAll("
    SELECT log_type, COUNT(*) as cnt
    FROM system_logs
    GROUP BY log_type
");
$levelStats = [];
foreach ($levelCounts as $lc) {
    $levelStats[$lc['log_type']] = $lc['cnt'];
}
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="page-title">System Logs</h1>
        <p class="page-subtitle">ดู logs การทำงานของระบบ</p>
    </div>
    <div class="dropdown">
        <button class="btn btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
            <i class="fas fa-trash me-2"></i>ล้าง Logs
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <form method="POST" class="px-3 py-2">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="clear_old">
                    <div class="mb-2">
                        <label class="form-label small">ลบ logs เก่ากว่า</label>
                        <select name="days" class="form-select form-select-sm">
                            <option value="7">7 วัน</option>
                            <option value="14">14 วัน</option>
                            <option value="30" selected>30 วัน</option>
                            <option value="60">60 วัน</option>
                            <option value="90">90 วัน</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm w-100"
                            onclick="return confirm('ยืนยันการลบ?')">
                        <i class="fas fa-trash me-1"></i>ลบ
                    </button>
                </form>
            </li>
        </ul>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center py-2">
            <div class="h4 mb-0"><?= number_format($totalLogs) ?></div>
            <small class="text-muted">Logs ทั้งหมด</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-2 bg-danger text-white">
            <div class="h4 mb-0"><?= number_format($levelStats['error'] ?? 0) ?></div>
            <small>Errors</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-2 bg-warning text-dark">
            <div class="h4 mb-0"><?= number_format($levelStats['warning'] ?? 0) ?></div>
            <small>Warnings</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-2 bg-info text-white">
            <div class="h4 mb-0"><?= number_format($levelStats['info'] ?? 0) ?></div>
            <small>Info</small>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Level</label>
                <select name="level" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <option value="error" <?= $level === 'error' ? 'selected' : '' ?>>Error</option>
                    <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>Warning</option>
                    <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>Info</option>
                    <option value="debug" <?= $level === 'debug' ? 'selected' : '' ?>>Debug</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">ค้นหา</label>
                <input type="text" name="search" class="form-control"
                       value="<?= sanitize($search) ?>" placeholder="ค้นหาใน message หรือ context...">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="fas fa-search me-1"></i>ค้นหา
                </button>
                <a href="<?= ADMIN_URL ?>/logs.php" class="btn btn-outline-secondary">รีเซ็ต</a>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th width="150">เวลา</th>
                        <th width="80">Level</th>
                        <th>Message</th>
                        <th width="80">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                <p class="mb-0">ไม่พบ logs</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $levelClass = match($log['log_type']) {
                                'error' => 'danger',
                                'warning' => 'warning',
                                'info' => 'info',
                                'debug' => 'secondary',
                                default => 'light'
                            };
                            ?>
                            <tr class="<?= $log['log_type'] === 'error' ? 'table-danger' : '' ?>">
                                <td>
                                    <small><?= date('d/m H:i:s', strtotime($log['created_at'])) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $levelClass ?>"><?= strtoupper($log['log_type']) ?></span>
                                </td>
                                <td>
                                    <div class="text-truncate" style="max-width: 500px;" title="<?= sanitize($log['message']) ?>">
                                        <?= sanitize($log['message']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($log['context']): ?>
                                        <button class="btn btn-sm btn-outline-primary"
                                                onclick="showContext(<?= $log['id'] ?>)"
                                                title="ดู Context">
                                            <i class="fas fa-code"></i>
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

    <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&level=<?= $level ?>&search=<?= urlencode($search) ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&level=<?= $level ?>&search=<?= urlencode($search) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&level=<?= $level ?>&search=<?= urlencode($search) ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Context Modal -->
<div class="modal fade" id="contextModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-code me-2"></i>Log Context
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-dark text-light p-3 rounded" id="contextContent"
                     style="max-height: 400px; overflow: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<?php
// Store logs context for JavaScript
$logsContext = [];
foreach ($logs as $log) {
    if ($log['context']) {
        $logsContext[$log['id']] = $log['context'];
    }
}
$logsContextJson = json_encode($logsContext);
$autoRefresh = ($page === 1 && !$search && !$level) ? 'setTimeout(() => location.reload(), 30000);' : '';
$pageScripts = <<<SCRIPTS
<script>
const logsContext = {$logsContextJson};

function showContext(id) {
    const context = logsContext[id];
    if (!context) return;

    try {
        const parsed = JSON.parse(context);
        document.getElementById('contextContent').textContent = JSON.stringify(parsed, null, 2);
    } catch (e) {
        document.getElementById('contextContent').textContent = context;
    }

    new bootstrap.Modal(document.getElementById('contextModal')).show();
}

// Auto-refresh every 30 seconds if on first page
{$autoRefresh}
</script>
SCRIPTS;
?>

<?php require_once __DIR__ . '/footer.php'; ?>
