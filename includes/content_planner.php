<?php
/**
 * AI AutoPost SEO System - Content Planner
 * =========================================
 * AI-powered content planning and keyword brain
 * Plans what to write each day for each site
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class ContentPlanner {
    private $ai;

    // Language names mapping (9 languages)
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
        $this->ai = aiOrchestrator();
    }

    /**
     * Generate content plan for a site for the next X days
     * Respects posting_days setting — only plans content for active posting days
     */
    public function planForSite(int $siteId, int $days = 7): array {
        $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
        if (!$site) {
            return ['success' => false, 'message' => 'Site not found'];
        }

        $articlesPerDay = (int)($site['daily_article_limit'] ?? 1);
        $topic = $site['main_topic'];
        $langCode = $site['language_code'] ?? 'th';

        // Calculate actual posting dates based on posting_days setting
        $postingDays = explode(',', $site['posting_days'] ?? '1,2,3,4,5,6,7');
        $postingDays = array_map('intval', $postingDays);
        $postingDates = $this->getPostingDates($postingDays, $days);

        if (empty($postingDates)) {
            return ['success' => false, 'message' => 'No posting days within the next ' . $days . ' days'];
        }

        $totalArticles = count($postingDates) * $articlesPerDay;

        // Get available keywords for this site (filtered by language)
        $keywords = $this->getAvailableKeywords($siteId, $topic, $langCode, $totalArticles * 2);

        if (empty($keywords)) {
            return ['success' => false, 'message' => 'No available keywords'];
        }

        // Get recently used keywords to avoid repetition
        $recentKeywords = $this->getRecentlyUsedKeywords($siteId, 30);

        // Get existing content types distribution
        $contentTypeStats = $this->getContentTypeStats($siteId);

        // Ask AI to create the plan (pass posting dates instead of days count)
        $plan = $this->generatePlanWithAI($site, $keywords, $recentKeywords, $contentTypeStats, $days, $articlesPerDay, $postingDates);

        if (!$plan['success']) {
            return $plan;
        }

        // Save the plan to database
        $saved = $this->savePlan($siteId, $plan['plan']);

        $postingDaysCount = count($postingDates);
        return [
            'success' => true,
            'message' => "Created plan for {$postingDaysCount} posting days ({$saved} articles)",
            'plan' => $plan['plan'],
            'saved_count' => $saved
        ];
    }

    /**
     * Get actual posting dates within the next X days based on posting_days setting
     * @param array $postingDays Array of day-of-week numbers (1=Mon, 7=Sun)
     * @param int $lookAheadDays How many days ahead to look
     * @return array Array of date strings (Y-m-d) that are posting days
     */
    private function getPostingDates(array $postingDays, int $lookAheadDays): array {
        $dates = [];
        $today = new DateTime('now', new DateTimeZone('Asia/Bangkok'));

        for ($i = 0; $i < $lookAheadDays; $i++) {
            $date = clone $today;
            $date->modify("+{$i} days");
            $dayOfWeek = (int)$date->format('N'); // 1=Mon, 7=Sun

            if (in_array($dayOfWeek, $postingDays)) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }

    /**
     * Get available keywords for planning
     * Prioritize Ahrefs data (traffic_potential, difficulty, intent)
     * Keywords are selected based on site's main_topic AND language_code
     * *** FIX: เพิ่มการกรอง keyword ที่เว็บนี้ใช้ไปแล้ว ***
     */
    private function getAvailableKeywords(int $siteId, string $topic, string $langCode = 'th', int $limit = 50): array {
        // Get keywords from topic AND language - prioritize Ahrefs keywords with best traffic/difficulty ratio
        // Exclude keywords already used by this specific site (using similarity deduplication)
        $deduplicationSQL = getKeywordDeduplicationSQL('k.keyword');

        $keywords = db()->fetchAll("
            SELECT k.* FROM keywords k
            WHERE k.topic = ? AND k.language_code = ? AND k.is_active = 1
              {$deduplicationSQL}
            ORDER BY k.usage_count ASC,
                     CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                     (COALESCE(k.traffic_potential, 0) / GREATEST(COALESCE(k.difficulty, 1), 1)) DESC,
                     k.search_volume DESC
            LIMIT ?
        ", [$topic, $langCode, $siteId, $siteId, $limit]);

        // Fallback: if no keywords found for this language, try site_id
        if (empty($keywords)) {
            $keywords = db()->fetchAll("
                SELECT k.* FROM keywords k
                WHERE k.site_id = ? AND k.language_code = ? AND k.is_active = 1
                  {$deduplicationSQL}
                ORDER BY k.usage_count ASC,
                         CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                         (COALESCE(k.traffic_potential, 0) / GREATEST(COALESCE(k.difficulty, 1), 1)) DESC,
                         k.search_volume DESC
                LIMIT ?
            ", [$siteId, $langCode, $siteId, $siteId, $limit]);
        }

        return $keywords;
    }

    /**
     * Get recently used keywords
     */
    private function getRecentlyUsedKeywords(int $siteId, int $days = 30): array {
        return db()->fetchAll("
            SELECT primary_keyword, COUNT(*) as count
            FROM articles
            WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY primary_keyword
        ", [$siteId, $days]);
    }

    /**
     * Get content type distribution
     */
    private function getContentTypeStats(int $siteId): array {
        return db()->fetchAll("
            SELECT content_type, COUNT(*) as count
            FROM content_plan
            WHERE site_id = ? AND planned_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY content_type
        ", [$siteId]);
    }

    /**
     * Use AI to generate optimal content plan
     * Enhanced with Ahrefs data (traffic_potential, search_intent, cpc)
     */
    private function generatePlanWithAI(array $site, array $keywords, array $recentKeywords, array $contentTypeStats, int $days, int $articlesPerDay, array $postingDates = []): array {
        $keywordList = array_map(function($k) {
            return [
                'id' => $k['id'],
                'keyword' => $k['keyword'],
                'topic' => $k['topic'],
                'search_volume' => $k['search_volume'] ?? 0,
                'difficulty' => $k['difficulty'] ?? 0,
                'traffic_potential' => $k['traffic_potential'] ?? 0,
                'search_intent' => $k['search_intent'] ?? 'informational',
                'cpc' => $k['cpc'] ?? 0,
                'data_source' => $k['data_source'] ?? 'manual',
                'usage_count' => $k['usage_count'] ?? 0
            ];
        }, $keywords);

        $recentList = array_column($recentKeywords, 'primary_keyword');

        $prompt = $this->buildPlannerPrompt($site, $keywordList, $recentList, $contentTypeStats, $days, $articlesPerDay, $postingDates);

        // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ content planning คุณภาพสูง
        $result = $this->ai->execute('strategy_plan', $prompt, ['max_tokens' => 4000]);

        if (empty($result['content'])) {
            return ['success' => false, 'message' => 'AI failed to generate plan'];
        }

        // Parse AI response
        $plan = $this->parseAIPlan($result['content'], $days, $articlesPerDay);

        if (empty($plan)) {
            return ['success' => false, 'message' => 'Failed to parse AI plan'];
        }

        return ['success' => true, 'plan' => $plan];
    }

    /**
     * Build prompt for AI planner
     * Enhanced with Ahrefs data intelligence and multi-language support
     */
    private function buildPlannerPrompt(array $site, array $keywords, array $recentKeywords, array $contentTypeStats, int $days, int $articlesPerDay, array $postingDates = []): string {
        $keywordJson = json_encode($keywords, JSON_UNESCAPED_UNICODE);
        $recentJson = json_encode($recentKeywords, JSON_UNESCAPED_UNICODE);
        $statsJson = json_encode($contentTypeStats, JSON_UNESCAPED_UNICODE);

        $startDate = date('Y-m-d');
        // Use posting dates if available, otherwise fall back to consecutive days
        if (!empty($postingDates)) {
            $totalArticles = count($postingDates) * $articlesPerDay;
        } else {
            $totalArticles = $days * $articlesPerDay;
        }
        $langCode = $site['language_code'] ?? 'th';
        $langName = $this->getLanguageName($langCode);

        // Content type descriptions by language
        $contentTypeDescriptions = [
            'th' => "- review: รีวิวเกม/บริการ (transactional, commercial intent)
- guide: คู่มือ/วิธีเล่น (informational intent)
- tips: เคล็ดลับ/เทคนิค (informational intent)
- news: ข่าว/อัปเดต (navigational intent)
- comparison: เปรียบเทียบ (commercial intent)
- brand: แบรนด์คอนเทนต์ (transactional intent)",
            'vn' => "- review: Đánh giá game/dịch vụ (transactional, commercial intent)
- guide: Hướng dẫn/Cách chơi (informational intent)
- tips: Mẹo/Kỹ thuật (informational intent)
- news: Tin tức/Cập nhật (navigational intent)
- comparison: So sánh (commercial intent)
- brand: Nội dung thương hiệu (transactional intent)",
            'en' => "- review: Game/Service reviews (transactional, commercial intent)
- guide: Guides/How to play (informational intent)
- tips: Tips/Techniques (informational intent)
- news: News/Updates (navigational intent)
- comparison: Comparisons (commercial intent)
- brand: Brand content (transactional intent)",
            'id' => "- review: Review game/layanan (transactional, commercial intent)
- guide: Panduan/Cara bermain (informational intent)
- tips: Tips/Teknik (informational intent)
- news: Berita/Update (navigational intent)
- comparison: Perbandingan (commercial intent)
- brand: Konten brand (transactional intent)",
            'my' => "- review: Ulasan game/perkhidmatan (transactional, commercial intent)
- guide: Panduan/Cara bermain (informational intent)
- tips: Tips/Teknik (informational intent)
- news: Berita/Kemas kini (navigational intent)
- comparison: Perbandingan (commercial intent)
- brand: Kandungan jenama (transactional intent)",
            'km' => "- review: ការពិនិត្យហ្គេម/សេវា (transactional, commercial intent)
- guide: មគ្គុទ្ទេសក៍/របៀបលេង (informational intent)
- tips: គន្លឹះ/បច្ចេកទេស (informational intent)
- news: ព័ត៌មាន/អាប់ដេត (navigational intent)
- comparison: ការប្រៀបធៀប (commercial intent)
- brand: មាតិកាម៉ាក (transactional intent)",
            'zh' => "- review: 游戏/服务评测 (transactional, commercial intent)
- guide: 指南/玩法教程 (informational intent)
- tips: 技巧/窍门 (informational intent)
- news: 新闻/更新 (navigational intent)
- comparison: 对比 (commercial intent)
- brand: 品牌内容 (transactional intent)",
            'ja' => "- review: ゲーム/サービスレビュー (transactional, commercial intent)
- guide: ガイド/遊び方 (informational intent)
- tips: ヒント/テクニック (informational intent)
- news: ニュース/アップデート (navigational intent)
- comparison: 比較 (commercial intent)
- brand: ブランドコンテンツ (transactional intent)",
            'ko' => "- review: 게임/서비스 리뷰 (transactional, commercial intent)
- guide: 가이드/플레이 방법 (informational intent)
- tips: 팁/기술 (informational intent)
- news: 뉴스/업데이트 (navigational intent)
- comparison: 비교 (commercial intent)
- brand: 브랜드 콘텐츠 (transactional intent)"
        ];

        $contentTypeDesc = $contentTypeDescriptions[$langCode] ?? $contentTypeDescriptions['en'];

        $customPrompt = getPromptTemplate('content_plan', '');
        if ($customPrompt) {
            return str_replace(
                ['{site_name}', '{topic}', '{days}', '{keywords_json}'],
                [$site['name'], $site['main_topic'], $days, $keywordJson],
                $customPrompt
            );
        }

        // Build posting dates info for prompt
        $dayNames = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];
        if (!empty($postingDates)) {
            $datesWithDays = array_map(function($d) use ($dayNames) {
                $dt = new DateTime($d);
                $dow = (int)$dt->format('N');
                $dayName = $dayNames[$dow] ?? '';
                return "{$d} (วัน{$dayName})";
            }, $postingDates);
            $postingScheduleText = "วันที่ต้องวางแผน (เฉพาะวันโพสต์):\n" . implode("\n", $datesWithDays);
            $dateInstruction = "สร้างบทความ {$articlesPerDay} บทความ/วัน เฉพาะวันที่กำหนดด้านล่างเท่านั้น (ห้ามสร้างวันอื่น)";
        } else {
            $postingScheduleText = "วางแผน: {$days} วัน ตั้งแต่ {$startDate}";
            $dateInstruction = "สร้างบทความ {$articlesPerDay} บทความ/วัน ตั้งแต่ {$startDate} เป็นเวลา {$days} วัน";
        }

        return <<<PROMPT
คุณเป็น SEO Content Strategist วางแผนเนื้อหาโดยใช้ข้อมูล Ahrefs

เว็บไซต์: {$site['name']} | หมวด: {$site['main_topic']} | ภาษา: {$langName} ({$langCode})
บทความ/วัน: {$articlesPerDay} | รวม: {$totalArticles} บทความ
{$dateInstruction}

{$postingScheduleText}

KEYWORDS (พร้อมข้อมูล Ahrefs — ทุก keyword ต้องเป็นภาษา {$langName}):
{$keywordJson}

ข้อมูล: search_volume (ปริมาณค้นหา/เดือน), difficulty (0-100 ยิ่งต่ำยิ่งง่าย), traffic_potential (traffic ถ้าติดอันดับ 1), search_intent (informational/commercial/transactional/navigational), cpc (ยิ่งสูง = commercial value สูง), data_source ("ahrefs" = ข้อมูลยืนยัน)

KEYWORDS ที่ใช้แล้ว (ห้ามใช้ซ้ำ):
{$recentJson}

สถิติ Content Type ปัจจุบัน:
{$statsJson}

ประเภท Content:
{$contentTypeDesc}

กลยุทธ์:
1. HIGH PRIORITY: traffic_potential สูง + difficulty ต่ำ = Quick wins
2. MEDIUM PRIORITY: volume สูง + difficulty ปานกลาง = ระยะยาว
3. จับคู่ content_type กับ search_intent (informational→guide/tips, commercial→comparison/review, transactional→review/brand)
4. CPC สูง = conversion potential สูง
5. เลือก data_source = "ahrefs" ก่อน

งาน:
1. เลือก keyword จากรายการ (เรียงตาม traffic_potential/difficulty)
2. จับคู่ content_type กับ search_intent
3. หลีกเลี่ยง keyword ที่ใช้แล้ว
4. สร้าง secondary keywords 2-3 คำเป็นภาษา {$langName}
5. ให้ priority score และเหตุผลพร้อมอ้างอิงตัวเลข Ahrefs
6. ใช้เฉพาะวันที่ที่กำหนดไว้ข้างต้นเท่านั้น (ห้ามใส่วันอื่น)

ตอบเป็น JSON array เท่านั้น:
[
  {
    "date": "YYYY-MM-DD",
    "primary_keyword_id": 1,
    "primary_keyword": "keyword",
    "secondary_keywords": ["secondary1", "secondary2"],
    "content_type": "guide",
    "search_intent": "informational",
    "priority": 8,
    "reasoning": "Traffic potential: 50000, Difficulty: 20"
  }
]
PROMPT;
    }

    /**
     * Parse AI response into plan array
     */
    private function parseAIPlan(string $content, int $days, int $articlesPerDay): array {
        // Clean up response
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);
        $content = trim($content);

        $plan = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($plan)) {
            // Try to extract JSON from response
            if (preg_match('/\[[\s\S]*\]/', $content, $matches)) {
                $plan = json_decode($matches[0], true);
            }
        }

        if (!is_array($plan)) {
            return [];
        }

        // Validate and normalize plan entries
        $validPlan = [];
        foreach ($plan as $entry) {
            if (!empty($entry['primary_keyword']) && !empty($entry['date'])) {
                $validPlan[] = [
                    'date' => $entry['date'],
                    'primary_keyword_id' => $entry['primary_keyword_id'] ?? null,
                    'primary_keyword' => $entry['primary_keyword'],
                    'secondary_keywords' => $entry['secondary_keywords'] ?? [],
                    'content_type' => $entry['content_type'] ?? 'guide',
                    'search_intent' => $entry['search_intent'] ?? 'informational',
                    'priority' => $entry['priority'] ?? 5,
                    'reasoning' => $entry['reasoning'] ?? ''
                ];
            }
        }

        return $validPlan;
    }

    /**
     * Save plan to database
     */
    private function savePlan(int $siteId, array $plan): int {
        $saved = 0;

        foreach ($plan as $entry) {
            // Check if already exists
            $exists = db()->fetchOne("
                SELECT id FROM content_plan
                WHERE site_id = ? AND planned_date = ? AND primary_keyword = ?
            ", [$siteId, $entry['date'], $entry['primary_keyword']]);

            if ($exists) {
                continue;
            }

            try {
                db()->insert('content_plan', [
                    'site_id' => $siteId,
                    'planned_date' => $entry['date'],
                    'primary_keyword_id' => $entry['primary_keyword_id'],
                    'primary_keyword' => $entry['primary_keyword'],
                    'secondary_keywords' => json_encode($entry['secondary_keywords']),
                    'content_type' => $entry['content_type'],
                    'search_intent' => $entry['search_intent'],
                    'priority' => $entry['priority'],
                    'ai_reasoning' => $entry['reasoning'],
                    'status' => 'planned'
                ]);
                $saved++;
            } catch (Exception $e) {
                // Skip duplicates
            }
        }

        return $saved;
    }

    /**
     * Get today's plan for a site
     */
    public function getTodaysPlan(int $siteId): array {
        return db()->fetchAll("
            SELECT * FROM content_plan
            WHERE site_id = ? AND planned_date = CURDATE() AND status = 'planned'
            ORDER BY priority DESC
        ", [$siteId]);
    }

    /**
     * Get plan for date range
     */
    public function getPlan(int $siteId, string $startDate = null, string $endDate = null): array {
        $startDate = $startDate ?? date('Y-m-d');
        $endDate = $endDate ?? date('Y-m-d', strtotime('+7 days'));

        return db()->fetchAll("
            SELECT cp.*, k.search_volume, k.difficulty
            FROM content_plan cp
            LEFT JOIN keywords k ON cp.primary_keyword_id = k.id
            WHERE cp.site_id = ? AND cp.planned_date BETWEEN ? AND ?
            ORDER BY cp.planned_date ASC, cp.priority DESC
        ", [$siteId, $startDate, $endDate]);
    }

    /**
     * Mark plan item as used
     */
    public function markAsUsed(int $planId, int $articleId): bool {
        return db()->update('content_plan', [
            'status' => 'generated',
            'article_id' => $articleId
        ], 'id = ?', [$planId]) > 0;
    }

    /**
     * Get next planned content for generation
     * *** FIX: เพิ่มการตรวจสอบว่า keyword ยังไม่เคยถูกใช้ในเว็บนี้ ***
     */
    public function getNextPlannedContent(int $siteId): ?array {
        // อัปเดต content_plan ที่ keyword ถูกใช้ไปแล้วให้เป็น 'skipped' (ใช้ similarity check)
        db()->query("
            UPDATE content_plan cp
            SET cp.status = 'skipped'
            WHERE cp.site_id = ?
              AND cp.status = 'planned'
              AND cp.planned_date <= CURDATE()
              AND (
                  EXISTS (
                      SELECT 1 FROM article_keywords ak
                      WHERE ak.site_id = ?
                      AND (
                          cp.primary_keyword = ak.keyword
                          OR (
                              CHAR_LENGTH(REPLACE(ak.keyword, ' ', '')) >= 6
                              AND (
                                  REPLACE(cp.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE(ak.keyword, ' ', ''), '%')
                                  OR REPLACE(ak.keyword, ' ', '') LIKE CONCAT('%', REPLACE(cp.primary_keyword, ' ', ''), '%')
                              )
                          )
                      )
                  )
                  OR EXISTS (
                      SELECT 1 FROM articles art
                      WHERE art.site_id = ?
                      AND art.primary_keyword IS NOT NULL
                      AND (
                          cp.primary_keyword = art.primary_keyword
                          OR (
                              CHAR_LENGTH(REPLACE(art.primary_keyword, ' ', '')) >= 6
                              AND (
                                  REPLACE(cp.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE(art.primary_keyword, ' ', ''), '%')
                                  OR REPLACE(art.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE(cp.primary_keyword, ' ', ''), '%')
                              )
                          )
                      )
                  )
              )
        ", [$siteId, $siteId, $siteId]);

        // ดึง content plan ที่ keyword ยังไม่เคยใช้ (ใช้ deduplication SQL)
        $deduplicationSQL = getKeywordDeduplicationSQL('cp.primary_keyword');
        return db()->fetchOne("
            SELECT * FROM content_plan cp
            WHERE cp.site_id = ?
            AND cp.status = 'planned'
            AND cp.planned_date <= CURDATE()
            {$deduplicationSQL}
            ORDER BY cp.planned_date ASC, cp.priority DESC
            LIMIT 1
        ", [$siteId, $siteId, $siteId]);
    }
    /**
     * แทนที่ keyword ที่ถูกใช้ไปแล้วด้วย keyword ใหม่ที่ยังไม่เคยเขียน
     * เรียกใช้จาก Content Calendar เพื่ออัปเดตแผนอัตโนมัติ
     */
    public function replaceUsedKeywords(int $siteId): int {
        // หา planned entries ที่ keyword ถูกเขียนไปแล้ว (ใช้ similarity check)
        $usedPlans = db()->fetchAll("
            SELECT cp.id, cp.site_id, cp.primary_keyword
            FROM content_plan cp
            WHERE cp.site_id = ? AND cp.status = 'planned'
              AND (
                  EXISTS (
                      SELECT 1 FROM article_keywords ak
                      WHERE ak.site_id = ?
                      AND (
                          cp.primary_keyword = ak.keyword
                          OR (
                              CHAR_LENGTH(REPLACE(ak.keyword, ' ', '')) >= 6
                              AND (
                                  REPLACE(cp.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE(ak.keyword, ' ', ''), '%')
                                  OR REPLACE(ak.keyword, ' ', '') LIKE CONCAT('%', REPLACE(cp.primary_keyword, ' ', ''), '%')
                              )
                          )
                      )
                  )
                  OR EXISTS (
                      SELECT 1 FROM articles art
                      WHERE art.site_id = ?
                      AND art.primary_keyword IS NOT NULL
                      AND (
                          cp.primary_keyword = art.primary_keyword
                          OR (
                              CHAR_LENGTH(REPLACE(art.primary_keyword, ' ', '')) >= 6
                              AND (
                                  REPLACE(cp.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE(art.primary_keyword, ' ', ''), '%')
                                  OR REPLACE(art.primary_keyword, ' ', '') LIKE CONCAT('%', REPLACE(cp.primary_keyword, ' ', ''), '%')
                              )
                          )
                      )
                  )
              )
            ORDER BY cp.planned_date ASC
        ", [$siteId, $siteId, $siteId]);

        if (empty($usedPlans)) {
            return 0;
        }

        // ดึงข้อมูลเว็บ
        $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
        if (!$site) return 0;

        $topic = $site['main_topic'];
        $langCode = $site['language_code'] ?? 'th';

        // ดึง keyword ที่ยังไม่เคยใช้ + ไม่อยู่ใน plan ที่ valid (ใช้ similarity deduplication)
        $needed = count($usedPlans);
        $deduplicationSQL = getKeywordDeduplicationSQL('k.keyword');

        $available = db()->fetchAll("
            SELECT k.id, k.keyword FROM keywords k
            WHERE k.topic = ? AND k.language_code = ? AND k.is_active = 1
              {$deduplicationSQL}
              AND k.keyword NOT IN (
                  SELECT DISTINCT cp2.primary_keyword
                  FROM content_plan cp2
                  WHERE cp2.site_id = ? AND cp2.status = 'planned'
              )
            ORDER BY k.usage_count ASC,
                     CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                     (COALESCE(k.traffic_potential, 0) / GREATEST(COALESCE(k.difficulty, 1), 1)) DESC,
                     k.search_volume DESC
            LIMIT ?
        ", [$topic, $langCode, $siteId, $siteId, $siteId, $needed]);

        // Fallback: ถ้าไม่พบ keyword จาก topic ให้ลอง site_id
        if (empty($available)) {
            $available = db()->fetchAll("
                SELECT k.id, k.keyword FROM keywords k
                WHERE k.site_id = ? AND k.language_code = ? AND k.is_active = 1
                  {$deduplicationSQL}
                  AND k.keyword NOT IN (
                      SELECT DISTINCT cp2.primary_keyword
                      FROM content_plan cp2
                      WHERE cp2.site_id = ? AND cp2.status = 'planned'
                  )
                ORDER BY k.usage_count ASC,
                         CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                         k.search_volume DESC
                LIMIT ?
            ", [$siteId, $langCode, $siteId, $siteId, $siteId, $needed]);
        }

        // แทนที่ keyword
        $replaced = 0;
        foreach ($usedPlans as $i => $plan) {
            if (isset($available[$i])) {
                $newKw = $available[$i];
                db()->update('content_plan', [
                    'primary_keyword_id' => $newKw['id'],
                    'primary_keyword' => $newKw['keyword'],
                    'ai_reasoning' => 'Auto-replaced: keyword "' . $plan['primary_keyword'] . '" already used'
                ], 'id = ?', [$plan['id']]);
                $replaced++;
            } else {
                // ไม่มี keyword ทดแทน → skip
                db()->update('content_plan', [
                    'status' => 'skipped'
                ], 'id = ?', [$plan['id']]);
            }
        }

        if ($replaced > 0) {
            logEvent('info', 'content_plan', "Replaced {$replaced} used keywords for site {$site['name']}", [
                'site_id' => $siteId,
                'replaced' => $replaced,
                'skipped' => count($usedPlans) - $replaced
            ]);
        }

        return $replaced;
    }
}

/**
 * Helper function
 */
function contentPlanner(): ContentPlanner {
    return new ContentPlanner();
}
