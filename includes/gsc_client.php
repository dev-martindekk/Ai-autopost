<?php
/**
 * AI AutoPost SEO System - Google Search Console Client
 * ======================================================
 * Integrates with GSC API for performance data and keyword opportunities
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class GSCClient {
    private $siteId;
    private $credentials;
    private $accessToken;
    private $baseUrl = 'https://www.googleapis.com/webmasters/v3';
    private $searchAnalyticsUrl = 'https://searchconsole.googleapis.com/webmasters/v3';

    /**
     * Initialize with site
     */
    public function __construct(int $siteId) {
        $this->siteId = $siteId;
        $this->loadCredentials();
    }

    /**
     * Load GSC credentials from database
     */
    private function loadCredentials(): void {
        $this->credentials = db()->fetchOne("
            SELECT * FROM gsc_credentials WHERE site_id = ? AND is_enabled = 1
        ", [$this->siteId]);
    }

    /**
     * Check if GSC is configured
     */
    public function isConfigured(): bool {
        return !empty($this->credentials) && !empty($this->credentials['refresh_token']);
    }

    /**
     * Refresh access token if needed
     */
    private function refreshTokenIfNeeded(): bool {
        if (empty($this->credentials)) {
            return false;
        }

        // Check if token is still valid (with 5 min buffer)
        if (!empty($this->credentials['token_expires_at'])) {
            $expiresAt = strtotime($this->credentials['token_expires_at']);
            if ($expiresAt > time() + 300) {
                $this->accessToken = decrypt($this->credentials['access_token']);
                return true;
            }
        }

        // Refresh token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->credentials['client_id'],
                'client_secret' => decrypt($this->credentials['client_secret']),
                'refresh_token' => decrypt($this->credentials['refresh_token']),
                'grant_type' => 'refresh_token'
            ]),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            logEvent('error', 'gsc', 'Failed to refresh token', ['response' => $response]);
            return false;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            return false;
        }

        // Update database with new token
        $expiresAt = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
        db()->query("
            UPDATE gsc_credentials
            SET access_token = ?, token_expires_at = ?
            WHERE site_id = ?
        ", [encrypt($data['access_token']), $expiresAt, $this->siteId]);

        $this->accessToken = $data['access_token'];
        return true;
    }

    /**
     * Make API request
     */
    private function request(string $endpoint, array $data = [], string $method = 'POST'): array {
        if (!$this->refreshTokenIfNeeded()) {
            return ['success' => false, 'error' => 'Failed to authenticate with GSC'];
        }

        $url = $this->searchAnalyticsUrl . $endpoint;

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => $decoded['error']['message'] ?? 'Unknown error',
                'code' => $httpCode
            ];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Get search analytics data
     */
    public function getSearchAnalytics(int $days = 28, string $dimension = 'query'): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'GSC not configured'];
        }

        $siteUrl = $this->credentials['property_url'];
        $endpoint = "/sites/" . urlencode($siteUrl) . "/searchAnalytics/query";

        $data = [
            'startDate' => date('Y-m-d', strtotime("-{$days} days")),
            'endDate' => date('Y-m-d', strtotime('-1 day')),
            'dimensions' => [$dimension],
            'rowLimit' => 1000,
            'startRow' => 0
        ];

        return $this->request($endpoint, $data);
    }

    /**
     * Get page performance data
     */
    public function getPagePerformance(int $days = 28): array {
        return $this->getSearchAnalytics($days, 'page');
    }

    /**
     * Get keyword opportunities
     */
    public function findKeywordOpportunities(int $days = 28): array {
        $result = $this->getSearchAnalytics($days, 'query');

        if (!$result['success']) {
            return $result;
        }

        $opportunities = [
            'quick_wins' => [],      // High impressions, position 8-20
            'improve_existing' => [], // Position 4-10, low CTR
            'new_content' => [],     // High impressions, no content
            'long_term' => []        // Position 20+, potential
        ];

        $existingKeywords = $this->getExistingKeywords();

        foreach ($result['data']['rows'] ?? [] as $row) {
            $keyword = $row['keys'][0];
            $impressions = $row['impressions'];
            $clicks = $row['clicks'];
            $ctr = $row['ctr'] * 100;
            $position = $row['position'];

            // Skip branded queries
            if ($this->isBrandedQuery($keyword)) {
                continue;
            }

            $opportunity = [
                'keyword' => $keyword,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => round($ctr, 2),
                'position' => round($position, 1),
                'has_content' => isset($existingKeywords[mb_strtolower($keyword)])
            ];

            // Categorize opportunity
            if ($position >= 8 && $position <= 20 && $impressions >= 100) {
                $opportunity['potential'] = 'Quick win - improve to page 1';
                $opportunities['quick_wins'][] = $opportunity;
            } elseif ($position >= 4 && $position <= 10 && $ctr < 3) {
                $opportunity['potential'] = 'Improve CTR with better title/description';
                $opportunities['improve_existing'][] = $opportunity;
            } elseif (!$opportunity['has_content'] && $impressions >= 50) {
                $opportunity['potential'] = 'Create dedicated content';
                $opportunities['new_content'][] = $opportunity;
            } elseif ($position > 20 && $impressions >= 200) {
                $opportunity['potential'] = 'Long-term improvement opportunity';
                $opportunities['long_term'][] = $opportunity;
            }
        }

        // Sort each category by impressions
        foreach ($opportunities as &$category) {
            usort($category, function($a, $b) {
                return $b['impressions'] <=> $a['impressions'];
            });
            $category = array_slice($category, 0, 20); // Limit each category
        }

        return [
            'success' => true,
            'opportunities' => $opportunities,
            'summary' => [
                'quick_wins' => count($opportunities['quick_wins']),
                'improve_existing' => count($opportunities['improve_existing']),
                'new_content' => count($opportunities['new_content']),
                'long_term' => count($opportunities['long_term'])
            ]
        ];
    }

    /**
     * Get existing keywords from articles
     */
    private function getExistingKeywords(): array {
        $keywords = db()->fetchAll("
            SELECT LOWER(primary_keyword) as kw FROM articles
            WHERE site_id = ? AND status = 'published'
        ", [$this->siteId]);

        return array_flip(array_column($keywords, 'kw'));
    }

    /**
     * Check if query is branded
     */
    private function isBrandedQuery(string $query): bool {
        $site = db()->fetchOne("SELECT name, base_url FROM sites WHERE id = ?", [$this->siteId]);

        $brandTerms = [
            strtolower($site['name']),
            parse_url($site['base_url'], PHP_URL_HOST)
        ];

        $queryLower = mb_strtolower($query);
        foreach ($brandTerms as $term) {
            if (mb_stripos($queryLower, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sync performance data to database
     */
    public function syncPerformanceData(int $days = 7): array {
        // Get page performance
        $pageResult = $this->getPagePerformance($days);

        if (!$pageResult['success']) {
            return $pageResult;
        }

        $site = db()->fetchOne("SELECT base_url FROM sites WHERE id = ?", [$this->siteId]);
        $baseHost = parse_url($site['base_url'], PHP_URL_HOST);

        $synced = 0;
        $errors = 0;

        foreach ($pageResult['data']['rows'] ?? [] as $row) {
            $pageUrl = $row['keys'][0];

            // Find matching article
            $article = db()->fetchOne("
                SELECT id FROM articles
                WHERE site_id = ? AND post_url LIKE ?
            ", [$this->siteId, '%' . parse_url($pageUrl, PHP_URL_PATH)]);

            if (!$article) {
                continue;
            }

            try {
                // Upsert performance data
                db()->query("
                    INSERT INTO article_performance
                    (article_id, impressions, clicks, ctr, avg_position, last_updated)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    impressions = VALUES(impressions),
                    clicks = VALUES(clicks),
                    ctr = VALUES(ctr),
                    avg_position = VALUES(avg_position),
                    last_updated = NOW()
                ", [
                    $article['id'],
                    $row['impressions'],
                    $row['clicks'],
                    $row['ctr'] * 100,
                    $row['position']
                ]);

                // Also save to history
                db()->query("
                    INSERT INTO article_performance_history
                    (article_id, date, impressions, clicks, ctr, avg_position)
                    VALUES (?, CURDATE(), ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    impressions = VALUES(impressions),
                    clicks = VALUES(clicks),
                    ctr = VALUES(ctr),
                    avg_position = VALUES(avg_position)
                ", [
                    $article['id'],
                    $row['impressions'],
                    $row['clicks'],
                    $row['ctr'] * 100,
                    $row['position']
                ]);

                $synced++;
            } catch (Exception $e) {
                $errors++;
            }
        }

        // Update last sync time
        db()->query("
            UPDATE gsc_credentials SET last_sync = NOW() WHERE site_id = ?
        ", [$this->siteId]);

        return [
            'success' => true,
            'synced' => $synced,
            'errors' => $errors
        ];
    }

    /**
     * Save keyword opportunities to database
     */
    public function saveKeywordOpportunities(): array {
        $result = $this->findKeywordOpportunities();

        if (!$result['success']) {
            return $result;
        }

        $saved = 0;

        $typeMapping = [
            'quick_wins' => 'quick_win',
            'improve_existing' => 'improve_existing',
            'new_content' => 'new_content',
            'long_term' => 'long_term'
        ];

        foreach ($result['opportunities'] as $type => $opportunities) {
            $dbType = $typeMapping[$type] ?? 'new_content';

            foreach ($opportunities as $opp) {
                // Calculate priority score
                $priorityScore = $this->calculatePriorityScore($opp, $dbType);

                // Check if keyword already has an article
                $existingArticle = db()->fetchOne("
                    SELECT id FROM articles
                    WHERE site_id = ? AND LOWER(primary_keyword) = LOWER(?)
                ", [$this->siteId, $opp['keyword']]);

                db()->query("
                    INSERT INTO gsc_keyword_opportunities
                    (site_id, keyword, impressions, clicks, ctr, avg_position,
                     opportunity_type, has_article, article_id, priority_score, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    impressions = VALUES(impressions),
                    clicks = VALUES(clicks),
                    ctr = VALUES(ctr),
                    avg_position = VALUES(avg_position),
                    opportunity_type = VALUES(opportunity_type),
                    priority_score = VALUES(priority_score),
                    updated_at = NOW()
                ", [
                    $this->siteId,
                    $opp['keyword'],
                    $opp['impressions'],
                    $opp['clicks'],
                    $opp['ctr'],
                    $opp['position'],
                    $dbType,
                    $existingArticle ? 1 : 0,
                    $existingArticle['id'] ?? null,
                    $priorityScore
                ]);

                $saved++;
            }
        }

        return [
            'success' => true,
            'saved' => $saved,
            'summary' => $result['summary']
        ];
    }

    /**
     * Calculate priority score for keyword opportunity
     */
    private function calculatePriorityScore(array $opp, string $type): int {
        $score = 0;

        // Base score from impressions (max 40 points)
        $score += min(40, $opp['impressions'] / 25);

        // Position score (max 30 points)
        if ($opp['position'] <= 10) {
            $score += 30;
        } elseif ($opp['position'] <= 20) {
            $score += 20;
        } elseif ($opp['position'] <= 30) {
            $score += 10;
        }

        // Type bonus
        if ($type === 'quick_win') {
            $score += 20;
        } elseif ($type === 'improve_existing') {
            $score += 15;
        } elseif ($type === 'new_content') {
            $score += 10;
        }

        // Low CTR but good position = opportunity
        if ($opp['position'] <= 10 && $opp['ctr'] < 3) {
            $score += 10;
        }

        return min(100, (int)$score);
    }

    /**
     * Get top keyword opportunities for content planning
     */
    public function getTopOpportunities(int $limit = 10, string $type = null): array {
        $sql = "
            SELECT * FROM gsc_keyword_opportunities
            WHERE site_id = ? AND has_article = 0
        ";
        $params = [$this->siteId];

        if ($type) {
            $sql .= " AND opportunity_type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY priority_score DESC LIMIT ?";
        $params[] = $limit;

        return db()->fetchAll($sql, $params);
    }
}

/**
 * Helper function
 */
function gscClient(int $siteId): GSCClient {
    return new GSCClient($siteId);
}
