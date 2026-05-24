<?php
/**
 * AI AutoPost SEO System - Refresh Available Models AJAX
 * ======================================================
 * Fetches latest models from provider APIs and detects new ones
 */

require_once __DIR__ . '/../../includes/auth.php';

auth()->requireAuth();
header('Content-Type: application/json');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
}

$providerId = (int)($_POST['provider_id'] ?? 0);
if (!$providerId) {
    jsonResponse(['success' => false, 'message' => 'Provider ID required']);
}

$provider = db()->fetchOne("SELECT * FROM ai_settings WHERE id = ?", [$providerId]);
if (!$provider) {
    jsonResponse(['success' => false, 'message' => 'Provider not found']);
}

if (empty($provider['api_key'])) {
    jsonResponse(['success' => false, 'message' => 'API Key ยังไม่ได้ตั้งค่า']);
}

try {
    $apiKey = decrypt($provider['api_key']);
    $fetchedModels = [];

    switch ($provider['provider_name']) {
        case 'openrouter':
            $ch = curl_init('https://openrouter.ai/api/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}"],
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                jsonResponse(['success' => false, 'message' => "API error: HTTP {$httpCode}"]);
            }

            $data = json_decode($response, true);
            if (!empty($data['data'])) {
                // Filter for popular/relevant models from major providers
                $allowedPrefixes = ['anthropic/', 'openai/', 'google/', 'meta-llama/', 'deepseek/', 'mistralai/'];
                foreach ($data['data'] as $model) {
                    $id = $model['id'] ?? '';
                    foreach ($allowedPrefixes as $prefix) {
                        if (str_starts_with($id, $prefix)) {
                            $fetchedModels[] = [
                                'id' => $id,
                                'name' => $model['name'] ?? $id,
                                'context_length' => $model['context_length'] ?? 0,
                                'pricing' => $model['pricing'] ?? [],
                            ];
                            break;
                        }
                    }
                }
            }
            break;

        case 'openai':
            $ch = curl_init('https://api.openai.com/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}"],
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                jsonResponse(['success' => false, 'message' => "API error: HTTP {$httpCode}"]);
            }

            $data = json_decode($response, true);
            if (!empty($data['data'])) {
                $chatModels = ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo', 'o1', 'o1-mini', 'o1-preview', 'o3-mini'];
                foreach ($data['data'] as $model) {
                    $id = $model['id'] ?? '';
                    foreach ($chatModels as $cm) {
                        if ($id === $cm || str_starts_with($id, $cm . '-')) {
                            $fetchedModels[] = ['id' => $id, 'name' => $id];
                            break;
                        }
                    }
                }
            }
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'ยังไม่รองรับการดึง model อัตโนมัติสำหรับ provider นี้']);
    }

    if (empty($fetchedModels)) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบ models จาก API']);
    }

    // Sort by name
    usort($fetchedModels, fn($a, $b) => strcmp($a['id'], $b['id']));

    // Current known models
    $knownModels = json_decode($provider['known_models'] ?? '[]', true) ?: [];
    $currentModels = json_decode($provider['available_models'] ?? '[]', true) ?: [];

    // Detect new models (in fetched but not in known)
    $fetchedIds = array_column($fetchedModels, 'id');
    $newModelIds = array_diff($fetchedIds, $knownModels);

    // Merge: keep current selected + add new ones
    $updatedModels = array_unique(array_merge($currentModels, $fetchedIds));
    sort($updatedModels);

    // Put default model first
    $defaultModel = $provider['default_model'];
    if (in_array($defaultModel, $updatedModels)) {
        $updatedModels = array_diff($updatedModels, [$defaultModel]);
        array_unshift($updatedModels, $defaultModel);
    }

    // Update DB
    db()->update('ai_settings', [
        'available_models' => json_encode(array_values($updatedModels)),
        'known_models' => json_encode(array_values($fetchedIds)),
    ], 'id = ?', [$providerId]);

    jsonResponse([
        'success' => true,
        'message' => 'อัปเดตรายการ models สำเร็จ',
        'total_models' => count($updatedModels),
        'new_models' => array_values($newModelIds),
        'new_count' => count($newModelIds),
        'models' => $fetchedModels,
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()]);
}
