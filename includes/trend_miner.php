<?php
/**
 * AI AutoPost SEO System - Real-time Trend Miner
 * ===============================================
 * ดึงเทรนด์จากหลายแหล่ง และแนะนำ Content ตามกระแส
 *
 * Sources:
 * - Google Trends (via SerpAPI or scraping)
 * - Twitter/X Trends
 * - YouTube Trending
 * - Reddit/Pantip (Gaming/Casino communities)
 *
 * Features:
 * - ดึงเทรนด์รายวัน
 * - AI วิเคราะห์ความเกี่ยวข้องกับ niche
 * - แนะนำ Keywords และ Content ideas
 * - แจ้งเตือนเทรนด์ที่สำคัญ
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class TrendMiner {

    private $db;
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

    // Trend categories relevant to our niche
    private const RELEVANT_CATEGORIES = [
        'technology' => ['เทคโนโลยี', 'แอป', 'app', 'มือถือ', 'mobile', 'AI', 'ปัญญาประดิษฐ์'],
        'entertainment' => ['บันเทิง', 'ดารา', 'หนัง', 'เพลง', 'คอนเสิร์ต', 'ซีรีส์'],
        'lifestyle' => ['ไลฟ์สไตล์', 'ท่องเที่ยว', 'อาหาร', 'สุขภาพ', 'ออกกำลังกาย'],
        'business' => ['ธุรกิจ', 'การเงิน', 'ลงทุน', 'startup', 'e-commerce'],
        'sports' => ['กีฬา', 'ฟุตบอล', 'บอล', 'มวย', 'football', 'sport']
    ];

    public function __construct() {
        $this->db = db();
        $this->ai = aiOrchestrator();
    }

    /**
     * Mine trends from selected sources
     */
    public function mineTrends(array $sources = ['google', 'pantip']): array {
        $trends = ['mined_at' => date('Y-m-d H:i:s')];

        if (in_array('google', $sources)) {
            $trends['google'] = $this->getGoogleTrends();
        }
        if (in_array('twitter', $sources)) {
            $trends['twitter'] = $this->getTwitterTrends();
        }
        if (in_array('youtube', $sources)) {
            $trends['youtube'] = $this->getYouTubeTrends();
        }
        if (in_array('pantip', $sources)) {
            $trends['pantip'] = $this->getPantipTrends();
        }

        // Merge and deduplicate
        $allTrends = $this->mergeTrends($trends);

        // Filter relevant trends
        $relevantTrends = $this->filterRelevantTrends($allTrends);

        // Save to database
        $this->saveTrends($relevantTrends);

        return [
            'success' => true,
            'total_found' => count($allTrends),
            'total_trends' => count($relevantTrends),
            'relevant' => count($relevantTrends),
            'trends' => $relevantTrends,
            'sources' => $trends
        ];
    }

    /**
     * Get Google Trends (Thailand)
     */
    private function getGoogleTrends(): array {
        $trends = [];

        // Method 1: Try SerpAPI if configured
        $serpApiKey = $this->db->fetchColumn("
            SELECT setting_value FROM system_settings WHERE setting_key = 'serpapi_key'
        ");

        if ($serpApiKey) {
            $url = "https://serpapi.com/search.json?engine=google_trends_trending_now&geo=TH&api_key={$serpApiKey}";

            $response = @file_get_contents($url);
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['trending_searches'])) {
                    foreach ($data['trending_searches'] as $item) {
                        $trends[] = [
                            'keyword' => $item['query'] ?? $item['title'] ?? '',
                            'source' => 'google_trends',
                            'traffic' => $item['traffic'] ?? null,
                            'url' => $item['link'] ?? null
                        ];
                    }
                }
            }
        }

        // Method 2: Fallback to RSS feed
        if (empty($trends)) {
            $rssUrl = 'https://trends.google.com/trends/trendingsearches/daily/rss?geo=TH';
            $rss = @simplexml_load_file($rssUrl);

            if ($rss) {
                foreach ($rss->channel->item as $item) {
                    $trends[] = [
                        'keyword' => (string)$item->title,
                        'source' => 'google_trends_rss',
                        'traffic' => (string)$item->children('ht', true)->approx_traffic ?? null,
                        'url' => (string)$item->link
                    ];
                }
            }
        }

        return array_slice($trends, 0, 20);
    }

    /**
     * Get Twitter/X Trends (Thailand)
     */
    private function getTwitterTrends(): array {
        $trends = [];

        // Note: Twitter API requires authentication
        // This is a placeholder - implement with actual Twitter API
        $twitterBearerToken = $this->db->fetchColumn("
            SELECT setting_value FROM system_settings WHERE setting_key = 'twitter_bearer_token'
        ");

        if ($twitterBearerToken) {
            // WOEID for Thailand = 23424960
            $url = 'https://api.twitter.com/1.1/trends/place.json?id=23424960';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $twitterBearerToken
                ]
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response) {
                $data = json_decode($response, true);
                if (isset($data[0]['trends'])) {
                    foreach ($data[0]['trends'] as $trend) {
                        $trends[] = [
                            'keyword' => str_replace('#', '', $trend['name']),
                            'source' => 'twitter',
                            'tweet_volume' => $trend['tweet_volume'],
                            'url' => $trend['url']
                        ];
                    }
                }
            }
        }

        // Fallback: Use AI to predict Twitter trends based on current events
        if (empty($trends)) {
            $trends = $this->predictTwitterTrends();
        }

        return array_slice($trends, 0, 15);
    }

    /**
     * Get YouTube Trending (Thailand)
     */
    private function getYouTubeTrends(): array {
        $trends = [];

        $youtubeApiKey = $this->db->fetchColumn("
            SELECT setting_value FROM system_settings WHERE setting_key = 'youtube_api_key'
        ");

        if ($youtubeApiKey) {
            // Gaming category = 20, Entertainment = 24
            $categories = [20, 24];

            foreach ($categories as $categoryId) {
                $url = "https://www.googleapis.com/youtube/v3/videos?part=snippet&chart=mostPopular&regionCode=TH&videoCategoryId={$categoryId}&maxResults=10&key={$youtubeApiKey}";

                $response = @file_get_contents($url);
                if ($response) {
                    $data = json_decode($response, true);
                    if (isset($data['items'])) {
                        foreach ($data['items'] as $video) {
                            $trends[] = [
                                'keyword' => $video['snippet']['title'],
                                'source' => 'youtube',
                                'category' => $categoryId == 20 ? 'gaming' : 'entertainment',
                                'channel' => $video['snippet']['channelTitle'],
                                'url' => 'https://youtube.com/watch?v=' . $video['id']
                            ];
                        }
                    }
                }
            }
        }

        return $trends;
    }

    /**
     * Get Pantip Trending Topics
     */
    private function getPantipTrends(): array {
        $trends = [];

        // Relevant Pantip rooms
        $rooms = [
            'sinthorn' => 'สินธร (การเงิน)',
            'klaibaan' => 'ใกล้บ้าน',
            'chalermkrung' => 'เฉลิมกรุง (บันเทิง)'
        ];

        foreach ($rooms as $roomId => $roomName) {
            $url = "https://pantip.com/forum/topic/ajax_json_all_topic?room={$roomId}&limit=10";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['items'])) {
                    foreach ($data['items'] as $topic) {
                        $trends[] = [
                            'keyword' => $topic['title'] ?? '',
                            'source' => 'pantip',
                            'room' => $roomName,
                            'comments' => $topic['comments_count'] ?? 0,
                            'url' => 'https://pantip.com/topic/' . ($topic['topic_id'] ?? '')
                        ];
                    }
                }
            }
        }

        return $trends;
    }

    /**
     * AI-predicted Twitter trends (fallback)
     */
    private function predictTwitterTrends(): array {
        $prompt = "จากข่าวและเหตุการณ์ปัจจุบันในประเทศไทย (วันที่ " . date('d/m/Y') . ")
คาดการณ์ 10 หัวข้อที่น่าจะกำลัง trending บน Twitter ไทย

พิจารณา:
- ข่าวกีฬา (ฟุตบอล, มวย)
- บันเทิง (ดารา, คอนเสิร์ต)
- เกมและ Esports
- เหตุการณ์สำคัญ

ตอบเป็น JSON:
```json
{\"trends\": [{\"keyword\": \"...\", \"reason\": \"...\"}]}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, ['temperature' => 0.7]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);
                if (isset($data['trends'])) {
                    return array_map(function($t) {
                        return [
                            'keyword' => $t['keyword'],
                            'source' => 'ai_predicted',
                            'reason' => $t['reason']
                        ];
                    }, $data['trends']);
                }
            }
        } catch (Exception $e) {
            // Silent fail
        }

        return [];
    }

    /**
     * Merge trends from all sources
     */
    private function mergeTrends(array $sources): array {
        $merged = [];
        $seen = [];

        foreach ($sources as $source => $trends) {
            if (!is_array($trends)) continue;

            foreach ($trends as $trend) {
                if (!isset($trend['keyword']) || empty($trend['keyword'])) continue;

                $keyword = mb_strtolower(trim($trend['keyword']));

                if (!isset($seen[$keyword])) {
                    $seen[$keyword] = true;
                    $trend['sources'] = [$source];
                    $merged[] = $trend;
                } else {
                    // Add source to existing
                    foreach ($merged as &$m) {
                        if (mb_strtolower($m['keyword']) === $keyword) {
                            $m['sources'][] = $source;
                            break;
                        }
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Filter trends relevant to our niche
     */
    private function filterRelevantTrends(array $trends): array {
        $relevant = [];

        foreach ($trends as $trend) {
            $keyword = mb_strtolower($trend['keyword']);
            $isRelevant = false;
            $matchedCategory = null;

            foreach (self::RELEVANT_CATEGORIES as $category => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($keyword, mb_strtolower($kw)) !== false) {
                        $isRelevant = true;
                        $matchedCategory = $category;
                        break 2;
                    }
                }
            }

            if ($isRelevant) {
                $trend['category'] = $matchedCategory;
                $trend['relevance'] = 'direct';
                $relevant[] = $trend;
            } else {
                // Check if AI thinks it's relevant
                // (Skip for performance, can be enabled if needed)
            }
        }

        return $relevant;
    }

    /**
     * Save trends to database
     */
    private function saveTrends(array $trends): void {
        foreach ($trends as $trend) {
            try {
                $this->db->query("
                    INSERT INTO trend_history (keyword, source, category, data, mined_at)
                    VALUES (?, ?, ?, ?, NOW())
                ", [
                    $trend['keyword'],
                    $trend['source'] ?? 'unknown',
                    $trend['category'] ?? null,
                    json_encode($trend)
                ]);
            } catch (Exception $e) {
                // Silent fail
            }
        }
    }

    /**
     * Get AI content suggestions based on trends
     * @param int $limit Number of suggestions
     * @param string $language Language code (th, vn, en, id, my, km, zh, ja, ko)
     */
    public function getContentSuggestions(int $limit = 10, string $language = 'th'): array {
        // Get recent relevant trends
        $trends = $this->db->fetchAll("
            SELECT keyword, source, category, data
            FROM trend_history
            WHERE mined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY mined_at DESC
            LIMIT 20
        ");

        if (empty($trends)) {
            // Mine fresh trends
            $result = $this->mineTrends();
            $trends = array_slice($result['trends'], 0, 20);
        }

        // Get existing keywords to avoid duplicates
        $existingKeywords = $this->db->fetchAll("
            SELECT keyword FROM keywords LIMIT 1000
        ");
        $existingList = array_column($existingKeywords, 'keyword');

        $langName = $this->getLanguageName($language);
        $trendsList = implode("\n", array_map(fn($t) => "- " . ($t['keyword'] ?? $t), $trends));
        $existingListStr = implode(", ", array_slice($existingList, 0, 50));

        // Prompts by language (9 languages)
        $prompts = [
            'th' => "คุณเป็นผู้เชี่ยวชาญ Content Strategy สำหรับเว็บไซต์ SEO ภาษาไทย

เทรนด์ที่กำลังมาแรง:
{$trendsList}

Keywords ที่มีอยู่แล้ว (หลีกเลี่ยงการซ้ำ):
{$existingListStr}

แนะนำ {$limit} ไอเดีย Content ที่:
1. เกาะกระแสเทรนด์ปัจจุบัน
2. มีโอกาสติด SEO สูง
3. เป็นประโยชน์ต่อผู้อ่านจริง
4. ไม่ซ้ำกับที่มีอยู่",

            'vn' => "Bạn là chuyên gia Content Strategy cho website slot/casino trực tuyến.

Xu hướng đang hot:
{$trendsList}

Keywords đã có (tránh trùng lặp):
{$existingListStr}

Đề xuất {$limit} ý tưởng Content:
1. Bắt kịp xu hướng hiện tại
2. Liên quan đến slot/casino/cá cược
3. Có cơ hội SEO tốt
4. Không trùng với nội dung đã có",

            'en' => "You are a Content Strategy expert for online slot/casino websites.

Trending now:
{$trendsList}

Existing keywords (avoid duplicates):
{$existingListStr}

Suggest {$limit} Content ideas that:
1. Ride current trends
2. Related to slots/casino/betting
3. Have SEO potential
4. Don't duplicate existing content",

            'id' => "Anda adalah ahli Content Strategy untuk website slot/casino online.

Tren yang sedang populer:
{$trendsList}

Keywords yang sudah ada (hindari duplikasi):
{$existingListStr}

Sarankan {$limit} ide Content yang:
1. Mengikuti tren saat ini
2. Terkait dengan slot/casino/taruhan
3. Memiliki potensi SEO
4. Tidak duplikasi konten yang ada",

            'my' => "Anda adalah pakar Content Strategy untuk laman web slot/kasino dalam talian.

Trend yang sedang popular:
{$trendsList}

Keywords sedia ada (elakkan pertindihan):
{$existingListStr}

Cadangkan {$limit} idea Content yang:
1. Mengikuti trend semasa
2. Berkaitan dengan slot/kasino/pertaruhan
3. Mempunyai potensi SEO
4. Tidak bertindih dengan kandungan sedia ada",

            'km' => "អ្នកជាអ្នកជំនាញ Content Strategy សម្រាប់គេហទំព័រ slot/casino អនឡាញ។

និន្នាការដែលកំពុងពេញនិយម:
{$trendsList}

Keywords ដែលមានរួចហើយ (ជៀសវាងការស្ទួន):
{$existingListStr}

ផ្តល់យោបល់ {$limit} គំនិត Content ដែល:
1. តាមដានទំនោរបច្ចុប្បន្ន
2. ទាក់ទងនឹង slot/casino/ការភ្នាល់
3. មានសក្តានុពល SEO
4. មិនស្ទួនមាតិកាដែលមានស្រាប់",

            'zh' => "您是在线老虎机/赌场网站的内容策略专家。

当前趋势:
{$trendsList}

现有关键词（避免重复）:
{$existingListStr}

建议 {$limit} 个内容创意：
1. 紧跟当前趋势
2. 与老虎机/赌场/博彩相关
3. 具有SEO潜力
4. 不重复现有内容",

            'ja' => "あなたはオンラインスロット/カジノサイトのコンテンツ戦略専門家です。

現在のトレンド:
{$trendsList}

既存のキーワード（重複を避ける）:
{$existingListStr}

{$limit}個のコンテンツアイデアを提案してください：
1. 現在のトレンドに乗る
2. スロット/カジノ/ベッティングに関連
3. SEOポテンシャルがある
4. 既存コンテンツと重複しない",

            'ko' => "귀하는 온라인 슬롯/카지노 웹사이트의 콘텐츠 전략 전문가입니다.

현재 트렌드:
{$trendsList}

기존 키워드 (중복 피하기):
{$existingListStr}

{$limit}개의 콘텐츠 아이디어를 제안하세요:
1. 현재 트렌드 활용
2. 슬롯/카지노/베팅 관련
3. SEO 잠재력 있음
4. 기존 콘텐츠와 중복 안 됨"
        ];

        $basePrompt = $prompts[$language] ?? $prompts['en'];

        $prompt = $basePrompt . "

Answer in JSON format:
```json
{
    \"suggestions\": [
        {
            \"title\": \"Content Title\",
            \"keyword\": \"main keyword\",
            \"trend_hook\": \"related trend\",
            \"content_type\": \"review/guide/news/tips\",
            \"urgency\": \"high/medium/low\",
            \"reason\": \"why write this\"
        }
    ]
}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, [
                'temperature' => 0.6,
                'max_tokens' => 2000
            ]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);
                return [
                    'success' => true,
                    'suggestions' => $data['suggestions'] ?? [],
                    'based_on_trends' => array_slice($trends, 0, 5)
                ];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => false, 'message' => 'Could not generate suggestions'];
    }

    /**
     * Get trending keywords for a specific topic
     */
    public function getTrendingForTopic(string $topic): array {
        $prompt = "หา 10 keywords ที่กำลัง trending เกี่ยวกับ \"{$topic}\" ในประเทศไทย

พิจารณา:
- Google Trends
- Social Media
- เหตุการณ์ปัจจุบัน
- ฤดูกาล

ตอบเป็น JSON:
```json
{
    \"keywords\": [
        {\"keyword\": \"...\", \"search_intent\": \"informational/transactional\", \"trend_level\": \"rising/stable/hot\"}
    ]
}
```";

        try {
            $result = $this->ai->execute('strategy_plan', $prompt, ['temperature' => 0.5]);

            $response = $result['content'] ?? '';

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                $data = json_decode($matches[1], true);
                return [
                    'success' => true,
                    'topic' => $topic,
                    'keywords' => $data['keywords'] ?? []
                ];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => false, 'message' => 'Could not get trending keywords'];
    }

    /**
     * Get dashboard stats for admin UI
     */
    public function getDashboardStats(): array {
        // Hot trends (score >= 80)
        $hotTrends = $this->db->fetchColumn("
            SELECT COUNT(*) FROM trend_history
            WHERE trend_score >= 80 AND is_relevant = 1
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ") ?: 0;

        // Rising trends (score 50-79)
        $risingTrends = $this->db->fetchColumn("
            SELECT COUNT(*) FROM trend_history
            WHERE trend_score >= 50 AND trend_score < 80 AND is_relevant = 1
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ") ?: 0;

        // Total trends in last 7 days
        $totalTrends = $this->db->fetchColumn("
            SELECT COUNT(*) FROM trend_history
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ") ?: 0;

        // Articles created from trends (check content_plan table)
        $articlesFromTrends = $this->db->fetchColumn("
            SELECT COUNT(*) FROM content_plan
            WHERE content_type = 'trend_article'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ") ?: 0;

        return [
            'hot_trends' => (int)$hotTrends,
            'rising_trends' => (int)$risingTrends,
            'total_trends' => (int)$totalTrends,
            'articles_from_trends' => (int)$articlesFromTrends
        ];
    }

    /**
     * Get dashboard summary
     */
    public function getDashboardSummary(): array {
        $recentTrends = $this->db->fetchAll("
            SELECT keyword, source, category, mined_at
            FROM trend_history
            WHERE mined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY mined_at DESC
            LIMIT 10
        ");

        $trendsByCategory = $this->db->fetchAll("
            SELECT category, COUNT(*) as count
            FROM trend_history
            WHERE mined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND category IS NOT NULL
            GROUP BY category
            ORDER BY count DESC
        ");

        $lastMined = $this->db->fetchColumn("
            SELECT MAX(mined_at) FROM trend_history
        ");

        return [
            'recent_trends' => $recentTrends,
            'by_category' => $trendsByCategory,
            'last_mined' => $lastMined,
            'total_24h' => count($recentTrends)
        ];
    }

    /**
     * Set up scheduled mining (for cron)
     */
    public function scheduledMine(): array {
        $result = $this->mineTrends();

        // Send Telegram notification if significant trends found
        if ($result['relevant'] > 5) {
            try {
                require_once __DIR__ . '/telegram_client.php';
                $tg = telegram();
                if ($tg->isEnabled()) {
                    $message = "🔥 <b>Trend Alert!</b>\n\n";
                    $message .= "พบ {$result['relevant']} เทรนด์ที่เกี่ยวข้อง\n\n";

                    foreach (array_slice($result['trends'], 0, 5) as $trend) {
                        $message .= "• {$trend['keyword']} ({$trend['category']})\n";
                    }

                    $message .= "\n📅 " . date('d/m/Y H:i');
                    $tg->sendMessage($message);
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        logEvent('info', 'trend_miner', 'Scheduled trend mining completed', [
            'total' => $result['total_found'],
            'relevant' => $result['relevant']
        ]);

        return $result;
    }
}

/**
 * Helper function
 */
function trendMiner(): TrendMiner {
    return new TrendMiner();
}
