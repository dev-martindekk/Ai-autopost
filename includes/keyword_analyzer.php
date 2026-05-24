<?php
/**
 * AI AutoPost SEO System - Keyword Analyzer
 * ==========================================
 * วิเคราะห์และหา Related Keywords สำหรับสร้างบทความ
 * - หา Secondary Keywords จากฐานข้อมูล
 * - หา Long Tail Keywords ที่เกี่ยวข้อง
 * - ใช้ AI วิเคราะห์ความสัมพันธ์ของ keywords
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class KeywordAnalyzer {

    private $ai;

    // Language names for prompts
    private $languageNames = [
        'th' => 'ภาษาไทย',
        'vn' => 'tiếng Việt',
        'en' => 'English',
        'id' => 'Bahasa Indonesia',
        'my' => 'Bahasa Malaysia',
        'km' => 'ភាសាខ្មែរ',
        'zh' => '中文',
        'ja' => '日本語',
        'ko' => '한국어'
    ];

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * Get language name from code
     */
    private function getLanguageName(string $code): string {
        return $this->languageNames[$code] ?? 'English';
    }

    /**
     * วิเคราะห์และหา Keyword Cluster สำหรับ Primary Keyword
     * @param array $primaryKeyword Primary keyword data from database
     * @param int $siteId Site ID
     * @return array Keyword cluster with primary, secondary, long_tail
     */
    public function analyzeKeywordCluster(array $primaryKeyword, int $siteId): array {
        $topic = $primaryKeyword['topic'] ?? 'general';
        $primaryKw = $primaryKeyword['keyword'];

        // Get site info
        $site = db()->fetchOne("SELECT language_code, site_niche, name FROM sites WHERE id = ?", [$siteId]);
        $langCode  = $site['language_code'] ?? 'th';
        $siteNiche = $site['site_niche'] ?? '';

        // 1. ดึง Keywords ทั้งหมดใน topic เดียวกัน (filter by language)
        $allKeywords = $this->getKeywordsByTopic($topic, $primaryKeyword['id'], $langCode);

        // 2. หา Related Keywords จากฐานข้อมูล (ใช้ text matching)
        $relatedFromDb = $this->findRelatedKeywords($primaryKw, $allKeywords);

        // 3. ใช้ AI วิเคราะห์และเลือก Keywords ที่เหมาะสมที่สุด
        $analyzedCluster = $this->aiAnalyzeCluster($primaryKeyword, $relatedFromDb, $allKeywords, $langCode, $siteNiche);

        return [
            'primary' => [
                'keyword' => $primaryKw,
                'volume' => $primaryKeyword['search_volume'] ?? 0,
                'difficulty' => $primaryKeyword['difficulty'] ?? 0,
                'intent' => $primaryKeyword['search_intent'] ?? 'informational',
                'suggested_density' => '2-3%', // 3-5 ครั้งต่อ 1500 คำ
                'placement' => ['title', 'h1', 'first_paragraph', 'conclusion', 'meta_description']
            ],
            'secondary' => $analyzedCluster['secondary'] ?? [],
            'long_tail' => $analyzedCluster['long_tail'] ?? [],
            'lsi_keywords' => $analyzedCluster['lsi'] ?? [],
            'content_suggestions' => $analyzedCluster['content_suggestions'] ?? [],
            'total_keywords' => 1 + count($analyzedCluster['secondary'] ?? []) + count($analyzedCluster['long_tail'] ?? [])
        ];
    }

    /**
     * ดึง Keywords ทั้งหมดใน Topic และ Language
     */
    private function getKeywordsByTopic(string $topic, ?int $excludeId = null, string $langCode = 'th'): array {
        // Handle null excludeId — AND id != NULL would filter out ALL rows
        $excludeClause = $excludeId !== null ? "AND id != ?" : "";
        $params = [$topic, $langCode];
        if ($excludeId !== null) {
            $params[] = $excludeId;
        }

        return db()->fetchAll("
            SELECT id, keyword, keyword_type, search_volume, difficulty,
                   traffic_potential, search_intent, data_source
            FROM keywords
            WHERE topic = ?
              AND language_code = ?
              {$excludeClause}
              AND is_active = 1
            ORDER BY
                CASE WHEN data_source = 'ahrefs' THEN 0 ELSE 1 END,
                search_volume DESC
            LIMIT 100
        ", $params);
    }

    /**
     * หา Related Keywords จากฐานข้อมูล (Text Matching)
     */
    private function findRelatedKeywords(string $primaryKw, array $allKeywords): array {
        $related = [
            'exact_match' => [],      // มี primary keyword อยู่ใน keyword
            'partial_match' => [],    // มีบางคำตรงกัน
            'same_topic' => []        // อยู่ใน topic เดียวกัน
        ];

        // แยกคำใน primary keyword
        $primaryWords = $this->tokenize($primaryKw);

        foreach ($allKeywords as $kw) {
            $kwText = $kw['keyword'];

            // Exact match - primary keyword อยู่ใน keyword นี้
            if (mb_stripos($kwText, $primaryKw) !== false) {
                $related['exact_match'][] = $kw;
                continue;
            }

            // Partial match - มีคำตรงกันบางคำ
            $kwWords = $this->tokenize($kwText);
            $commonWords = array_intersect($primaryWords, $kwWords);

            if (count($commonWords) > 0) {
                $kw['match_score'] = count($commonWords) / max(count($primaryWords), count($kwWords));
                $related['partial_match'][] = $kw;
            } else {
                $related['same_topic'][] = $kw;
            }
        }

        // Sort partial match by score
        usort($related['partial_match'], function($a, $b) {
            return ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0);
        });

        return $related;
    }

    /**
     * แยกคำ (Tokenize) - รองรับภาษาไทย
     */
    private function tokenize(string $text): array {
        // ลบอักขระพิเศษ
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = mb_strtolower($text);

        // แยกคำ
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // กรองคำที่สั้นเกินไป
        $words = array_filter($words, function($w) {
            return mb_strlen($w) >= 2;
        });

        return array_values($words);
    }

    /**
     * ใช้ AI วิเคราะห์และเลือก Keyword Cluster
     */
    private function aiAnalyzeCluster(array $primaryKeyword, array $relatedFromDb, array $allKeywords, string $langCode = 'th', string $siteNiche = ''): array {
        $langName = $this->getLanguageName($langCode);
        $primaryKw = $primaryKeyword['keyword'];
        $topic = $primaryKeyword['topic'] ?? 'general';
        $intent = $primaryKeyword['search_intent'] ?? 'informational';
        $nicheContext = $siteNiche ? "WEBSITE NICHE: {$siteNiche}\n" : '';

        // เตรียมข้อมูลสำหรับ AI
        $exactMatch = array_slice($relatedFromDb['exact_match'], 0, 10);
        $partialMatch = array_slice($relatedFromDb['partial_match'], 0, 15);
        $sameTopic = array_slice($relatedFromDb['same_topic'], 0, 10);

        $keywordList = [];
        foreach (array_merge($exactMatch, $partialMatch, $sameTopic) as $kw) {
            $keywordList[] = [
                'keyword' => $kw['keyword'],
                'type' => $kw['keyword_type'] ?? 'secondary',
                'volume' => $kw['search_volume'] ?? 0,
                'difficulty' => $kw['difficulty'] ?? 0,
                'intent' => $kw['search_intent'] ?? 'informational'
            ];
        }

        $keywordJson = json_encode($keywordList, JSON_UNESCAPED_UNICODE);
        $hasDbKeywords = count($keywordList) >= 3;

        $customPrompt = getPromptTemplate('keyword_cluster', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{primary_keyword}', '{topic}', '{keywords_json}'],
                [$primaryKw, $topic, $keywordJson],
                $customPrompt
            );
        } elseif ($hasDbKeywords) {
            // มี keywords ในฐานข้อมูล — ให้ AI เลือกและจัดกลุ่ม
            $prompt = <<<PROMPT
คุณเป็น SEO Expert วิเคราะห์ Keyword Cluster สำหรับบทความ{$langName}
⚠️ สำคัญ: ทุก keyword ที่แนะนำต้องเป็น{$langName}ที่เหมาะสมกับตลาดนั้น

PRIMARY KEYWORD: "{$primaryKw}"
TOPIC: {$topic}
SEARCH INTENT: {$intent}
TARGET LANGUAGE: {$langName}
{$nicheContext}

AVAILABLE KEYWORDS FROM DATABASE:
{$keywordJson}

งานของคุณ:
1. เลือก 3-5 Secondary Keywords ที่เกี่ยวข้องและสนับสนุน primary keyword (ต้องเป็น{$langName})
2. เลือก 2-4 Long Tail Keywords (ประโยคยาว เจาะจงมากขึ้น เป็น{$langName})
3. แนะนำ 3-5 LSI Keywords (คำที่มีความหมายใกล้เคียง ช่วย Google เข้าใจ context เป็น{$langName})
4. แนะนำหัวข้อย่อย (H2) ที่ควรมีในบทความ (เป็น{$langName})

เกณฑ์การเลือก:
- Secondary: เกี่ยวข้องโดยตรง, volume ดี, difficulty ไม่สูงเกินไป
- Long Tail: เจาะจง, ตอบคำถามผู้อ่าน, มักเป็นคำถาม
- LSI: คำที่ Google คาดหวังจะเห็นในบทความหัวข้อนี้

ตอบเป็น JSON เท่านั้น:
{
  "secondary": [
    {"keyword": "xxx", "reason": "เหตุผลที่เลือก", "density": "1-2%", "placement": ["h2", "body"]}
  ],
  "long_tail": [
    {"keyword": "xxx", "reason": "เหตุผล", "use_as": "h2 หรือ paragraph"}
  ],
  "lsi": ["คำ1", "คำ2", "คำ3"],
  "content_suggestions": {
    "h2_headings": ["หัวข้อ H2 ที่ควรมี"],
    "questions_to_answer": ["คำถามที่บทความควรตอบ"],
    "topics_to_cover": ["ประเด็นที่ควรครอบคลุม"]
  }
}
PROMPT;
        } else {
            // ไม่มี keywords ในฐานข้อมูล (หรือน้อยเกินไป) — ให้ AI สร้างขึ้นมาเอง
            $prompt = <<<PROMPT
คุณเป็น SEO Expert สร้าง Keyword Cluster สำหรับบทความ{$langName}
⚠️ สำคัญ: ทุก keyword ต้องเป็น{$langName}ที่คนใช้จริงใน Search Engine

PRIMARY KEYWORD: "{$primaryKw}"
TOPIC: {$topic}
SEARCH INTENT: {$intent}
TARGET LANGUAGE: {$langName}
{$nicheContext}

ไม่มี keywords ในฐานข้อมูล — ให้คุณ**สร้าง**ขึ้นมาเองจากความรู้ด้าน SEO:

1. คิด 3-5 Secondary Keywords ที่เกี่ยวข้องโดยตรงกับ "{$primaryKw}" (เป็น{$langName})
2. คิด 3-5 Long Tail Keywords รูปแบบคำถามหรือวลียาว (เป็น{$langName})
3. คิด 4-6 LSI Keywords (คำที่เกี่ยวข้องเชิงความหมาย Google คาดหวังในบทความ)
4. แนะนำหัวข้อ H2 ที่เหมาะสม 4-6 หัวข้อ (เป็น{$langName})
5. แนะนำคำถามที่ผู้อ่านน่าจะสงสัย 3-5 ข้อ (เป็น{$langName})

หลักการ:
- Secondary: คำที่คนค้นหาเพื่อเรื่องเดียวกับ primary แต่ต่างมุม
- Long Tail: วลียาวกว่า 3 คำ เจาะจง เช่น "วิธี...", "...คืออะไร", "...ที่ดีที่สุด"
- LSI: คำศัพท์ที่เกี่ยวข้องเชิงหัวข้อ ช่วยให้บทความครอบคลุม

ตอบเป็น JSON เท่านั้น:
{
  "secondary": [
    {"keyword": "xxx", "reason": "เหตุผล", "density": "1-2%", "placement": ["h2", "body"]}
  ],
  "long_tail": [
    {"keyword": "xxx", "reason": "เหตุผล", "use_as": "h2 หรือ paragraph"}
  ],
  "lsi": ["คำ1", "คำ2", "คำ3", "คำ4"],
  "content_suggestions": {
    "h2_headings": ["หัวข้อ H2 ที่ควรมี"],
    "questions_to_answer": ["คำถามที่บทความควรตอบ"],
    "topics_to_cover": ["ประเด็นที่ควรครอบคลุม"]
  }
}
PROMPT;
        }

        // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ keyword analysis คุณภาพสูง
        $result = $this->ai->execute('keyword_analysis', $prompt, ['max_tokens' => 2000]);

        if (empty($result['content'])) {
            return $this->getFallbackCluster($relatedFromDb);
        }

        // Parse AI response
        $content = $result['content'];
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
        }

        if (!is_array($parsed)) {
            return $this->getFallbackCluster($relatedFromDb);
        }

        return $parsed;
    }

    /**
     * Fallback cluster ถ้า AI ไม่ตอบ
     */
    private function getFallbackCluster(array $relatedFromDb): array {
        $secondary = [];
        $longTail = [];

        foreach (array_slice($relatedFromDb['exact_match'], 0, 3) as $kw) {
            $secondary[] = [
                'keyword' => $kw['keyword'],
                'reason' => 'Exact match with primary',
                'density' => '1-2%',
                'placement' => ['h2', 'body']
            ];
        }

        foreach ($relatedFromDb['partial_match'] as $kw) {
            if (mb_strlen($kw['keyword']) > 20 && count($longTail) < 3) {
                $longTail[] = ['keyword' => $kw['keyword'], 'reason' => 'Related long phrase', 'use_as' => 'paragraph'];
            } elseif (count($secondary) < 5) {
                $secondary[] = ['keyword' => $kw['keyword'], 'reason' => 'Partial match', 'density' => '1%', 'placement' => ['body']];
            }
        }

        return [
            'secondary' => $secondary,
            'long_tail' => $longTail,
            'lsi' => [],
            'content_suggestions' => []
        ];
    }

    /**
     * สร้าง Keyword Map สำหรับส่งให้ AI เขียนบทความ
     */
    public function buildKeywordMapForArticle(array $cluster): string {
        $map = "## KEYWORD STRATEGY สำหรับบทความนี้\n⚠️ หมายเหตุ: กระจาย keywords ทั้งหมดภายในโครงสร้างบทความที่กำหนด (3 H2 หลัก + ตาราง + FAQ + สรุป) ห้ามสร้าง H2 ใหม่สำหรับแต่ละ keyword\n\n";

        // Primary
        $primary = $cluster['primary'];
        $map .= "### PRIMARY KEYWORD (สำคัญที่สุด)\n";
        $map .= "- **Keyword:** {$primary['keyword']}\n";
        $map .= "- **Search Volume:** " . number_format($primary['volume']) . "\n";
        $map .= "- **Intent:** {$primary['intent']}\n";
        $map .= "- **ใช้ใน:** Title, H1, ย่อหน้าแรก, สรุป, Meta Description\n";
        $map .= "- **ความถี่:** 3-5 ครั้ง (ธรรมชาติ ไม่ยัดใส่)\n\n";

        // Secondary
        if (!empty($cluster['secondary'])) {
            $map .= "### SECONDARY KEYWORDS (สนับสนุน Primary)\n";
            foreach ($cluster['secondary'] as $i => $kw) {
                $num = $i + 1;
                $keyword = is_array($kw) ? $kw['keyword'] : $kw;
                $placement = is_array($kw) && isset($kw['placement']) ? implode(', ', $kw['placement']) : 'body';
                $map .= "{$num}. **{$keyword}** - ใช้ใน: {$placement} (1-2 ครั้ง)\n";
            }
            $map .= "\n";
        }

        // Long Tail
        if (!empty($cluster['long_tail'])) {
            $map .= "### LONG TAIL KEYWORDS (เจาะจง/ตอบคำถาม)\n";
            foreach ($cluster['long_tail'] as $i => $kw) {
                $num = $i + 1;
                $keyword = is_array($kw) ? $kw['keyword'] : $kw;
                $useAs = is_array($kw) && isset($kw['use_as']) ? $kw['use_as'] : 'paragraph';
                $map .= "{$num}. **{$keyword}** - ใช้เป็น: {$useAs}\n";
            }
            $map .= "\n";
        }

        // LSI
        if (!empty($cluster['lsi_keywords'])) {
            $lsiList = is_array($cluster['lsi_keywords']) ? implode(', ', $cluster['lsi_keywords']) : $cluster['lsi_keywords'];
            $map .= "### LSI KEYWORDS (คำที่เกี่ยวข้องช่วย SEO)\n";
            $map .= "ใช้คำเหล่านี้กระจายในบทความอย่างเป็นธรรมชาติ: {$lsiList}\n\n";
        }

        // Content Suggestions
        if (!empty($cluster['content_suggestions'])) {
            $suggestions = $cluster['content_suggestions'];

            if (!empty($suggestions['h2_headings'])) {
                $map .= "### แนะนำแนวทาง H2 (เลือกใช้แค่ 3 หัวข้อหลัก)\n";
                foreach ($suggestions['h2_headings'] as $h2) {
                    $map .= "- {$h2}\n";
                }
                $map .= "(เลือกแค่ 3 หัวข้อที่เหมาะสมที่สุด ไม่ต้องใช้ทั้งหมด)\n\n";
            }

            if (!empty($suggestions['questions_to_answer'])) {
                $map .= "### คำถามที่บทความควรตอบ\n";
                foreach ($suggestions['questions_to_answer'] as $q) {
                    $map .= "- {$q}\n";
                }
                $map .= "\n";
            }
        }

        return $map;
    }
}

/**
 * Helper function
 */
function keywordAnalyzer(): KeywordAnalyzer {
    return new KeywordAnalyzer();
}
