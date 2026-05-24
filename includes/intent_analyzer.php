<?php
/**
 * AI AutoPost SEO System - Search Intent Analyzer
 * ================================================
 * Analyzes keyword search intent for better content matching
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class IntentAnalyzer {
    private $ai;

    // Intent types
    const INTENT_INFORMATIONAL = 'informational';  // Want to learn
    const INTENT_COMMERCIAL = 'commercial';         // Researching before buying
    const INTENT_TRANSACTIONAL = 'transactional';   // Ready to act/buy
    const INTENT_NAVIGATIONAL = 'navigational';     // Looking for specific site

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * Analyze search intent for a keyword
     */
    public function analyze(string $keyword): array {
        // First try rule-based analysis
        $ruleBasedIntent = $this->ruleBasedAnalysis($keyword);

        // Then use AI for deeper analysis
        $aiAnalysis = $this->aiAnalysis($keyword);

        // Combine results
        return [
            'keyword' => $keyword,
            'primary_intent' => $aiAnalysis['primary_intent'] ?? $ruleBasedIntent['intent'],
            'secondary_intent' => $aiAnalysis['secondary_intent'] ?? null,
            'confidence' => $aiAnalysis['confidence'] ?? $ruleBasedIntent['confidence'],
            'content_type_suggestion' => $aiAnalysis['content_type'] ?? $this->suggestContentType($ruleBasedIntent['intent']),
            'content_structure' => $aiAnalysis['structure'] ?? [],
            'user_needs' => $aiAnalysis['user_needs'] ?? [],
            'competing_formats' => $aiAnalysis['competing_formats'] ?? []
        ];
    }

    /**
     * Rule-based intent analysis
     */
    private function ruleBasedAnalysis(string $keyword): array {
        $keywordLower = mb_strtolower($keyword);

        // Informational signals (Thai + English + Vietnamese)
        $infoSignals = [
            // Thai
            'วิธี', 'คืออะไร', 'ทำไม', 'เรียนรู้', 'คู่มือ', 'อธิบาย',
            // English
            'how to', 'what is', 'why', 'learn', 'guide', 'tutorial', 'explain',
            // Vietnamese
            'cách', 'hướng dẫn', 'là gì', 'tại sao', 'học', 'chỉ dẫn', 'giải thích'
        ];

        // Commercial signals (Thai + English + Vietnamese)
        $commercialSignals = [
            // Thai
            'รีวิว', 'เปรียบเทียบ', 'ดีไหม', 'ยอดนิยม', 'แนะนำ', 'อันไหนดี',
            // English
            'review', 'compare', 'vs', 'best', 'top', 'recommend',
            // Vietnamese
            'đánh giá', 'so sánh', 'tốt nhất', 'nên chọn', 'khuyên dùng', 'nên mua'
        ];

        // Transactional signals (Thai + English + Vietnamese)
        $transactionalSignals = [
            // Thai
            'สมัคร', 'ซื้อ', 'ดาวน์โหลด', 'ราคา', 'โปรโมชั่น', 'เล่นเลย', 'ทดลองเล่น',
            // English
            'register', 'buy', 'download', 'price', 'promotion', 'play now',
            // Vietnamese
            'đăng ký', 'mua', 'tải', 'giá', 'khuyến mãi', 'chơi ngay', 'thử nghiệm'
        ];

        // Navigational signals (Thai + English + Vietnamese)
        $navSignals = [
            // Thai
            'เข้าสู่ระบบ', 'ทางเข้า', 'เว็บ', 'ออฟฟิเชียล', 'แอป',
            // English
            'login', 'website', 'official', '.com', 'app',
            // Vietnamese
            'đăng nhập', 'trang chủ', 'liên hệ', 'chính thức', 'ứng dụng'
        ];

        $scores = [
            self::INTENT_INFORMATIONAL => 0,
            self::INTENT_COMMERCIAL => 0,
            self::INTENT_TRANSACTIONAL => 0,
            self::INTENT_NAVIGATIONAL => 0
        ];

        foreach ($infoSignals as $signal) {
            if (mb_stripos($keywordLower, $signal) !== false) {
                $scores[self::INTENT_INFORMATIONAL] += 2;
            }
        }

        foreach ($commercialSignals as $signal) {
            if (mb_stripos($keywordLower, $signal) !== false) {
                $scores[self::INTENT_COMMERCIAL] += 2;
            }
        }

        foreach ($transactionalSignals as $signal) {
            if (mb_stripos($keywordLower, $signal) !== false) {
                $scores[self::INTENT_TRANSACTIONAL] += 2;
            }
        }

        foreach ($navSignals as $signal) {
            if (mb_stripos($keywordLower, $signal) !== false) {
                $scores[self::INTENT_NAVIGATIONAL] += 2;
            }
        }

        // Find highest score
        $maxScore = max($scores);
        $intent = array_search($maxScore, $scores);

        // Default to informational if no clear signal
        if ($maxScore == 0) {
            $intent = self::INTENT_INFORMATIONAL;
            $confidence = 0.5;
        } else {
            $totalScore = array_sum($scores);
            $confidence = $maxScore / max($totalScore, 1);
        }

        return [
            'intent' => $intent,
            'confidence' => round($confidence, 2),
            'scores' => $scores
        ];
    }

    /**
     * AI-powered intent analysis
     */
    private function aiAnalysis(string $keyword): array {
        $prompt = <<<PROMPT
You are an expert SEO analyst. Analyze the search intent for this keyword.

KEYWORD: {$keyword}

Analyze and determine:
1. Primary search intent (informational, commercial, transactional, navigational)
2. Secondary intent if any
3. What the user really wants to find
4. Best content type to satisfy this search
5. Recommended content structure

OUTPUT FORMAT (JSON):
{
  "primary_intent": "informational|commercial|transactional|navigational",
  "secondary_intent": "null or another intent",
  "confidence": 0.85,
  "content_type": "guide|review|comparison|tutorial|news|landing",
  "user_needs": [
    "What specific things user wants to know/do"
  ],
  "structure": {
    "recommended_format": "listicle|long-form|how-to|comparison-table",
    "key_sections": ["section1", "section2"],
    "must_include": ["important elements"]
  },
  "competing_formats": ["What format top results likely use"]
}

Return ONLY JSON.
PROMPT;

        // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ intent analysis คุณภาพสูง
        $result = $this->ai->execute('content_review', $prompt, ['max_tokens' => 1500]);

        if (empty($result['content'])) {
            return [];
        }

        $content = trim($result['content']);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);

        $analysis = json_decode($content, true);

        return is_array($analysis) ? $analysis : [];
    }

    /**
     * Suggest content type based on intent
     */
    private function suggestContentType(string $intent): string {
        $mapping = [
            self::INTENT_INFORMATIONAL => 'guide',
            self::INTENT_COMMERCIAL => 'review',
            self::INTENT_TRANSACTIONAL => 'landing',
            self::INTENT_NAVIGATIONAL => 'brand'
        ];

        return $mapping[$intent] ?? 'guide';
    }

    /**
     * Analyze multiple keywords at once
     */
    public function analyzeBatch(array $keywords): array {
        $results = [];
        foreach ($keywords as $keyword) {
            $results[$keyword] = $this->analyze($keyword);
        }
        return $results;
    }

    /**
     * Get content brief based on intent analysis
     */
    public function getContentBrief(string $keyword): array {
        $analysis = $this->analyze($keyword);

        $brief = [
            'keyword' => $keyword,
            'intent' => $analysis['primary_intent'],
            'content_type' => $analysis['content_type_suggestion'],
            'target_word_count' => $this->getTargetWordCount($analysis['primary_intent']),
            'structure' => $analysis['content_structure'],
            'must_answer' => $analysis['user_needs'],
            'tone' => $this->getTone($analysis['primary_intent']),
            'cta_type' => $this->getCTAType($analysis['primary_intent'])
        ];

        return $brief;
    }

    /**
     * Get target word count based on intent
     */
    private function getTargetWordCount(string $intent): array {
        $counts = [
            self::INTENT_INFORMATIONAL => ['min' => 1500, 'max' => 3000],
            self::INTENT_COMMERCIAL => ['min' => 1200, 'max' => 2500],
            self::INTENT_TRANSACTIONAL => ['min' => 800, 'max' => 1500],
            self::INTENT_NAVIGATIONAL => ['min' => 500, 'max' => 1000]
        ];

        return $counts[$intent] ?? ['min' => 1500, 'max' => 2500];
    }

    /**
     * Get recommended tone based on intent
     */
    private function getTone(string $intent): string {
        $tones = [
            self::INTENT_INFORMATIONAL => 'educational, helpful, thorough',
            self::INTENT_COMMERCIAL => 'objective, balanced, comparative',
            self::INTENT_TRANSACTIONAL => 'clear, action-oriented, trustworthy',
            self::INTENT_NAVIGATIONAL => 'direct, branded, authoritative'
        ];

        return $tones[$intent] ?? 'helpful and informative';
    }

    /**
     * Get CTA type based on intent
     */
    private function getCTAType(string $intent): string {
        $ctas = [
            self::INTENT_INFORMATIONAL => 'learn_more',
            self::INTENT_COMMERCIAL => 'compare_options',
            self::INTENT_TRANSACTIONAL => 'sign_up',
            self::INTENT_NAVIGATIONAL => 'visit_site'
        ];

        return $ctas[$intent] ?? 'learn_more';
    }
}

/**
 * Helper function
 */
function intentAnalyzer(): IntentAnalyzer {
    return new IntentAnalyzer();
}
