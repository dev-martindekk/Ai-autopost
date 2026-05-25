<?php
/**
 * AI AutoPost SEO System - Test Telegram AJAX
 * ============================================
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/telegram_client.php';

// Require authentication
auth()->requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Validate CSRF
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
}

$botToken = trim($_POST['bot_token'] ?? '');
$chatId   = trim($_POST['chat_id'] ?? '');

try {
    // Fallback: load saved settings for this owner
    if (empty($botToken) || empty($chatId)) {
        $ownerData = auth()->getOwnerData();
        $saved = db()->fetchOne(
            "SELECT bot_token, chat_id FROM telegram_settings
             WHERE setting_name = 'default' AND owner_type = ? AND owner_id = ?",
            [$ownerData['owner_type'], $ownerData['owner_id']]
        );
        if ($saved) {
            if (empty($botToken)) $botToken = $saved['bot_token'] ?? '';
            if (empty($chatId))   $chatId   = $saved['chat_id']   ?? '';
        }
    }

    if (empty($botToken) || empty($chatId)) {
        jsonResponse(['success' => false, 'message' => 'กรุณากรอก Bot Token และ Chat ID']);
    }

    // Create telegram client with provided settings
    $telegram = new TelegramClient([
        'bot_token' => $botToken,
        'chat_id' => $chatId,
        'is_enabled' => true
    ]);

    // Test connection
    $result = $telegram->testConnection();

    // Update test status in database
    $updateData = [
        'last_test_status' => $result['success'] ? 'success' : 'failed',
        'last_test_time' => date('Y-m-d H:i:s')
    ];

    $existing = db()->fetchOne("SELECT id FROM telegram_settings WHERE setting_name = 'default'");
    if ($existing) {
        db()->update('telegram_settings', $updateData, 'setting_name = ?', ['default']);
    }

    // Log the test
    logEvent(
        $result['success'] ? 'info' : 'warning',
        'telegram',
        'Telegram connection test',
        ['success' => $result['success'], 'message' => $result['message']]
    );

    jsonResponse($result);

} catch (Exception $e) {
    logEvent('error', 'telegram', 'Telegram test failed', ['error' => $e->getMessage()]);

    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
