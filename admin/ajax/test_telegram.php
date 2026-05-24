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
$chatId = trim($_POST['chat_id'] ?? '');

try {
    // Get existing settings if bot_token not provided
    if (empty($botToken)) {
        $settings = db()->fetchOne("SELECT bot_token FROM telegram_settings WHERE setting_name = 'default'");
        if ($settings && !empty($settings['bot_token'])) {
            $botToken = $settings['bot_token'];
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
