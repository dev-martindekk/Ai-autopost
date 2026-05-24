<?php
/**
 * AI AutoPost SEO System - Worker Manager
 * ========================================
 * ระบบจัดการ Workers สำหรับ Multi-Processing
 * รองรับการทำงานหลาย process พร้อมกัน
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/queue_manager.php';

class WorkerManager {
    private $maxWorkers = 3;
    private $workerTimeout = 600; // 10 minutes
    private $workerId;

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

    private function getLanguageName(string $code): string {
        return $this->languageNames[$code] ?? 'English';
    }

    public function __construct() {
        $this->workerId = $this->generateWorkerId();
    }

    /**
     * สร้าง Worker ID
     */
    private function generateWorkerId(): string {
        return 'worker_' . gethostname() . '_' . getmypid() . '_' . uniqid();
    }

    /**
     * ลงทะเบียน Worker
     */
    public function register(): int {
        return db()->insert('workers', [
            'worker_id' => $this->workerId,
            'hostname' => gethostname(),
            'pid' => getmypid(),
            'status' => 'idle',
            'started_at' => date('Y-m-d H:i:s'),
            'last_heartbeat' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * ส่ง Heartbeat
     */
    public function heartbeat(string $status = 'idle', ?string $currentJob = null): void {
        db()->update('workers', [
            'status' => $status,
            'current_job' => $currentJob,
            'last_heartbeat' => date('Y-m-d H:i:s')
        ], 'worker_id = ?', [$this->workerId]);
    }

    /**
     * ยกเลิกการลงทะเบียน Worker
     */
    public function unregister(): void {
        db()->delete('workers', 'worker_id = ?', [$this->workerId]);
    }

    /**
     * ตรวจสอบว่าสามารถเริ่ม Worker ใหม่ได้ไหม
     */
    public function canStartWorker(): bool {
        $activeWorkers = $this->getActiveWorkersCount();
        return $activeWorkers < $this->maxWorkers;
    }

    /**
     * นับ Active Workers
     */
    public function getActiveWorkersCount(): int {
        return (int) db()->fetchColumn("
            SELECT COUNT(*) FROM workers
            WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            AND status != 'stopped'
        ");
    }

    /**
     * ดึงรายการ Workers ทั้งหมด
     */
    public function getAllWorkers(): array {
        return db()->fetchAll("
            SELECT *,
                CASE
                    WHEN last_heartbeat < DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'dead'
                    ELSE status
                END as actual_status
            FROM workers
            ORDER BY started_at DESC
        ");
    }

    /**
     * ล้าง Dead Workers
     */
    public function cleanupDeadWorkers(): int {
        return db()->delete(
            'workers',
            'last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
        );
    }

    /**
     * รัน Worker Loop
     */
    public function run(array $jobTypes = [], int $maxJobs = 0): void {
        $this->register();
        $processedJobs = 0;

        // Setup signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function() {
                $this->shutdown();
            });
            pcntl_signal(SIGINT, function() {
                $this->shutdown();
            });
        }

        echo "[{$this->workerId}] Worker started\n";

        while (true) {
            // Check for signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Update heartbeat
            $this->heartbeat('waiting');

            // Get next job
            $jobType = !empty($jobTypes) ? $jobTypes[array_rand($jobTypes)] : null;
            $job = queue()->pop($jobType, $this->workerId);

            if (!$job) {
                sleep(5); // Wait before checking again
                continue;
            }

            // Process job
            $this->heartbeat('processing', $job['job_type']);
            echo "[{$this->workerId}] Processing job #{$job['id']}: {$job['job_type']}\n";

            try {
                $result = $this->processJob($job);
                queue()->complete($job['id'], $result);
                echo "[{$this->workerId}] Job #{$job['id']} completed\n";
            } catch (Exception $e) {
                queue()->fail($job['id'], $e->getMessage());
                echo "[{$this->workerId}] Job #{$job['id']} failed: {$e->getMessage()}\n";
            }

            $processedJobs++;

            // Check if we should stop
            if ($maxJobs > 0 && $processedJobs >= $maxJobs) {
                break;
            }
        }

        $this->shutdown();
    }

    /**
     * ประมวลผลงาน
     */
    private function processJob(array $job): array {
        $jobType = $job['job_type'];
        $payload = $job['payload'];
        $startTime = microtime(true);

        switch ($jobType) {
            case 'generate_article':
                $result = $this->handleGenerateArticle($payload);
                break;

            case 'refresh_article':
                $result = $this->handleRefreshArticle($payload);
                break;

            case 'build_internal_links':
                $result = $this->handleBuildInternalLinks($payload);
                break;

            case 'analyze_keywords':
                $result = $this->handleAnalyzeKeywords($payload);
                break;

            case 'check_link_health':
                $result = $this->handleCheckLinkHealth($payload);
                break;

            case 'sync_gsc':
                $result = $this->handleSyncGSC($payload);
                break;

            case 'optimize_ctr':
                $result = $this->handleOptimizeCTR($payload);
                break;

            case 'send_notification':
                $result = $this->handleSendNotification($payload);
                break;

            default:
                throw new Exception("Unknown job type: {$jobType}");
        }

        $result['execution_time'] = round(microtime(true) - $startTime, 2);
        return $result;
    }

    /**
     * Handle: Generate Article
     */
    private function handleGenerateArticle(array $payload): array {
        require_once __DIR__ . '/ai_client.php';
        require_once __DIR__ . '/wordpress_client.php';
        require_once __DIR__ . '/ai_rate_limiter.php';

        $siteId = $payload['site_id'];
        $keywordId = $payload['keyword_id'] ?? null;

        // Get site
        $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
        if (!$site) {
            throw new Exception("Site not found: {$siteId}");
        }

        // Get keyword (filter by site language)
        // *** FIX: เพิ่มการตรวจสอบว่า keyword ยังไม่เคยถูกใช้ในเว็บนี้ ***
        $siteLang = $site['language_code'] ?? 'th';
        if ($keywordId) {
            // ตรวจสอบว่า keyword นี้ยังไม่เคยใช้ในเว็บนี้
            $keyword = db()->fetchOne("
                SELECT * FROM keywords
                WHERE id = ?
                AND keyword NOT IN (
                    SELECT DISTINCT primary_keyword FROM articles
                    WHERE site_id = ? AND primary_keyword IS NOT NULL
                )
            ", [$keywordId, $siteId]);
        } else {
            $keyword = db()->fetchOne("
                SELECT * FROM keywords
                WHERE (site_id IS NULL OR site_id = ?)
                AND language_code = ?
                AND is_active = 1
                AND keyword NOT IN (
                    SELECT DISTINCT primary_keyword FROM articles
                    WHERE site_id = ? AND primary_keyword IS NOT NULL
                )
                ORDER BY usage_count ASC, priority ASC
                LIMIT 1
            ", [$siteId, $siteLang, $siteId]);
        }

        if (!$keyword) {
            throw new Exception("No keywords available for site: {$site['name']}");
        }

        // Generate article using AI Orchestrator
        require_once __DIR__ . '/ai_orchestrator.php';
        $ai = aiOrchestrator();

        $siteLanguage = $site['language_code'] ?? 'th';
        $langName = $this->getLanguageName($siteLanguage);
        $prompt = "เขียนบทความ SEO {$langName}เกี่ยวกับ {$keyword['keyword']} ความยาว 1500-2000 คำ";

        // ใช้ tier3 (Claude-3.5-Sonnet) สำหรับสร้างบทความ
        $result = $ai->execute('generate_article', $prompt, [
            'max_tokens' => 4000,
            'language' => $siteLanguage
        ]);

        // Save article
        $content = $result['content'];
        preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $titleMatch);
        $title = $titleMatch[1] ?? "บทความเกี่ยวกับ {$keyword['keyword']}";
        $title = strip_tags($title);

        // *** FIX: สร้าง slug จาก keyword ***
        $slug = $keyword['keyword'];
        $slug = preg_replace('/[\s\x{00A0}]+/u', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{N}\p{M}\-]+/u', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        $articleId = db()->insert('articles', [
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'primary_keyword' => $keyword['keyword'],
            'status' => 'generated',
            'ai_provider' => $result['provider'] ?? 'openrouter',
            'ai_model' => $result['model'] ?? null,
            'ai_tokens_used' => $result['tokens_used'] ?? 0
        ]);

        // Post to WordPress
        $wp = new WordPressClient($site);
        $postResult = $wp->createPost([
            'title' => $title,
            'content' => $content,
            'slug' => $slug,
            'status' => $site['post_status'] ?? 'publish'
        ]);

        if ($postResult['success']) {
            db()->update('articles', [
                'wp_post_id' => $postResult['post_id'],
                'post_url' => $postResult['post_url'],
                'status' => 'published',
                'published_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$articleId]);
        }

        return [
            'success' => $postResult['success'],
            'article_id' => $articleId,
            'post_url' => $postResult['post_url'] ?? null
        ];
    }

    /**
     * Handle: Refresh Article
     */
    private function handleRefreshArticle(array $payload): array {
        require_once __DIR__ . '/content_refresher.php';

        $articleId = $payload['article_id'];
        $refresher = new ContentRefresher();
        return $refresher->refresh($articleId);
    }

    /**
     * Handle: Build Internal Links
     */
    private function handleBuildInternalLinks(array $payload): array {
        require_once __DIR__ . '/internal_link_builder.php';

        $articleId = $payload['article_id'];
        $builder = new InternalLinkBuilder();
        return $builder->buildLinksForArticle($articleId);
    }

    /**
     * Handle: Analyze Keywords
     */
    private function handleAnalyzeKeywords(array $payload): array {
        require_once __DIR__ . '/keyword_analyzer.php';

        $keywordId = $payload['keyword_id'];
        $analyzer = new KeywordAnalyzer();
        return $analyzer->analyze($keywordId);
    }

    /**
     * Handle: Check Link Health
     */
    private function handleCheckLinkHealth(array $payload): array {
        require_once __DIR__ . '/link_checker.php';

        $siteId = $payload['site_id'];
        $checker = new LinkChecker();
        return $checker->checkSite($siteId);
    }

    /**
     * Handle: Sync GSC
     */
    private function handleSyncGSC(array $payload): array {
        require_once __DIR__ . '/gsc_client.php';

        $siteId = $payload['site_id'];
        $gsc = new GSCClient($siteId);
        return $gsc->syncPerformanceData();
    }

    /**
     * Handle: Optimize CTR
     */
    private function handleOptimizeCTR(array $payload): array {
        require_once __DIR__ . '/ctr_optimizer.php';

        $articleId = $payload['article_id'];
        $optimizer = new CTROptimizer();
        return $optimizer->optimize($articleId);
    }

    /**
     * Handle: Send Notification
     */
    private function handleSendNotification(array $payload): array {
        require_once __DIR__ . '/telegram_client.php';

        $telegram = new TelegramClient();
        $type = $payload['type'] ?? 'message';
        $message = $payload['message'] ?? '';

        switch ($type) {
            case 'article_posted':
                $article = $payload['article'];
                $site = $payload['site'];
                return ['success' => $telegram->notifyArticlePosted($article, $site)];

            case 'error':
                $title = $payload['title'] ?? 'Error';
                $error = $payload['error'] ?? '';
                return ['success' => $telegram->notifyError($title, $error)];

            default:
                return ['success' => $telegram->send($message)];
        }
    }

    /**
     * Shutdown Worker
     */
    private function shutdown(): void {
        echo "[{$this->workerId}] Worker shutting down\n";
        $this->heartbeat('stopped');
        $this->unregister();
        exit(0);
    }

    /**
     * Get Worker ID
     */
    public function getWorkerId(): string {
        return $this->workerId;
    }

    /**
     * Set Max Workers
     */
    public function setMaxWorkers(int $max): void {
        $this->maxWorkers = $max;
    }
}

/**
 * Helper function
 */
function workerManager(): WorkerManager {
    static $instance = null;
    if ($instance === null) {
        $instance = new WorkerManager();
    }
    return $instance;
}
