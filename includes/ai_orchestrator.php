<?php
/**
 * AI AutoPost SEO System - AI Orchestrator
 * =========================================
 * ระบบจัดการ AI อัจฉริยะ - กระจายงานตามความซับซ้อน
 *
 * Features:
 * - Task-based model selection (เลือก model ตามประเภทงาน)
 * - Auto-fallback (สลับ model อัตโนมัติเมื่อล่ม)
 * - Cost optimization (ประหยัดค่าใช้จ่าย)
 * - Rate limit aware (กระจายงานเมื่อติด limit)
 * - Usage tracking (ติดตามการใช้งาน)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class AIOrchestrator {

    // OpenRouter Models - เรียงตามราคา (ถูก → แพง)
    private $models = [
        // Tier 1: Ultra Fast & Cheap - งานง่ายมาก
        'tier1_fast' => [
            'google/gemini-2.0-flash-001' => ['input' => 0.10, 'output' => 0.40, 'context' => 1000000],
            'meta-llama/llama-3.1-8b-instruct' => ['input' => 0.06, 'output' => 0.06, 'context' => 131072],
            'google/gemini-flash-1.5-8b' => ['input' => 0.0375, 'output' => 0.15, 'context' => 1000000],
        ],

        // Tier 2: Fast & Affordable - งานง่าย
        'tier2_balanced' => [
            'anthropic/claude-3-haiku' => ['input' => 0.25, 'output' => 1.25, 'context' => 200000],
            'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'context' => 128000],
            'google/gemini-flash-1.5' => ['input' => 0.075, 'output' => 0.30, 'context' => 1000000],
        ],

        // Tier 3: Smart & Capable - งานปานกลาง
        'tier3_smart' => [
            'anthropic/claude-sonnet-4.5' => ['input' => 3.00, 'output' => 15.00, 'context' => 200000],
            'anthropic/claude-3.5-sonnet' => ['input' => 3.00, 'output' => 15.00, 'context' => 200000],
            'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'context' => 128000],
            'google/gemini-pro-1.5' => ['input' => 1.25, 'output' => 5.00, 'context' => 2000000],
        ],

        // Tier 4: Most Powerful - งานซับซ้อน
        'tier4_powerful' => [
            'anthropic/claude-sonnet-4' => ['input' => 3.00, 'output' => 15.00, 'context' => 200000],
            'openai/gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00, 'context' => 128000],
            'anthropic/claude-3-opus' => ['input' => 15.00, 'output' => 75.00, 'context' => 200000],
        ],
    ];

    // Task → Tier mapping
    // 🎯 QUALITY MODE: อัพเกรดทุก task เป็น Tier 3/4 เน้นคุณภาพสูงสุด
    private $taskMapping = [
        // Tier 1: งานง่ายมาก - ไม่ต้องคิดเยอะ (ใช้แค่ test/simple)
        'test_connection' => 'tier1_fast',
        'simple_response' => 'tier1_fast',

        // Tier 2: ไม่ใช้แล้ว - ย้ายไป Tier 3 หมด (QUALITY MODE)
        // 'generate_title' => 'tier2_balanced',  // ย้ายไป tier3
        // 'keyword_suggest' => 'tier2_balanced', // ย้ายไป tier3

        // Tier 3: งานทั่วไป - ใช้ Claude 3.5 Sonnet (QUALITY MODE)
        'generate_title' => 'tier3_smart',      // อัพเกรดจาก tier2
        'generate_meta' => 'tier3_smart',       // อัพเกรดจาก tier2
        'generate_excerpt' => 'tier3_smart',    // อัพเกรดจาก tier2
        'check_grammar' => 'tier3_smart',       // อัพเกรดจาก tier2
        'improve_title' => 'tier3_smart',       // อัพเกรดจาก tier2
        'ab_title_test' => 'tier3_smart',       // อัพเกรดจาก tier2
        'keyword_suggest' => 'tier3_smart',     // อัพเกรดจาก tier2
        'keyword_analysis' => 'tier3_smart',    // เพิ่มใหม่
        'intent_analyze' => 'tier3_smart',      // อัพเกรดจาก tier2
        'risk_check' => 'tier3_smart',          // อัพเกรดจาก tier2
        'translate_short' => 'tier3_smart',     // อัพเกรดจาก tier1
        'extract_keywords' => 'tier3_smart',    // อัพเกรดจาก tier1
        'summarize_short' => 'tier3_smart',     // อัพเกรดจาก tier1
        'generate_article' => 'tier3_smart',
        'content_review' => 'tier3_smart',
        'content_refresh' => 'tier3_smart',
        'quality_check' => 'tier3_smart',
        'internal_link_suggest' => 'tier3_smart',
        'content_gap' => 'tier3_smart',         // เพิ่มใหม่
        'ctr_optimize' => 'tier3_smart',
        'content_plan' => 'tier3_smart',
        'trend_analyze' => 'tier3_smart',

        // Tier 4: งานซับซ้อน - ใช้ Claude Sonnet 4 (งานกลยุทธ์)
        'strategy_plan' => 'tier4_powerful',
        'comprehensive_audit' => 'tier4_powerful',
        'eeat_content' => 'tier4_powerful',
        'long_form_content' => 'tier4_powerful',
        'multi_language' => 'tier3_smart',
    ];

    private $endpoint = 'https://openrouter.ai/api/v1/chat/completions';
    private $apiKey;
    private $currentModel;
    private $currentTier;
    private $usageLog = [];

    private $configured = false;

    public function __construct() {
        $this->loadApiKey();
    }

    /**
     * Check if OpenRouter is configured
     */
    public function isConfigured(): bool {
        return $this->configured;
    }

    /**
     * Load OpenRouter API key from database
     */
    private function loadApiKey(): void {
        $settings = db()->fetchOne("
            SELECT api_key FROM ai_settings
            WHERE provider_name = 'openrouter' AND is_enabled = 1
        ");

        if (!$settings || empty($settings['api_key'])) {
            $this->configured = false;
            return;
        }

        $this->apiKey = decrypt($settings['api_key']);
        $this->configured = true;
    }

    /**
     * Execute AI task with automatic model selection
     */
    public function execute(string $taskType, string $prompt, array $options = []): array {
        if (!$this->configured) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'OpenRouter ยังไม่ได้ตั้งค่า กรุณาไปที่ Settings > AI เพื่อเพิ่ม OpenRouter API Key',
            ];
        }

        $startTime = microtime(true);

        // Get appropriate tier for this task
        $tier = $this->taskMapping[$taskType] ?? 'tier2_balanced';
        $this->currentTier = $tier;

        // Allow override
        if (!empty($options['tier'])) {
            $tier = $options['tier'];
        }

        // Get models for this tier
        $tierModels = $this->models[$tier] ?? $this->models['tier2_balanced'];

        // Allow specific model override
        if (!empty($options['model'])) {
            $this->currentModel = $options['model'];
            return $this->callModel($prompt, $options);
        }

        // Try each model in tier with fallback
        $lastError = null;
        foreach ($tierModels as $model => $pricing) {
            try {
                $this->currentModel = $model;
                $result = $this->callModel($prompt, $options);

                // Log successful usage
                $this->logUsage($taskType, $model, $tier, $result, microtime(true) - $startTime);

                return $result;

            } catch (Exception $e) {
                $lastError = $e;
                // Log failed attempt
                logEvent('warning', 'ai_orchestrator', "Model {$model} failed: " . $e->getMessage());
                continue;
            }
        }

        // All models in tier failed, try fallback to lower tier
        $fallbackResult = $this->tryFallback($tier, $prompt, $options, $taskType);
        if ($fallbackResult) {
            $this->logUsage($taskType, $this->currentModel, $this->currentTier, $fallbackResult, microtime(true) - $startTime);
            return $fallbackResult;
        }

        throw new Exception("All AI models failed. Last error: " . ($lastError ? $lastError->getMessage() : 'Unknown'));
    }

    /**
     * Try fallback to different tier
     */
    private function tryFallback(string $currentTier, string $prompt, array $options, string $taskType): ?array {
        $fallbackOrder = [
            'tier4_powerful' => ['tier3_smart', 'tier2_balanced'],
            'tier3_smart' => ['tier2_balanced', 'tier4_powerful'],
            'tier2_balanced' => ['tier3_smart', 'tier1_fast'],
            'tier1_fast' => ['tier2_balanced'],
        ];

        $fallbackTiers = $fallbackOrder[$currentTier] ?? [];

        foreach ($fallbackTiers as $fallbackTier) {
            $tierModels = $this->models[$fallbackTier] ?? [];

            foreach ($tierModels as $model => $pricing) {
                try {
                    $this->currentModel = $model;
                    $this->currentTier = $fallbackTier;
                    return $this->callModel($prompt, $options);
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Call OpenRouter API
     */
    private function callModel(string $prompt, array $options = []): array {
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? $this->getDefaultMaxTokens();

        // Build messages
        $messages = [];

        if (!empty($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = [
            'model' => $this->currentModel,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: ' . BASE_URL,
            'X-Title: AI AutoPost SEO'
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400 || isset($decoded['error'])) {
            $errorMsg = $decoded['error']['message'] ?? "HTTP Error: {$httpCode}";
            throw new Exception($errorMsg);
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        $inputTokens = $decoded['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $decoded['usage']['completion_tokens'] ?? 0;

        // Calculate cost
        $pricing = $this->getModelPricing($this->currentModel);
        $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);

        return [
            'content' => $content,
            'model' => $this->currentModel,
            'tier' => $this->currentTier,
            'tokens_used' => $inputTokens + $outputTokens,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($cost, 6),
            'provider' => 'openrouter'
        ];
    }

    /**
     * Get model pricing
     */
    private function getModelPricing(string $model): array {
        foreach ($this->models as $tier => $models) {
            if (isset($models[$model])) {
                return $models[$model];
            }
        }
        return ['input' => 1.00, 'output' => 3.00, 'context' => 128000];
    }

    /**
     * Get default max tokens based on tier
     */
    private function getDefaultMaxTokens(): int {
        return match($this->currentTier) {
            'tier1_fast' => 1000,
            'tier2_balanced' => 2000,
            'tier3_smart' => 16000,
            'tier4_powerful' => 8000,
            default => 2000
        };
    }

    /**
     * Log AI usage to database
     */
    private function logUsage(string $taskType, string $model, string $tier, array $result, float $duration): void {
        try {
            db()->insert('ai_usage_log', [
                'provider' => 'openrouter',
                'model' => $model,
                'task_type' => $taskType,
                'tier' => $tier,
                'input_tokens' => $result['input_tokens'] ?? 0,
                'output_tokens' => $result['output_tokens'] ?? 0,
                'total_tokens' => $result['tokens_used'] ?? 0,
                'estimated_cost' => $result['cost_usd'] ?? 0,
                'duration_seconds' => round($duration, 2),
                'metadata' => json_encode([
                    'success' => true
                ])
            ]);
        } catch (Exception $e) {
            // Silent fail for logging
        }
    }

    /**
     * Get usage statistics
     */
    public function getUsageStats(string $period = 'today'): array {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "1=1"
        };

        $stats = db()->fetchAll("
            SELECT
                tier,
                model,
                task_type,
                COUNT(*) as requests,
                SUM(total_tokens) as total_tokens,
                SUM(estimated_cost) as total_cost,
                AVG(duration_seconds) as avg_duration
            FROM ai_usage_log
            WHERE {$dateCondition}
            GROUP BY tier, model, task_type
            ORDER BY total_cost DESC
        ");

        $summary = db()->fetchOne("
            SELECT
                COUNT(*) as total_requests,
                SUM(total_tokens) as total_tokens,
                SUM(estimated_cost) as total_cost,
                AVG(duration_seconds) as avg_duration
            FROM ai_usage_log
            WHERE {$dateCondition}
        ");

        return [
            'period' => $period,
            'summary' => $summary,
            'by_tier' => $this->groupBy($stats, 'tier'),
            'by_model' => $this->groupBy($stats, 'model'),
            'by_task' => $this->groupBy($stats, 'task_type'),
            'details' => $stats
        ];
    }

    /**
     * Group array by key
     */
    private function groupBy(array $items, string $key): array {
        $result = [];
        foreach ($items as $item) {
            $groupKey = $item[$key];
            if (!isset($result[$groupKey])) {
                $result[$groupKey] = [
                    'requests' => 0,
                    'total_tokens' => 0,
                    'total_cost' => 0
                ];
            }
            $result[$groupKey]['requests'] += $item['requests'];
            $result[$groupKey]['total_tokens'] += $item['total_tokens'];
            $result[$groupKey]['total_cost'] += $item['total_cost'];
        }
        return $result;
    }

    /**
     * Get recommended model for task
     */
    public function getRecommendedModel(string $taskType): array {
        $tier = $this->taskMapping[$taskType] ?? 'tier2_balanced';
        $models = $this->models[$tier] ?? $this->models['tier2_balanced'];
        $model = array_key_first($models);
        $pricing = $models[$model];

        return [
            'task' => $taskType,
            'tier' => $tier,
            'model' => $model,
            'pricing' => $pricing,
            'estimated_cost_per_1k_tokens' => round(($pricing['input'] + $pricing['output']) / 2, 4)
        ];
    }

    /**
     * Get all available models and pricing
     */
    public function getAvailableModels(): array {
        return $this->models;
    }

    /**
     * Get task mapping
     */
    public function getTaskMapping(): array {
        return $this->taskMapping;
    }

    // ========================================
    // Shortcut methods for common tasks
    // ========================================

    /**
     * Generate article content
     */
    public function generateArticle(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 16000;
        return $this->execute('generate_article', $prompt, $options);
    }

    /**
     * Generate title variations
     */
    public function generateTitle(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 500;
        return $this->execute('generate_title', $prompt, $options);
    }

    /**
     * Review content quality
     */
    public function reviewContent(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 2000;
        return $this->execute('content_review', $prompt, $options);
    }

    /**
     * Analyze keywords
     */
    public function analyzeKeywords(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 1000;
        return $this->execute('keyword_suggest', $prompt, $options);
    }

    /**
     * Check content risk
     */
    public function checkRisk(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 1000;
        return $this->execute('risk_check', $prompt, $options);
    }

    /**
     * Plan content strategy
     */
    public function planStrategy(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 6000;
        return $this->execute('strategy_plan', $prompt, $options);
    }

    /**
     * Simple quick response
     */
    public function quickResponse(string $prompt, array $options = []): array {
        $options['max_tokens'] = $options['max_tokens'] ?? 500;
        return $this->execute('simple_response', $prompt, $options);
    }
}

/**
 * Helper function to get orchestrator instance
 */
function aiOrchestrator(): AIOrchestrator {
    static $instance = null;
    if ($instance === null) {
        $instance = new AIOrchestrator();
    }
    return $instance;
}

/**
 * Quick helper for AI tasks
 */
function aiTask(string $taskType, string $prompt, array $options = []): array {
    return aiOrchestrator()->execute($taskType, $prompt, $options);
}
