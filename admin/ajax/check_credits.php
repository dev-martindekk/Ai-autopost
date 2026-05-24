<?php
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
    jsonResponse(['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า API Key']);
}

try {
    $apiKey = decrypt($provider['api_key']);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'ไม่สามารถถอดรหัส API Key']);
}

$name = $provider['provider_name'];

if ($name === 'openrouter') {
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ];

    // ดึงข้อมูล key (usage + limit)
    $ch = curl_init('https://openrouter.ai/api/v1/auth/key');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) {
        jsonResponse(['success' => false, 'message' => 'OpenRouter ตอบกลับ HTTP ' . $httpCode]);
    }

    $data = json_decode($body, true)['data'] ?? null;
    if (!$data) {
        jsonResponse(['success' => false, 'message' => 'ไม่สามารถอ่านข้อมูลจาก OpenRouter']);
    }

    $usage     = (float)($data['usage'] ?? 0);
    $limit     = isset($data['limit']) && $data['limit'] !== null ? (float)$data['limit'] : null;
    $isFree    = (bool)($data['is_free_tier'] ?? false);

    // สำหรับบัญชี Prepaid (limit = null) ดึงยอดเงินจาก /credits
    $balance   = null;
    $remaining = null;
    if ($limit !== null) {
        $remaining = max(0, $limit - $usage);
    } else {
        $ch2 = curl_init('https://openrouter.ai/api/v1/credits');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body2     = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($httpCode2 === 200 && $body2) {
            $credits = json_decode($body2, true)['data'] ?? null;
            if ($credits !== null) {
                // total_credits = เครดิตที่เติมไป, total_usage = ใช้ไปแล้ว
                $totalCredits = (float)($credits['total_credits'] ?? 0);
                $totalUsage   = (float)($credits['total_usage']   ?? $usage);
                $balance      = max(0, $totalCredits - $totalUsage);
                $remaining    = $balance;
            }
        }
    }

    jsonResponse([
        'success'      => true,
        'provider'     => 'openrouter',
        'usage'        => round($usage, 6),
        'limit'        => $limit !== null ? round($limit, 2) : null,
        'remaining'    => $remaining !== null ? round($remaining, 6) : null,
        'is_free_tier' => $isFree,
        'is_prepaid'   => $limit === null && !$isFree,
        'label'        => $data['label'] ?? '',
        'rate_limit'   => $data['rate_limit'] ?? null,
    ]);

} elseif ($name === 'openai') {
    // Try billing subscription endpoint
    $ch = curl_init('https://api.openai.com/v1/dashboard/billing/subscription');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) {
        jsonResponse(['success' => false, 'message' => 'OpenAI API ไม่รองรับการเช็คเครดิตสำหรับบัญชีนี้ (HTTP ' . $httpCode . ')']);
    }

    $data = json_decode($body, true);
    $hardLimit   = ($data['hard_limit_usd'] ?? null);
    $softLimit   = ($data['soft_limit_usd'] ?? null);

    // Fetch usage for current billing period
    $now    = date('Y-m-d');
    $start  = date('Y-m-01');
    $ch2 = curl_init("https://api.openai.com/v1/dashboard/billing/usage?start_date={$start}&end_date={$now}");
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body2     = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    $usageCents = 0;
    if ($httpCode2 === 200 && $body2) {
        $usageData  = json_decode($body2, true);
        $usageCents = (float)($usageData['total_usage'] ?? 0);
    }

    $usageUsd    = round($usageCents / 100, 4);
    $remaining   = $hardLimit !== null ? max(0, (float)$hardLimit - $usageUsd) : null;

    jsonResponse([
        'success'    => true,
        'provider'   => 'openai',
        'usage'      => $usageUsd,
        'limit'      => $hardLimit,
        'soft_limit' => $softLimit,
        'remaining'  => $remaining,
        'period'     => $start . ' – ' . $now,
    ]);

} else {
    jsonResponse([
        'success' => false,
        'message' => 'Provider นี้ไม่รองรับการเช็คเครดิตผ่าน API',
    ]);
}
