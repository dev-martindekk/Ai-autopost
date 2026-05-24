<?php
/**
 * AI AutoPost SEO System - Similarity Checker
 * ============================================
 * Checks for duplicate/similar content between articles
 * Prevents content cannibalization and spam signals
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class SimilarityChecker {
    private $similarityThreshold = 0.6; // 60% similarity = warning
    private $duplicateThreshold = 0.85; // 85% similarity = duplicate

    /**
     * Check if new content is too similar to existing articles
     */
    public function checkSimilarity(string $newContent, int $siteId, int $excludeArticleId = 0): array {
        // Get existing articles
        $articles = db()->fetchAll("
            SELECT id, title, content, primary_keyword
            FROM articles
            WHERE site_id = ? AND id != ? AND status IN ('published', 'generated', 'reviewed')
            ORDER BY id DESC
            LIMIT 50
        ", [$siteId, $excludeArticleId]);

        if (empty($articles)) {
            return [
                'is_unique' => true,
                'max_similarity' => 0,
                'similar_articles' => []
            ];
        }

        $newContentClean = $this->cleanContent($newContent);
        $newShingles = $this->getShingles($newContentClean);

        $similarArticles = [];
        $maxSimilarity = 0;

        foreach ($articles as $article) {
            $existingClean = $this->cleanContent($article['content']);
            $existingShingles = $this->getShingles($existingClean);

            $similarity = $this->calculateJaccardSimilarity($newShingles, $existingShingles);

            if ($similarity > $this->similarityThreshold) {
                $similarArticles[] = [
                    'article_id' => $article['id'],
                    'title' => $article['title'],
                    'keyword' => $article['primary_keyword'],
                    'similarity' => round($similarity * 100, 1)
                ];
            }

            if ($similarity > $maxSimilarity) {
                $maxSimilarity = $similarity;
            }
        }

        // Sort by similarity
        usort($similarArticles, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return [
            'is_unique' => $maxSimilarity < $this->similarityThreshold,
            'is_duplicate' => $maxSimilarity >= $this->duplicateThreshold,
            'max_similarity' => round($maxSimilarity * 100, 1),
            'similar_articles' => array_slice($similarArticles, 0, 5),
            'recommendation' => $this->getRecommendation($maxSimilarity)
        ];
    }

    /**
     * Check keyword cannibalization
     */
    public function checkKeywordCannibalization(string $keyword, int $siteId, int $excludeArticleId = 0): array {
        // Use LIKE search (more reliable than FULLTEXT for Thai content)
        $existing = db()->fetchAll("
            SELECT id, title, post_url, primary_keyword
            FROM articles
            WHERE site_id = ? AND id != ? AND status = 'published'
            AND (primary_keyword LIKE ? OR title LIKE ?)
            ORDER BY id DESC
            LIMIT 10
        ", [$siteId, $excludeArticleId, "%{$keyword}%", "%{$keyword}%"]);

        $exactMatch = array_filter($existing, function($a) use ($keyword) {
            return mb_strtolower($a['primary_keyword']) === mb_strtolower($keyword);
        });

        return [
            'has_cannibalization' => !empty($exactMatch),
            'exact_matches' => count($exactMatch),
            'similar_keywords' => $existing,
            'recommendation' => empty($exactMatch)
                ? 'OK to proceed'
                : 'Consider using different keyword or updating existing article'
        ];
    }

    /**
     * Clean content for comparison
     */
    private function cleanContent(string $content): string {
        // Remove HTML tags
        $content = strip_tags($content);

        // Remove extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);

        // Convert to lowercase
        $content = mb_strtolower($content);

        // Remove common words (Thai and English)
        $stopWords = ['และ', 'หรือ', 'แต่', 'เป็น', 'ที่', 'จะ', 'ได้', 'มี', 'ใน', 'ของ', 'ให้', 'กับ',
                      'the', 'and', 'or', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
                      'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should'];

        foreach ($stopWords as $word) {
            $content = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', '', $content);
        }

        return trim($content);
    }

    /**
     * Get shingles (n-grams) from content
     */
    private function getShingles(string $content, int $n = 3): array {
        $words = preg_split('/\s+/', $content);
        $shingles = [];

        for ($i = 0; $i <= count($words) - $n; $i++) {
            $shingle = implode(' ', array_slice($words, $i, $n));
            $shingles[md5($shingle)] = true;
        }

        return $shingles;
    }

    /**
     * Calculate Jaccard similarity between two shingle sets
     */
    private function calculateJaccardSimilarity(array $set1, array $set2): float {
        if (empty($set1) || empty($set2)) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($set1, $set2));
        $union = count($set1) + count($set2) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Get recommendation based on similarity
     */
    private function getRecommendation(float $similarity): string {
        if ($similarity >= $this->duplicateThreshold) {
            return 'BLOCK: Content is too similar to existing articles. Rewrite significantly or use different keyword.';
        } elseif ($similarity >= $this->similarityThreshold) {
            return 'WARNING: Content has similarities with existing articles. Consider differentiating more.';
        } elseif ($similarity >= 0.4) {
            return 'CAUTION: Some overlap detected. Review similar articles to ensure unique value.';
        }
        return 'OK: Content appears unique.';
    }

    /**
     * Find all duplicate pairs in site
     */
    public function findDuplicatesInSite(int $siteId): array {
        $articles = db()->fetchAll("
            SELECT id, title, content, primary_keyword
            FROM articles
            WHERE site_id = ? AND status = 'published'
            ORDER BY id
        ", [$siteId]);

        $duplicates = [];
        $processed = [];

        foreach ($articles as $i => $article1) {
            $content1 = $this->cleanContent($article1['content']);
            $shingles1 = $this->getShingles($content1);

            foreach ($articles as $j => $article2) {
                if ($i >= $j) continue; // Skip already compared pairs

                $pairKey = $article1['id'] . '-' . $article2['id'];
                if (isset($processed[$pairKey])) continue;

                $content2 = $this->cleanContent($article2['content']);
                $shingles2 = $this->getShingles($content2);

                $similarity = $this->calculateJaccardSimilarity($shingles1, $shingles2);

                if ($similarity >= $this->similarityThreshold) {
                    $duplicates[] = [
                        'article1' => [
                            'id' => $article1['id'],
                            'title' => $article1['title'],
                            'keyword' => $article1['primary_keyword']
                        ],
                        'article2' => [
                            'id' => $article2['id'],
                            'title' => $article2['title'],
                            'keyword' => $article2['primary_keyword']
                        ],
                        'similarity' => round($similarity * 100, 1)
                    ];
                }

                $processed[$pairKey] = true;
            }
        }

        // Sort by similarity
        usort($duplicates, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return $duplicates;
    }

    /**
     * Set similarity thresholds
     */
    public function setThresholds(float $similar, float $duplicate): void {
        $this->similarityThreshold = max(0, min(1, $similar));
        $this->duplicateThreshold = max(0, min(1, $duplicate));
    }
}

/**
 * Helper function
 */
function similarityChecker(): SimilarityChecker {
    return new SimilarityChecker();
}
