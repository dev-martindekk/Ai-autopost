<?php
/**
 * AI AutoPost SEO System - Configuration
 * ======================================
 * Main configuration file
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Environment
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', APP_ENV === 'development');

// Database Configuration (all secrets must be set via environment variables / .env)
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'ai_autopost_seo');
define('DB_USER', getenv('DB_USER') ?: 'seouser');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Security
define('ENCRYPTION_METHOD', 'AES-256-CBC');
define('SESSION_LIFETIME', 86400 * 30); // 30 days
define('CSRF_TOKEN_LIFETIME', 86400 * 7); // 7 days
define('MAX_LOGIN_ATTEMPTS', 10);
define('LOCKOUT_TIME', 300); // 5 minutes

// Encryption key — must be set via ENCRYPTION_KEY environment variable
$encryptionKey = getenv('ENCRYPTION_KEY') ?: '';
define('ENCRYPTION_KEY', $encryptionKey);

// Paths
define('INCLUDES_PATH', APP_ROOT . '/includes');
define('ADMIN_PATH', APP_ROOT . '/admin');
define('JOBS_PATH', APP_ROOT . '/jobs');
define('LOGS_PATH', APP_ROOT . '/logs');
define('ASSETS_PATH', APP_ROOT . '/assets');
define('UPLOADS_PATH', APP_ROOT . '/uploads');

// URLs
$baseUrl = getenv('BASE_URL') ?: 'https://adminseo.pigads.me';
define('BASE_URL', $baseUrl);
define('ADMIN_URL', BASE_URL . '/admin');
define('ASSETS_URL', BASE_URL . '/assets');

// Timezone
date_default_timezone_set('Asia/Bangkok');

// Error Reporting
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
ini_set('log_errors', 1);
ini_set('error_log', LOGS_PATH . '/php_errors.log');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

// API Defaults
define('DEFAULT_AI_TEMPERATURE', 0.7);
define('DEFAULT_AI_MAX_TOKENS', 16000);
define('DEFAULT_ARTICLE_MIN_WORDS', 2500);
define('DEFAULT_ARTICLE_MAX_WORDS', 4000);

// Topic mappings (Thai) — loaded from DB, fallback to hardcoded
function getTopics(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = db()->fetchAll("SELECT slug, name_th FROM topics WHERE is_active = 1 ORDER BY sort_order, id");
        if (!empty($rows)) {
            $cache = array_column($rows, 'name_th', 'slug');
            return $cache;
        }
    } catch (Exception $e) {}
    $cache = ['general' => 'ทั่วไป'];
    return $cache;
}
define('TOPICS', ['general' => 'ทั่วไป']);

// AI Providers
define('AI_PROVIDERS', [
    'claude' => [
        'name' => 'Anthropic Claude',
        'endpoint' => 'https://api.anthropic.com/v1/messages',
        'models' => ['claude-sonnet-4-20250514', 'claude-3-5-sonnet-20241022', 'claude-3-haiku-20240307']
    ],
    'openai' => [
        'name' => 'OpenAI GPT',
        'endpoint' => 'https://api.openai.com/v1/chat/completions',
        'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo']
    ],
    'gemini' => [
        'name' => 'Google Gemini',
        'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'models' => ['gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-pro']
    ],
    'deepseek' => [
        'name' => 'DeepSeek',
        'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
        'models' => ['deepseek-chat', 'deepseek-coder']
    ]
]);

// Boot security on every web request (skip CLI)
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/security.php';
    security()->boot();
}
