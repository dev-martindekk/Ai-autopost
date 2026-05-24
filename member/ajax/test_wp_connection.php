<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/member_auth.php';
require_once __DIR__ . '/../../includes/wordpress_client.php';

header('Content-Type: application/json');

if (!memberAuth()->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$memberId = (int)memberAuth()->getMember()['id'];
$siteId   = (int)($_POST['site_id'] ?? 0);
$baseUrl  = rtrim(trim($_POST['base_url'] ?? ''), '/');
$wpUser   = trim($_POST['wp_user'] ?? '');
$wpPass   = trim($_POST['wp_pass'] ?? '');

try {
    if ($siteId > 0) {
        $site = db()->fetchOne(
            "SELECT * FROM sites WHERE id=? AND owner_type='member' AND owner_id=?",
            [$siteId, $memberId]
        );
        if (!$site) {
            echo json_encode(['success' => false, 'message' => 'Site not found']);
            exit;
        }

        $siteConfig = [
            'id'              => $siteId,
            'base_url'        => $site['base_url'],
            'wp_api_url'      => $site['wp_api_url'],
            'wp_user'         => $wpUser ?: $site['wp_user'],
            'wp_app_password' => $wpPass ? encrypt($wpPass) : $site['wp_app_password'],
        ];
    } else {
        if (empty($baseUrl) || empty($wpUser)) {
            echo json_encode(['success' => false, 'message' => 'กรุณากรอก URL และ Username']);
            exit;
        }

        $siteConfig = [
            'id'              => null,
            'base_url'        => $baseUrl,
            'wp_api_url'      => $baseUrl . '/wp-json',
            'wp_user'         => $wpUser,
            'wp_app_password' => $wpPass ? encrypt($wpPass) : '',
        ];
    }

    $wp     = new WordPressClient($siteConfig);
    $result = $wp->testConnection();

    if ($siteId > 0) {
        db()->update('sites', [
            'last_test_status'   => $result['success'] ? 'success' : 'failed',
            'last_test_time'     => date('Y-m-d H:i:s'),
            'last_test_message'  => $result['message'],
            'is_mdes_blocked'    => !empty($result['mdes_blocked']) ? 1 : 0,
        ], 'id = ?', [$siteId]);
    }

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
