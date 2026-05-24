<?php
/**
 * Member Portal Header & Sidebar
 */

require_once __DIR__ . '/../includes/member_auth.php';
require_once __DIR__ . '/../includes/plan_manager.php';

header('Content-Type: text/html; charset=UTF-8');

memberAuth()->requireAuth();

$currentMember = memberAuth()->getMember();
$currentPage   = basename($_SERVER['PHP_SELF'], '.php');
$csrfToken     = generateCsrfToken();

// Quota for sidebar display
try {
    $quota = planManager()->getQuota((int)$currentMember['id']);
} catch (Exception $e) {
    $quota = null;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?? 'Member Portal' ?> - AI AutoPost SEO</title>

    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #6366F1;
            --primary-dark: #4F46E5;
            --secondary: #8B5CF6;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
            --info: #06B6D4;
            --sidebar-bg: linear-gradient(180deg, #0F172A 0%, #1E1B4B 50%, #312E81 100%);
            --sidebar-width: 260px;
            --border-radius: 16px;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 25px 50px -12px rgb(0 0 0 / 0.25);
        }
        * { font-family: 'Inter','Prompt',sans-serif; }
        body { background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%); min-height: 100vh; }

        /* Sidebar */
        .sidebar { position:fixed; top:0; left:0; width:var(--sidebar-width); height:100vh;
            background:var(--sidebar-bg); z-index:1000; display:flex; flex-direction:column;
            box-shadow:var(--shadow-xl); transition:all .3s; }
        .sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,.1); display:flex; align-items:center; gap:12px; }
        .sidebar-brand-icon { width:42px; height:42px; background:linear-gradient(135deg,var(--primary),var(--secondary));
            border-radius:12px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; }
        .sidebar-brand-text { color:#fff; font-size:15px; font-weight:700; }
        .sidebar-brand-text small { display:block; font-size:10px; font-weight:400; opacity:.6; }

        .sidebar-nav { flex:1; overflow-y:auto; overflow-x:hidden; padding:12px 0;
            scrollbar-width:thin; scrollbar-color:rgba(255,255,255,.2) transparent; }
        .nav-section-title { color:rgba(255,255,255,.4); font-size:10px; font-weight:700;
            text-transform:uppercase; letter-spacing:1.5px; padding:14px 20px 6px; }
        .sidebar-nav .nav-link { color:rgba(255,255,255,.7); padding:10px 20px; display:flex; align-items:center;
            gap:12px; transition:all .2s; border-left:3px solid transparent; margin:2px 10px 2px 0;
            border-radius:0 12px 12px 0; font-size:13px; font-weight:500; }
        .sidebar-nav .nav-link:hover { color:#fff; background:rgba(255,255,255,.05); border-left-color:rgba(99,102,241,.5); }
        .sidebar-nav .nav-link.active { color:#fff; background:linear-gradient(90deg,rgba(99,102,241,.3),rgba(139,92,246,.1));
            border-left-color:var(--primary); font-weight:600; }
        .sidebar-nav .nav-link i { width:18px; text-align:center; font-size:14px; }

        /* Quota card in sidebar */
        .quota-card { margin:12px 16px; background:rgba(255,255,255,.07); border-radius:12px; padding:14px; }
        .quota-card-title { color:rgba(255,255,255,.6); font-size:10px; font-weight:700;
            text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; }
        .quota-item { margin-bottom:8px; }
        .quota-item-label { color:rgba(255,255,255,.8); font-size:11px; display:flex; justify-content:space-between; margin-bottom:3px; }
        .quota-progress { height:4px; border-radius:2px; background:rgba(255,255,255,.15); overflow:hidden; }
        .quota-progress-bar { height:100%; border-radius:2px; transition:width .5s; }

        .sidebar-footer { padding:14px 16px; border-top:1px solid rgba(255,255,255,.1); background:rgba(0,0,0,.2); }
        .sidebar-footer-user { display:flex; align-items:center; gap:10px; }
        .member-avatar { width:36px; height:36px; background:linear-gradient(135deg,var(--primary),var(--secondary));
            border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:14px; }

        /* Main */
        .main-content { margin-left:var(--sidebar-width); min-height:100vh; }
        .main-header { background:rgba(255,255,255,.85); backdrop-filter:blur(20px); height:64px;
            padding:0 28px; display:flex; align-items:center; justify-content:space-between;
            border-bottom:1px solid rgba(0,0,0,.05); position:sticky; top:0; z-index:100; }
        .content-area { padding:28px; }

        /* Cards */
        .card { border:none; border-radius:var(--border-radius); box-shadow:var(--shadow); background:#fff; overflow:hidden; }
        .card-header { background:#fff; border-bottom:1px solid rgba(0,0,0,.05); padding:18px 22px; font-weight:600; font-size:14px; color:#1E293B; }
        .card-body { padding:22px; }

        /* Stat cards */
        .stat-card { border-radius:var(--border-radius); padding:22px; color:#fff; position:relative; overflow:hidden; }
        .stat-card::before { content:''; position:absolute; right:-20px; bottom:-20px; width:100px; height:100px; background:rgba(255,255,255,.1); border-radius:50%; }
        .stat-card-icon { width:48px; height:48px; background:rgba(255,255,255,.2); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:14px; }
        .stat-card-value { font-size:30px; font-weight:800; line-height:1; }
        .stat-card-label { font-size:13px; opacity:.9; margin-top:6px; font-weight:500; }

        /* Gradients */
        .bg-gradient-primary { background:linear-gradient(135deg,#6366F1,#8B5CF6); }
        .bg-gradient-success { background:linear-gradient(135deg,#10B981,#34D399); }
        .bg-gradient-warning { background:linear-gradient(135deg,#F59E0B,#FBBF24); }
        .bg-gradient-info    { background:linear-gradient(135deg,#06B6D4,#22D3EE); }
        .bg-gradient-danger  { background:linear-gradient(135deg,#EF4444,#F87171); }

        /* Buttons */
        .btn { border-radius:10px; font-weight:600; padding:9px 18px; transition:all .2s; border:none; }
        .btn-primary { background:linear-gradient(135deg,var(--primary),var(--secondary)); color:#fff; box-shadow:0 4px 14px rgba(99,102,241,.3); }
        .btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,.4); color:#fff; }
        .btn-success { background:linear-gradient(135deg,var(--success),#34D399); color:#fff; }
        .btn-danger  { background:linear-gradient(135deg,var(--danger),#F87171); color:#fff; }
        .btn-outline-primary { border:2px solid var(--primary); color:var(--primary); background:transparent; }
        .btn-outline-primary:hover { background:var(--primary); color:#fff; }
        .btn-sm { padding:6px 12px; font-size:12px; border-radius:8px; }

        /* Forms */
        .form-control,.form-select { border-radius:10px; padding:10px 14px; border:2px solid #E2E8F0; font-size:13px; transition:all .2s; }
        .form-control:focus,.form-select:focus { border-color:var(--primary); box-shadow:0 0 0 4px rgba(99,102,241,.1); }
        .form-label { font-weight:600; margin-bottom:6px; color:#374151; font-size:13px; }

        /* Tables */
        .table thead th { font-weight:600; background:linear-gradient(135deg,#6366F1,#8B5CF6); color:#fff;
            border:none; padding:14px 18px; font-size:11px; text-transform:uppercase; letter-spacing:.8px; }
        .table td { vertical-align:middle; border-color:#F1F5F9; padding:14px 18px; font-size:13px; color:#475569; }

        /* Badges */
        .badge { font-weight:600; padding:5px 10px; border-radius:6px; font-size:10px; }
        .status-badge { padding:5px 12px; border-radius:16px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
        .status-success { background:rgba(16,185,129,.15); color:#047857; }
        .status-danger  { background:rgba(239,68,68,.15); color:#B91C1C; }
        .status-warning { background:rgba(245,158,11,.15); color:#B45309; }
        .status-info    { background:rgba(6,182,212,.15); color:#0E7490; }

        /* Alerts */
        .alert { border:none; border-radius:12px; padding:14px 18px; font-weight:500; }
        .alert-success { background:rgba(16,185,129,.1); color:#047857; border-left:4px solid var(--success); }
        .alert-danger  { background:rgba(239,68,68,.1); color:#B91C1C; border-left:4px solid var(--danger); }
        .alert-warning { background:rgba(245,158,11,.1); color:#B45309; border-left:4px solid var(--warning); }
        .alert-info    { background:rgba(6,182,212,.1); color:#0E7490; border-left:4px solid var(--info); }

        /* Progress */
        .progress { height:6px; border-radius:3px; background:#E2E8F0; }
        .progress-bar { border-radius:3px; }

        /* Modal */
        .modal-content { border:none; border-radius:18px; box-shadow:var(--shadow-xl); }
        .modal-header { border-bottom:1px solid #F1F5F9; padding:18px 22px; }
        .modal-body   { padding:22px; }
        .modal-footer { border-top:1px solid #F1F5F9; padding:14px 22px; }

        /* Empty state */
        .empty-state { padding:50px 20px; text-align:center; }
        .empty-state > i { font-size:3.5rem; background:linear-gradient(135deg,#E2E8F0,#CBD5E1); -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:16px; }
        .empty-state h5 { color:#64748B; }
        .empty-state p  { color:#94A3B8; }

        @keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        .fade-in { animation:fadeIn .3s ease-out; }

        @media(max-width:991px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.show { transform:translateX(0); }
            .main-content { margin-left:0; }
            .content-area { padding:16px; }
        }

        #toast-container { top:75px !important; right:16px !important; }
        #toast-container > div { min-width:300px !important; border-radius:14px !important; padding:16px 20px 16px 54px !important; font-size:14px !important; opacity:1 !important; }
        #toast-container > div:before { font-family:"Font Awesome 6 Free"; font-weight:900; position:absolute; left:16px; top:50%; transform:translateY(-50%); font-size:18px; }
        #toast-container > .toast-success { background:linear-gradient(135deg,#10B981,#059669) !important; }
        #toast-container > .toast-success:before { content:"\f058"; }
        #toast-container > .toast-error { background:linear-gradient(135deg,#EF4444,#DC2626) !important; }
        #toast-container > .toast-error:before { content:"\f06a"; }
        #toast-container > .toast-warning { background:linear-gradient(135deg,#F59E0B,#D97706) !important; }
        #toast-container > .toast-warning:before { content:"\f071"; }
    </style>
</head>
<body>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon"><i class="fas fa-robot"></i></div>
            <div class="sidebar-brand-text">
                AI AutoPost
                <small>Member Portal</small>
            </div>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section-title">Command Center</div>
            <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="/member/dashboard.php">
                <i class="fas fa-chart-pie"></i><span>Dashboard</span>
            </a>
            <a class="nav-link <?= $currentPage === 'queue_manager' ? 'active' : '' ?>" href="/member/queue_manager.php">
                <i class="fas fa-layer-group"></i><span>Queue Manager</span>
            </a>

            <div class="nav-section-title">Content Management</div>
            <a class="nav-link <?= in_array($currentPage, ['sites_list','sites_edit']) ? 'active' : '' ?>" href="/member/sites_list.php">
                <i class="fas fa-globe-asia"></i><span>เว็บไซต์</span>
            </a>
            <a class="nav-link <?= in_array($currentPage, ['articles_list','article_view']) ? 'active' : '' ?>" href="/member/articles_list.php">
                <i class="fas fa-file-alt"></i><span>บทความ</span>
            </a>
            <a class="nav-link <?= $currentPage === 'content_calendar' ? 'active' : '' ?>" href="/member/content_calendar.php">
                <i class="fas fa-calendar-alt"></i><span>Content Calendar</span>
            </a>
            <a class="nav-link <?= $currentPage === 'topics_list' ? 'active' : '' ?>" href="/member/topics_list.php">
                <i class="fas fa-tags"></i><span>หมวดหมู่</span>
            </a>
            <a class="nav-link <?= in_array($currentPage, ['keywords_list','keywords_import']) ? 'active' : '' ?>" href="/member/keywords_list.php">
                <i class="fas fa-key"></i><span>Keywords</span>
            </a>
            <a class="nav-link <?= $currentPage === 'keyword_research' ? 'active' : '' ?>" href="/member/keyword_research.php">
                <i class="fas fa-chart-bar"></i><span>Keyword Research</span>
            </a>

            <a class="nav-link <?= $currentPage === 'prompts' ? 'active' : '' ?>" href="/member/prompts.php">
                <i class="fas fa-pen-nib"></i><span>Prompts & SEO</span>
            </a>

            <div class="nav-section-title">การตั้งค่า</div>
            <a class="nav-link <?= $currentPage === 'settings_ai' ? 'active' : '' ?>" href="/member/settings_ai.php">
                <i class="fas fa-robot"></i><span>ตั้งค่า AI</span>
            </a>
            <a class="nav-link <?= $currentPage === 'settings_telegram' ? 'active' : '' ?>" href="/member/settings_telegram.php">
                <i class="fab fa-telegram"></i><span>Telegram</span>
            </a>


            <div class="nav-section-title">บัญชี</div>
            <a class="nav-link <?= $currentPage === 'billing' ? 'active' : '' ?>" href="/member/billing.php">
                <i class="fas fa-credit-card"></i><span>Plan & ชำระเงิน</span>
            </a>
            <a class="nav-link <?= in_array($currentPage, ['profile','security_2fa']) ? 'active' : '' ?>" href="/member/profile.php">
                <i class="fas fa-user-gear"></i><span>โปรไฟล์ & ความปลอดภัย</span>
            </a>
            <a class="nav-link text-danger" href="/member/logout.php">
                <i class="fas fa-right-from-bracket"></i><span>ออกจากระบบ</span>
            </a>
        </div>

        <!-- Quota Card -->
        <?php if ($quota && $quota['plan_name']): ?>
        <?php $isSidebarTrial = !empty($quota['is_trial']); ?>
        <div class="quota-card">
            <div class="quota-card-title">
                <?= $isSidebarTrial ? '🧪 Trial — ตลอดชีพ' : 'Quota เดือนนี้' ?>
            </div>

            <?php
            $items = [
                ['label'=>'บทความ', 'q'=>$quota['articles'], 'color'=>'#6366F1'],
                ['label'=>'Sites',  'q'=>$quota['sites'],    'color'=>'#10B981'],
                ['label'=>'Keywords','q'=>$quota['keywords'],'color'=>'#F59E0B'],
            ];
            foreach ($items as $item):
                $q = $item['q'];
                $pct = $q['unlimited'] ? 0 : ($q['limit'] > 0 ? min(100, round($q['used'] / $q['limit'] * 100)) : 0);
                $barColor = $pct >= 90 ? '#EF4444' : ($pct >= 70 ? '#F59E0B' : $item['color']);
            ?>
            <div class="quota-item">
                <div class="quota-item-label">
                    <span><?= $item['label'] ?></span>
                    <span>
                        <?= $q['unlimited'] ? '<span style="color:#10B981">∞</span>' : "{$q['used']}/{$q['limit']}" ?>
                    </span>
                </div>
                <?php if (!$q['unlimited']): ?>
                <div class="quota-progress">
                    <div class="quota-progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div style="margin-top:10px;color:rgba(255,255,255,.5);font-size:10px;">
                <i class="fas fa-clock me-1"></i>
                <?php if ($isSidebarTrial): ?>
                    <a href="/member/billing.php" style="color:rgba(255,255,255,.6);">อัพเกรดแพ็คเกจ &rarr;</a>
                <?php elseif ($quota['days_left'] > 0): ?>
                    Plan หมด <?= $quota['days_left'] ?> วัน
                <?php else: ?>
                    <span style="color:#EF4444;">Plan หมดอายุแล้ว</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="sidebar-footer-user">
                <div class="member-avatar"><?= strtoupper(substr($currentMember['username'], 0, 1)) ?></div>
                <div>
                    <div style="color:#fff;font-size:12px;font-weight:600;"><?= sanitize($currentMember['username']) ?></div>
                    <div style="color:rgba(255,255,255,.5);font-size:10px;"><?= sanitize($currentMember['email']) ?></div>
                </div>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <header class="main-header">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link d-lg-none p-0" onclick="document.getElementById('sidebar').classList.toggle('show')">
                    <i class="fas fa-bars fa-lg text-dark"></i>
                </button>
                <h6 class="mb-0 fw-bold" style="color:#1E293B;"><?= $pageTitle ?? 'Dashboard' ?></h6>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if ($quota && $quota['plan_name']): ?>
                <span class="badge bg-gradient-primary"><?= sanitize($quota['plan_name']) ?></span>
                <?php else: ?>
                <a href="/member/billing.php" class="btn btn-sm btn-warning">
                    <i class="fas fa-exclamation-triangle me-1"></i>ยังไม่มี Plan
                </a>
                <?php endif; ?>
                <div class="member-avatar" style="width:36px;height:36px;font-size:13px;"><?= strtoupper(substr($currentMember['username'], 0, 1)) ?></div>
            </div>
        </header>

        <div class="content-area fade-in">
