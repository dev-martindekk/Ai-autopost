<?php
/**
 * AI AutoPost SEO System - AI Client
 * ===================================
 * Unified AI API client for multiple providers
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class AIClient {
    private $provider;
    private $apiKey;
    private $model;
    private $temperature;
    private $maxTokens;
    private $endpoint;

    public function __construct(?array $settings = null) {
        if ($settings) {
            $this->loadSettings($settings);
        } else {
            $this->loadPrimaryProvider();
        }
    }

    /**
     * Load primary AI provider settings from database
     */
    private function loadPrimaryProvider(): void {
        $settings = db()->fetchOne("
            SELECT * FROM ai_settings
            WHERE is_primary = 1 AND is_enabled = 1
            LIMIT 1
        ");

        if (!$settings) {
            // Fall back to any enabled provider
            $settings = db()->fetchOne("
                SELECT * FROM ai_settings
                WHERE is_enabled = 1
                ORDER BY provider_name
                LIMIT 1
            ");
        }

        if (!$settings) {
            throw new Exception('No AI provider configured');
        }

        $this->loadSettings($settings);
    }

    /**
     * Load settings from array
     */
    private function loadSettings(array $settings): void {
        $this->provider = $settings['provider_name'];
        $this->apiKey = isset($settings['api_key']) ? decrypt($settings['api_key']) : '';
        $this->model = $settings['default_model'];
        $this->temperature = (float)($settings['temperature'] ?? 0.7);
        $this->maxTokens = (int)($settings['max_tokens'] ?? 4000);
        $this->endpoint = $settings['api_endpoint'];
    }

    /**
     * Load specific provider by name
     */
    public function loadProvider(string $providerName): self {
        $settings = db()->fetchOne("
            SELECT * FROM ai_settings
            WHERE provider_name = ? AND is_enabled = 1
        ", [$providerName]);

        if (!$settings) {
            throw new Exception("Provider '{$providerName}' not found or disabled");
        }

        $this->loadSettings($settings);
        return $this;
    }

    /**
     * Build system prompt — member custom → admin saved → hardcoded default
     */
    private function buildSystemPrompt(string $language, ?int $memberId = null): string {
        // 1. Member's own custom system prompt
        if ($memberId) {
            $row = db()->fetchOne(
                "SELECT prompt_content FROM prompt_templates
                 WHERE template_type='system' AND owner_type='member' AND owner_id=? AND is_active=1
                 LIMIT 1",
                [$memberId]
            );
            if ($row) return $row['prompt_content'];
        }

        // 2. Admin-saved system prompt
        $row = db()->fetchOne(
            "SELECT prompt_content FROM prompt_templates
             WHERE template_type='system' AND (owner_type='admin' OR owner_type IS NULL) AND is_active=1
             ORDER BY is_default DESC LIMIT 1"
        );
        if ($row) return $row['prompt_content'];

        // 3. Hardcoded default
        return 'คุณคือนักเขียนบทความ SEO มืออาชีพ เขียนเนื้อหาภาษาไทยที่มีคุณภาพสูง เป็นธรรมชาติ และเป็นมิตรกับ SEO ใช้ภาษาที่คนไทยใช้จริง หลีกเลี่ยงการแปลตรงตัวจากภาษาอื่น';
    }

    /**
     * Generate content using AI
     */
    public function generate(string $prompt, array $options = []): array {
        $model = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;
        $language = $options['language'] ?? 'th';
        $memberId = $options['member_id'] ?? null;
        $systemPrompt = $options['system_prompt'] ?? $this->buildSystemPrompt($language, $memberId);

        $startTime = microtime(true);

        switch ($this->provider) {
            case 'claude':
                $result = $this->callClaude($prompt, $model, $temperature, $maxTokens, $systemPrompt);
                break;
            case 'openai':
                $result = $this->callOpenAI($prompt, $model, $temperature, $maxTokens, $systemPrompt);
                break;
            case 'gemini':
                $result = $this->callGemini($prompt, $model, $temperature, $maxTokens, $systemPrompt);
                break;
            case 'deepseek':
                $result = $this->callDeepSeek($prompt, $model, $temperature, $maxTokens, $systemPrompt);
                break;
            case 'openrouter':
                $result = $this->callOpenRouter($prompt, $model, $temperature, $maxTokens, $systemPrompt);
                break;
            default:
                throw new Exception("Unknown provider: {$this->provider}");
        }

        $result['generation_time'] = round(microtime(true) - $startTime, 2);
        $result['provider'] = $this->provider;
        $result['model'] = $model;
        $result['language'] = $language;

        return $result;
    }

    /**
     * Test connection to AI provider
     */
    public function testConnection(): array {
        try {
            $result = $this->generate("Say 'Connection successful!' in Thai.", [
                'max_tokens' => 100
            ]);

            return [
                'success' => true,
                'message' => 'การเชื่อมต่อสำเร็จ',
                'response' => $result['content'] ?? '',
                'tokens_used' => $result['tokens_used'] ?? 0
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Call Anthropic Claude API
     */
    private function callClaude(string $prompt, string $model, float $temperature, int $maxTokens, string $systemPrompt = ''): array {
        $data = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        // Add system prompt for Claude
        if (!empty($systemPrompt)) {
            $data['system'] = $systemPrompt;
        }

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        ];

        $response = $this->httpRequest($this->endpoint, $data, $headers);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'Claude API error');
        }

        return [
            'content' => $response['content'][0]['text'] ?? '',
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            'input_tokens' => $response['usage']['input_tokens'] ?? 0,
            'output_tokens' => $response['usage']['output_tokens'] ?? 0
        ];
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $prompt, string $model, float $temperature, int $maxTokens, string $systemPrompt = ''): array {
        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        $response = $this->httpRequest($this->endpoint, $data, $headers);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'OpenAI API error');
        }

        return [
            'content' => $response['choices'][0]['message']['content'] ?? '',
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            'input_tokens' => $response['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $response['usage']['completion_tokens'] ?? 0
        ];
    }

    /**
     * Call Google Gemini API
     */
    private function callGemini(string $prompt, string $model, float $temperature, int $maxTokens, string $systemPrompt = ''): array {
        $endpoint = "{$this->endpoint}/{$model}:generateContent?key={$this->apiKey}";

        // Prepend system prompt to user prompt for Gemini
        $fullPrompt = !empty($systemPrompt) ? "{$systemPrompt}\n\n{$prompt}" : $prompt;

        $data = [
            'contents' => [
                ['parts' => [['text' => $fullPrompt]]]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens
            ]
        ];

        $headers = [
            'Content-Type: application/json'
        ];

        $response = $this->httpRequest($endpoint, $data, $headers);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'Gemini API error');
        }

        $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $tokensUsed = $response['usageMetadata']['totalTokenCount'] ?? 0;

        return [
            'content' => $content,
            'tokens_used' => $tokensUsed,
            'input_tokens' => $response['usageMetadata']['promptTokenCount'] ?? 0,
            'output_tokens' => $response['usageMetadata']['candidatesTokenCount'] ?? 0
        ];
    }

    /**
     * Call DeepSeek API (OpenAI-compatible)
     */
    private function callDeepSeek(string $prompt, string $model, float $temperature, int $maxTokens, string $systemPrompt = ''): array {
        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        $response = $this->httpRequest($this->endpoint, $data, $headers);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'DeepSeek API error');
        }

        return [
            'content' => $response['choices'][0]['message']['content'] ?? '',
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            'input_tokens' => $response['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $response['usage']['completion_tokens'] ?? 0
        ];
    }

    /**
     * Call OpenRouter API (OpenAI-compatible, supports multiple models)
     */
    private function callOpenRouter(string $prompt, string $model, float $temperature, int $maxTokens, string $systemPrompt = ''): array {
        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: ' . (defined('BASE_URL') ? BASE_URL : 'https://ai-autopost-seo.local'),
            'X-Title: AI AutoPost SEO'
        ];

        $response = $this->httpRequest($this->endpoint, $data, $headers);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'OpenRouter API error');
        }

        return [
            'content' => $response['choices'][0]['message']['content'] ?? '',
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            'input_tokens' => $response['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $response['usage']['completion_tokens'] ?? 0
        ];
    }

    /**
     * Make HTTP request
     */
    private function httpRequest(string $url, array $data, array $headers): array {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
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

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . substr($response, 0, 200));
        }

        if ($httpCode >= 400) {
            $errorMessage = $decoded['error']['message'] ?? $decoded['message'] ?? "HTTP Error: {$httpCode}";
            throw new Exception($errorMessage);
        }

        return $decoded;
    }

    /**
     * Get current provider name
     */
    public function getProvider(): string {
        return $this->provider;
    }

    /**
     * Get current model
     */
    public function getModel(): string {
        return $this->model;
    }
}

/**
 * Helper function to get AI client instance
 */
function ai(?string $provider = null): AIClient {
    $client = new AIClient();
    if ($provider) {
        $client->loadProvider($provider);
    }
    return $client;
}
