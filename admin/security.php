<?php
$pageTitle = 'Security';
require_once __DIR__ . '/header.php';

auth()->requireRole('admin');

$errors = [];

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    if (isset($_POST['ban_ip'])) {
        $ip      = trim($_POST['ip_address']);
        $reason  = trim($_POST['reason'] ?: 'Manual ban by admin');
        $hours   = max(1, (int)($_POST['hours'] ?? 24));
        $perm    = !empty($_POST['permanent']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            security()->banIp($ip, $reason, $hours, $perm, auth()->getUserId());
            setFlash('success', "Banned IP: {$ip}");
        } else {
            $errors[] = 'IP address ไม่ถูกต้อง';
        }
        redirect(ADMIN_URL . '/security.php');
    }

    if (isset($_POST['unban_ip'])) {
        $ip = trim($_POST['ip_address']);
        security()->unbanIp($ip);
        setFlash('success', "Unbanned IP: {$ip}");
        redirect(ADMIN_URL . '/security.php');
    }

    if (isset($_POST['clear_events'])) {
        db()->query("DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [(int)($_POST['days'] ?? 7)]);
        setFlash('success', 'ล้าง security events เรียบร้อย');
        redirect(ADMIN_URL . '/security.php');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterSeverity = $_GET['severity'] ?? '';
$filterType     = $_GET['type'] ?? '';
$filterIp       = $_GET['ip'] ?? '';
$since          = $_GET['since'] ?? date('Y-m-d', strtotime('-1 day'));

$filters = array_filter([
    'severity'   => $filterSeverity,
    'event_type' => $filterType,
    'ip'         => $filterIp,
    'since'      => $since ? $since . ' 00:00:00' : null,
]);

$stats   = security()->getStats();
$events  = security()->getEvents(200, $filters);
$bans    = security()->getBans();
$topIPs  = security()->getTopAttackers(10);
$summary = security()->getEventTypeSummary();

$severityColors = [
    'critical' => 'danger',
    'high'     => 'warning',
    'medium'   => 'info',
    'low'      => 'secondary',
];
?>

<style>
.event-row-critical { background: rgba(239,68,68,.06); }
.event-row-high     { background: rgba(251,146,60,.06); }
.event-badge { font-size: 11px; padding: 2px 8px; border-radius: 20px; }
.ip-mono { font-family: monospace; font-size: 13px; }
.stat-mini { font-size: 11px; color: #64748b; }
</style>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['last_hour',   'ชั่วโมงที่ผ่านมา', 'clock', 'primary'],
        ['critical',    'Critical',          'skull-crossbones', 'danger'],
        ['high_count',  'High',              'exclamation-triangle', 'warning'],
        ['unique_ips',  'Unique IPs (24h)',  'network-wired', 'info'],
        ['active_bans', 'Active Bans',       'ban', 'dark'],
    ];
    foreach ($cards as [$key, $label, $icon, $color]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <i class="fas fa-<?= $icon ?> fa-lg text-<?= $color ?> mb-1"></i>
                <div class="fw-bold fs-4"><?= number_format((int)($stats[$key] ?? 0)) ?></div>
                <div class="stat-mini"><?= $label ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <i class="fas fa-chart-bar fa-lg text-success mb-1"></i>
                <div class="fw-bold fs-4"><?= number_format((int)($stats['total_events'] ?? 0)) ?></div>
                <div class="stat-mini">รวม Events (24h)</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">

    <!-- Left: Events Table -->
    <div class="col-lg-8">

        <!-- Filter Bar -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <select name="severity" class="form-select form-select-sm">
                            <option value="">ทุก Severity</option>
                            <?php foreach(['critical','high','medium','low'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filterSeverity===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="type" class="form-select form-select-sm">
                            <option value="">ทุก Type</option>
                            <?php foreach($summary as $row): ?>
                            <option value="<?= $row['event_type'] ?>" <?= $filterType===$row['event_type']?'selected':'' ?>><?= $row['event_type'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="ip" class="form-control form-control-sm ip-mono"
                               placeholder="Filter IP" value="<?= sanitize($filterIp) ?>" style="width:140px">
                    </div>
                    <div class="col-auto">
                        <input type="date" name="since" class="form-control form-control-sm" value="<?= $since ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="<?= ADMIN_URL ?>/security.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-shield-alt me-2 text-danger"></i>Security Events
                    <span class="badge bg-secondary ms-1"><?= count($events) ?></span>
                </span>
                <form method="POST" class="d-inline" onsubmit="return confirm('ล้าง events เก่ากว่า 7 วัน?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="days" value="7">
                    <button name="clear_events" type="submit" class="btn btn-xs btn-outline-danger">
                        <i class="fas fa-trash"></i> Clear Old
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($events)): ?>
                <div class="empty-state"><i class="fas fa-shield-check"></i><h5>ไม่มี Security Events</h5></div>
                <?php else: ?>
                <div class="table-responsive" style="max-height:600px;overflow-y:auto">
                    <table class="table table-sm mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th style="width:120px">เวลา</th>
                                <th style="width:80px">Severity</th>
                                <th>Type</th>
                                <th>IP</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($events as $ev): ?>
                        <?php $color = $severityColors[$ev['severity']] ?? 'secondary'; ?>
                        <tr class="event-row-<?= $ev['severity'] ?>">
                            <td class="text-muted" style="font-size:11px;white-space:nowrap">
                                <?= date('d/m H:i:s', strtotime($ev['created_at'])) ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $color ?> event-badge"><?= $ev['severity'] ?></span>
                            </td>
                            <td style="font-size:12px">
                                <code><?= sanitize($ev['event_type']) ?></code>
                            </td>
                            <td>
                                <span class="ip-mono" style="font-size:12px"><?= sanitize($ev['ip_address']) ?></span>
                                <?php if ($ev['ip_address']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="ip_address" value="<?= sanitize($ev['ip_address']) ?>">
                                    <input type="hidden" name="reason" value="Quick ban from event log">
                                    <input type="hidden" name="hours" value="24">
                                    <button name="ban_ip" type="submit" class="btn btn-xs btn-outline-danger ms-1" title="Ban IP">
                                        <i class="fas fa-ban" style="font-size:10px"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="<?= sanitize($ev['details'] ?? '') ?>">
                                <?= sanitize(substr($ev['details'] ?? '', 0, 120)) ?>
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

    <!-- Right: Bans + Ban Form + Top IPs + Type Summary -->
    <div class="col-lg-4">

        <!-- Ban IP Form -->
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-ban me-2 text-danger"></i>Ban IP</div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-3"><ul class="mb-0 ps-3"><?php foreach($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach ?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="mb-2">
                        <input type="text" name="ip_address" class="form-control form-control-sm ip-mono"
                               placeholder="IP Address (e.g. 1.2.3.4)" required>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="reason" class="form-control form-control-sm"
                               placeholder="เหตุผล" value="Manual ban">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <input type="number" name="hours" class="form-control form-control-sm"
                                   placeholder="ชั่วโมง" value="24" min="1">
                        </div>
                        <div class="col-auto d-flex align-items-center">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="permanent" id="permBan">
                                <label class="form-check-label" for="permBan" style="font-size:13px">ถาวร</label>
                            </div>
                        </div>
                    </div>
                    <button name="ban_ip" type="submit" class="btn btn-danger btn-sm w-100">
                        <i class="fas fa-ban me-1"></i>Ban IP
                    </button>
                </form>
            </div>
        </div>

        <!-- Active Bans -->
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-shield-alt me-2 text-warning"></i>IP Bans (<?= count($bans) ?>)</div>
            <div class="card-body p-0">
                <?php if (empty($bans)): ?>
                <div class="text-center py-3 text-muted" style="font-size:13px">ไม่มี IP ที่ถูก Ban</div>
                <?php else: ?>
                <div style="max-height:280px;overflow-y:auto">
                    <?php foreach ($bans as $ban): ?>
                    <div class="d-flex align-items-center px-3 py-2 border-bottom">
                        <div class="flex-grow-1">
                            <div class="ip-mono" style="font-size:13px"><?= sanitize($ban['ip_address']) ?>
                                <?php if ($ban['is_permanent']): ?>
                                <span class="badge bg-danger ms-1" style="font-size:9px">PERM</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size:10px">
                                <?= sanitize(substr($ban['reason'], 0, 50)) ?>
                                <?php if (!$ban['is_permanent'] && $ban['expires_at']): ?>
                                · หมดอายุ <?= date('d/m H:i', strtotime($ban['expires_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="POST" class="ms-2">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="ip_address" value="<?= sanitize($ban['ip_address']) ?>">
                            <button name="unban_ip" type="submit" class="btn btn-xs btn-outline-success" title="Unban">
                                <i class="fas fa-unlock" style="font-size:10px"></i>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Attackers -->
        <?php if (!empty($topIPs)): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-crosshairs me-2 text-danger"></i>Top Attackers (24h)</div>
            <div class="card-body p-0">
                <?php foreach ($topIPs as $row): ?>
                <div class="d-flex align-items-center px-3 py-2 border-bottom">
                    <div class="flex-grow-1">
                        <span class="ip-mono" style="font-size:12px"><?= sanitize($row['ip_address']) ?></span>
                        <div class="text-muted" style="font-size:10px">
                            <?= $row['events'] ?> events
                            <?php if ($row['critical']): ?>
                            · <span class="text-danger"><?= $row['critical'] ?> critical</span>
                            <?php endif; ?>
                            · last: <?= date('H:i', strtotime($row['last_seen'])) ?>
                        </div>
                    </div>
                    <a href="?ip=<?= urlencode($row['ip_address']) ?>" class="btn btn-xs btn-outline-secondary">
                        <i class="fas fa-search" style="font-size:10px"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Event Type Summary -->
        <?php if (!empty($summary)): ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-pie me-2 text-info"></i>Event Types (24h)</div>
            <div class="card-body p-0">
                <?php foreach ($summary as $row): ?>
                <div class="d-flex justify-content-between align-items-center px-3 py-1 border-bottom">
                    <code style="font-size:11px"><?= sanitize($row['event_type']) ?></code>
                    <span class="badge bg-secondary"><?= $row['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
