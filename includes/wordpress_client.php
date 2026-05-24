<?php
/**
 * AI AutoPost SEO System - WordPress Client
 * ==========================================
 * WordPress REST API client for posting articles
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class WordPressClient {
    private $baseUrl;
    private $apiUrl;
    private $username;
    private $appPassword;
    private $siteId;
    private $currentUserId = null;

    public function __construct(array $site) {
        $this->siteId = $site['id'] ?? null;
        $this->baseUrl = rtrim($site['base_url'], '/');
        $this->apiUrl = rtrim($site['wp_api_url'], '/');
        $this->username = $site['wp_user'];
        $this->appPassword = isset($site['wp_app_password']) ? decrypt($site['wp_app_password']) : '';
    }

    /**
     * Get current authenticated user ID
     */
    public function getCurrentUserId(): ?int {
        if ($this->currentUserId !== null) {
            return $this->currentUserId;
        }

        try {
            $user = $this->request('GET', '/users/me', [], true);
            if (isset($user['id'])) {
                $this->currentUserId = (int)$user['id'];
                return $this->currentUserId;
            }
        } catch (Exception $e) {
            // Silent fail
        }

        return null;
    }

    /**
     * Test connection to WordPress
     */
    public function testConnection(): array {
        try {
            // First try to get site info (no auth needed)
            $siteInfo = $this->request('GET', '');

            if (!$siteInfo) {
                return ['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อกับ WordPress REST API'];
            }

            // Then try authenticated request to verify credentials
            $user = $this->request('GET', '/users/me', [], true);

            if (isset($user['code']) && $user['code'] === 'rest_not_logged_in') {
                return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือ Application Password ไม่ถูกต้อง'];
            }

            if (!isset($user['id'])) {
                return ['success' => false, 'message' => 'ไม่สามารถยืนยันตัวตนได้'];
            }

            // Cache user ID
            $this->currentUserId = (int)$user['id'];

            // Check user capabilities
            $canPublish = false;
            if (isset($user['capabilities'])) {
                $canPublish = isset($user['capabilities']['publish_posts']) && $user['capabilities']['publish_posts'];
            }

            return [
                'success' => true,
                'message' => 'เชื่อมต่อสำเร็จ',
                'site_name' => $siteInfo['name'] ?? '',
                'site_url' => $siteInfo['url'] ?? $this->baseUrl,
                'user_name' => $user['name'] ?? $this->username,
                'user_id' => $user['id'],
                'can_publish' => $canPublish
            ];

        } catch (MdesBlockedException $e) {
            return [
                'success' => false,
                'mdes_blocked' => true,
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create a new post
     */
    public function createPost(array $postData): array {
        // *** FIX: Encode slug ภาษาไทยให้ WordPress รองรับ ***
        $slug = $postData['slug'] ?? '';
        if (!empty($slug)) {
            // URL encode เฉพาะอักขระ non-ASCII (ภาษาไทย, จีน, เวียดนาม ฯลฯ)
            // WordPress จะ decode และเก็บเป็น UTF-8 slug
            $slug = implode('-', array_map(function($part) {
                // ถ้ามีอักขระ non-ASCII ให้ encode
                if (preg_match('/[^\x00-\x7F]/', $part)) {
                    return rawurlencode($part);
                }
                return $part;
            }, explode('-', $slug)));
        }

        $data = [
            'title' => $postData['title'],
            'content' => $postData['content'],
            'status' => $postData['status'] ?? 'draft',
            'excerpt' => $postData['excerpt'] ?? '',
            'slug' => $slug
        ];

        // Set author: use provided value, or get current authenticated user ID
        if (!empty($postData['author'])) {
            $data['author'] = (int)$postData['author'];
        } else {
            // Get current user ID to prevent "Invalid author ID" error
            $currentUserId = $this->getCurrentUserId();
            if ($currentUserId) {
                $data['author'] = $currentUserId;
            }
        }

        // Add categories if specified
        if (!empty($postData['categories'])) {
            $data['categories'] = $postData['categories'];
        } elseif (!empty($postData['category_id'])) {
            $data['categories'] = [$postData['category_id']];
        }

        // Add featured image if specified
        if (!empty($postData['featured_media'])) {
            $data['featured_media'] = (int)$postData['featured_media'];
        } elseif (!empty($postData['featured_image_id'])) {
            $data['featured_media'] = (int)$postData['featured_image_id'];
        }

        // Add SEO meta (if Yoast/RankMath installed)
        $meta = [];

        // Meta Title & Description
        if (!empty($postData['meta_title'])) {
            $meta['_yoast_wpseo_title'] = $postData['meta_title'];
            $meta['_yoast_wpseo_metadesc'] = $postData['meta_description'] ?? '';
        }

        // Focus Keyword - รองรับทั้ง Yoast และ Rank Math
        if (!empty($postData['focus_keyword'])) {
            // Yoast SEO
            $meta['_yoast_wpseo_focuskw'] = $postData['focus_keyword'];
            // Rank Math SEO
            $meta['rank_math_focus_keyword'] = $postData['focus_keyword'];
        }

        if (!empty($meta)) {
            $data['meta'] = $meta;
        }

        $response = $this->request('POST', '/posts', $data, true);

        if (isset($response['id'])) {
            return [
                'success' => true,
                'post_id' => $response['id'],
                'post_url' => $response['link'] ?? '',
                'post_slug' => $response['slug'] ?? ''
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Failed to create post'
        ];
    }

    /**
     * Update an existing post
     */
    public function updatePost(int $postId, array $postData): array {
        $response = $this->request('POST', "/posts/{$postId}", $postData, true);

        if (isset($response['id'])) {
            return [
                'success' => true,
                'post_id' => $response['id'],
                'post_url' => $response['link'] ?? ''
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Failed to update post'
        ];
    }

    /**
     * Delete a post
     */
    public function deletePost(int $postId, bool $force = false): array {
        $params = $force ? ['force' => true] : [];
        $response = $this->request('DELETE', "/posts/{$postId}", $params, true);

        return [
            'success' => isset($response['deleted']) || isset($response['id']),
            'message' => $response['message'] ?? ''
        ];
    }

    /**
     * Get a post by ID
     */
    public function getPost(int $postId): ?array {
        $response = $this->request('GET', "/posts/{$postId}", [], true);
        return isset($response['id']) ? $response : null;
    }

    /**
     * Get posts list
     */
    public function getPosts(array $params = []): array {
        $defaults = [
            'per_page' => 10,
            'page' => 1,
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'desc'
        ];

        $params = array_merge($defaults, $params);
        return $this->request('GET', '/posts', $params, true) ?: [];
    }

    /**
     * Upload media
     * @param string $filePath Local file path
     * @param string $fileName Filename for WordPress
     * @param string $altText Alt text for SEO
     * @param string $title Title for media (optional, defaults to keyword only)
     */
    public function uploadMedia(string $filePath, string $fileName = '', string $altText = '', string $title = ''): array {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found'];
        }

        $fileName = $fileName ?: basename($filePath);
        $mimeType = mime_content_type($filePath);

        $url = $this->apiUrl . '/media';

        $ch = curl_init($url);

        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->appPassword),
            'Content-Disposition: attachment; filename="' . $fileName . '"',
            'Content-Type: ' . $mimeType
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => file_get_contents($filePath),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['id'])) {
            $mediaId = $decoded['id'];

            // Update title and alt text - ใช้แค่ keyword อย่างเดียว
            $updateData = [];
            if (!empty($altText)) {
                $updateData['alt_text'] = $altText;
            }
            if (!empty($title)) {
                $updateData['title'] = $title;
            }
            if (!empty($updateData)) {
                $this->updateMedia($mediaId, $updateData);
            }

            return [
                'success' => true,
                'media_id' => $mediaId,
                'media_url' => $decoded['source_url'] ?? ''
            ];
        }

        return [
            'success' => false,
            'message' => $decoded['message'] ?? 'Failed to upload media'
        ];
    }

    /**
     * Update media attributes (alt text, caption, etc.)
     */
    public function updateMedia(int $mediaId, array $data): array {
        $response = $this->request('POST', "/media/{$mediaId}", $data, true);

        if (isset($response['id'])) {
            return ['success' => true, 'media_id' => $response['id']];
        }

        return ['success' => false, 'message' => $response['message'] ?? 'Failed to update media'];
    }

    /**
     * Upload media from URL
     */
    public function uploadMediaFromUrl(string $imageUrl, string $title = ''): array {
        // Download image to temp file using cURL (more reliable than file_get_contents)
        $tempFile = tempnam(sys_get_temp_dir(), 'wp_media_');

        $ch = curl_init($imageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'AI-AutoPost-SEO/1.0'
        ]);
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || !$imageData) {
            @unlink($tempFile);
            return ['success' => false, 'message' => 'Failed to download image: ' . ($error ?: "HTTP {$httpCode}")];
        }

        file_put_contents($tempFile, $imageData);

        // Generate clean filename from title
        $fileName = !empty($title) ? preg_replace('/[^a-zA-Z0-9\p{Thai}]+/u', '-', $title) : 'ai-image-' . date('Ymd-His');
        $fileName = trim($fileName, '-') . '.png';

        $result = $this->uploadMedia($tempFile, $fileName);

        // Cleanup
        @unlink($tempFile);

        return $result;
    }

    /**
     * Get categories
     */
    public function getCategories(array $params = []): array {
        $defaults = ['per_page' => 100, 'hide_empty' => false];
        $params = array_merge($defaults, $params);
        return $this->request('GET', '/categories', $params, true) ?: [];
    }

    /**
     * Get tags
     */
    public function getTags(array $params = []): array {
        $defaults = ['per_page' => 100, 'hide_empty' => false];
        $params = array_merge($defaults, $params);
        return $this->request('GET', '/tags', $params, true) ?: [];
    }

    /**
     * Create or get tag by name
     */
    public function createTag(string $name): ?int {
        // Check if tag exists
        $existing = $this->request('GET', '/tags', ['search' => $name], true);
        foreach ($existing as $tag) {
            if (strtolower($tag['name']) === strtolower($name)) {
                return $tag['id'];
            }
        }

        // Create new tag
        $response = $this->request('POST', '/tags', ['name' => $name], true);
        return $response['id'] ?? null;
    }

    /**
     * Make HTTP request to WordPress REST API
     */
    private function request(string $method, string $endpoint, array $data = [], bool $auth = false): ?array {
        $url = $this->apiUrl . $endpoint;

        // Add query params for GET requests
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        // Add authentication
        if ($auth && !empty($this->username) && !empty($this->appPassword)) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->appPassword);
        }

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2
        ];

        // Apply anti-MDES settings if configured (DoH or SOCKS5/HTTP proxy)
        $proxySettings = $this->getProxySettings();
        if ($proxySettings) {
            if ($proxySettings['mode'] === 'doh') {
                $options[CURLOPT_DOH_URL] = $proxySettings['doh_url'];
            } elseif ($proxySettings['mode'] === 'proxy') {
                $options[CURLOPT_PROXY] = $proxySettings['host'] . ':' . $proxySettings['port'];
                $options[CURLOPT_PROXYTYPE] = $proxySettings['type'] === 'http'
                    ? CURLPROXY_HTTP
                    : CURLPROXY_SOCKS5_HOSTNAME;
            }
        }

        switch ($method) {
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
            case 'PUT':
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                if (!empty($data)) {
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        // Check for Thai MDES block (DNS redirects to illegal.mdes.go.th)
        if ($error && strpos($error, 'SSL') !== false) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            curl_close($ch);

            // Detect MDES block by checking certificate or IP
            if ($this->isMdesBlocked($url)) {
                throw new MdesBlockedException("เว็บไซต์ถูกบล็อกโดยกระทรวงดิจิทัลฯ (MDES) — ต้องใช้ VPN เพื่อเชื่อมต่อ");
            }

            // Retry with relaxed SSL if not MDES blocked
            $ch = curl_init($url);
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            unset($options[CURLOPT_SSLVERSION]);
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
        }

        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: {$error}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response");
        }

        return $decoded;
    }

    /**
     * Get site ID
     */
    public function getSiteId(): ?int {
        return $this->siteId;
    }

    /**
     * Get base URL
     */
    public function getBaseUrl(): string {
        return $this->baseUrl;
    }

    /**
     * Get proxy/DoH settings from system_settings
     * Returns null if bypass is disabled
     */
    private function getProxySettings(): ?array {
        static $loaded = false;
        static $cache = null;

        if ($loaded) {
            return $cache;
        }
        $loaded = true;

        $enabled = getSetting('proxy_enabled', '0');
        if ($enabled !== '1') {
            return null;
        }

        $mode = getSetting('proxy_mode', 'doh'); // 'doh' or 'proxy'

        if ($mode === 'doh') {
            if (!defined('CURLOPT_DOH_URL')) {
                logEvent('warning', 'proxy', 'DoH mode enabled but CURLOPT_DOH_URL not supported by this PHP/cURL version');
                return null;
            }
            $cache = [
                'mode' => 'doh',
                'doh_url' => getSetting('proxy_doh_url', 'https://cloudflare-dns.com/dns-query'),
            ];
        } else {
            $cache = [
                'mode' => 'proxy',
                'host' => getSetting('proxy_host', 'warp-proxy'),
                'port' => (int) getSetting('proxy_port', '1080'),
                'type' => getSetting('proxy_type', 'socks5'),
            ];
        }
        return $cache;
    }

    /**
     * Check if a URL is blocked by Thai MDES (Ministry of Digital Economy and Society)
     * MDES redirects DNS to illegal.mdes.go.th (IP: 125.26.170.x range)
     */
    private function isMdesBlocked(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        // Check by connecting and reading the SSL certificate
        $ch = curl_init("https://{$host}:443");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CERTINFO => true,
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        curl_close($ch);

        if (!empty($certInfo)) {
            foreach ($certInfo as $cert) {
                $subject = $cert['Subject'] ?? '';
                if (stripos($subject, 'mdes.go.th') !== false || stripos($subject, 'illegal.mdes') !== false) {
                    return true;
                }
            }
        }

        // Fallback: resolve IP and check if it's a known MDES IP
        $ip = gethostbyname($host);
        if (strpos($ip, '125.26.170.') === 0) {
            return true;
        }

        return false;
    }
}

/**
 * Custom exception for MDES-blocked websites
 */
class MdesBlockedException extends Exception {}

/**
 * Helper function to create WordPress client from site ID
 */
function wordpress(int $siteId): WordPressClient {
    $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);

    if (!$site) {
        throw new Exception("Site not found: {$siteId}");
    }

    return new WordPressClient($site);
}
