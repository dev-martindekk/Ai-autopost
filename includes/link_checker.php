<?php
/**
 * AI AutoPost SEO System - Link Health Checker
 * =============================================
 * Checks internal links for broken URLs and issues
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class LinkChecker {
    private $timeout = 10; // seconds
    private $userAgent = 'Mozilla/5.0 (compatible; LinkChecker/1.0)';

    /**
     * Check all internal links in an article
     */
    public function checkArticleLinks(int $articleId): array {
        $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        // Extract all links from content
        $links = $this->extractLinks($article['content']);

        if (empty($links)) {
            return [
                'success' => true,
                'total_links' => 0,
                'broken_links' => [],
                'working_links' => []
            ];
        }

        $results = [
            'broken' => [],
            'working' => [],
            'redirects' => [],
            'errors' => []
        ];

        foreach ($links as $link) {
            $status = $this->checkUrl($link['url']);

            $linkInfo = [
                'url' => $link['url'],
                'anchor' => $link['anchor'],
                'status_code' => $status['code'],
                'response_time' => $status['time']
            ];

            if ($status['code'] === 0) {
                $linkInfo['error'] = $status['error'];
                $results['errors'][] = $linkInfo;
            } elseif ($status['code'] >= 400) {
                $results['broken'][] = $linkInfo;
            } elseif ($status['code'] >= 300) {
                $linkInfo['redirect_to'] = $status['redirect'];
                $results['redirects'][] = $linkInfo;
            } else {
                $results['working'][] = $linkInfo;
            }
        }

        return [
            'success' => true,
            'article_id' => $articleId,
            'total_links' => count($links),
            'broken_links' => $results['broken'],
            'redirects' => $results['redirects'],
            'errors' => $results['errors'],
            'working_links' => $results['working'],
            'health_score' => $this->calculateHealthScore($results, count($links))
        ];
    }

    /**
     * Check all links in a site
     */
    public function checkSiteLinks(int $siteId, int $limit = 50): array {
        $articles = db()->fetchAll("
            SELECT id, title, content
            FROM articles
            WHERE site_id = ? AND status = 'published'
            ORDER BY published_at DESC
            LIMIT ?
        ", [$siteId, $limit]);

        $allResults = [
            'total_articles' => count($articles),
            'total_links' => 0,
            'broken_count' => 0,
            'redirect_count' => 0,
            'articles_with_issues' => []
        ];

        foreach ($articles as $article) {
            $result = $this->checkArticleLinks($article['id']);

            if ($result['success']) {
                $allResults['total_links'] += $result['total_links'];
                $allResults['broken_count'] += count($result['broken_links']);
                $allResults['redirect_count'] += count($result['redirects']);

                if (!empty($result['broken_links']) || !empty($result['errors'])) {
                    $allResults['articles_with_issues'][] = [
                        'article_id' => $article['id'],
                        'title' => $article['title'],
                        'broken_links' => $result['broken_links'],
                        'errors' => $result['errors']
                    ];
                }
            }

            // Delay between checks
            usleep(500000); // 0.5 second
        }

        $allResults['health_score'] = $allResults['total_links'] > 0
            ? round((1 - ($allResults['broken_count'] / $allResults['total_links'])) * 100)
            : 100;

        return $allResults;
    }

    /**
     * Extract links from HTML content
     */
    private function extractLinks(string $content): array {
        $links = [];

        // Match all <a> tags
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $url = $match[1];
            $anchor = strip_tags($match[2]);

            // Skip mailto:, tel:, javascript:, and anchor links
            if (preg_match('/^(mailto:|tel:|javascript:|#)/i', $url)) {
                continue;
            }

            $links[] = [
                'url' => $url,
                'anchor' => $anchor
            ];
        }

        return $links;
    }

    /**
     * Check if URL is accessible
     */
    private function checkUrl(string $url): array {
        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true, // HEAD request
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false, // Don't follow redirects
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        curl_close($ch);

        $time = round((microtime(true) - $start) * 1000); // ms

        return [
            'code' => $code,
            'time' => $time,
            'redirect' => $redirect,
            'error' => $error
        ];
    }

    /**
     * Calculate health score
     */
    private function calculateHealthScore(array $results, int $total): int {
        if ($total === 0) return 100;

        $broken = count($results['broken']);
        $errors = count($results['errors']);
        $redirects = count($results['redirects']);

        // Broken and errors are bad, redirects are minor issues
        $issues = ($broken * 1) + ($errors * 1) + ($redirects * 0.3);
        $score = max(0, 100 - ($issues / $total * 100));

        return round($score);
    }

    /**
     * Find and fix broken internal links
     */
    public function findBrokenInternalLinks(int $siteId): array {
        // Get all internal links from database
        $internalLinks = db()->fetchAll("
            SELECT il.*,
                   sa.title as source_title, sa.post_url as source_url,
                   ta.title as target_title, ta.post_url as target_url, ta.status as target_status
            FROM internal_links il
            JOIN articles sa ON il.source_article_id = sa.id
            JOIN articles ta ON il.target_article_id = ta.id
            WHERE il.site_id = ?
        ", [$siteId]);

        $brokenLinks = [];

        foreach ($internalLinks as $link) {
            // Check if target article is still published
            if ($link['target_status'] !== 'published') {
                $brokenLinks[] = [
                    'type' => 'unpublished_target',
                    'source_article' => $link['source_title'],
                    'target_article' => $link['target_title'],
                    'anchor_text' => $link['anchor_text'],
                    'reason' => 'Target article is no longer published'
                ];
                continue;
            }

            // Check if target URL is valid
            if (empty($link['target_url'])) {
                $brokenLinks[] = [
                    'type' => 'missing_url',
                    'source_article' => $link['source_title'],
                    'target_article' => $link['target_title'],
                    'anchor_text' => $link['anchor_text'],
                    'reason' => 'Target article has no URL'
                ];
            }
        }

        return $brokenLinks;
    }

    /**
     * Check external links for a site (limited)
     */
    public function checkExternalLinks(int $siteId, int $articleLimit = 10): array {
        $articles = db()->fetchAll("
            SELECT id, title, content
            FROM articles
            WHERE site_id = ? AND status = 'published'
            ORDER BY published_at DESC
            LIMIT ?
        ", [$siteId, $articleLimit]);

        $site = db()->fetchOne("SELECT base_url FROM sites WHERE id = ?", [$siteId]);
        $siteHost = parse_url($site['base_url'], PHP_URL_HOST);

        $externalLinks = [];

        foreach ($articles as $article) {
            $links = $this->extractLinks($article['content']);

            foreach ($links as $link) {
                $linkHost = parse_url($link['url'], PHP_URL_HOST);

                // Only check external links
                if ($linkHost && $linkHost !== $siteHost) {
                    $status = $this->checkUrl($link['url']);

                    $externalLinks[] = [
                        'article_id' => $article['id'],
                        'article_title' => $article['title'],
                        'url' => $link['url'],
                        'anchor' => $link['anchor'],
                        'status_code' => $status['code'],
                        'is_broken' => $status['code'] === 0 || $status['code'] >= 400
                    ];

                    usleep(300000); // 0.3 second delay
                }
            }
        }

        $broken = array_filter($externalLinks, fn($l) => $l['is_broken']);

        return [
            'total_external_links' => count($externalLinks),
            'broken_count' => count($broken),
            'broken_links' => array_values($broken)
        ];
    }
}

/**
 * Helper function
 */
function linkChecker(): LinkChecker {
    return new LinkChecker();
}
