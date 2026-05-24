<?php
/**
 * AI AutoPost SEO System - Load Balancer
 * =======================================
 * ระบบกระจายงานให้สมดุล
 * รองรับหลายเว็บไซต์และหลาย AI Providers
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/queue_manager.php';
require_once __DIR__ . '/ai_rate_limiter.php';

class LoadBalancer {
    // จำนวนบทความสูงสุดต่อชั่วโมงต่อเว็บ
    private $maxArticlesPerHour = 2;

    // จำนวนบทความสูงสุดต่อวันต่อเว็บ
    private $maxArticlesPerDay = 5;

    // ช่วงเวลาพักระหว่างบทความ (วินาที)
    private $minIntervalBetweenPosts = 1800; // 30 minutes

    /**
     * กระจายงานสร้างบทความให้ทุกเว็บ
     */
    public function distributeArticleJobs(int $totalArticles = 10): array {
        $results = [
            'queued' => 0,
            'skipped' => 0,
            'errors' => [],
            'jobs' => []
        ];

        // Get active sites sorted by priority
        $sites = db()->fetchAll("
            SELECT s.*,
                   (SELECT COUNT(*) FROM articles WHERE site_id = s.id AND DATE(created_at) = CURDATE()) as today_count,
                   (SELECT MAX(created_at) FROM articles WHERE site_id = s.id) as last_article_time
            FROM sites s
            WHERE s.posting_enabled = 1
            ORDER BY s.priority ASC, today_count ASC
        ");

        if (empty($sites)) {
            $results['errors'][] = 'No active sites found';
            return $results;
        }

        $articlesAssigned = 0;
        $siteIndex = 0;
        $sitesCount = count($sites);

        while ($articlesAssigned < $totalArticles && $siteIndex < $sitesCount * 2) {
            $site = $sites[$siteIndex % $sitesCount];
            $siteIndex++;

            // Check site limits
            $check = $this->canPostToSite($site);
            if (!$check['allowed']) {
                $results['skipped']++;
                continue;
            }

            // Calculate scheduled time
            $scheduledAt = $this->calculateNextPostTime($site);

            // Get best keyword for this site
            $keyword = $this->getBestKeywordForSite($site['id']);
            if (!$keyword) {
                $results['errors'][] = "No keywords for site: {$site['name']}";
                continue;
            }

            // Queue the job
            $jobId = queue()->push('generate_article', [
                'site_id' => $site['id'],
                'keyword_id' => $keyword['id'],
                'keyword' => $keyword['keyword']
            ], [
                'priority' => $site['priority'],
                'site_id' => $site['id'],
                'scheduled_at' => $scheduledAt
            ]);

            $results['queued']++;
            $results['jobs'][] = [
                'job_id' => $jobId,
                'site' => $site['name'],
                'keyword' => $keyword['keyword'],
                'scheduled_at' => $scheduledAt
            ];

            $articlesAssigned++;

            // Update site's today count in memory
            $site['today_count']++;
        }

        return $results;
    }

    /**
     * ตรวจสอบว่าสามารถโพสไปที่เว็บได้ไหม
     */
    public function canPostToSite(array $site): array {
        // Check daily limit
        $todayCount = $site['today_count'] ?? 0;
        $dailyLimit = $site['daily_article_limit'] ?? $this->maxArticlesPerDay;

        if ($todayCount >= $dailyLimit) {
            return [
                'allowed' => false,
                'reason' => 'Daily limit reached',
                'current' => $todayCount,
                'limit' => $dailyLimit
            ];
        }

        // Check hourly limit
        $hourlyCount = db()->fetchColumn("
            SELECT COUNT(*) FROM articles
            WHERE site_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ", [$site['id']]);

        if ($hourlyCount >= $this->maxArticlesPerHour) {
            return [
                'allowed' => false,
                'reason' => 'Hourly limit reached',
                'current' => $hourlyCount,
                'limit' => $this->maxArticlesPerHour
            ];
        }

        // Check minimum interval
        if (!empty($site['last_article_time'])) {
            $lastPostTime = strtotime($site['last_article_time']);
            $timeSinceLastPost = time() - $lastPostTime;

            if ($timeSinceLastPost < $this->minIntervalBetweenPosts) {
                return [
                    'allowed' => false,
                    'reason' => 'Too soon since last post',
                    'wait_seconds' => $this->minIntervalBetweenPosts - $timeSinceLastPost
                ];
            }
        }

        return ['allowed' => true];
    }

    /**
     * คำนวณเวลาโพสถัดไป
     */
    private function calculateNextPostTime(array $site): string {
        // Get pending jobs for this site
        $lastScheduled = db()->fetchColumn("
            SELECT MAX(scheduled_at) FROM job_queue
            WHERE site_id = ? AND status = 'pending' AND job_type = 'generate_article'
        ", [$site['id']]);

        if ($lastScheduled) {
            // Schedule after the last pending job
            $baseTime = strtotime($lastScheduled);
        } else {
            // Start from now or last article time
            $baseTime = time();
            if (!empty($site['last_article_time'])) {
                $lastPostTime = strtotime($site['last_article_time']);
                $nextAllowedTime = $lastPostTime + $this->minIntervalBetweenPosts;
                if ($nextAllowedTime > $baseTime) {
                    $baseTime = $nextAllowedTime;
                }
            }
        }

        // Add random interval (30-60 minutes)
        $interval = rand($this->minIntervalBetweenPosts, $this->minIntervalBetweenPosts * 2);

        return date('Y-m-d H:i:s', $baseTime + $interval);
    }

    /**
     * เลือก Keyword ที่ดีที่สุดสำหรับเว็บ (filter by language)
     * *** FIX: เพิ่มการตรวจสอบว่า keyword ยังไม่เคยถูกใช้ในเว็บนี้ ***
     */
    private function getBestKeywordForSite(int $siteId): ?array {
        // Get site topic and language
        $site = db()->fetchOne("SELECT main_topic, language_code FROM sites WHERE id = ?", [$siteId]);
        $topic = $site['main_topic'] ?? 'general';
        $langCode = $site['language_code'] ?? 'th';

        return db()->fetchOne("
            SELECT k.* FROM keywords k
            WHERE (k.site_id IS NULL OR k.site_id = ?)
              AND (k.topic = ? OR k.topic = 'general')
              AND k.language_code = ?
              AND k.is_active = 1
              AND k.id NOT IN (
                  SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.keyword_id'))
                  FROM job_queue
                  WHERE status = 'pending' AND job_type = 'generate_article'
              )
              AND k.keyword NOT IN (
                  SELECT DISTINCT primary_keyword FROM articles
                  WHERE site_id = ? AND primary_keyword IS NOT NULL
              )
            ORDER BY
                CASE WHEN k.site_id = ? THEN 0 ELSE 1 END,
                k.usage_count ASC,
                k.priority ASC
            LIMIT 1
        ", [$siteId, $topic, $langCode, $siteId, $siteId]);
    }

    /**
     * เลือก AI Provider ที่ดีที่สุด
     */
    public function selectBestProvider(): ?array {
        $providers = db()->fetchAll("
            SELECT *,
                   COALESCE(monthly_limit, 999999999) - current_usage as remaining_quota
            FROM ai_settings
            WHERE is_enabled = 1 AND last_test_status = 'success'
            ORDER BY
                is_primary DESC,
                remaining_quota DESC
        ");

        foreach ($providers as $provider) {
            $check = rateLimiter()->canRequest($provider['provider_name']);
            if ($check['allowed']) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * สร้างแผนงานประจำวัน
     */
    public function createDailyPlan(): array {
        $plan = [
            'date' => date('Y-m-d'),
            'sites' => [],
            'total_articles' => 0,
            'schedule' => []
        ];

        // Get all active sites
        $sites = db()->fetchAll("
            SELECT * FROM sites
            WHERE posting_enabled = 1
            ORDER BY priority ASC
        ");

        // Calculate articles per site based on priority
        $totalSlots = 0;
        foreach ($sites as $site) {
            $dailyLimit = min($site['daily_article_limit'], $this->maxArticlesPerDay);
            $plan['sites'][$site['id']] = [
                'name' => $site['name'],
                'daily_limit' => $dailyLimit,
                'priority' => $site['priority'],
                'planned_articles' => 0
            ];
            $totalSlots += $dailyLimit;
        }

        // Get total capacity based on AI provider limits
        $aiCapacity = $this->calculateAICapacity();
        $articlesToCreate = min($totalSlots, $aiCapacity);

        // Distribute articles across sites
        $distributed = $this->distributeArticleSlots($sites, $articlesToCreate);

        foreach ($distributed as $siteId => $count) {
            if (isset($plan['sites'][$siteId])) {
                $plan['sites'][$siteId]['planned_articles'] = $count;
                $plan['total_articles'] += $count;
            }
        }

        // Create schedule
        $startHour = 9; // Start at 9 AM
        $endHour = 21; // End at 9 PM
        $currentTime = strtotime("today {$startHour}:00");

        foreach ($distributed as $siteId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $plan['schedule'][] = [
                    'site_id' => $siteId,
                    'site_name' => $plan['sites'][$siteId]['name'],
                    'scheduled_time' => date('H:i', $currentTime)
                ];
                $currentTime += $this->minIntervalBetweenPosts;

                // Wrap around if past end hour
                if (date('H', $currentTime) >= $endHour) {
                    $currentTime = strtotime("tomorrow {$startHour}:00");
                }
            }
        }

        // Sort schedule by time
        usort($plan['schedule'], function($a, $b) {
            return strcmp($a['scheduled_time'], $b['scheduled_time']);
        });

        return $plan;
    }

    /**
     * คำนวณความจุของ AI
     */
    private function calculateAICapacity(): int {
        $providers = db()->fetchAll("
            SELECT
                provider_name,
                monthly_limit,
                current_usage
            FROM ai_settings
            WHERE is_enabled = 1 AND last_test_status = 'success'
        ");

        $totalRemaining = 0;
        $avgTokensPerArticle = 3000; // Estimated

        foreach ($providers as $p) {
            if ($p['monthly_limit']) {
                $remaining = max(0, $p['monthly_limit'] - $p['current_usage']);
                $totalRemaining += $remaining;
            } else {
                $totalRemaining += 1000000; // No limit set
            }
        }

        // Calculate how many articles we can generate
        $articlesCapacity = floor($totalRemaining / $avgTokensPerArticle);

        // Limit to reasonable daily amount
        return min($articlesCapacity, 50);
    }

    /**
     * กระจาย slots บทความตาม priority
     */
    private function distributeArticleSlots(array $sites, int $totalArticles): array {
        $distributed = [];
        $remaining = $totalArticles;

        // First pass: give minimum to each site
        foreach ($sites as $site) {
            $min = min(1, $site['daily_article_limit']);
            if ($remaining >= $min) {
                $distributed[$site['id']] = $min;
                $remaining -= $min;
            }
        }

        // Second pass: distribute remaining by inverse priority (lower priority = more articles)
        while ($remaining > 0) {
            $assigned = false;
            foreach ($sites as $site) {
                $current = $distributed[$site['id']] ?? 0;
                $limit = $site['daily_article_limit'];

                if ($current < $limit && $remaining > 0) {
                    $distributed[$site['id']] = $current + 1;
                    $remaining--;
                    $assigned = true;
                }
            }

            if (!$assigned) break;
        }

        return $distributed;
    }

    /**
     * ดึงสถิติ Load Balancing
     */
    public function getStats(): array {
        return [
            'queue' => queue()->getStats(),
            'workers' => db()->fetchColumn("
                SELECT COUNT(*) FROM workers
                WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            "),
            'sites' => db()->fetchAll("
                SELECT
                    s.id,
                    s.name,
                    s.priority,
                    s.daily_article_limit,
                    s.articles_posted_today,
                    (SELECT COUNT(*) FROM job_queue WHERE site_id = s.id AND status = 'pending') as pending_jobs
                FROM sites s
                WHERE s.posting_enabled = 1
                ORDER BY s.priority ASC
            "),
            'ai_providers' => db()->fetchAll("
                SELECT
                    provider_name,
                    is_primary,
                    monthly_limit,
                    current_usage,
                    last_test_status
                FROM ai_settings
                WHERE is_enabled = 1
            ")
        ];
    }
}

/**
 * Helper function
 */
function loadBalancer(): LoadBalancer {
    static $instance = null;
    if ($instance === null) {
        $instance = new LoadBalancer();
    }
    return $instance;
}
