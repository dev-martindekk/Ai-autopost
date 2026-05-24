<?php
/**
 * AI AutoPost SEO System - Multi-Language Support
 * ================================================
 * รองรับการสร้างเนื้อหาหลายภาษา
 *
 * Supported Languages:
 * - TH: ไทย (default)
 * - EN: English
 * - VN: Tiếng Việt
 * - ID: Bahasa Indonesia
 * - MY: Bahasa Melayu
 * - ZH: 中文 (Simplified)
 * - JA: 日本語
 * - KO: 한국어
 *
 * Features:
 * - แปลบทความแบบ Native Level
 * - ปรับ SEO ตามภาษา
 * - Localize keywords
 * - Content Plan แยกตามภูมิภาค
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class MultiLanguage {

    private $db;
    private $ai;

    // Supported languages with their configurations
    private const LANGUAGES = [
        'th' => [
            'name' => 'ไทย',
            'name_en' => 'Thai',
            'locale' => 'th_TH',
            'direction' => 'ltr',
            'date_format' => 'd/m/Y',
            'currency' => 'THB',
            'currency_symbol' => '฿',
            'word_separator' => '',
            'tone_default' => 'casual',
            'seo_tips' => [
                'ใช้คำไทยเป็นหลัก',
                'หลีกเลี่ยงศัพท์ทับศัพท์มากเกินไป',
                'เน้น long-tail keywords ภาษาไทย'
            ]
        ],
        'en' => [
            'name' => 'English',
            'name_en' => 'English',
            'locale' => 'en_US',
            'direction' => 'ltr',
            'date_format' => 'M d, Y',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'word_separator' => ' ',
            'tone_default' => 'professional',
            'seo_tips' => [
                'Use natural English phrasing',
                'Include question-based keywords',
                'Focus on search intent'
            ]
        ],
        'vn' => [
            'name' => 'Tiếng Việt',
            'name_en' => 'Vietnamese',
            'locale' => 'vi_VN',
            'direction' => 'ltr',
            'date_format' => 'd/m/Y',
            'currency' => 'VND',
            'currency_symbol' => '₫',
            'word_separator' => ' ',
            'tone_default' => 'casual',
            'seo_tips' => [
                'Sử dụng từ khóa tiếng Việt tự nhiên',
                'Tránh dịch máy',
                'Chú ý dấu thanh điệu'
            ]
        ],
        'id' => [
            'name' => 'Bahasa Indonesia',
            'name_en' => 'Indonesian',
            'locale' => 'id_ID',
            'direction' => 'ltr',
            'date_format' => 'd/m/Y',
            'currency' => 'IDR',
            'currency_symbol' => 'Rp',
            'word_separator' => ' ',
            'tone_default' => 'semi_formal',
            'seo_tips' => [
                'Gunakan bahasa Indonesia baku',
                'Hindari kata serapan berlebihan',
                'Fokus pada kata kunci lokal'
            ]
        ],
        'my' => [
            'name' => 'Bahasa Melayu',
            'name_en' => 'Malay',
            'locale' => 'ms_MY',
            'direction' => 'ltr',
            'date_format' => 'd/m/Y',
            'currency' => 'MYR',
            'currency_symbol' => 'RM',
            'word_separator' => ' ',
            'tone_default' => 'semi_formal',
            'seo_tips' => [
                'Gunakan Bahasa Melayu standard',
                'Elakkan penggunaan slang berlebihan'
            ]
        ],
        'zh' => [
            'name' => '中文',
            'name_en' => 'Chinese (Simplified)',
            'locale' => 'zh_CN',
            'direction' => 'ltr',
            'date_format' => 'Y年m月d日',
            'currency' => 'CNY',
            'currency_symbol' => '¥',
            'word_separator' => '',
            'tone_default' => 'professional',
            'seo_tips' => [
                '使用简体中文',
                '关注百度SEO规则',
                '使用本地化关键词'
            ]
        ],
        'ja' => [
            'name' => '日本語',
            'name_en' => 'Japanese',
            'locale' => 'ja_JP',
            'direction' => 'ltr',
            'date_format' => 'Y年m月d日',
            'currency' => 'JPY',
            'currency_symbol' => '¥',
            'word_separator' => '',
            'tone_default' => 'formal',
            'seo_tips' => [
                '敬語を適切に使用',
                'カタカナ語に注意',
                'ロングテールキーワードを重視'
            ]
        ],
        'ko' => [
            'name' => '한국어',
            'name_en' => 'Korean',
            'locale' => 'ko_KR',
            'direction' => 'ltr',
            'date_format' => 'Y년 m월 d일',
            'currency' => 'KRW',
            'currency_symbol' => '₩',
            'word_separator' => ' ',
            'tone_default' => 'semi_formal',
            'seo_tips' => [
                '한국어 자연스러운 표현 사용',
                '네이버 SEO 고려',
                '롱테일 키워드 활용'
            ]
        ]
    ];

    public function __construct() {
        $this->db = db();
        $this->ai = aiOrchestrator();
    }

    /**
     * Get all supported languages
     */
    public static function getSupportedLanguages(): array {
        return self::LANGUAGES;
    }

    /**
     * Get language config
     */
    public static function getLanguageConfig(string $langCode): ?array {
        return self::LANGUAGES[strtolower($langCode)] ?? null;
    }

    /**
     * Translate article to target language
     */
    public function translateArticle(int $articleId, string $targetLang): array {
        $article = $this->db->fetchOne("
            SELECT a.*, s.name as site_name
            FROM articles a
            JOIN sites s ON a.site_id = s.id
            WHERE a.id = ?
        ", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        $langConfig = self::getLanguageConfig($targetLang);
        if (!$langConfig) {
            return ['success' => false, 'message' => 'Unsupported language'];
        }

        // Detect source language
        $sourceLang = $this->detectLanguage($article['title']);

        if ($sourceLang === $targetLang) {
            return ['success' => false, 'message' => 'Source and target language are the same'];
        }

        $prompt = "คุณเป็นนักแปลมืออาชีพ และผู้เชี่ยวชาญ SEO

แปลบทความต่อไปนี้จาก {$sourceLang} เป็น {$langConfig['name_en']}

## กฎการแปล:
1. แปลให้เป็นธรรมชาติเหมือนเจ้าของภาษาเขียน (Native Level)
2. รักษาโครงสร้าง HTML tags ทั้งหมด
3. ปรับ SEO ให้เหมาะกับภาษาเป้าหมาย
4. แปล Keywords ให้เหมาะกับการค้นหาในภาษานั้น
5. รักษาความหมายและอารมณ์ของเนื้อหา
6. ห้ามใช้ Machine Translation แบบตรงตัว

## เนื้อหาต้นฉบับ:

Title: {$article['title']}

Content:
{$article['content']}

Primary Keyword: {$article['primary_keyword']}

## Output Format:
ตอบเป็น JSON:
```json
{
    \"translated_title\": \"...\",
    \"translated_content\": \"...\",
    \"translated_keyword\": \"...\",
    \"meta_description\": \"...\",
    \"seo_notes\": \"ข้อสังเกตสำหรับ SEO ในภาษานี้\"
}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, [
                'temperature' => 0.3,
                'max_tokens' => 8000
            ]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);

                if (!$data) {
                    return ['success' => false, 'message' => 'Invalid translation response'];
                }

                // Save translation
                $translationId = $this->saveTranslation($articleId, $targetLang, $data);

                return [
                    'success' => true,
                    'translation_id' => $translationId,
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'translated' => $data
                ];
            }

            return ['success' => false, 'message' => 'Could not parse translation'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Detect language of text
     */
    private function detectLanguage(string $text): string {
        // Simple detection based on character ranges
        if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $text)) {
            return 'th';
        }
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
            return 'zh';
        }
        if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) {
            return 'ja';
        }
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) {
            return 'ko';
        }
        if (preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/ui', $text)) {
            return 'vn';
        }

        return 'en'; // Default to English
    }

    /**
     * Save translation to database
     */
    private function saveTranslation(int $articleId, string $targetLang, array $data): int {
        return $this->db->insert('article_translations', [
            'article_id' => $articleId,
            'language_code' => $targetLang,
            'translated_title' => $data['translated_title'],
            'translated_content' => $data['translated_content'],
            'translated_keyword' => $data['translated_keyword'],
            'meta_description' => $data['meta_description'] ?? '',
            'seo_notes' => $data['seo_notes'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Translate keywords for a language
     */
    public function translateKeywords(array $keywords, string $targetLang): array {
        $langConfig = self::getLanguageConfig($targetLang);
        if (!$langConfig) {
            return ['success' => false, 'message' => 'Unsupported language'];
        }

        $keywordList = implode("\n", array_map(fn($k) => "- {$k}", $keywords));

        $prompt = "แปล keywords ต่อไปนี้เป็น {$langConfig['name_en']} สำหรับ SEO

Keywords:
{$keywordList}

กฎ:
1. แปลให้เหมาะกับการค้นหาในภาษานั้น
2. รักษา Search Intent
3. ใช้คำที่คนในประเทศนั้นใช้จริง
4. อาจมีหลาย variations

ตอบเป็น JSON:
```json
{
    \"translations\": [
        {
            \"original\": \"keyword เดิม\",
            \"translated\": \"keyword แปล\",
            \"variations\": [\"variation 1\", \"variation 2\"]
        }
    ]
}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, ['temperature' => 0.3]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);
                return [
                    'success' => true,
                    'target_lang' => $targetLang,
                    'translations' => $data['translations'] ?? []
                ];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => false, 'message' => 'Could not translate keywords'];
    }

    /**
     * Create localized content plan for a region
     */
    public function createLocalizedPlan(int $siteId, string $targetLang, int $days = 7): array {
        $site = $this->db->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
        if (!$site) {
            return ['success' => false, 'message' => 'Site not found'];
        }

        $langConfig = self::getLanguageConfig($targetLang);
        if (!$langConfig) {
            return ['success' => false, 'message' => 'Unsupported language'];
        }

        $prompt = "สร้าง Content Plan สำหรับเว็บไซต์ {$site['main_topic']} ในภาษา {$langConfig['name_en']}

จำนวน: {$days} วัน (1 บทความ/วัน)

พิจารณา:
1. Keywords ที่คนในประเทศนั้นค้นหา
2. เทรนด์ท้องถิ่น
3. วัฒนธรรมและความชอบของตลาด
4. SEO สำหรับ Search Engine ในประเทศนั้น (Google, Baidu, Naver, etc.)

ตอบเป็น JSON:
```json
{
    \"plan\": [
        {
            \"day\": 1,
            \"keyword\": \"keyword ในภาษาเป้าหมาย\",
            \"title_idea\": \"ไอเดีย title\",
            \"content_type\": \"review/guide/news/tips\",
            \"local_trend\": \"เทรนด์ท้องถิ่นที่เกี่ยวข้อง\",
            \"notes\": \"หมายเหตุ\"
        }
    ],
    \"market_insights\": \"ข้อมูลเชิงลึกของตลาดนี้\"
}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, [
                'temperature' => 0.5,
                'max_tokens' => 2500
            ]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);

                // Save plan to database
                if (isset($data['plan'])) {
                    foreach ($data['plan'] as $day) {
                        $this->db->insert('localized_content_plan', [
                            'site_id' => $siteId,
                            'language_code' => $targetLang,
                            'planned_date' => date('Y-m-d', strtotime("+{$day['day']} days")),
                            'keyword' => $day['keyword'],
                            'title_idea' => $day['title_idea'],
                            'content_type' => $day['content_type'],
                            'notes' => $day['notes'] ?? '',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }

                return [
                    'success' => true,
                    'target_lang' => $targetLang,
                    'site' => $site['name'],
                    'plan' => $data['plan'] ?? [],
                    'market_insights' => $data['market_insights'] ?? ''
                ];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => false, 'message' => 'Could not create localized plan'];
    }

    /**
     * Generate article in specific language
     */
    public function generateInLanguage(string $keyword, string $targetLang, array $options = []): array {
        $langConfig = self::getLanguageConfig($targetLang);
        if (!$langConfig) {
            return ['success' => false, 'message' => 'Unsupported language'];
        }

        $contentType = $options['content_type'] ?? 'review';
        $topic = $options['topic'] ?? 'slots';
        $minWords = $options['min_words'] ?? 1500;

        $prompt = "เขียนบทความ SEO ภาษา {$langConfig['name_en']} เกี่ยวกับ: {$keyword}

## ข้อกำหนด:
- ภาษา: {$langConfig['name_en']} (เขียนเหมือนเจ้าของภาษา)
- ประเภท: {$contentType}
- หมวด: {$topic}
- ความยาว: อย่างน้อย {$minWords} คำ
- Format: HTML

## SEO Tips สำหรับภาษานี้:
" . implode("\n", array_map(fn($t) => "- {$t}", $langConfig['seo_tips'])) . "

## โครงสร้าง:
1. Title (H1) - มี keyword
2. Introduction - ดึงดูดความสนใจ
3. เนื้อหาหลัก - ใช้ H2, H3 เหมาะสม
4. สรุป - มี CTA

ตอบเป็น JSON:
```json
{
    \"title\": \"...\",
    \"content\": \"<เนื้อหา HTML>\",
    \"meta_description\": \"...\",
    \"slug\": \"...\",
    \"word_count\": 1500
}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, [
                'temperature' => 0.6,
                'max_tokens' => 8000
            ]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);
                return [
                    'success' => true,
                    'language' => $targetLang,
                    'article' => $data
                ];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => false, 'message' => 'Could not generate article'];
    }

    /**
     * Get translation stats
     */
    public function getTranslationStats(): array {
        $total = $this->db->fetchColumn("SELECT COUNT(*) FROM article_translations") ?: 0;

        $languagesUsed = $this->db->fetchColumn("SELECT COUNT(DISTINCT language_code) FROM article_translations") ?: 0;

        $thisMonth = $this->db->fetchColumn("
            SELECT COUNT(*) FROM article_translations
            WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ") ?: 0;

        // Check if localized_content_plan has translated keywords
        $translatedKeywords = 0;
        try {
            $translatedKeywords = $this->db->fetchColumn("
                SELECT COUNT(*) FROM localized_content_plan WHERE keyword IS NOT NULL
            ") ?: 0;
        } catch (Exception $e) {
            // Ignore if table doesn't exist
        }

        $byLanguage = $this->db->fetchAll("
            SELECT language_code as language, COUNT(*) as count
            FROM article_translations
            GROUP BY language_code
            ORDER BY count DESC
        ");

        $recent = $this->db->fetchAll("
            SELECT t.*, a.title as original_title
            FROM article_translations t
            JOIN articles a ON t.article_id = a.id
            ORDER BY t.created_at DESC
            LIMIT 10
        ");

        return [
            'total_translations' => (int)$total,
            'languages_used' => (int)$languagesUsed,
            'this_month' => (int)$thisMonth,
            'translated_keywords' => (int)$translatedKeywords,
            'by_language' => $byLanguage,
            'recent' => $recent
        ];
    }

    /**
     * Bulk translate articles
     */
    public function bulkTranslate(array $articleIds, string $targetLang): array {
        $results = [];

        foreach ($articleIds as $articleId) {
            $result = $this->translateArticle($articleId, $targetLang);
            $results[] = [
                'article_id' => $articleId,
                'success' => $result['success'],
                'message' => $result['message'] ?? ($result['success'] ? 'Translated' : 'Failed')
            ];

            // Prevent rate limiting
            usleep(500000); // 0.5 second delay
        }

        $successful = count(array_filter($results, fn($r) => $r['success']));

        return [
            'total' => count($articleIds),
            'successful' => $successful,
            'failed' => count($articleIds) - $successful,
            'results' => $results
        ];
    }
}

/**
 * Helper function
 */
function multiLanguage(): MultiLanguage {
    return new MultiLanguage();
}
