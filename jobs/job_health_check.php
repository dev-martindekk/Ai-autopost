<?php
/**
 * AI AutoPost SEO System - Daily Health Check Job
 * ================================================
 * Runs at midnight Thai time (Asia/Bangkok)
 * Checks connectivity to all services: Database, AI, WordPress sites, Telegram
 * Logs results and sends Telegram alert if any service is down
 *
 * Schedule: 0 0 * * * (Every day at midnight)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== Daily Health Check ===\n";
echo "Time: " . date('Y-m-d H:i:s') . " (Asia/Bangkok)\n\n";

$results = [];
$hasErrors = false;

// ─── 1. Database Connection ─────────────────────────────────────────
echo "[1/4] Checking Database...\n";
try {
    $siteCount = db()->fetchColumn("SELECT COUNT(*) FROM sites");
    $articleCount = db()->fetchColumn("SELECT COUNT(*) FROM articles");
    $results['database'] = [
        'status' => 'ok',
        'message' => "เชื่อมต่อสำเร็จ (sites: {$siteCount}, articles: {$articleCount})"
    ];
    echo "  OK - {$results['database']['message']}\n";
} catch (Exception $e) {
    $results['database'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    $hasErrors = true;
    echo "  ERROR - {$e->getMessage()}\n";
}

// ─── 2. AI Provider Connection ──────────────────────────────────────
echo "[2/4] Checking AI Provider...\n";
try {
    require_once __DIR__ . '/../includes/ai_client.php';
    $ai = new AIClient();
    $response = $ai->testConnection();
    if ($response['success']) {
        $model = $response['model'] ?? 'unknown';
        $results['ai'] = [
            'status' => 'ok',
            'message' => "AI ทำงานปกติ (model: {$model})"
        ];
        echo "  OK - {$results['ai']['message']}\n";
    } else {
        $errorMsg = $response['message'] ?? 'ไม่สามารถเชื่อมต่อได้';
        $results['ai'] = [
            'status' => 'error',
            'message' => $errorMsg
        ];
        $hasErrors = true;
        echo "  ERROR - {$errorMsg}\n";
    }
} catch (Exception $e) {
    $results['ai'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    $hasErrors = true;
    echo "  ERROR - {$e->getMessage()}\n";
}

// ─── 3. WordPress Sites ────────────────────────────────────────────
echo "[3/4] Checking WordPress Sites...\n";
try {
    require_once __DIR__ . '/../includes/wordpress_client.php';
    $sites = db()->fetchAll("SELECT * FROM sites WHERE posting_enabled = 1 ORDER BY name");

    if (empty($sites)) {
        $results['wordpress'] = [
            'status' => 'warning',
            'message' => 'ไม่มีเว็บไซต์ที่เปิดใช้งาน'
        ];
        echo "  WARNING - ไม่มีเว็บไซต์ที่เปิดใช้งาน\n";
    } else {
        $results['wordpress'] = [
            'status' => 'ok',
            'sites' => []
        ];

        foreach ($sites as $site) {
            try {
                $wp = new WordPressClient($site);
                $info = $wp->testConnection();
                if ($info['success']) {
                    $siteName = $info['site_name'] ?? $site['name'];
                    $results['wordpress']['sites'][$site['name']] = [
                        'status' => 'ok',
                        'message' => "เชื่อมต่อสำเร็จ ({$siteName})"
                    ];
                    echo "  OK - {$site['name']}: เชื่อมต่อสำเร็จ\n";
                } elseif (!empty($info['mdes_blocked'])) {
                    $results['wordpress']['sites'][$site['name']] = [
                        'status' => 'mdes_blocked',
                        'message' => $info['message'] ?? 'เว็บไซต์ถูกบล็อกโดย MDES'
                    ];
                    $results['wordpress']['status'] = 'error';
                    $hasErrors = true;
                    echo "  MDES BLOCKED - {$site['name']}: ถูกบล็อกโดยกระทรวงดิจิทัลฯ (ต้องใช้ VPN)\n";
                } else {
                    $errorMsg = $info['message'] ?? 'ไม่สามารถเชื่อมต่อได้';
                    $results['wordpress']['sites'][$site['name']] = [
                        'status' => 'error',
                        'message' => $errorMsg
                    ];
                    $results['wordpress']['status'] = 'error';
                    $hasErrors = true;
                    echo "  ERROR - {$site['name']}: {$errorMsg}\n";
                }
            } catch (Exception $e) {
                $results['wordpress']['sites'][$site['name']] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $results['wordpress']['status'] = 'error';
                $hasErrors = true;
                echo "  ERROR - {$site['name']}: {$e->getMessage()}\n";
            }
        }
    }
} catch (Exception $e) {
    $results['wordpress'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    $hasErrors = true;
    echo "  ERROR - {$e->getMessage()}\n";
}

// ─── 4. Telegram ───────────────────────────────────────────────────
echo "[4/4] Checking Telegram...\n";
try {
    require_once __DIR__ . '/../includes/telegram_client.php';
    $tg = telegram();
    if ($tg->isEnabled()) {
        $results['telegram'] = [
            'status' => 'ok',
            'message' => 'Telegram พร้อมใช้งาน'
        ];
        echo "  OK - Telegram พร้อมใช้งาน\n";
    } else {
        $results['telegram'] = [
            'status' => 'warning',
            'message' => 'Telegram ยังไม่ได้ตั้งค่า'
        ];
        echo "  WARNING - Telegram ยังไม่ได้ตั้งค่า\n";
    }
} catch (Exception $e) {
    $results['telegram'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    $hasErrors = true;
    echo "  ERROR - {$e->getMessage()}\n";
}

// ─── Save results to system_logs ────────────────────────────────────
$overallStatus = $hasErrors ? 'error' : 'ok';
echo "\n=== Overall Status: " . strtoupper($overallStatus) . " ===\n";

logEvent(
    $hasErrors ? 'error' : 'info',
    'health_check',
    'Daily health check: ' . ($hasErrors ? 'ISSUES FOUND' : 'ALL OK'),
    $results
);

// ─── Save last check result to system_settings ──────────────────────
setSetting('last_health_check', json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => $overallStatus,
    'results' => $results
]));

// ─── Send Telegram notification ─────────────────────────────────────
try {
    require_once __DIR__ . '/../includes/telegram_client.php';
    $tg = telegram();

    if ($tg->isEnabled()) {
        $date = date('d/m/Y H:i');

        if ($hasErrors) {
            // Send alert for errors
            $msg = "🔴 Health Check — มีปัญหา!\n";
            $msg .= "📅 {$date}\n\n";

            foreach ($results as $service => $result) {
                if ($service === 'wordpress' && isset($result['sites'])) {
                    foreach ($result['sites'] as $siteName => $siteResult) {
                        if ($siteResult['status'] === 'ok') {
                            $icon = '✅';
                        } elseif ($siteResult['status'] === 'mdes_blocked') {
                            $icon = '🚫';
                        } else {
                            $icon = '❌';
                        }
                        $msg .= "{$icon} WP: {$siteName} — {$siteResult['message']}\n";
                    }
                } else {
                    $status = $result['status'] ?? 'unknown';
                    $icon = $status === 'ok' ? '✅' : ($status === 'warning' ? '⚠️' : '❌');
                    $label = ucfirst($service);
                    $message = $result['message'] ?? '';
                    $msg .= "{$icon} {$label} — {$message}\n";
                }
            }

            $tg->sendMessage($msg);
            echo "\nTelegram alert sent.\n";
        } else {
            // Send daily OK report
            $msg = "✅ Health Check — ระบบปกติ\n";
            $msg .= "📅 {$date}\n\n";
            $msg .= "✅ Database OK\n";
            $msg .= "✅ AI Provider OK\n";

            if (isset($results['wordpress']['sites'])) {
                $siteCount = count($results['wordpress']['sites']);
                $msg .= "✅ WordPress ({$siteCount} sites) OK\n";
            }

            if (($results['telegram']['status'] ?? '') === 'ok') {
                $msg .= "✅ Telegram OK\n";
            }

            $tg->sendMessage($msg);
            echo "\nTelegram daily report sent.\n";
        }
    }
} catch (Exception $e) {
    echo "Failed to send Telegram notification: {$e->getMessage()}\n";
}

echo "\nDone.\n";
