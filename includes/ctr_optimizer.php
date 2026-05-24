<?php
/**
 * AI AutoPost SEO System - CTR Optimizer
 * =======================================
 * Optimizes titles and meta descriptions based on CTR performance
 * Uses AI to generate better headlines for underperforming content
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class CTROptimizer {
    private $lowCTRThreshold = 2.0; // Below 2% CTR needs optimization
    private $goodCTRThreshold = 5.0; // Above 5% CTR is good
    private $minImpressions = 100; // Minimum impressions to consider

    /**
     * Find articles with low CTR that need optimization
     */
    public function findLowCTRArticles(int $siteId, int $limit = 20): array {
        // Get articles with CTR data
        $articles = db()->fetchAll("
            SELECT a.id, a.title, a.seo_title, a.seo_description, a.primary_keyword,
                   a.post_url, a.published_at,
                   ap.impressions, ap.clicks, ap.avg_position, ap.ctr,
                   ap.last_updated as performance_date
            FROM articles a
            LEFT JOIN article_performance ap ON a.id = ap.article_id
            WHERE a.site_id = ?
            AND a.status = 'published'
            AND ap.impressions >= ?
            AND ap.ctr < ?
            ORDER BY ap.impressions DESC, ap.ctr ASC
            LIMIT ?
        ", [$siteId, $this->minImpressions, $this->lowCTRThreshold, $limit]);

        return array_map(function($article) {
            $article['optimization_potential'] = $this->calculateOptimizationPotential($article);
            $article['suggested_action'] = $this->getSuggestedAction($article);
            return $article;
        }, $articles);
    }

    /**
     * Calculate optimization potential score
     */
    private function calculateOptimizationPotential(array $article): array {
        $score = 0;
        $factors = [];

        // High impressions but low CTR = high potential
        if ($article['impressions'] > 1000 && $article['ctr'] < 2) {
            $score += 30;
            $factors[] = 'High impressions with low CTR';
        }

        // Good position but low CTR = title/description issue
        if ($article['avg_position'] <= 10 && $article['ctr'] < 3) {
            $score += 25;
            $factors[] = 'Good ranking position but low CTR';
        }

        // Very low CTR
        if ($article['ctr'] < 1) {
            $score += 20;
            $factors[] = 'CTR below 1%';
        }

        // Title too short or too long
        $titleLen = mb_strlen($article['seo_title'] ?: $article['title']);
        if ($titleLen < 30 || $titleLen > 60) {
            $score += 10;
            $factors[] = 'Title length not optimal';
        }

        // Description missing or wrong length
        $descLen = mb_strlen($article['seo_description'] ?? '');
        if ($descLen < 120 || $descLen > 160) {
            $score += 10;
            $factors[] = 'Description length not optimal';
        }

        // Missing keyword in title
        if (mb_stripos($article['title'], $article['primary_keyword']) === false) {
            $score += 5;
            $factors[] = 'Keyword not in title';
        }

        return [
            'score' => min(100, $score),
            'priority' => $score >= 50 ? 'high' : ($score >= 25 ? 'medium' : 'low'),
            'factors' => $factors
        ];
    }

    /**
     * Get suggested action for article
     */
    private function getSuggestedAction(array $article): string {
        $ctr = floatval($article['ctr']);
        $position = floatval($article['avg_position']);

        if ($position <= 5 && $ctr < 5) {
            return 'Rewrite title and description - good position but poor CTR';
        } elseif ($position <= 10 && $ctr < 3) {
            return 'A/B test new title variations';
        } elseif ($position > 10 && $ctr < 2) {
            return 'Focus on content improvement first to improve ranking';
        } elseif ($ctr < 1) {
            return 'Complete title and description rewrite needed';
        }

        return 'Monitor and consider minor title tweaks';
    }

    /**
     * Generate optimized title and description using AI
     */
    public function generateOptimizedMeta(int $articleId): array {
        $article = db()->fetchOne("
            SELECT a.*, ap.ctr, ap.avg_position, ap.impressions, s.language_code
            FROM articles a
            LEFT JOIN article_performance ap ON a.id = ap.article_id
            LEFT JOIN sites s ON a.site_id = s.id
            WHERE a.id = ?
        ", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        // Get high-performing titles from same topic for reference
        $highCTRExamples = db()->fetchAll("
            SELECT a.title, a.seo_title, ap.ctr
            FROM articles a
            JOIN article_performance ap ON a.id = ap.article_id
            WHERE a.site_id = ? AND ap.ctr >= ? AND ap.impressions >= 100
            ORDER BY ap.ctr DESC
            LIMIT 5
        ", [$article['site_id'], $this->goodCTRThreshold]);

        $prompt = $this->buildOptimizationPrompt($article, $highCTRExamples);

        try {
            // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ CTR optimization คุณภาพสูง
            $ai = aiOrchestrator();
            $result = $ai->execute('generate_title', $prompt, [
                'temperature' => 0.7,
                'max_tokens' => 2000
            ]);

            $suggestions = $this->parseAISuggestions($result['content']);

            return [
                'success' => true,
                'article_id' => $articleId,
                'current' => [
                    'title' => $article['seo_title'] ?: $article['title'],
                    'description' => $article['seo_description'],
                    'ctr' => $article['ctr']
                ],
                'suggestions' => $suggestions,
                'reasoning' => $result['content']
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Build optimization prompt
     */
    private function buildOptimizationPrompt(array $article, array $examples): string {
        // Get language
        $langCode = $article['language_code'] ?? 'th';
        $langNames = [
            'th' => 'ภาษาไทย', 'vn' => 'tiếng Việt', 'en' => 'English',
            'id' => 'Bahasa Indonesia', 'my' => 'Bahasa Malaysia', 'km' => 'ភាសាខ្មែរ',
            'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어'
        ];
        $langName = $langNames[$langCode] ?? 'English';

        $customPrompt = getPromptTemplate('ctr_optimize', '');
        if ($customPrompt) {
            $title = $article['seo_title'] ?: $article['title'];
            $ctr = $article['ctr'] ?? 'N/A';
            $position = $article['avg_position'] ?? 'N/A';
            return str_replace(
                ['{title}', '{keyword}', '{ctr}', '{position}'],
                [$title, $article['primary_keyword'], $ctr, $position],
                $customPrompt
            );
        }

        $prompt = "คุณเป็นผู้เชี่ยวชาญ SEO ที่ต้องปรับปรุง Title และ Meta Description {$langName}เพื่อเพิ่ม CTR\n\n";

        $prompt .= "## บทความปัจจุบัน:\n";
        $prompt .= "- Title: " . ($article['seo_title'] ?: $article['title']) . "\n";
        $prompt .= "- Description: " . ($article['seo_description'] ?? 'ไม่มี') . "\n";
        $prompt .= "- Keyword: {$article['primary_keyword']}\n";
        $prompt .= "- CTR ปัจจุบัน: " . ($article['ctr'] ?? 'N/A') . "%\n";
        $prompt .= "- ตำแหน่งเฉลี่ย: " . ($article['avg_position'] ?? 'N/A') . "\n\n";

        if (!empty($examples)) {
            $prompt .= "## ตัวอย่าง Title ที่มี CTR สูงจากเว็บเดียวกัน:\n";
            foreach ($examples as $ex) {
                $prompt .= "- {$ex['seo_title']} (CTR: {$ex['ctr']}%)\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## กฎการเขียน:\n";
        $prompt .= "1. Title: 50-60 ตัวอักษร, มี keyword, ดึงดูดการคลิก\n";
        $prompt .= "2. Description: 140-155 ตัวอักษร, มี call-to-action\n";
        $prompt .= "3. ใช้ตัวเลข, emoji (พอเหมาะ), คำถาม ถ้าเหมาะสม\n";
        $prompt .= "4. สร้างความอยากรู้อยากเห็นแต่ไม่ clickbait\n";
        $prompt .= "5. เน้น benefit ที่ผู้อ่านจะได้รับ\n\n";

        $prompt .= "## ให้ผลลัพธ์เป็น JSON format:\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"titles\": [\n";
        $prompt .= "    {\"text\": \"Title 1\", \"strategy\": \"อธิบายกลยุทธ์\"},\n";
        $prompt .= "    {\"text\": \"Title 2\", \"strategy\": \"อธิบายกลยุทธ์\"},\n";
        $prompt .= "    {\"text\": \"Title 3\", \"strategy\": \"อธิบายกลยุทธ์\"}\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"descriptions\": [\n";
        $prompt .= "    {\"text\": \"Description 1\", \"strategy\": \"อธิบายกลยุทธ์\"},\n";
        $prompt .= "    {\"text\": \"Description 2\", \"strategy\": \"อธิบายกลยุทธ์\"}\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"analysis\": \"วิเคราะห์ปัญหาของ title/description ปัจจุบัน\"\n";
        $prompt .= "}\n";
        $prompt .= "```\n";

        return $prompt;
    }

    /**
     * Parse AI suggestions from response
     */
    private function parseAISuggestions(string $response): array {
        // Extract JSON from response
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } else {
            // Try to find raw JSON
            $json = $response;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'titles' => [],
                'descriptions' => [],
                'analysis' => 'Failed to parse AI response',
                'raw_response' => $response
            ];
        }

        return $decoded;
    }

    /**
     * Apply optimized meta to article
     */
    public function applyOptimization(int $articleId, string $newTitle, string $newDescription): array {
        // Store old values for tracking
        $current = db()->fetchOne("
            SELECT seo_title, seo_description FROM articles WHERE id = ?
        ", [$articleId]);

        if (!$current) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        // Update article
        db()->query("
            UPDATE articles
            SET seo_title = ?,
                seo_description = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$newTitle, $newDescription, $articleId]);

        // Log the change
        db()->query("
            INSERT INTO ctr_optimizations
            (article_id, old_title, new_title, old_description, new_description, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ", [
            $articleId,
            $current['seo_title'],
            $newTitle,
            $current['seo_description'],
            $newDescription
        ]);

        return [
            'success' => true,
            'message' => 'Optimization applied',
            'changes' => [
                'title' => ['old' => $current['seo_title'], 'new' => $newTitle],
                'description' => ['old' => $current['seo_description'], 'new' => $newDescription]
            ]
        ];
    }

    /**
     * Get CTR performance history for an article
     */
    public function getPerformanceHistory(int $articleId, int $days = 30): array {
        return db()->fetchAll("
            SELECT date, impressions, clicks, ctr, avg_position
            FROM article_performance_history
            WHERE article_id = ?
            AND date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY date ASC
        ", [$articleId, $days]);
    }

    /**
     * Analyze title patterns from high CTR articles
     */
    public function analyzeHighCTRPatterns(int $siteId): array {
        $highCTR = db()->fetchAll("
            SELECT a.title, a.seo_title, a.primary_keyword, ap.ctr
            FROM articles a
            JOIN article_performance ap ON a.id = ap.article_id
            WHERE a.site_id = ? AND ap.ctr >= ? AND ap.impressions >= 100
            ORDER BY ap.ctr DESC
            LIMIT 20
        ", [$siteId, $this->goodCTRThreshold]);

        $patterns = [
            'numbers' => 0,
            'questions' => 0,
            'howto' => 0,
            'list' => 0,
            'year' => 0,
            'emoji' => 0,
            'power_words' => 0
        ];

        $powerWords = ['ดีที่สุด', 'แนะนำ', 'เคล็ดลับ', 'วิธี', 'สุดยอด', 'ครบ', 'อัพเดท', 'ล่าสุด',
                       'best', 'top', 'ultimate', 'complete', 'guide', 'tips'];

        foreach ($highCTR as $article) {
            $title = $article['seo_title'] ?: $article['title'];

            if (preg_match('/\d+/', $title)) $patterns['numbers']++;
            if (preg_match('/[?？]/', $title)) $patterns['questions']++;
            if (preg_match('/(วิธี|how to)/iu', $title)) $patterns['howto']++;
            if (preg_match('/^\d+\s/', $title)) $patterns['list']++;
            if (preg_match('/202[4-9]/', $title)) $patterns['year']++;
            if (preg_match('/[\x{1F300}-\x{1F9FF}]/u', $title)) $patterns['emoji']++;

            foreach ($powerWords as $word) {
                if (mb_stripos($title, $word) !== false) {
                    $patterns['power_words']++;
                    break;
                }
            }
        }

        $total = count($highCTR) ?: 1;

        return [
            'total_analyzed' => count($highCTR),
            'patterns' => [
                'numbers' => round($patterns['numbers'] / $total * 100) . '% use numbers',
                'questions' => round($patterns['questions'] / $total * 100) . '% are questions',
                'howto' => round($patterns['howto'] / $total * 100) . '% are how-to',
                'list' => round($patterns['list'] / $total * 100) . '% are list format',
                'year' => round($patterns['year'] / $total * 100) . '% include year',
                'emoji' => round($patterns['emoji'] / $total * 100) . '% use emoji',
                'power_words' => round($patterns['power_words'] / $total * 100) . '% use power words'
            ],
            'recommendations' => $this->generatePatternRecommendations($patterns, $total)
        ];
    }

    /**
     * Generate recommendations based on patterns
     */
    private function generatePatternRecommendations(array $patterns, int $total): array {
        $recommendations = [];

        if ($patterns['numbers'] / $total > 0.5) {
            $recommendations[] = 'Include numbers in titles - they perform well';
        }
        if ($patterns['year'] / $total > 0.3) {
            $recommendations[] = 'Add current year to titles when relevant';
        }
        if ($patterns['howto'] / $total > 0.3) {
            $recommendations[] = 'How-to format works well for this site';
        }
        if ($patterns['power_words'] / $total > 0.4) {
            $recommendations[] = 'Use power words like "ดีที่สุด", "แนะนำ", "เคล็ดลับ"';
        }

        return $recommendations;
    }

    /**
     * Quick title improvement suggestions
     */
    public function quickTitleSuggestions(string $title, string $keyword): array {
        $suggestions = [];
        $titleLen = mb_strlen($title);

        // Check length
        if ($titleLen < 30) {
            $suggestions[] = [
                'issue' => 'Title too short',
                'fix' => 'Add more descriptive words or year'
            ];
        } elseif ($titleLen > 60) {
            $suggestions[] = [
                'issue' => 'Title too long',
                'fix' => 'Shorten to under 60 characters'
            ];
        }

        // Check keyword presence
        if (mb_stripos($title, $keyword) === false) {
            $suggestions[] = [
                'issue' => 'Keyword not in title',
                'fix' => 'Include "' . $keyword . '" in title'
            ];
        }

        // Check for numbers
        if (!preg_match('/\d+/', $title)) {
            $suggestions[] = [
                'issue' => 'No numbers in title',
                'fix' => 'Consider adding numbers like "10 วิธี..." or "2024"'
            ];
        }

        // Check for emotional hooks
        $hasHook = preg_match('/(ดีที่สุด|แนะนำ|เคล็ดลับ|วิธี|สุดยอด|ครบ|ง่าย|เร็ว)/u', $title);
        if (!$hasHook) {
            $suggestions[] = [
                'issue' => 'Missing emotional hook',
                'fix' => 'Add power words like "ดีที่สุด", "เคล็ดลับ", "วิธีง่ายๆ"'
            ];
        }

        return [
            'current_title' => $title,
            'length' => $titleLen,
            'suggestions' => $suggestions,
            'score' => max(0, 100 - count($suggestions) * 20)
        ];
    }
}

/**
 * Helper function
 */
function ctrOptimizer(): CTROptimizer {
    return new CTROptimizer();
}
