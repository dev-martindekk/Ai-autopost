<?php
/**
 * AI AutoPost SEO System - A/B Title Tester
 * ==========================================
 * ทดสอบ Title หลายแบบ และเลือกแบบที่ CTR ดีที่สุด
 *
 * Features:
 * - AI สร้าง Title หลายเวอร์ชัน
 * - ติดตาม CTR แต่ละเวอร์ชันจาก GSC
 * - เลือก Title ที่ดีที่สุดอัตโนมัติ
 * - รายงานผลการทดสอบ
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';
require_once __DIR__ . '/wordpress_client.php';

class ABTitleTester {

    private $db;
    private $ai;

    // Test duration in days
    private const DEFAULT_TEST_DURATION = 7;
    private const MIN_IMPRESSIONS = 100; // ต้องมี impressions อย่างน้อยเท่านี้ถึงจะตัดสิน

    // Language names mapping
    private $languageNames = [
        'th' => 'ภาษาไทย',
        'vn' => 'tiếng Việt',
        'en' => 'English',
        'id' => 'Bahasa Indonesia',
        'my' => 'Bahasa Melayu',
        'km' => 'ភាសាខ្មែរ',
        'zh' => '中文',
        'ja' => '日本語',
        'ko' => '한국어'
    ];

    private function getLanguageName(string $code): string {
        return $this->languageNames[$code] ?? $this->languageNames['en'];
    }

    public function __construct() {
        $this->db = db();
        $this->ai = aiOrchestrator();
    }

    /**
     * Generate title variations for an article
     */
    public function generateTitleVariations(int $articleId, int $count = 3): array {
        $article = $this->db->fetchOne("
            SELECT a.*, s.name as site_name, s.main_topic, s.language_code
            FROM articles a
            JOIN sites s ON a.site_id = s.id
            WHERE a.id = ?
        ", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        $langCode = $article['language_code'] ?? 'th';
        $langName = $this->getLanguageName($langCode);

        $customPrompt = getPromptTemplate('title_ab', '');
        if ($customPrompt) {
            $basePrompt = str_replace(
                ['{title}', '{keyword}', '{topic}', '{count}'],
                [$article['title'], $article['primary_keyword'], $article['topic'], $count],
                $customPrompt
            );
        } else {
            // Default Thai prompt
            $basePrompt = "คุณเป็นผู้เชี่ยวชาญ SEO และ CTR Optimization

บทความ:
- Title ปัจจุบัน: {$article['title']}
- Keyword: {$article['primary_keyword']}
- หมวด: {$article['topic']}

สร้าง {$count} เวอร์ชันของ Title ที่แตกต่างกัน โดยแต่ละแบบต้อง:
1. มี Primary Keyword อยู่
2. ความยาว 50-60 ตัวอักษร
3. ดึงดูดให้คลิก (CTR สูง)
4. ใช้เทคนิคต่างกัน เช่น:
   - ใช้ตัวเลข (5 วิธี, Top 10)
   - ใช้คำถาม
   - ใช้ Power words (ฟรี, ล่าสุด, เด็ด)
   - ใช้ Brackets [2025] (คู่มือ)
   - สร้างความเร่งด่วน";
        }

        // JSON response format (same for all languages)
        $prompt = $basePrompt . "

ตอบเป็น JSON / Answer in JSON / Trả lời bằng JSON:
```json
{
    \"variations\": [
        {
            \"title\": \"Title version 1\",
            \"technique\": \"Technique used\",
            \"predicted_ctr_boost\": \"+10-20%\"
        }
    ]
}
```";

        try {
            // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ A/B title analysis คุณภาพสูง
            $result = $this->ai->execute('generate_title', $prompt, [
                'temperature' => 0.7,
                'max_tokens' => 1500
            ]);
            $response = $result['content'] ?? '';

            // Parse JSON
            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);
            } elseif (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            } else {
                return ['success' => false, 'message' => 'Could not parse AI response'];
            }

            if (!isset($data['variations'])) {
                return ['success' => false, 'message' => 'Invalid response format'];
            }

            return [
                'success' => true,
                'article' => $article,
                'original_title' => $article['title'],
                'variations' => $data['variations']
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Start A/B test for an article
     */
    public function startTest(int $articleId, array $titleVariations): array {
        $article = $this->db->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        // Check if already testing
        $existingTest = $this->db->fetchOne("
            SELECT * FROM ab_title_tests
            WHERE article_id = ? AND status = 'running'
        ", [$articleId]);

        if ($existingTest) {
            return ['success' => false, 'message' => 'มีการทดสอบอยู่แล้ว'];
        }

        // Create test
        $testId = $this->db->insert('ab_title_tests', [
            'article_id' => $articleId,
            'site_id' => $article['site_id'],
            'original_title' => $article['title'],
            'status' => 'running',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+' . self::DEFAULT_TEST_DURATION . ' days')),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Create variations
        foreach ($titleVariations as $index => $variation) {
            $this->db->insert('ab_title_variations', [
                'test_id' => $testId,
                'article_id' => $articleId,
                'variation_index' => $index,
                'title' => $variation['title'],
                'technique' => $variation['technique'] ?? '',
                'is_original' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        // Also add original as variation 0
        $this->db->insert('ab_title_variations', [
            'test_id' => $testId,
            'article_id' => $articleId,
            'variation_index' => -1,
            'title' => $article['title'],
            'technique' => 'Original',
            'is_original' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Apply first variation to WordPress
        $this->applyVariation($articleId, $titleVariations[0]['title']);

        logEvent('info', 'ab_test', 'A/B Title test started', [
            'article_id' => $articleId,
            'test_id' => $testId,
            'variations' => count($titleVariations)
        ]);

        return [
            'success' => true,
            'test_id' => $testId,
            'message' => 'เริ่มทดสอบ A/B แล้ว',
            'current_variation' => $titleVariations[0]['title']
        ];
    }

    /**
     * Apply a title variation to WordPress
     */
    private function applyVariation(int $articleId, string $newTitle): bool {
        $article = $this->db->fetchOne("
            SELECT a.*, s.*
            FROM articles a
            JOIN sites s ON a.site_id = s.id
            WHERE a.id = ?
        ", [$articleId]);

        if (!$article || !$article['wp_post_id']) {
            return false;
        }

        try {
            $wpClient = new WordPressClient($article);
            $result = $wpClient->updatePost($article['wp_post_id'], [
                'title' => $newTitle
            ]);

            if ($result['success']) {
                // Log the change
                $this->db->insert('ab_title_changes', [
                    'article_id' => $articleId,
                    'old_title' => $article['title'],
                    'new_title' => $newTitle,
                    'changed_at' => date('Y-m-d H:i:s')
                ]);

                return true;
            }
        } catch (Exception $e) {
            logEvent('error', 'ab_test', 'Failed to apply title variation', [
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Record metrics for a variation (called during GSC sync)
     */
    public function recordMetrics(int $testId, int $variationIndex, array $metrics): void {
        $this->db->query("
            UPDATE ab_title_variations
            SET impressions = impressions + ?,
                clicks = clicks + ?,
                ctr = CASE WHEN impressions + ? > 0
                      THEN (clicks + ?) / (impressions + ?) * 100
                      ELSE 0 END,
                avg_position = ?,
                updated_at = NOW()
            WHERE test_id = ? AND variation_index = ?
        ", [
            $metrics['impressions'],
            $metrics['clicks'],
            $metrics['impressions'],
            $metrics['clicks'],
            $metrics['impressions'],
            $metrics['position'],
            $testId,
            $variationIndex
        ]);
    }

    /**
     * Rotate to next variation
     */
    public function rotateVariation(int $testId): array {
        $test = $this->db->fetchOne("SELECT * FROM ab_title_tests WHERE id = ?", [$testId]);

        if (!$test || $test['status'] !== 'running') {
            return ['success' => false, 'message' => 'Test not running'];
        }

        // Get variations
        $variations = $this->db->fetchAll("
            SELECT * FROM ab_title_variations
            WHERE test_id = ? AND is_original = 0
            ORDER BY variation_index
        ", [$testId]);

        // Find current and next
        $currentIndex = $test['current_variation_index'] ?? 0;
        $nextIndex = ($currentIndex + 1) % count($variations);

        // Apply next variation
        $nextVariation = $variations[$nextIndex];
        $applied = $this->applyVariation($test['article_id'], $nextVariation['title']);

        if ($applied) {
            $this->db->update('ab_title_tests', [
                'current_variation_index' => $nextIndex,
                'last_rotation' => date('Y-m-d H:i:s')
            ], 'id = ?', [$testId]);

            return [
                'success' => true,
                'new_variation' => $nextVariation['title'],
                'variation_index' => $nextIndex
            ];
        }

        return ['success' => false, 'message' => 'Failed to apply variation'];
    }

    /**
     * Evaluate test and pick winner
     */
    public function evaluateTest(int $testId): array {
        $test = $this->db->fetchOne("SELECT * FROM ab_title_tests WHERE id = ?", [$testId]);

        if (!$test) {
            return ['success' => false, 'message' => 'Test not found'];
        }

        // Get all variations with metrics
        $variations = $this->db->fetchAll("
            SELECT * FROM ab_title_variations
            WHERE test_id = ?
            ORDER BY ctr DESC
        ", [$testId]);

        // Check if we have enough data
        $totalImpressions = array_sum(array_column($variations, 'impressions'));

        if ($totalImpressions < self::MIN_IMPRESSIONS) {
            return [
                'success' => false,
                'message' => "ยังมี impressions ไม่พอ ({$totalImpressions}/" . self::MIN_IMPRESSIONS . ")",
                'variations' => $variations
            ];
        }

        // Find winner (highest CTR)
        $winner = $variations[0];
        $original = array_filter($variations, fn($v) => $v['is_original'] == 1);
        $original = reset($original);

        // Calculate improvement
        $improvement = 0;
        if ($original && $original['ctr'] > 0) {
            $improvement = (($winner['ctr'] - $original['ctr']) / $original['ctr']) * 100;
        }

        return [
            'success' => true,
            'winner' => $winner,
            'original' => $original,
            'improvement' => round($improvement, 1),
            'all_variations' => $variations,
            'total_impressions' => $totalImpressions,
            'recommendation' => $winner['is_original']
                ? 'เก็บ Title เดิม (ดีที่สุด)'
                : "เปลี่ยนเป็น: {$winner['title']} (+{$improvement}% CTR)"
        ];
    }

    /**
     * Complete test and apply winner
     */
    public function completeTest(int $testId, bool $applyWinner = true): array {
        $evaluation = $this->evaluateTest($testId);

        if (!$evaluation['success']) {
            return $evaluation;
        }

        $test = $this->db->fetchOne("SELECT * FROM ab_title_tests WHERE id = ?", [$testId]);

        // Apply winner title if not original
        if ($applyWinner && !$evaluation['winner']['is_original']) {
            $this->applyVariation($test['article_id'], $evaluation['winner']['title']);

            // Update article title in database
            $this->db->update('articles', [
                'title' => $evaluation['winner']['title']
            ], 'id = ?', [$test['article_id']]);
        }

        // Mark test as completed
        $this->db->update('ab_title_tests', [
            'status' => 'completed',
            'winner_variation_index' => $evaluation['winner']['variation_index'],
            'final_improvement' => $evaluation['improvement'],
            'completed_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$testId]);

        logEvent('info', 'ab_test', 'A/B Title test completed', [
            'test_id' => $testId,
            'winner' => $evaluation['winner']['title'],
            'improvement' => $evaluation['improvement']
        ]);

        return [
            'success' => true,
            'message' => 'ทดสอบเสร็จสิ้น',
            'winner' => $evaluation['winner'],
            'improvement' => $evaluation['improvement'],
            'applied' => $applyWinner && !$evaluation['winner']['is_original']
        ];
    }

    /**
     * Get all running tests
     */
    public function getRunningTests(): array {
        return $this->db->fetchAll("
            SELECT t.*, a.title as current_title, a.post_url, s.name as site_name,
                   (SELECT COUNT(*) FROM ab_title_variations WHERE test_id = t.id) as variation_count
            FROM ab_title_tests t
            JOIN articles a ON t.article_id = a.id
            JOIN sites s ON t.site_id = s.id
            WHERE t.status = 'running'
            ORDER BY t.start_date DESC
        ");
    }

    /**
     * Get test history
     */
    public function getTestHistory(int $limit = 20): array {
        return $this->db->fetchAll("
            SELECT t.*, a.title as current_title, a.post_url, s.name as site_name
            FROM ab_title_tests t
            JOIN articles a ON t.article_id = a.id
            JOIN sites s ON t.site_id = s.id
            ORDER BY t.created_at DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Get test details with all variations
     */
    public function getTestDetails(int $testId): array {
        $test = $this->db->fetchOne("
            SELECT t.*, a.title as current_title, a.post_url, a.primary_keyword, s.name as site_name
            FROM ab_title_tests t
            JOIN articles a ON t.article_id = a.id
            JOIN sites s ON t.site_id = s.id
            WHERE t.id = ?
        ", [$testId]);

        if (!$test) {
            return ['success' => false, 'message' => 'Test not found'];
        }

        $variations = $this->db->fetchAll("
            SELECT * FROM ab_title_variations
            WHERE test_id = ?
            ORDER BY ctr DESC
        ", [$testId]);

        return [
            'success' => true,
            'test' => $test,
            'variations' => $variations
        ];
    }

    /**
     * Cancel a running test
     */
    public function cancelTest(int $testId, bool $restoreOriginal = true): array {
        $test = $this->db->fetchOne("SELECT * FROM ab_title_tests WHERE id = ?", [$testId]);

        if (!$test) {
            return ['success' => false, 'message' => 'Test not found'];
        }

        // Restore original title if requested
        if ($restoreOriginal) {
            $this->applyVariation($test['article_id'], $test['original_title']);

            $this->db->update('articles', [
                'title' => $test['original_title']
            ], 'id = ?', [$test['article_id']]);
        }

        // Mark as cancelled
        $this->db->update('ab_title_tests', [
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$testId]);

        return ['success' => true, 'message' => 'ยกเลิกการทดสอบแล้ว'];
    }

    /**
     * Get dashboard stats
     */
    public function getDashboardStats(): array {
        $running = $this->db->fetchColumn("SELECT COUNT(*) FROM ab_title_tests WHERE status = 'running'");
        $completed = $this->db->fetchColumn("SELECT COUNT(*) FROM ab_title_tests WHERE status = 'completed'");

        $avgImprovement = $this->db->fetchColumn("
            SELECT AVG(final_improvement) FROM ab_title_tests
            WHERE status = 'completed' AND final_improvement > 0
        ");

        $bestImprovement = $this->db->fetchOne("
            SELECT t.*, a.title, a.post_url
            FROM ab_title_tests t
            JOIN articles a ON t.article_id = a.id
            WHERE t.status = 'completed'
            ORDER BY t.final_improvement DESC
            LIMIT 1
        ");

        return [
            'running_tests' => (int)$running,
            'completed_tests' => (int)$completed,
            'avg_improvement' => round($avgImprovement ?? 0, 1),
            'best_improvement' => $bestImprovement
        ];
    }
}

/**
 * Helper function
 */
function abTitleTester(): ABTitleTester {
    return new ABTitleTester();
}
