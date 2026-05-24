<?php
/**
 * AI AutoPost SEO System - Content Quality Checker
 * =================================================
 * ใช้ AI ตรวจสอบคุณภาพบทความก่อนโพสต์
 * - ตรวจสอบ SEO (keyword density, structure)
 * - ตรวจสอบคุณภาพเนื้อหา (ความสมบูรณ์, ความถูกต้อง)
 * - ตรวจสอบ Internal Links
 * - ให้คะแนนและคำแนะนำ
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class ContentQualityChecker {

    private $ai;

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * ตรวจสอบคุณภาพบทความก่อนโพสต์
     * @param string $title หัวข้อบทความ
     * @param string $content เนื้อหาบทความ
     * @param array $keywordCluster Keyword cluster ที่ใช้
     * @param array $site ข้อมูลเว็บไซต์
     * @return array ผลการตรวจสอบ
     */
    public function checkQuality(string $title, string $content, array $keywordCluster, array $site): array {
        $logs = [];
        $issues = [];
        $suggestions = [];

        // 1. ตรวจสอบพื้นฐาน (ไม่ใช้ AI)
        $basicCheck = $this->basicCheck($title, $content, $keywordCluster);
        $logs = array_merge($logs, $basicCheck['logs']);
        $issues = array_merge($issues, $basicCheck['issues']);

        // 2. ใช้ AI ตรวจสอบคุณภาพเชิงลึก
        $aiCheck = $this->aiQualityCheck($title, $content, $keywordCluster, $site);

        if ($aiCheck['success']) {
            $logs = array_merge($logs, $aiCheck['logs']);
            $issues = array_merge($issues, $aiCheck['issues']);
            $suggestions = array_merge($suggestions, $aiCheck['suggestions']);
        }

        // 3. คำนวณคะแนนรวม
        $score = $this->calculateScore($basicCheck, $aiCheck);

        // 4. ตัดสินใจว่าควรโพสต์หรือไม่
        $shouldPublish = $score >= 70 && count(array_filter($issues, fn($i) => $i['severity'] === 'critical')) === 0;

        return [
            'score' => $score,
            'should_publish' => $shouldPublish,
            'issues' => $issues,
            'suggestions' => $suggestions,
            'logs' => $logs,
            'details' => [
                'basic' => $basicCheck,
                'ai' => $aiCheck
            ]
        ];
    }

    /**
     * ตรวจสอบพื้นฐาน (ไม่ใช้ AI)
     */
    private function basicCheck(string $title, string $content, array $keywordCluster): array {
        $logs = [];
        $issues = [];
        $scores = [];

        $primaryKeyword = $keywordCluster['primary']['keyword'] ?? '';
        $contentText = strip_tags($content);
        $wordCount = $this->countWords($contentText);

        // 1. ตรวจสอบความยาว
        $logs[] = "จำนวนคำ: {$wordCount}";
        if ($wordCount < 800) {
            $issues[] = ['type' => 'length', 'severity' => 'warning', 'message' => "บทความสั้นเกินไป ({$wordCount} คำ) ควรมีอย่างน้อย 800 คำ"];
            $scores['length'] = 60;
        } elseif ($wordCount < 1200) {
            $scores['length'] = 80;
        } else {
            $scores['length'] = 100;
        }

        // 2. ตรวจสอบ Primary Keyword ใน Title
        if (mb_stripos($title, $primaryKeyword) !== false) {
            $logs[] = "Primary keyword อยู่ใน Title";
            $scores['title_keyword'] = 100;
        } else {
            $issues[] = ['type' => 'seo', 'severity' => 'warning', 'message' => "Primary keyword ไม่อยู่ใน Title"];
            $scores['title_keyword'] = 50;
        }

        // 3. ตรวจสอบ H1
        if (preg_match('/<h1[^>]*>(.+?)<\/h1>/is', $content)) {
            $logs[] = "มี H1 tag";
            $scores['h1'] = 100;
        } else {
            $issues[] = ['type' => 'structure', 'severity' => 'warning', 'message' => "ไม่พบ H1 tag"];
            $scores['h1'] = 50;
        }

        // 4. ตรวจสอบ H2
        preg_match_all('/<h2[^>]*>(.+?)<\/h2>/is', $content, $h2Matches);
        $h2Count = count($h2Matches[0]);
        $logs[] = "จำนวน H2: {$h2Count}";
        if ($h2Count >= 3) {
            $scores['h2'] = 100;
        } elseif ($h2Count >= 2) {
            $scores['h2'] = 80;
        } elseif ($h2Count >= 1) {
            $scores['h2'] = 60;
            $issues[] = ['type' => 'structure', 'severity' => 'info', 'message' => "มี H2 แค่ {$h2Count} หัวข้อ ควรมีอย่างน้อย 3"];
        } else {
            $scores['h2'] = 40;
            $issues[] = ['type' => 'structure', 'severity' => 'warning', 'message' => "ไม่มี H2 tag ควรแบ่งหัวข้อย่อย"];
        }

        // 5. ตรวจสอบ Keyword Density
        $keywordCount = 0;
        $density = 0;
        if (!empty($primaryKeyword)) {
            $keywordCount = substr_count(mb_strtolower($contentText), mb_strtolower($primaryKeyword));
            $density = ($keywordCount / max($wordCount, 1)) * 100;
        }
        $logs[] = "Keyword density: " . round($density, 2) . "% ({$keywordCount} ครั้ง)";

        if ($density < 0.5) {
            $issues[] = ['type' => 'seo', 'severity' => 'warning', 'message' => "Keyword density ต่ำเกินไป ({$density}%) ควรอยู่ที่ 1-3%"];
            $scores['density'] = 50;
        } elseif ($density > 4) {
            $issues[] = ['type' => 'seo', 'severity' => 'warning', 'message' => "Keyword density สูงเกินไป ({$density}%) อาจดูเป็น spam"];
            $scores['density'] = 60;
        } else {
            $scores['density'] = 100;
        }

        // 6. ตรวจสอบ Internal Links
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $linkMatches);
        $internalLinks = count($linkMatches[0]);
        $logs[] = "จำนวน Internal Links: {$internalLinks}";

        if ($internalLinks >= 3) {
            $scores['internal_links'] = 100;
        } elseif ($internalLinks >= 1) {
            $scores['internal_links'] = 70;
        } else {
            $issues[] = ['type' => 'seo', 'severity' => 'info', 'message' => "ไม่มี Internal Links ควรเพิ่มลิงก์ไปบทความอื่น"];
            $scores['internal_links'] = 40;
        }

        // 7. ตรวจสอบย่อหน้าแรก
        if (preg_match('/<p[^>]*>(.+?)<\/p>/is', $content, $firstPara)) {
            $firstParaText = strip_tags($firstPara[1]);
            if (mb_stripos($firstParaText, $primaryKeyword) !== false) {
                $logs[] = "Primary keyword อยู่ในย่อหน้าแรก";
                $scores['first_para'] = 100;
            } else {
                $issues[] = ['type' => 'seo', 'severity' => 'info', 'message' => "Primary keyword ไม่อยู่ในย่อหน้าแรก"];
                $scores['first_para'] = 70;
            }
        } else {
            $scores['first_para'] = 50;
        }

        // คำนวณคะแนนเฉลี่ย
        $avgScore = !empty($scores) ? array_sum($scores) / count($scores) : 0;

        return [
            'score' => round($avgScore),
            'scores' => $scores,
            'logs' => $logs,
            'issues' => $issues
        ];
    }

    /**
     * ใช้ AI ตรวจสอบคุณภาพเชิงลึก
     */
    private function aiQualityCheck(string $title, string $content, array $keywordCluster, array $site): array {
        // Get language
        $langCode = $site['language_code'] ?? 'th';
        $langNames = [
            'th' => 'ภาษาไทย', 'vn' => 'tiếng Việt', 'en' => 'English',
            'id' => 'Bahasa Indonesia', 'my' => 'Bahasa Malaysia', 'km' => 'ភាសាខ្មែរ',
            'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어'
        ];
        $langName = $langNames[$langCode] ?? 'English';

        $primaryKeyword = $keywordCluster['primary']['keyword'] ?? '';
        $secondaryKeywords = array_column($keywordCluster['secondary'] ?? [], 'keyword');
        $longTailKeywords = array_column($keywordCluster['long_tail'] ?? [], 'keyword');

        $contentPreview = mb_substr(strip_tags($content), 0, 3000);

        $customPrompt = getPromptTemplate('quality_check', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{title}', '{keyword}', '{content}', '{topic}'],
                [$title, $primaryKeyword, $contentPreview, $site['main_topic']],
                $customPrompt
            );
        } else {
            $prompt = <<<PROMPT
คุณเป็น SEO Content Auditor ตรวจคุณภาพบทความภาษาไทยก่อนเผยแพร่

## บทความ
**หัวข้อ:** {$title}
**Keyword หลัก:** {$primaryKeyword}
**Keywords รอง:** {$this->arrayToString($secondaryKeywords)}
**Long Tail Keywords:** {$this->arrayToString($longTailKeywords)}
**หมวด:** {$site['main_topic']}

**เนื้อหา:**
{$contentPreview}

## เกณฑ์ตรวจสอบ

### SEO (40 คะแนน)
- keyword กระจายเป็นธรรมชาติ ไม่ยัด ไม่ขาด
- มี secondary/long tail keywords ใน heading
- โครงสร้าง H1 → H2 → H3 เหมาะสม ไม่ heading ติดกัน
- มี Meta Description ที่ดี

### เนื้อหา (40 คะแนน)
- ข้อมูลถูกต้อง เป็นประโยชน์ ไม่เดาเอง
- อ่านเป็นธรรมชาติ เหมือนคนจริงเขียน ไม่เหมือน AI
- ครบทุกส่วน (บทนำ, เนื้อหาหลัก, FAQ, Q&A) ไม่ตัดจบกลางคัน
- EEAT — มีสัญญาณความเชี่ยวชาญและประสบการณ์จริง

### UX (20 คะแนน)
- แบ่งย่อหน้าเหมาะสม อ่านง่าย flow ดี
- เนื้อหาเป็นประโยชน์ต่อผู้อ่านจริง ไม่สร้างความคาดหวังเกินจริง
- ไม่มีเนื้อหาที่เป็นอันตรายหรือข้อมูลเท็จที่อาจหลอกลวงผู้อ่าน

## ตอบเป็น JSON เท่านั้น:
{
  "overall_score": 85,
  "seo_score": 80,
  "content_score": 90,
  "ux_score": 85,
  "should_publish": true,
  "issues": [
    {"severity": "warning", "type": "seo", "message": "คำอธิบายปัญหา"}
  ],
  "suggestions": ["คำแนะนำปรับปรุง"],
  "summary": "สรุปคุณภาพสั้นๆ"
}

severity: "critical" (ห้ามโพสต์), "warning" (ควรแก้), "info" (แนะนำ)
PROMPT;
        }

        // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ quality check คุณภาพสูง
        $result = $this->ai->execute('content_review', $prompt, ['max_tokens' => 2500]);

        if (empty($result['content'])) {
            return [
                'success' => false,
                'score' => 70,
                'logs' => ['AI ไม่สามารถตรวจสอบได้'],
                'issues' => [],
                'suggestions' => []
            ];
        }

        // Parse response
        $content = $result['content'];
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $parsed = json_decode($matches[0], true);
                // ตรวจสอบ error ครั้งที่ 2 หลัง regex extraction
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $parsed = null;
                }
            }
        }

        if (!is_array($parsed)) {
            return [
                'success' => false,
                'score' => 70,
                'logs' => ['ไม่สามารถ parse AI response'],
                'issues' => [],
                'suggestions' => []
            ];
        }

        $logs = [];
        $logs[] = "AI Score: {$parsed['overall_score']}/100";
        $logs[] = "SEO: {$parsed['seo_score']}, Content: {$parsed['content_score']}, UX: {$parsed['ux_score']}";
        if (!empty($parsed['summary'])) {
            $logs[] = "AI Summary: {$parsed['summary']}";
        }

        return [
            'success' => true,
            'score' => $parsed['overall_score'] ?? 70,
            'seo_score' => $parsed['seo_score'] ?? 70,
            'content_score' => $parsed['content_score'] ?? 70,
            'ux_score' => $parsed['ux_score'] ?? 70,
            'should_publish' => $parsed['should_publish'] ?? true,
            'logs' => $logs,
            'issues' => $parsed['issues'] ?? [],
            'suggestions' => $parsed['suggestions'] ?? [],
            'summary' => $parsed['summary'] ?? ''
        ];
    }

    /**
     * คำนวณคะแนนรวม
     */
    private function calculateScore(array $basicCheck, array $aiCheck): int {
        $basicScore = $basicCheck['score'] ?? 70;
        $aiScore = $aiCheck['score'] ?? 70;

        // Basic check 40%, AI check 60%
        return round(($basicScore * 0.4) + ($aiScore * 0.6));
    }

    /**
     * นับจำนวนคำ (รองรับภาษาไทย)
     */
    private function countWords(string $text): int {
        // English words
        $englishWords = str_word_count($text);

        // Thai characters (ประมาณ 1 คำ = 5 ตัวอักษร)
        $thaiChars = mb_strlen(preg_replace('/[^\x{0E00}-\x{0E7F}]/u', '', $text));
        $thaiWords = $thaiChars / 5;

        return $englishWords + (int)$thaiWords;
    }

    /**
     * แปลง array เป็น string
     */
    private function arrayToString(array $arr): string {
        if (empty($arr)) return '-';
        return implode(', ', array_slice($arr, 0, 5));
    }

    /**
     * ตรวจสอบแบบรวดเร็ว (ไม่ใช้ AI) สำหรับ batch processing
     */
    public function quickCheck(string $title, string $content, string $primaryKeyword): array {
        $contentText = strip_tags($content);
        $wordCount = $this->countWords($contentText);
        $issues = [];
        $score = 100;

        // ตรวจสอบพื้นฐาน
        if ($wordCount < 500) {
            $issues[] = 'บทความสั้นเกินไป';
            $score -= 30;
        }

        if (!empty($primaryKeyword) && mb_stripos($title, $primaryKeyword) === false) {
            $issues[] = 'Keyword ไม่อยู่ใน Title';
            $score -= 10;
        }

        if (!preg_match('/<h2[^>]*>/i', $content)) {
            $issues[] = 'ไม่มี H2';
            $score -= 10;
        }

        if (!empty($primaryKeyword)) {
            $keywordCount = substr_count(mb_strtolower($contentText), mb_strtolower($primaryKeyword));
            if ($keywordCount < 2) {
                $issues[] = 'Keyword น้อยเกินไป';
                $score -= 15;
            }
        }

        return [
            'score' => max(0, $score),
            'pass' => $score >= 70,
            'issues' => $issues,
            'word_count' => $wordCount
        ];
    }
}

/**
 * Helper function
 */
function contentQualityChecker(): ContentQualityChecker {
    return new ContentQualityChecker();
}
