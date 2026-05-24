<?php
/**
 * AI AutoPost SEO System - Security Manager
 * ==========================================
 * IP banning, rate limiting, attack detection, security headers
 */

require_once __DIR__ . '/db.php';

class SecurityManager {
    private static ?self $instance = null;
    private string $ip;

    // Known bad user-agent substrings (scanners, exploit tools)
    private const BAD_UA = [
        'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'nuclei',
        'dirbuster', 'gobuster', 'wfuzz', 'hydra', 'metasploit',
        'nessus', 'openvas', 'burpsuite', 'acunetix', 'arachni',
        'havij', 'pangolin', 'beef/', 'libwww-perl',
        'semrushbot', 'ahrefsbot', 'dotbot', 'mj12bot',
    ];

    // Patterns that indicate attack attempts in URI/query string
    private const ATTACK_PATTERNS = [
        // SQL Injection
        'sqli'  => [
            '/(\bunion\b.{0,30}\bselect\b)/i',
            '/(\bselect\b.{0,30}\bfrom\b)/i',
            '/(\bdrop\b.{0,20}\btable\b)/i',
            '/(\binsert\b.{0,20}\binto\b)/i',
            '/(\'|\%27)\s*(or|and)\s*(\'|\%27|\d)/i',
            '/\b(sleep|benchmark|waitfor\s+delay)\s*\(/i',
            '/\bload_file\s*\(/i',
            '/\binto\s+(outfile|dumpfile)\b/i',
            '/information_schema\./i',
        ],
        // XSS
        'xss' => [
            '/<script[\s>]/i',
            '/javascript\s*:/i',
            '/on(error|load|click|mouse\w+|key\w+|focus|blur)\s*=/i',
            '/<\s*(iframe|object|embed|applet|link|base)\b/i',
            '/expression\s*\(/i',
            '/vbscript\s*:/i',
            '/data\s*:\s*text\/html/i',
        ],
        // LFI / Path Traversal
        'lfi' => [
            '/\.\.[\/\\\\]/i',
            '/\.\.[%\s]/i',
            '/%2e%2e[%2f%5c]/i',
            '/(\/etc\/passwd|\/etc\/shadow|\/proc\/self)/i',
            '/\bfile\s*:\/\//i',
            '/(boot\.ini|win\.ini|system32)/i',
        ],
        // RFI
        'rfi' => [
            '/=[a-z]{3,6}:\/\/[^\s&]{8,}/i',  // =http:// =ftp:// etc in param values
        ],
        // Shell / Command Injection
        'shell' => [
            '/[;&|`]\s*(ls|cat|wget|curl|bash|sh|nc|python|perl|php)\b/i',
            '/\$\(.*\)/',
            '/`[^`]{1,100}`/',
        ],
    ];

    // Auto-ban thresholds: after N events of a type within 10 minutes → ban
    private const AUTO_BAN_RULES = [
        'sqli_attempt'  => ['threshold' => 3,  'ban_hours' => 48],
        'xss_attempt'   => ['threshold' => 5,  'ban_hours' => 24],
        'lfi_attempt'   => ['threshold' => 3,  'ban_hours' => 48],
        'rfi_attempt'   => ['threshold' => 3,  'ban_hours' => 72],
        'shell_attempt' => ['threshold' => 2,  'ban_hours' => 168], // 1 week
        'rate_exceeded' => ['threshold' => 10, 'ban_hours' => 1],
        'login_abuse'   => ['threshold' => 20, 'ban_hours' => 24],
    ];

    private function __construct() {
        $this->ip = $this->resolveClientIp();
    }

    private function resolveClientIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Only trust proxy headers when the direct connection is from a private/known range
        $privatePrefixes = ['127.', '::1', '172.', '10.', '192.168.'];
        $isPrivate = false;
        foreach ($privatePrefixes as $p) {
            if (str_starts_with($remoteAddr, $p)) { $isPrivate = true; break; }
        }
        if ($isPrivate) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
                if (!empty($_SERVER[$h]) && filter_var($_SERVER[$h], FILTER_VALIDATE_IP)) {
                    return $_SERVER[$h];
                }
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($parts[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $remoteAddr;
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Boot (called on every web request) ───────────────────────────────────

    public function boot(): void {
        // Skip CLI
        if (php_sapi_name() === 'cli') return;

        $this->sendSecurityHeaders();

        // 1. Check IP ban
        if ($this->isIpBanned($this->ip)) {
            http_response_code(403);
            $this->logEvent('blocked_banned_ip', 'high', 'Request blocked — IP is banned');
            $this->outputBlockPage('IP ของคุณถูกบล็อก กรุณาติดต่อผู้ดูแลระบบ');
        }

        // 2. Detect attack patterns in URL + query string (not POST body to avoid false positives)
        $target = ($this->getRequestUri() . '?' . ($this->getQueryString()));
        $attackType = $this->detectPatterns($target);
        if ($attackType) {
            $this->logEvent("{$attackType}_attempt", 'critical',
                "Attack detected [{$attackType}] in: " . substr($target, 0, 500));
            $this->maybeAutoBan("{$attackType}_attempt");
            http_response_code(400);
            $this->outputBlockPage('คำขอไม่ถูกต้อง');
        }

        // 3. Check bad user agent
        if ($this->isBadUserAgent()) {
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
            $this->logEvent('bad_user_agent', 'medium', "Blocked UA: {$ua}");
            http_response_code(403);
            $this->outputBlockPage('Access Denied');
        }

        // 4. Periodic cleanup (1% chance per request)
        if (mt_rand(1, 100) === 1) {
            $this->cleanup();
        }
    }

    // ─── IP Banning ────────────────────────────────────────────────────────────

    public function isIpBanned(string $ip): bool {
        static $cache = [];
        if (isset($cache[$ip])) return $cache[$ip];

        try {
            $ban = db()->fetchOne(
                "SELECT id FROM ip_bans
                 WHERE ip_address = ?
                   AND (is_permanent = 1 OR expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1",
                [$ip]
            );
            return $cache[$ip] = ($ban !== null);
        } catch (Exception $e) {
            return $cache[$ip] = false;
        }
    }

    public function banIp(string $ip, string $reason, int $hours = 24, bool $permanent = false, ?int $bannedBy = null): void {
        try {
            $expiresAt = $permanent ? null : date('Y-m-d H:i:s', time() + $hours * 3600);
            db()->query(
                "INSERT INTO ip_bans (ip_address, reason, is_permanent, expires_at, banned_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   reason = VALUES(reason),
                   is_permanent = VALUES(is_permanent),
                   expires_at = CASE WHEN VALUES(is_permanent)=1 THEN NULL
                                     WHEN expires_at IS NULL THEN NULL
                                     WHEN VALUES(expires_at) > expires_at THEN VALUES(expires_at)
                                     ELSE expires_at END,
                   banned_at = NOW()",
                [$ip, $reason, $permanent ? 1 : 0, $expiresAt, $bannedBy]
            );
            $this->logEvent('ip_banned', 'high',
                "IP {$ip} banned: {$reason} | permanent=" . ($permanent ? 'yes' : 'no') . " hours={$hours}");
        } catch (Exception $e) {}
    }

    public function unbanIp(string $ip): bool {
        try {
            $rows = db()->delete('ip_bans', 'ip_address = ?', [$ip]);
            if ($rows > 0) {
                $this->logEvent('ip_unbanned', 'low', "IP {$ip} unbanned");
            }
            return $rows > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    // ─── Rate Limiting ─────────────────────────────────────────────────────────

    /**
     * Check rate limit. Returns true if allowed, false if exceeded.
     * Automatically logs event and may auto-ban on repeated violations.
     */
    public function rateLimit(string $action, int $maxAttempts, int $windowSeconds, ?string $ip = null): bool {
        $ip = $ip ?? $this->ip;
        $bucket = (int)floor(time() / $windowSeconds);

        try {
            // Atomic increment
            db()->query(
                "INSERT INTO rate_limits (ip_address, action, window_bucket, attempts)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE attempts = attempts + 1",
                [$ip, $action, $bucket]
            );

            $attempts = (int)db()->fetchColumn(
                "SELECT attempts FROM rate_limits WHERE ip_address=? AND action=? AND window_bucket=?",
                [$ip, $action, $bucket]
            );

            if ($attempts > $maxAttempts) {
                $this->logEvent('rate_exceeded', 'high',
                    "Rate limit exceeded: {$action} — {$attempts}/{$maxAttempts} in {$windowSeconds}s");
                $this->maybeAutoBan('rate_exceeded');
                return false;
            }
        } catch (Exception $e) {}

        return true;
    }

    // ─── Security Events ───────────────────────────────────────────────────────

    public function logEvent(string $type, string $severity, string $details, ?int $memberId = null): void {
        try {
            db()->query(
                "INSERT INTO security_events
                 (event_type, severity, ip_address, user_agent, request_uri, details, member_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $type,
                    $severity,
                    $this->ip,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    substr($this->getRequestUri(), 0, 1000),
                    $details,
                    $memberId
                ]
            );
        } catch (Exception $e) {}
    }

    // ─── Auto-ban Logic ────────────────────────────────────────────────────────

    private function maybeAutoBan(string $eventType): void {
        $rule = self::AUTO_BAN_RULES[$eventType] ?? null;
        if (!$rule) return;

        try {
            $count = (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM security_events
                 WHERE ip_address = ? AND event_type = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                [$this->ip, $eventType]
            );

            if ($count >= $rule['threshold']) {
                $this->banIp(
                    $this->ip,
                    "Auto-ban: {$count}x {$eventType} in 10 minutes",
                    $rule['ban_hours']
                );
            }
        } catch (Exception $e) {}
    }

    // ─── Attack Detection ──────────────────────────────────────────────────────

    private function detectPatterns(string $input): ?string {
        // Normalize: urldecode handles both %XX and + as space; apply twice for double-encoding
        $decoded = urldecode(urldecode($input));
        // Strip SQL comments (/**/ bypass technique) then re-check
        $stripped = preg_replace('/\/\*.*?\*\//s', ' ', $decoded);

        foreach ([$decoded, $stripped] as $target) {
            foreach (self::ATTACK_PATTERNS as $type => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $target)) {
                        return $type;
                    }
                }
            }
        }
        return null;
    }

    private function isBadUserAgent(): bool {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (empty($ua)) return false; // Allow empty UA (some legit tools)

        foreach (self::BAD_UA as $bad) {
            if (str_contains($ua, $bad)) return true;
        }
        return false;
    }

    // ─── Security Headers ──────────────────────────────────────────────────────

    public function sendSecurityHeaders(): void {
        if (headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('X-Permitted-Cross-Domain-Policies: none');
        // Remove server fingerprint
        header_remove('X-Powered-By');
        header('Server: ');
    }

    // ─── Admin Queries ─────────────────────────────────────────────────────────

    public function getEvents(int $limit = 100, array $filters = []): array {
        $where = '1=1';
        $params = [];

        if (!empty($filters['severity'])) {
            $where .= ' AND severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['event_type'])) {
            $where .= ' AND event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['ip'])) {
            $where .= ' AND ip_address = ?';
            $params[] = $filters['ip'];
        }
        if (!empty($filters['since'])) {
            $where .= ' AND created_at >= ?';
            $params[] = $filters['since'];
        }

        $params[] = $limit;
        return db()->fetchAll(
            "SELECT * FROM security_events WHERE {$where} ORDER BY created_at DESC LIMIT ?",
            $params
        );
    }

    public function getBans(): array {
        return db()->fetchAll(
            "SELECT ib.*, au.username as banned_by_name
             FROM ip_bans ib
             LEFT JOIN admin_users au ON au.id = ib.banned_by
             ORDER BY ib.banned_at DESC"
        );
    }

    public function getStats(): array {
        try {
            $row = db()->fetchOne(
                "SELECT
                    COUNT(*) as total_events,
                    SUM(severity='critical') as critical,
                    SUM(severity='high') as high_count,
                    SUM(severity='medium') as medium_count,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as last_hour
                 FROM security_events
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $bans = db()->fetchColumn("SELECT COUNT(*) FROM ip_bans WHERE is_permanent=1 OR expires_at > NOW()");
            $row['active_bans'] = (int)$bans;
            return $row ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    public function getTopAttackers(int $limit = 10): array {
        return db()->fetchAll(
            "SELECT ip_address, COUNT(*) as events,
                    SUM(severity='critical') as critical,
                    MAX(created_at) as last_seen
             FROM security_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY ip_address
             ORDER BY events DESC
             LIMIT ?",
            [$limit]
        );
    }

    public function getEventTypeSummary(): array {
        return db()->fetchAll(
            "SELECT event_type, COUNT(*) as cnt, MAX(created_at) as last_seen
             FROM security_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY event_type
             ORDER BY cnt DESC"
        );
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function getRequestUri(): string {
        return $_SERVER['REQUEST_URI'] ?? '';
    }

    private function getQueryString(): string {
        return $_SERVER['QUERY_STRING'] ?? '';
    }

    private function cleanup(): void {
        try {
            // Old rate limit buckets (older than 1 hour)
            $cutoff = (int)floor((time() - 3600) / 60);
            db()->query("DELETE FROM rate_limits WHERE window_bucket < ?", [$cutoff]);
            // Old security events (keep 30 days)
            db()->query("DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            // Expired IP bans
            db()->query("DELETE FROM ip_bans WHERE is_permanent=0 AND expires_at IS NOT NULL AND expires_at < NOW()");
        } catch (Exception $e) {}
    }

    private function outputBlockPage(string $message): never {
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">
        <title>Access Denied</title>
        <style>body{font-family:sans-serif;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{text-align:center;padding:40px;background:#1e293b;border-radius:16px;border:1px solid #334155}
        h1{color:#ef4444;font-size:24px}p{color:#94a3b8}</style></head>
        <body><div class="box">
        <h1>&#128683; Access Denied</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <p style="font-size:12px;margin-top:20px">IP: ' . htmlspecialchars($this->ip) . '</p>
        </div></body></html>';
        exit;
    }

    private function __clone() {}
    public function __wakeup() { throw new Exception('Cannot unserialize singleton'); }
}

/**
 * Helper function
 */
function security(): SecurityManager {
    return SecurityManager::getInstance();
}
