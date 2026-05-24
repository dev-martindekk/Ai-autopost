<?php
/**
 * AI AutoPost SEO System - Content Safety Filter
 * ===============================================
 * Generic content risk assessment and quality filtering
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class RiskFilter {
    private $ai;

    // Phrases that make false or misleading claims
    private $dangerousPhrases = [
        'การันตี 100%', 'ได้เงินแน่นอน', 'รวยชัวร์', 'ไม่มีความเสี่ยง',
        'guaranteed win', 'no risk', '100% profit', 'always win',
        'cheat code', 'hack the system', 'secret formula', 'never lose',
        'ทุนน้อยรวยได้', 'สูตรลับ', 'โกงระบบ', 'ชนะทุกตา'
    ];

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * Check content for risks
     */
    public function checkRisk(string $content, string $topic = 'general'): array {
        $risks = [];
        $riskScore = 0;

        // 1. Check for false/misleading claims
        $foundPhrases = $this->checkDangerousPhrases($content);
        if (!empty($foundPhrases)) {
            $riskScore += count($foundPhrases) * 15;
            $risks[] = [
                'type' => 'dangerous_claims',
                'severity' => 'high',
                'items' => $foundPhrases,
                'message' => 'พบคำโฆษณาเกินจริง/ข้อมูลที่อาจหลอกลวง'
            ];
        }

        // 2. AI deep analysis
        $aiAnalysis = $this->aiRiskAnalysis($content, $topic);
        if (!empty($aiAnalysis['risks'])) {
            $risks = array_merge($risks, $aiAnalysis['risks']);
            $riskScore += $aiAnalysis['additional_score'];
        }

        $riskScore = min(100, $riskScore);

        return [
            'success' => true,
            'risk_score' => $riskScore,
            'risk_level' => $this->getRiskLevel($riskScore),
            'passes' => $riskScore < 50,
            'risks' => $risks,
            'recommendations' => $this->getRecommendations($risks)
        ];
    }

    private function checkDangerousPhrases(string $content): array {
        $found = [];
        $contentLower = mb_strtolower($content);

        foreach ($this->dangerousPhrases as $phrase) {
            if (mb_stripos($contentLower, mb_strtolower($phrase)) !== false) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    private function aiRiskAnalysis(string $content, string $topic): array {
        $customPrompt = getPromptTemplate('risk_filter', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{content}', '{topic}'],
                [$content, $topic],
                $customPrompt
            );
        } else {
            $prompt = <<<PROMPT
คุณเป็นเจ้าหน้าที่ตรวจสอบคุณภาพเนื้อหา วิเคราะห์เนื้อหาต่อไปนี้:

เนื้อหา:
{$content}

หมวด: {$topic}

ตรวจสอบ:
1. ข้อมูลเท็จหรืออ้างสิ่งที่พิสูจน์ไม่ได้
2. การโฆษณาเกินจริง สร้างความคาดหวังผิด
3. เนื้อหาที่อาจเป็นอันตรายต่อผู้อ่าน
4. ข้อมูลที่ล้าสมัยและอาจทำให้เข้าใจผิด
5. เนื้อหาที่ละเมิดกฎหมายหรือศีลธรรม

ตอบเป็น JSON เท่านั้น:
{
  "risks": [
    {"type": "ประเภท", "severity": "low|medium|high|critical", "message": "อธิบาย"}
  ],
  "additional_score": 0,
  "safe_for_publishing": true,
  "notes": "หมายเหตุสั้นๆ"
}
PROMPT;
        }

        $result = $this->ai->execute('content_review', $prompt, ['max_tokens' => 2000]);

        if (empty($result['content'])) {
            return ['risks' => [], 'additional_score' => 0];
        }

        $raw = trim($result['content']);
        $raw = preg_replace('/^```json\s*/i', '', $raw);
        $raw = preg_replace('/^```\s*/m', '', $raw);

        $analysis = json_decode($raw, true);

        if (!is_array($analysis)) {
            return ['risks' => [], 'additional_score' => 0];
        }

        return [
            'risks' => $analysis['risks'] ?? [],
            'additional_score' => $analysis['additional_score'] ?? 0
        ];
    }

    private function getRiskLevel(int $score): string {
        if ($score >= 70) return 'critical';
        if ($score >= 50) return 'high';
        if ($score >= 30) return 'medium';
        if ($score >= 10) return 'low';
        return 'safe';
    }

    private function getRecommendations(array $risks): array {
        $recommendations = [];

        foreach ($risks as $risk) {
            switch ($risk['type']) {
                case 'dangerous_claims':
                    $recommendations[] = 'ลบหรือแก้ไขคำโฆษณาเกินจริงให้เป็นข้อมูลที่ถูกต้อง';
                    break;
                default:
                    if (!empty($risk['message'])) {
                        $recommendations[] = $risk['message'];
                    }
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = 'เนื้อหาผ่านการตรวจสอบแล้ว';
        }

        return array_unique($recommendations);
    }

    /**
     * Filter and fix risky content
     */
    public function filterAndFix(string $content, string $topic = 'general'): array {
        $check = $this->checkRisk($content, $topic);

        if ($check['passes']) {
            return [
                'success' => true,
                'content' => $content,
                'modified' => false,
                'risk_score' => $check['risk_score'],
            ];
        }

        $modifiedContent = $content;

        $safeAlternatives = [
            'การันตี 100%' => 'มีโอกาส',
            'ได้เงินแน่นอน' => 'มีโอกาสได้รับผลตอบแทน',
            'รวยชัวร์' => 'มีโอกาสประสบความสำเร็จ',
            'ไม่มีความเสี่ยง' => 'ลดความเสี่ยง',
            'ทุนน้อยรวยได้' => 'ลงทุนได้ทุกระดับ',
        ];

        foreach ($this->dangerousPhrases as $phrase) {
            $replacement = $safeAlternatives[$phrase] ?? '';
            $modifiedContent = str_ireplace($phrase, $replacement, $modifiedContent);
        }

        $newCheck = $this->checkRisk($modifiedContent, $topic);

        return [
            'success' => true,
            'content' => $modifiedContent,
            'modified' => true,
            'original_risk_score' => $check['risk_score'],
            'new_risk_score' => $newCheck['risk_score'],
            'risk_score' => $newCheck['risk_score'],
        ];
    }
}

function riskFilter(): RiskFilter {
    return new RiskFilter();
}
