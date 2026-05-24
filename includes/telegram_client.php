<?php
/**
 * AI AutoPost SEO System - Telegram Client
 * =========================================
 * Telegram Bot API client for notifications
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class TelegramClient {
    private $botToken;
    private $chatId;
    private $threadId;
    private $isEnabled;
    private $settings = [];

    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(?array $settings = null) {
        if ($settings) {
            $this->loadSettings($settings);
        } else {
            $this->loadDefaultSettings();
        }
    }

    /**
     * Load admin telegram settings from database
     */
    private function loadDefaultSettings(): void {
        $settings = db()->fetchOne("
            SELECT * FROM telegram_settings
            WHERE setting_name = 'default' AND owner_type = 'admin'
            ORDER BY id ASC LIMIT 1
        ");

        if (!$settings) {
            // fallback: any default record
            $settings = db()->fetchOne("SELECT * FROM telegram_settings WHERE setting_name = 'default' LIMIT 1");
        }

        if ($settings) {
            $this->loadSettings($settings);
        }
    }

    /**
     * Load settings from array
     */
    private function loadSettings(array $settings): void {
        $this->botToken = $settings['bot_token'] ?? '';
        $this->chatId = $settings['chat_id'] ?? '';
        $this->threadId = $settings['thread_id'] ?? null;
        $this->isEnabled = (bool)($settings['is_enabled'] ?? true);
        $this->settings = $settings;
    }

    /**
     * Check if telegram is configured
     */
    public function isConfigured(): bool {
        return !empty($this->botToken) && !empty($this->chatId);
    }

    /**
     * Check if telegram is enabled
     */
    public function isEnabled(): bool {
        return $this->isEnabled && $this->isConfigured();
    }

    /**
     * Send a text message
     */
    public function sendMessage(string $message, array $options = []): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Telegram is not enabled or configured'];
        }

        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => $options['parse_mode'] ?? 'HTML',
            'disable_web_page_preview' => $options['disable_preview'] ?? false
        ];

        // Add thread_id for forum/topic groups
        if ($this->threadId) {
            $data['message_thread_id'] = $this->threadId;
        }

        return $this->apiRequest('sendMessage', $data);
    }

    /**
     * Send a photo with caption
     */
    public function sendPhoto(string $photoUrl, string $caption = '', array $options = []): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Telegram is not enabled or configured'];
        }

        $data = [
            'chat_id' => $this->chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => $options['parse_mode'] ?? 'HTML'
        ];

        if ($this->threadId) {
            $data['message_thread_id'] = $this->threadId;
        }

        return $this->apiRequest('sendPhoto', $data);
    }

    /**
     * Send a document/file
     */
    public function sendDocument(string $documentUrl, string $caption = ''): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Telegram is not enabled or configured'];
        }

        $data = [
            'chat_id' => $this->chatId,
            'document' => $documentUrl,
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];

        if ($this->threadId) {
            $data['message_thread_id'] = $this->threadId;
        }

        return $this->apiRequest('sendDocument', $data);
    }

    /**
     * Test connection by getting bot info
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Bot token or chat ID not configured'];
        }

        // First, verify the bot token
        $botInfo = $this->apiRequest('getMe', []);

        if (!$botInfo['success']) {
            return $botInfo;
        }

        // Then try to send a test message
        $testResult = $this->sendMessage("✅ <b>ทดสอบการเชื่อมต่อสำเร็จ!</b>\n\n🤖 AI AutoPost SEO System\n📅 " . date('d/m/Y H:i:s'));

        if ($testResult['success']) {
            return [
                'success' => true,
                'message' => 'การเชื่อมต่อสำเร็จ',
                'bot_name' => $botInfo['result']['first_name'] ?? '',
                'bot_username' => $botInfo['result']['username'] ?? ''
            ];
        }

        return $testResult;
    }

    /**
     * Get bot information
     */
    public function getBotInfo(): array {
        return $this->apiRequest('getMe', []);
    }

    /**
     * Notify admin: new member registered (pending approval)
     * Uses notify_on_post flag
     */
    public function notifyNewMember(array $member): array {
        if (!($this->settings['notify_on_post'] ?? 1)) {
            return ['success' => true, 'message' => 'disabled'];
        }
        $message = "🆕 <b>สมาชิกใหม่รอยืนยัน!</b>\n\n"
                 . "👤 Username: <b>{$member['username']}</b>\n"
                 . "📧 Email: {$member['email']}\n"
                 . "📅 เวลา: " . date('d/m/Y H:i') . "\n\n"
                 . "🔗 <a href=\"" . (defined('ADMIN_URL') ? ADMIN_URL : '') . "/members_list.php\">จัดการสมาชิก</a>";
        return $this->sendMessage($message);
    }

    /**
     * Notify admin: payment slip submitted (pending review)
     * Uses notify_on_error flag
     */
    public function notifyPaymentSlip(array $member, array $plan, int $months, float $amount): array {
        if (!($this->settings['notify_on_error'] ?? 1)) {
            return ['success' => true, 'message' => 'disabled'];
        }
        $message = "📎 <b>สลิปใหม่รอตรวจสอบ!</b>\n\n"
                 . "👤 สมาชิก: <b>{$member['username']}</b>\n"
                 . "📦 Plan: {$plan['name']} ({$months} เดือน)\n"
                 . "💰 จำนวน: ฿" . number_format($amount, 0) . "\n"
                 . "📅 เวลา: " . date('d/m/Y H:i') . "\n\n"
                 . "🔗 <a href=\"" . (defined('ADMIN_URL') ? ADMIN_URL : '') . "/slips_list.php\">ตรวจสอบสลิป</a>";
        return $this->sendMessage($message);
    }

    /**
     * Send article posted notification
     */
    public function notifyArticlePosted(array $article, array $site): array {
        $message = "📝 <b>บทความใหม่ถูกเผยแพร่!</b>\n\n";
        $message .= "📰 <b>หัวข้อ:</b> {$article['title']}\n";
        $message .= "🌐 <b>เว็บไซต์:</b> {$site['name']}\n";
        $message .= "📂 <b>หมวดหมู่:</b> " . getTopicThai($article['topic']) . "\n";
        $message .= "📊 <b>จำนวนคำ:</b> " . formatNumberThai($article['word_count']) . "\n";
        $message .= "🤖 <b>AI:</b> {$article['ai_provider']} ({$article['ai_model']})\n";

        if (!empty($article['post_url'])) {
            $message .= "\n🔗 <a href=\"{$article['post_url']}\">อ่านบทความ</a>";
        }

        return $this->sendMessage($message);
    }

    /**
     * Send error notification
     */
    public function notifyError(string $errorType, string $errorMessage, array $context = []): array {
        $message = "⚠️ <b>เกิดข้อผิดพลาด!</b>\n\n";
        $message .= "❌ <b>ประเภท:</b> {$errorType}\n";
        $message .= "📝 <b>รายละเอียด:</b> {$errorMessage}\n";

        if (!empty($context['site'])) {
            $message .= "🌐 <b>เว็บไซต์:</b> {$context['site']}\n";
        }

        $message .= "⏰ <b>เวลา:</b> " . date('d/m/Y H:i:s');

        return $this->sendMessage($message);
    }

    /**
     * Send weekly report
     */
    public function sendWeeklyReport(array $report): array {
        $message = "📊 <b>รายงานประจำสัปดาห์</b>\n";
        $message .= "📅 {$report['week_start']} - {$report['week_end']}\n\n";

        $message .= "📝 <b>บทความทั้งหมด:</b> " . formatNumberThai($report['total_articles']) . "\n";
        $message .= "✅ <b>เผยแพร่สำเร็จ:</b> " . formatNumberThai($report['successful_posts']) . "\n";
        $message .= "❌ <b>ล้มเหลว:</b> " . formatNumberThai($report['failed_posts']) . "\n";
        $message .= "📖 <b>จำนวนคำรวม:</b> " . formatNumberThai($report['total_words']) . "\n";
        $message .= "🔗 <b>Internal Links:</b> " . formatNumberThai($report['internal_links_created']) . "\n";
        $message .= "🪙 <b>Tokens ใช้:</b> " . formatNumberThai($report['ai_tokens_used']) . "\n";

        if ($report['ai_cost_estimate'] > 0) {
            $message .= "💰 <b>ค่าใช้จ่ายโดยประมาณ:</b> $" . number_format($report['ai_cost_estimate'], 2) . "\n";
        }

        // Articles by site
        if (!empty($report['articles_by_site'])) {
            $message .= "\n<b>แยกตามเว็บไซต์:</b>\n";
            $siteData = json_decode($report['articles_by_site'], true) ?? [];
            foreach ($siteData as $site => $count) {
                $message .= "• {$site}: {$count}\n";
            }
        }

        return $this->sendMessage($message);
    }

    /**
     * Make API request to Telegram
     */
    private function apiRequest(string $method, array $data): array {
        $url = self::API_BASE . $this->botToken . '/' . $method;

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => "cURL Error: {$error}"];
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Invalid JSON response'];
        }

        if (!($decoded['ok'] ?? false)) {
            return [
                'success' => false,
                'message' => $decoded['description'] ?? 'Unknown Telegram API error',
                'error_code' => $decoded['error_code'] ?? null
            ];
        }

        return [
            'success' => true,
            'result' => $decoded['result'] ?? null
        ];
    }

    /**
     * Set bot token
     */
    public function setBotToken(string $token): self {
        $this->botToken = $token;
        return $this;
    }

    /**
     * Set chat ID
     */
    public function setChatId(string $chatId): self {
        $this->chatId = $chatId;
        return $this;
    }
}

/**
 * Helper function to get telegram client
 */
function telegram(): TelegramClient {
    return new TelegramClient();
}
