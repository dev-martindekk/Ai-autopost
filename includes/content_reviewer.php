<?php
/**
 * AI AutoPost SEO System - Content Reviewer
 * =========================================
 * AI-powered content quality gate
 * Reviews articles before posting
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class ContentReviewer {
    private $ai;
    private $minQualityScore = 70; // Minimum score to pass

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * Review an article and return quality assessment
     */
    public function review(int $articleId): array {
        $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        // Build review prompt
        $prompt = $this->buildReviewPrompt($article);

        // Get AI review
        $result = $this->ai->execute('content_review', $prompt, ['max_tokens' => 2000]);

        if (empty($result['content'])) {
            return ['success' => false, 'message' => 'AI failed to review'];
        }

        // Parse review result
        $review = $this->parseReview($result['content']);

        if (!$review) {
            return ['success' => false, 'message' => 'Failed to parse review'];
        }

        // Save review to database
        $this->saveReview($articleId, $review);

        // Determine if article passes
        $passes = $review['quality_score'] >= $this->minQualityScore;

        return [
            'success' => true,
            'passes' => $passes,
            'quality_score' => $review['quality_score'],
            'issues' => $review['issues'],
            'suggestions' => $review['suggestions'],
            'eeat_score' => $review['eeat_score'] ?? null,
            'revised_content' => $review['revised_content'] ?? null
        ];
    }

    /**
     * Build review prompt
     */
    private function buildReviewPrompt(array $article): string {
        $content = $article['content'];
        $keyword = $article['primary_keyword'];
        $topic = $article['topic'];

        $customPrompt = getPromptTemplate('review', '');
        if ($customPrompt) {
            return str_replace(
                ['{title}', '{keyword}', '{topic}', '{content}'],
                [$article['title'], $keyword, $topic, $content],
                $customPrompt
            );
        }

        return <<<PROMPT
คุณเป็น Content Reviewer ตรวจสอบบทความภาษาไทยเชิงลึก เน้นประเมิน EEAT

## บทความ
**Keyword:** {$keyword} | **หมวด:** {$topic} | **หัวข้อ:** {$article['title']}

**เนื้อหา:**
{$content}

## เกณฑ์ให้คะแนน
1. **quality_score** (0-100): ความลึก ความแม่นยำ ความเป็นต้นฉบับ flow การเขียน
2. **seo_score** (0-100): keyword, โครงสร้าง heading, meta-friendly
3. **eeat_score** (0-100): Experience (ประสบการณ์จริง), Expertise (ความเชี่ยวชาญ), Authority (ความน่าเชื่อถือ), Trust (ความไว้วางใจ)
4. **content_safe**: เนื้อหาไม่มีข้อมูลเท็จหรือสร้างความเข้าใจผิด

## ตอบเป็น JSON เท่านั้น:
{
  "quality_score": 85,
  "seo_score": 80,
  "eeat_score": 75,
  "content_safe": true,
  "issues": [
    {"type": "quality|seo|eeat", "severity": "low|medium|high", "description": "อธิบายปัญหา"}
  ],
  "suggestions": ["คำแนะนำเฉพาะเจาะจง"],
  "risk_flags": [],
  "passes_quality_gate": true,
  "summary": "สรุปภาพรวมสั้นๆ"
}
PROMPT;
    }

    /**
     * Parse AI review response
     */
    private function parseReview(string $content): ?array {
        // Clean up response
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);
        $content = trim($content);

        $review = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $review = json_decode($matches[0], true);
            }
        }

        if (!is_array($review) || !isset($review['quality_score'])) {
            return null;
        }

        return [
            'quality_score' => (int)($review['quality_score'] ?? 0),
            'seo_score' => (int)($review['seo_score'] ?? 0),
            'eeat_score' => (int)($review['eeat_score'] ?? 0),
            'content_safe' => $review['content_safe'] ?? true,
            'issues' => $review['issues'] ?? [],
            'suggestions' => $review['suggestions'] ?? [],
            'risk_flags' => $review['risk_flags'] ?? [],
            'passes' => $review['passes_quality_gate'] ?? ($review['quality_score'] >= $this->minQualityScore),
            'summary' => $review['summary'] ?? ''
        ];
    }

    /**
     * Save review results to database
     */
    private function saveReview(int $articleId, array $review): void {
        db()->update('articles', [
            'quality_score' => $review['quality_score'],
            'review_issues' => json_encode([
                'seo_score' => $review['seo_score'],
                'eeat_score' => $review['eeat_score'],
                'issues' => $review['issues'],
                'suggestions' => $review['suggestions'],
                'risk_flags' => $review['risk_flags'],
                'summary' => $review['summary']
            ]),
            'has_responsible_gaming' => ($review['content_safe'] ?? true) ? 1 : 0,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'status' => $review['passes'] ? 'reviewed' : 'needs_revision'
        ], 'id = ?', [$articleId]);
    }

    /**
     * Review and fix article if needed
     */
    public function reviewAndFix(int $articleId): array {
        $review = $this->review($articleId);

        if (!$review['success']) {
            return $review;
        }

        // If passes, no fix needed
        if ($review['passes']) {
            return $review;
        }

        // Try to fix the article
        $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);
        $fixResult = $this->fixArticle($article, $review);

        if ($fixResult['success']) {
            // Update article with fixed content
            db()->update('articles', [
                'content' => $fixResult['content'],
                'status' => 'reviewed'
            ], 'id = ?', [$articleId]);

            // Re-review to get new score
            return $this->review($articleId);
        }

        return $review;
    }

    /**
     * Fix article issues
     */
    private function fixArticle(array $article, array $review): array {
        $issues = implode("\n", array_map(function($i) {
            return "- {$i['description']}";
        }, $review['issues']));

        $suggestions = implode("\n", array_map(function($s) {
            return "- {$s}";
        }, $review['suggestions']));

        $prompt = <<<PROMPT
You are an expert Content Editor. Fix the following article based on the review feedback.

ORIGINAL ARTICLE:
{$article['content']}

ISSUES FOUND:
{$issues}

SUGGESTIONS:
{$suggestions}

KEYWORD: {$article['primary_keyword']}

YOUR TASK:
1. Fix all identified issues
2. Implement the suggestions
3. Ensure natural keyword usage
4. Improve content safety — remove any false claims or misleading statements
5. Improve EEAT signals
6. Keep the same HTML structure

OUTPUT:
Return ONLY the improved HTML content, no explanation.
PROMPT;

        $result = $this->ai->execute('content_review', $prompt, ['max_tokens' => 2000]);

        if (empty($result['content'])) {
            return ['success' => false, 'message' => 'Failed to fix article'];
        }

        // Clean content
        $content = $result['content'];
        $content = preg_replace('/^```html\s*/im', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);
        $content = trim($content);

        return ['success' => true, 'content' => $content];
    }

    /**
     * Set minimum quality score
     */
    public function setMinQualityScore(int $score): void {
        $this->minQualityScore = max(0, min(100, $score));
    }
}

/**
 * Helper function
 */
function contentReviewer(): ContentReviewer {
    return new ContentReviewer();
}
