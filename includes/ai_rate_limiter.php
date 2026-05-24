<?php
/**
 * AI AutoPost SEO System - AI Rate Limiter
 * =========================================
 * ระบบจำกัดการใช้งาน AI แบบ Real-time
 * ป้องกันการใช้ API เกินโควต้า
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class AIRateLimiter {
    // Rate limits per provider (requests per minute)
    private $rateLimits = [
        'claude' => 50,
        'openai' => 60,
        'gemini' => 60,
        'deepseek' => 60,
        'openrouter' => 200
    ];

    // Token limits per request
    private $tokenLimits = [
        'claude' => 100000,
        'openai' => 128000,
        'gemini' => 1000000,
        'deepseek' => 64000,
        'openrouter' => 128000
    ];

    /**
     * ตรวจสอบว่าสามารถทำ request ได้ไหม
     */
    public function canRequest(string $provider): array {
        // Check rate limit
        $rateCheck = $this->checkRateLimit($provider);
        if (!$rateCheck['allowed']) {
            return $rateCheck;
        }

        // Check daily usage limit
        $usageCheck = $this->checkDailyLimit($provider);
        if (!$usageCheck['allowed']) {
            return $usageCheck;
        }

        // Check monthly budget
        $budgetCheck = $this->checkMonthlyBudget($provider);
        if (!$budgetCheck['allowed']) {
            return $budgetCheck;
        }

        return ['allowed' => true];
    }

    /**
     * ตรวจสอบ Rate Limit (requests per minute)
     */
    private function checkRateLimit(string $provider): array {
        $limit = $this->rateLimits[$provider] ?? 60;
        $windowStart = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $count = db()->fetchColumn("
            SELECT COUNT(*) FROM ai_usage_log
            WHERE provider = ? AND created_at >= ?
        ", [$provider, $windowStart]);

        if ($count >= $limit) {
            $waitTime = $this->getWaitTime($provider);
            return [
                'allowed' => false,
                'reason' => 'rate_limit',
                'message' => "Rate limit exceeded for {$provider}. Wait {$waitTime} seconds.",
                'wait_seconds' => $waitTime,
                'current' => $count,
                'limit' => $limit
            ];
        }

        return ['allowed' => true, 'current' => $count, 'limit' => $limit];
    }

    /**
     * ตรวจสอบ Daily Usage Limit
     */
    private function checkDailyLimit(string $provider): array {
        $settings = db()->fetchOne("
            SELECT daily_limit, current_daily_usage
            FROM ai_settings
            WHERE provider_name = ?
        ", [$provider]);

        if (!$settings || !$settings['daily_limit']) {
            return ['allowed' => true];
        }

        if ($settings['current_daily_usage'] >= $settings['daily_limit']) {
            return [
                'allowed' => false,
                'reason' => 'daily_limit',
                'message' => "Daily limit reached for {$provider}",
                'current' => $settings['current_daily_usage'],
                'limit' => $settings['daily_limit']
            ];
        }

        return ['allowed' => true];
    }

    /**
     * ตรวจสอบ Monthly Budget
     */
    private function checkMonthlyBudget(string $provider): array {
        $settings = db()->fetchOne("
            SELECT monthly_limit, current_usage
            FROM ai_settings
            WHERE provider_name = ?
        ", [$provider]);

        if (!$settings || !$settings['monthly_limit']) {
            return ['allowed' => true];
        }

        if ($settings['current_usage'] >= $settings['monthly_limit']) {
            return [
                'allowed' => false,
                'reason' => 'monthly_limit',
                'message' => "Monthly budget reached for {$provider}",
                'current' => $settings['current_usage'],
                'limit' => $settings['monthly_limit']
            ];
        }

        return ['allowed' => true];
    }

    /**
     * คำนวณเวลารอ
     */
    private function getWaitTime(string $provider): int {
        $oldestRequest = db()->fetchColumn("
            SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW())
            FROM ai_usage_log
            WHERE provider = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ", [$provider]);

        return max(0, 60 - ($oldestRequest ?? 0));
    }

    /**
     * บันทึกการใช้งาน
     */
    public function logUsage(string $provider, int $inputTokens, int $outputTokens, float $cost = 0, array $metadata = []): void {
        $totalTokens = $inputTokens + $outputTokens;

        // Log to usage table
        db()->insert('ai_usage_log', [
            'provider' => $provider,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $cost,
            'metadata' => json_encode($metadata),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Update provider usage counter
        db()->query("
            UPDATE ai_settings
            SET current_usage = current_usage + ?,
                current_daily_usage = current_daily_usage + ?
            WHERE provider_name = ?
        ", [$totalTokens, $totalTokens, $provider]);
    }

    /**
     * คำนวณค่าใช้จ่ายโดยประมาณ
     */
    public function estimateCost(string $provider, int $inputTokens, int $outputTokens): float {
        // Pricing per 1M tokens (approximate)
        $pricing = [
            'claude' => ['input' => 3.00, 'output' => 15.00],
            'openai' => ['input' => 2.50, 'output' => 10.00],
            'gemini' => ['input' => 0.075, 'output' => 0.30],
            'deepseek' => ['input' => 0.14, 'output' => 0.28],
            'openrouter' => ['input' => 1.00, 'output' => 3.00] // varies by model
        ];

        $rates = $pricing[$provider] ?? ['input' => 1.00, 'output' => 3.00];

        $inputCost = ($inputTokens / 1000000) * $rates['input'];
        $outputCost = ($outputTokens / 1000000) * $rates['output'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * ดึงสถิติการใช้งาน
     */
    public function getUsageStats(string $period = 'today'): array {
        $dateFilter = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'yesterday' => "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        return db()->fetchAll("
            SELECT
                provider,
                COUNT(*) as requests,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(estimated_cost) as total_cost,
                AVG(total_tokens) as avg_tokens_per_request
            FROM ai_usage_log
            WHERE {$dateFilter}
            GROUP BY provider
            ORDER BY total_tokens DESC
        ");
    }

    /**
     * ดึงสถิติรายชั่วโมง
     */
    public function getHourlyStats(string $provider = null): array {
        $where = "created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $params = [];

        if ($provider) {
            $where .= " AND provider = ?";
            $params[] = $provider;
        }

        return db()->fetchAll("
            SELECT
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour,
                provider,
                COUNT(*) as requests,
                SUM(total_tokens) as tokens,
                SUM(estimated_cost) as cost
            FROM ai_usage_log
            WHERE {$where}
            GROUP BY hour, provider
            ORDER BY hour DESC
        ", $params);
    }

    /**
     * รอจนกว่าจะสามารถ request ได้
     */
    public function waitForAvailability(string $provider, int $maxWait = 120): array {
        $startTime = time();

        while (true) {
            $check = $this->canRequest($provider);

            if ($check['allowed']) {
                return ['success' => true, 'waited' => time() - $startTime];
            }

            if ($check['reason'] !== 'rate_limit') {
                return ['success' => false, 'error' => $check['message']];
            }

            $elapsed = time() - $startTime;
            if ($elapsed >= $maxWait) {
                return ['success' => false, 'error' => "Timeout waiting for rate limit"];
            }

            $sleepTime = min($check['wait_seconds'] ?? 5, $maxWait - $elapsed);
            sleep($sleepTime);
        }
    }

    /**
     * ดึง Provider ที่ดีที่สุด (มีโควต้าเหลือมากที่สุด)
     */
    public function getBestProvider(): ?string {
        $providers = db()->fetchAll("
            SELECT
                provider_name,
                is_enabled,
                last_test_status,
                monthly_limit,
                current_usage,
                COALESCE(monthly_limit, 999999999) - current_usage as remaining
            FROM ai_settings
            WHERE is_enabled = 1 AND last_test_status = 'success'
            ORDER BY remaining DESC
        ");

        foreach ($providers as $provider) {
            $check = $this->canRequest($provider['provider_name']);
            if ($check['allowed']) {
                return $provider['provider_name'];
            }
        }

        return null;
    }

    /**
     * Reset daily usage (เรียกตอนเที่ยงคืน)
     */
    public function resetDailyUsage(): void {
        db()->query("UPDATE ai_settings SET current_daily_usage = 0");
    }

    /**
     * Reset monthly usage (เรียกวันที่ 1)
     */
    public function resetMonthlyUsage(): void {
        db()->query("UPDATE ai_settings SET current_usage = 0");
    }
}

/**
 * Helper function
 */
function rateLimiter(): AIRateLimiter {
    static $instance = null;
    if ($instance === null) {
        $instance = new AIRateLimiter();
    }
    return $instance;
}
