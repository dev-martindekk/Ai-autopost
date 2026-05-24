<?php
/**
 * AI AutoPost SEO System - Helper Functions
 * =========================================
 * Common utility functions
 */

/**
 * Encrypt sensitive data (API keys, passwords)
 */
function encrypt(string $data): string {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

/**
 * Decrypt sensitive data
 */
function decrypt(string $data): string {
    $parts = explode('::', base64_decode($data), 2);
    if (count($parts) !== 2) {
        return '';
    }
    list($iv, $encrypted) = $parts;
    return openssl_decrypt($encrypted, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv) ?: '';
}

/**
 * Mask API key for display (show only first 8 and last 4 chars)
 */
function maskApiKey(string $key): string {
    if (strlen($key) < 16) {
        return str_repeat('*', strlen($key));
    }
    return substr($key, 0, 8) . str_repeat('*', strlen($key) - 12) . substr($key, -4);
}

/**
 * Sanitize input string
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize array recursively
 */
function sanitizeArray(array $input): array {
    return array_map(function($value) {
        if (is_array($value)) {
            return sanitizeArray($value);
        }
        return is_string($value) ? sanitize($value) : $value;
    }, $input);
}

/**
 * Sanitize HTML content - allows safe formatting tags, removes dangerous ones
 */
function sanitizeHtmlContent(string $html): string {
    // Remove potentially dangerous tags
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html);
    $html = preg_replace('/<object\b[^>]*>.*?<\/object>/is', '', $html);
    $html = preg_replace('/<embed\b[^>]*>.*?<\/embed>/is', '', $html);
    $html = preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $html);
    $html = preg_replace('/<input\b[^>]*>/is', '', $html);
    $html = preg_replace('/<button\b[^>]*>.*?<\/button>/is', '', $html);
    $html = preg_replace('/<textarea\b[^>]*>.*?<\/textarea>/is', '', $html);
    $html = preg_replace('/<select\b[^>]*>.*?<\/select>/is', '', $html);

    // Remove event handlers (onclick, onerror, onload, etc.)
    $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
    $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);

    // Remove javascript: and data: URIs in href/src attributes
    $html = preg_replace('/\b(href|src)\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '', $html);
    $html = preg_replace('/\b(href|src)\s*=\s*["\']?\s*data:[^"\'>\s]*/i', '', $html);

    return $html;
}

/**
 * Validate email
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate URL
 */
function isValidUrl(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Generate random string
 */
function generateRandomString(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate CSRF token (reuse existing if valid)
 */
function generateCsrfToken(): string {
    // Check if existing token is still valid
    if (!empty($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token_time'])) {
        $tokenAge = time() - $_SESSION['csrf_token_time'];
        // Reuse token if less than half the lifetime
        if ($tokenAge < (CSRF_TOKEN_LIFETIME / 2)) {
            return $_SESSION['csrf_token'];
        }
    }

    // Generate new token
    $token = generateRandomString(64);
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

/**
 * Validate CSRF token
 */
function validateCsrfToken(string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    // Check token expiry
    $tokenTime = $_SESSION['csrf_token_time'] ?? 0;
    if (time() - $tokenTime > CSRF_TOKEN_LIFETIME) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verify CSRF token — dies with 403 if invalid
 */
function verifyCsrfToken(string $token): void {
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        die('Invalid or expired CSRF token. Please go back and try again.');
    }
}

/**
 * Redirect to URL
 */
function redirect(string $url): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header("Location: {$url}");
        exit;
    }
    // Fallback when HTML already output (e.g. header.php included before POST handling)
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit;
}

/**
 * Set flash message
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Format date Thai style
 */
function formatDateThai(string $date): string {
    $timestamp = strtotime($date);
    $thaiMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];

    $day = date('j', $timestamp);
    $month = $thaiMonths[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp) + 543;
    $time = date('H:i', $timestamp);

    return "{$day} {$month} {$year} {$time}";
}

/**
 * Format number Thai style
 */
function formatNumberThai($number): string {
    return number_format($number, 0, '.', ',');
}

/**
 * Get topic name in Thai
 */
function getTopicThai(string $topic): string {
    return TOPICS[$topic] ?? $topic;
}

/**
 * Calculate time ago
 * Note: MySQL timezone is +07:00 (Bangkok), same as PHP
 */
function timeAgo(string $datetime): string {
    $bangkokTimezone = new DateTimeZone('Asia/Bangkok');

    $dbTime = new DateTime($datetime, $bangkokTimezone);
    $now = new DateTime('now', $bangkokTimezone);
    $diff = $now->getTimestamp() - $dbTime->getTimestamp();

    if ($diff < 0) return 'เมื่อสักครู่'; // Future time edge case
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 604800) return floor($diff / 86400) . ' วันที่แล้ว';
    if ($diff < 2592000) return floor($diff / 604800) . ' สัปดาห์ที่แล้ว';

    return formatDateThai($datetime);
}

/**
 * Log system event
 */
function logEvent(string $type, string $category, string $message, array $context = []): void {
    try {
        db()->insert('system_logs', [
            'log_type' => $type,
            'category' => $category,
            'message' => $message,
            'context' => json_encode($context),
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Fallback to file logging
        $logFile = LOGS_PATH . '/system.log';
        $logMessage = sprintf(
            "[%s] [%s] [%s] %s - %s\n",
            date('Y-m-d H:i:s'),
            $type,
            $category,
            $message,
            json_encode($context)
        );
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

/**
 * JSON response for AJAX
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get client IP address
 */
function getClientIP(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    // Cloudflare always sends CF-Connecting-IP with the real visitor IP.
    // Trust it unconditionally — Cloudflare strips spoofed headers before forwarding.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }

    // Standard reverse proxy / Docker internal network
    $trustedProxies = ['127.0.0.1', '::1', '172.', '10.', '192.168.'];
    $isTrustedProxy = false;
    foreach ($trustedProxies as $prefix) {
        if (str_starts_with($remoteAddr, $prefix)) {
            $isTrustedProxy = true;
            break;
        }
    }

    if ($isTrustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : 'unknown';
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Get system setting
 */
function getSetting(string $key, $default = null) {
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key] ?? $default;
    }

    try {
        $result = db()->fetchOne(
            "SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?",
            [$key]
        );

        if (!$result) {
            $cache[$key] = null;
            return $default;
        }

        $value = $result['setting_value'];
        switch ($result['setting_type']) {
            case 'int':
                $value = (int) $value; break;
            case 'bool':
                $value = (bool) $value; break;
            case 'json':
                $value = json_decode($value, true); break;
        }
        $cache[$key] = $value;
        return $value;
    } catch (Exception $e) {
        return $default;
    }
}


/**
 * Set system setting
 */
function setSetting(string $key, $value, string $type = 'string'): bool {
    try {
        if ($type === 'json') {
            $value = json_encode($value);
        } elseif ($type === 'bool') {
            $value = $value ? '1' : '0';
        }

        db()->query(
            "INSERT INTO system_settings (setting_key, setting_value, setting_type)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)",
            [$key, $value, $type]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get per-member setting
 */
function getMemberSetting(int $memberId, string $key, $default = null) {
    static $cache = [];
    $cacheKey = "{$memberId}:{$key}";

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey] ?? $default;
    }

    try {
        $result = db()->fetchOne(
            "SELECT setting_value, setting_type FROM member_settings WHERE member_id = ? AND setting_key = ?",
            [$memberId, $key]
        );
        if (!$result) {
            $cache[$cacheKey] = null;
            return $default;
        }
        $value = $result['setting_value'];
        switch ($result['setting_type']) {
            case 'int':  $value = (int) $value; break;
            case 'bool': $value = (bool) $value; break;
            case 'json': $value = json_decode($value, true); break;
        }
        $cache[$cacheKey] = $value;
        return $value;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set per-member setting
 */
function setMemberSetting(int $memberId, string $key, $value, string $type = 'string'): bool {
    try {
        if ($type === 'json')      $value = json_encode($value);
        elseif ($type === 'bool')  $value = $value ? '1' : '0';
        db()->query(
            "INSERT INTO member_settings (member_id, setting_key, setting_value, setting_type)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)",
            [$memberId, $key, $value, $type]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Save all keywords used in an article (primary + secondary + long_tail + lsi)
 * and deactivate them + similar keywords from the keyword pool
 */
function saveAndDeactivateUsedKeywords(int $articleId, int $siteId, string $primaryKeyword, array $keywordCluster): int {
    $allUsed = [];

    // Primary
    $allUsed[] = ['keyword' => $primaryKeyword, 'type' => 'primary'];

    // Secondary
    foreach ($keywordCluster['secondary'] ?? [] as $kw) {
        $text = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
        if ($text) $allUsed[] = ['keyword' => $text, 'type' => 'secondary'];
    }

    // Long tail
    foreach ($keywordCluster['long_tail'] ?? [] as $kw) {
        $text = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
        if ($text) $allUsed[] = ['keyword' => $text, 'type' => 'long_tail'];
    }

    // LSI
    foreach ($keywordCluster['lsi_keywords'] ?? $keywordCluster['lsi'] ?? [] as $kw) {
        $text = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
        if ($text) $allUsed[] = ['keyword' => $text, 'type' => 'lsi'];
    }

    // Save to article_keywords table
    foreach ($allUsed as $item) {
        db()->insert('article_keywords', [
            'article_id' => $articleId,
            'site_id' => $siteId,
            'keyword' => $item['keyword'],
            'keyword_type' => $item['type']
        ]);
    }

    // ไม่ deactivate keyword อีกต่อไป — ให้ getKeywordDeduplicationSQL() ตรวจ per-site แทน
    // เพราะ keyword เดียวกันอาจถูกใช้โดยเว็บ A แต่เว็บ B ยังไม่ได้ใช้
    // is_active ใช้สำหรับ admin ปิด keyword ด้วยมือเท่านั้น
    return 0;
}

/**
 * SQL fragment for smart keyword deduplication
 * Checks against article_keywords table (all used keywords: primary + secondary + long_tail + lsi)
 * Uses exact match + similarity (contains check, min 6 chars to avoid short word false positives)
 */
function getKeywordDeduplicationSQL(string $keywordColumn = 'k.keyword'): string {
    // Check both article_keywords table AND articles.primary_keyword
    // This ensures old articles (without article_keywords records) are also deduplicated
    return "AND NOT EXISTS (
        SELECT 1 FROM article_keywords ak
        WHERE ak.site_id = ?
        AND (
            {$keywordColumn} = ak.keyword
            OR (
                CHAR_LENGTH(REPLACE(ak.keyword, ' ', '')) >= 6
                AND (
                    REPLACE({$keywordColumn}, ' ', '') LIKE CONCAT('%', REPLACE(ak.keyword, ' ', ''), '%')
                    OR REPLACE(ak.keyword, ' ', '') LIKE CONCAT('%', REPLACE({$keywordColumn}, ' ', ''), '%')
                )
            )
        )
    )
    AND NOT EXISTS (
        SELECT 1 FROM articles art
        WHERE art.site_id = ?
        AND art.primary_keyword IS NOT NULL
        AND (
            {$keywordColumn} = art.primary_keyword
            OR (
                CHAR_LENGTH(REPLACE(art.primary_keyword, ' ', '')) >= 6
                AND (
                    REPLACE({$keywordColumn}, ' ', '') LIKE CONCAT('%', REPLACE(art.primary_keyword, ' ', ''), '%')
                    OR REPLACE(art.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE({$keywordColumn}, ' ', ''), '%')
                )
            )
        )
    )";
}

/**
 * Convert Markdown remnants in AI-generated content to proper HTML
 * Handles: ## headings, --- dividers, **bold**, * bullets, <br> in tables
 */
function convertMarkdownToHtml(string $content): string {
    // Convert Markdown headings wrapped in <p> tags: <p>## Heading</p> → <h2>Heading</h2>
    $content = preg_replace('/<p>\s*#{6}\s*(.+?)\s*<\/p>/i', '<h6>$1</h6>', $content);
    $content = preg_replace('/<p>\s*#{5}\s*(.+?)\s*<\/p>/i', '<h5>$1</h5>', $content);
    $content = preg_replace('/<p>\s*#{4}\s*(.+?)\s*<\/p>/i', '<h4>$1</h4>', $content);
    $content = preg_replace('/<p>\s*###\s*(.+?)\s*<\/p>/i', '<h3>$1</h3>', $content);
    $content = preg_replace('/<p>\s*##\s*(.+?)\s*<\/p>/i', '<h2>$1</h2>', $content);
    $content = preg_replace('/<p>\s*#\s*(.+?)\s*<\/p>/i', '<h1>$1</h1>', $content);

    // Convert bare Markdown headings: ## Heading → <h2>Heading</h2>
    $content = preg_replace('/^#{6}\s*(.+)$/m', '<h6>$1</h6>', $content);
    $content = preg_replace('/^#{5}\s*(.+)$/m', '<h5>$1</h5>', $content);
    $content = preg_replace('/^#{4}\s*(.+)$/m', '<h4>$1</h4>', $content);
    $content = preg_replace('/^###\s*(.+)$/m', '<h3>$1</h3>', $content);
    $content = preg_replace('/^##\s*(.+)$/m', '<h2>$1</h2>', $content);
    $content = preg_replace('/^#\s*(.+)$/m', '<h1>$1</h1>', $content);

    // Remove headings wrapped in <p> tags: <p><h2>...</h2></p> → <h2>...</h2>
    $content = preg_replace('/<p>\s*(<h[1-6][^>]*>.*?<\/h[1-6]>)\s*<\/p>/i', '$1', $content);

    // Convert --- horizontal rules to <hr>
    $content = preg_replace('/^---+\s*$/m', '<hr>', $content);
    // Remove <p>---</p> or <p><hr></p>
    $content = preg_replace('/<p>\s*---+\s*<\/p>/i', '<hr>', $content);
    $content = preg_replace('/<p>\s*<hr>\s*<\/p>/i', '<hr>', $content);

    // Convert **bold** to <strong> (only outside of HTML tags)
    $content = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $content);

    // Convert Markdown bullet lists: * item or - item (at start of line, not inside HTML)
    // Only convert if it looks like a list (2+ consecutive bullet items)
    $content = preg_replace_callback('/(?:^[\*\-]\s+.+\n?){2,}/m', function($match) {
        $items = preg_split('/^[\*\-]\s+/m', trim($match[0]), -1, PREG_SPLIT_NO_EMPTY);
        $li = implode('', array_map(function($item) {
            return '<li>' . trim($item) . '</li>';
        }, $items));
        return '<ul>' . $li . '</ul>';
    }, $content);

    // Fix <br> inside table cells (AI sometimes uses <br> instead of proper table structure)
    $content = preg_replace('/<br\s*\/?>\s*<\/(td|th)>/i', '</$1>', $content);

    // Remove empty <p></p> tags
    $content = preg_replace('/<p>\s*<\/p>/i', '', $content);

    // Clean up multiple <hr> in a row
    $content = preg_replace('/(<hr>\s*){2,}/', '<hr>', $content);

    // Remove "สรุป"/"บทสรุป" section at end of article (H2 สรุป + content until next H2 or end)
    $content = preg_replace('/<h2[^>]*>\s*(?:สรุป|บทสรุป)[^<]*<\/h2>.*?(?=<h2|$)/is', '', $content);

    // Fix unclosed HTML tags using DOMDocument
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $fixed = $dom->saveHTML();
    $fixed = preg_replace('/^.*?<div>(.*)<\/div>.*$/is', '$1', $fixed);
    // DOMDocument converts non-ASCII to HTML entities — decode them back
    $fixed = html_entity_decode($fixed, ENT_QUOTES, 'UTF-8');
    if (!empty(trim($fixed))) {
        $content = $fixed;
    }

    return trim($content);
}

/**
 * Modernize year in keyword to current year
 * e.g. "keyword2023" -> "keyword2026"
 */
function modernizeKeywordYear(string $keyword): string {
    $currentYear = (int) date('Y');
    $currentYearBE = $currentYear + 543; // พ.ศ.
    // Replace old years (2019-last year) with current year
    $keyword = preg_replace_callback('/\b(20[1-9]\d)\b/', function($m) use ($currentYear) {
        $year = (int)$m[1];
        return ($year >= 2019 && $year < $currentYear) ? (string)$currentYear : $m[0];
    }, $keyword);
    // Also handle Thai Buddhist Era years (256x-257x)
    $keyword = preg_replace_callback('/\b(25[6-7]\d)\b/', function($m) use ($currentYearBE) {
        $year = (int)$m[1];
        return ($year >= 2562 && $year < $currentYearBE) ? (string)$currentYearBE : $m[0];
    }, $keyword);
    return $keyword;
}

/**
 * Word count for Thai text
 */
function countWords(string $text): int {
    // Decode HTML entities first, then remove tags
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    // Count Thai words (approximate by characters / 2 for Thai)
    // and English words normally
    $thaiChars = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $text);
    $englishWords = str_word_count(preg_replace('/[\x{0E00}-\x{0E7F}]/u', '', $text));

    return (int) ceil($thaiChars / 2) + $englishWords;
}

/**
 * Extract FAQ from article HTML and generate FAQPage JSON-LD Schema
 * Looks for H2 "คำถามที่พบบ่อย" followed by H3 questions with answer paragraphs
 */
function generateFaqSchema(string $content): string {
    // Find FAQ section: H3 questions after the FAQ H2
    // Pattern: <h3>question</h3> followed by text content (paragraphs, lists, etc.)
    if (!preg_match('/<h2[^>]*>.*?(?:คำถามที่พบบ่อย|ถาม-ตอบ|ถามตอบ|FAQ).*?<\/h2>(.*?)(?=<h2|$)/is', $content, $faqSection)) {
        return '';
    }

    $faqHtml = $faqSection[1];

    // Extract each Q&A pair: <h3>question</h3> followed by content until next <h3> or end
    if (!preg_match_all('/<h3[^>]*>(.*?)<\/h3>(.*?)(?=<h3|$)/is', $faqHtml, $matches, PREG_SET_ORDER)) {
        return '';
    }

    $faqItems = [];
    foreach ($matches as $match) {
        $question = trim(strip_tags($match[1]));
        $answer = trim(strip_tags($match[2]));

        if (!empty($question) && !empty($answer)) {
            $faqItems[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer
                ]
            ];
        }
    }

    if (empty($faqItems)) {
        return '';
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faqItems
    ];

    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}

/**
 * Convert FAQ section to Accordion HTML + append FAQ Schema
 * Replaces H3 Q&A with <details>/<summary> accordion and adds JSON-LD
 */
function appendFaqSchema(string $content): string {
    // Find FAQ section
    if (!preg_match('/(<h2[^>]*>.*?(?:คำถามที่พบบ่อย|ถาม-ตอบ|ถามตอบ|FAQ).*?<\/h2>)(.*?)(?=<h2|$)/is', $content, $faqSection)) {
        return $content;
    }

    $faqH2 = $faqSection[1];
    $faqBody = $faqSection[2];

    // Extract Q&A pairs
    if (!preg_match_all('/<h3[^>]*>(.*?)<\/h3>(.*?)(?=<h3|$)/is', $faqBody, $matches, PREG_SET_ORDER)) {
        return $content;
    }

    // Build accordion HTML and Schema items
    $accordionHtml = '<div class="faq-accordion">' . "\n";
    $schemaItems = [];

    $style = '<style>.faq-accordion details{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;overflow:hidden}.faq-accordion summary{padding:14px 18px;font-weight:600;font-size:16px;cursor:pointer;background:#f8fafc;color:#000;list-style:none;display:flex;align-items:center;justify-content:space-between}.faq-accordion summary::-webkit-details-marker{display:none}.faq-accordion summary::after{content:"＋";font-size:18px;transition:transform .2s}.faq-accordion details[open] summary::after{content:"－"}.faq-accordion details[open] summary{background:#eef2ff;color:#000}.faq-accordion .faq-answer{padding:14px 18px;line-height:1.7}</style>';
    $accordionHtml = $style . "\n" . $accordionHtml;

    foreach ($matches as $i => $match) {
        $question = trim(strip_tags($match[1]));
        $answerHtml = trim($match[2]);
        $answerText = trim(strip_tags($answerHtml));

        if (empty($question) || empty($answerText)) continue;

        // First item open by default
        $open = $i === 0 ? ' open' : '';
        $accordionHtml .= "<details{$open}>\n";
        $accordionHtml .= "  <summary>{$question}</summary>\n";
        $accordionHtml .= "  <div class=\"faq-answer\">{$answerHtml}</div>\n";
        $accordionHtml .= "</details>\n";

        $schemaItems[] = [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $answerText
            ]
        ];
    }

    $accordionHtml .= "</div>\n";

    // Replace original FAQ body with accordion
    $content = str_replace($faqSection[0], $faqH2 . "\n" . $accordionHtml, $content);

    // Append JSON-LD Schema
    if (!empty($schemaItems)) {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $schemaItems
        ];
        $content .= "\n" . '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }

    return $content;
}

/**
 * Truncate text
 */
function truncateText(string $text, int $length = 100, string $suffix = '...'): string {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . $suffix;
}

/**
 * Generate slug from text
 */
function generateSlug(string $text): string {
    // Transliterate Thai to ASCII (simplified)
    $text = preg_replace('/[\x{0E00}-\x{0E7F}]+/u', '', $text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Get prompt template from database by type, with fallback to default
 */
function getPromptTemplate(string $type, string $default = ''): string {
    try {
        $prompt = db()->fetchOne("
            SELECT prompt_content FROM prompt_templates
            WHERE template_type = ? AND is_active = 1
            ORDER BY is_default DESC
            LIMIT 1
        ", [$type]);
        return $prompt ? $prompt['prompt_content'] : $default;
    } catch (\Exception $e) {
        return $default;
    }
}

/**
 * Prompt type definitions - used by admin page and helper
 */
function getPromptTypes(): array {
    return [
        'article' => [
            'name' => 'สร้างบทความ',
            'desc' => 'Prompt หลักสำหรับสร้างบทความด้วย AI',
            'icon' => 'fa-newspaper',
            'vars' => ['{keyword}', '{topic}', '{min_words}', '{max_words}'],
            'file' => 'job_generate_articles.php'
        ],
        'system' => [
            'name' => 'System Prompt',
            'desc' => 'คำสั่งพื้นฐานที่ส่งให้ AI ทุกครั้ง (บอกว่า AI เป็นใคร)',
            'icon' => 'fa-cog',
            'vars' => [],
            'file' => 'ai_client.php'
        ],
        'image' => [
            'name' => 'สร้างรูปภาพ Featured Image',
            'desc' => 'Prompt สำหรับสร้างรูปภาพประกอบบทความ',
            'icon' => 'fa-image',
            'vars' => ['{title}', '{keyword}', '{topic}'],
            'file' => 'image_generator.php'
        ],
        'keyword_cluster' => [
            'name' => 'วิเคราะห์ Keyword Cluster',
            'desc' => 'วิเคราะห์และจัดกลุ่ม keyword (Secondary, Long Tail, LSI)',
            'icon' => 'fa-search',
            'vars' => ['{primary_keyword}', '{topic}', '{keywords_json}'],
            'file' => 'keyword_analyzer.php'
        ],
        'content_plan' => [
            'name' => 'วางแผนเนื้อหา',
            'desc' => 'Prompt สำหรับ AI วางแผน Content Calendar',
            'icon' => 'fa-calendar-alt',
            'vars' => ['{site_name}', '{topic}', '{days}', '{keywords_json}'],
            'file' => 'content_planner.php'
        ],
        'quality_check' => [
            'name' => 'ตรวจคุณภาพบทความ',
            'desc' => 'ตรวจ SEO/Content/UX ก่อนเผยแพร่',
            'icon' => 'fa-check-circle',
            'vars' => ['{title}', '{keyword}', '{content}'],
            'file' => 'content_quality_checker.php'
        ],
        'ctr_optimize' => [
            'name' => 'ปรับปรุง CTR',
            'desc' => 'ปรับหัวข้อและ Meta Description เพื่อเพิ่ม CTR',
            'icon' => 'fa-chart-line',
            'vars' => ['{title}', '{keyword}', '{ctr}', '{position}'],
            'file' => 'ctr_optimizer.php'
        ],
        'content_refresh' => [
            'name' => 'อัปเดตบทความเก่า',
            'desc' => 'รีเฟรชเนื้อหาให้ทันสมัย เพิ่มข้อมูลใหม่',
            'icon' => 'fa-sync',
            'vars' => ['{title}', '{keyword}', '{content}', '{year}'],
            'file' => 'content_refresher.php'
        ],
        'risk_filter' => [
            'name' => 'ตรวจความเสี่ยง',
            'desc' => 'ตรวจสอบความเหมาะสมและความปลอดภัยของเนื้อหา',
            'icon' => 'fa-shield-alt',
            'vars' => ['{content}', '{topic}'],
            'file' => 'risk_filter.php'
        ],
    ];
}
