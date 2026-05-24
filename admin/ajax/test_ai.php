<?php
/**
 * AI AutoPost SEO System - Test AI Connection AJAX
 * =================================================
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_client.php';

// Require authentication
auth()->requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Validate CSRF
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
}

$providerId = (int)($_POST['provider_id'] ?? 0);

if (!$providerId) {
    jsonResponse(['success' => false, 'message' => 'Provider ID required']);
}

try {
    // Get provider settings
    $provider = db()->fetchOne("SELECT * FROM ai_settings WHERE id = ?", [$providerId]);

    if (!$provider) {
        jsonResponse(['success' => false, 'message' => 'Provider not found']);
    }

    // Check if API key is set
    if (empty($provider['api_key'])) {
        // Update test status
        db()->update('ai_settings', [
            'last_test_status' => 'failed',
            'last_test_time' => date('Y-m-d H:i:s'),
            'last_test_message' => 'API Key not configured'
        ], 'id = ?', [$providerId]);

        jsonResponse(['success' => false, 'message' => 'API Key ยังไม่ได้ตั้งค่า']);
    }

    // Create AI client with this provider's settings
    $client = new AIClient($provider);

    // Test connection
    $result = $client->testConnection();

    // Update test status in database
    db()->update('ai_settings', [
        'last_test_status' => $result['success'] ? 'success' : 'failed',
        'last_test_time' => date('Y-m-d H:i:s'),
        'last_test_message' => $result['message']
    ], 'id = ?', [$providerId]);

    // Log the test
    logEvent(
        $result['success'] ? 'info' : 'warning',
        'ai_test',
        "AI connection test: {$provider['provider_name']}",
        ['provider_id' => $providerId, 'success' => $result['success']]
    );

    jsonResponse($result);

} catch (Exception $e) {
    // Update test status as failed
    db()->update('ai_settings', [
        'last_test_status' => 'failed',
        'last_test_time' => date('Y-m-d H:i:s'),
        'last_test_message' => $e->getMessage()
    ], 'id = ?', [$providerId]);

    logEvent('error', 'ai_test', 'AI test failed', [
        'provider_id' => $providerId,
        'error' => $e->getMessage()
    ]);

    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
