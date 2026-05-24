<?php
/**
 * AI AutoPost SEO System - Queue Processor Job (Full AI Mode)
 * ===========================================================
 * Cron job to process queued jobs
 * 🎯 QUALITY MODE: ใช้ AI ทุกขั้นตอนเหมือน run_article.php
 * Schedule: Every 5 minutes
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

set_time_limit(0); // Cron job — no execution time limit

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/queue_manager.php';
require_once __DIR__ . '/../includes/worker_manager.php';
require_once __DIR__ . '/../includes/ai_rate_limiter.php';
require_once __DIR__ . '/../includes/load_balancer.php';
require_once __DIR__ . '/../includes/plan_manager.php';

$startTime = microtime(true);

echo "=== AI AutoPost SEO - Queue Processor (Full AI Mode) ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Release stuck jobs first
    $released = queue()->releaseStuckJobs();
    if ($released > 0) {
        echo "Released {$released} stuck jobs\n";
    }

    // Clean up dead workers
    $cleaned = workerManager()->cleanupDeadWorkers();
    if ($cleaned > 0) {
        echo "Cleaned up {$cleaned} dead workers\n";
    }

    // Get queue stats
    $stats = queue()->getStats();
    echo "Queue Status: {$stats['pending']} pending, {$stats['processing']} processing\n\n";

    // Process jobs (max 5 per run to avoid timeout)
    $maxJobs = 5;
    $processed = 0;
    $succeeded = 0;
    $failed = 0;

    $workerId = 'cron_' . getmypid();

    while ($processed < $maxJobs) {
        $job = queue()->pop(null, $workerId);

        if (!$job) {
            echo "No more jobs to process\n";
            break;
        }

        echo "Processing job #{$job['id']}: {$job['job_type']}... ";

        try {
            $result = processJob($job);
            queue()->complete($job['id'], $result);
            echo "OK\n";
            $succeeded++;
        } catch (Exception $e) {
            queue()->fail($job['id'], $e->getMessage());
            echo "FAILED: {$e->getMessage()}\n";
            $failed++;
        }

        $processed++;
    }

    // Summary
    $duration = round(microtime(true) - $startTime, 2);
    echo "\n=== Summary ===\n";
    echo "Duration: {$duration}s\n";
    echo "Processed: {$processed}\n";
    echo "Succeeded: {$succeeded}\n";
    echo "Failed: {$failed}\n";

    // Log
    logEvent('info', 'job', 'Queue processor completed (Full AI Mode)', [
        'duration' => $duration,
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed
    ]);

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Queue processor failed', ['error' => $e->getMessage()]);
}

/**
 * Process a single job
 */
function processJob(array $job): array {
    $jobType = $job['job_type'];
    $payload = $job['payload'];

    switch ($jobType) {
        case 'generate_article':
            return processGenerateArticle($payload);

        case 'refresh_article':
            require_once __DIR__ . '/../includes/content_refresher.php';
            $refresher = new ContentRefresher();
            return $refresher->refresh($payload['article_id']);

        case 'build_internal_links':
            require_once __DIR__ . '/../includes/internal_link_builder.php';
            $builder = new InternalLinkBuilder();

            // Get article data
            $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$payload['article_id']]);
            if (!$article) {
                throw new Exception("Article not found: {$payload['article_id']}");
            }

            // Build internal links
            $linkResult = $builder->buildInternalLinks(
                $article['content'],
                $article['site_id'],
                $article['primary_keyword']
            );

            // Update article content if links were added
            if ($linkResult['links_added'] > 0) {
                db()->update('articles', [
                    'content' => $linkResult['content']
                ], 'id = ?', [$payload['article_id']]);
            }

            return $linkResult;

        case 'check_link_health':
            require_once __DIR__ . '/../includes/link_checker.php';
            $checker = new LinkChecker();
            return $checker->checkSiteLinks($payload['site_id']);

        case 'sync_gsc':
            require_once __DIR__ . '/../includes/gsc_client.php';
            $gsc = new GSCClient($payload['site_id']);
            return $gsc->syncPerformanceData();

        case 'send_notification':
            require_once __DIR__ . '/../includes/telegram_client.php';
            $telegram = new TelegramClient();
            return ['success' => $telegram->send($payload['message'])];

        default:
            throw new Exception("Unknown job type: {$jobType}");
    }
}

/**
 * Process generate article job (Full AI Mode)
 * 🎯 QUALITY MODE: ใช้ AI ทุกขั้นตอนเหมือน run_article.php
 */
function processGenerateArticle(array $payload): array {
    require_once __DIR__ . '/../includes/ai_client.php';
    require_once __DIR__ . '/../includes/wordpress_client.php';
    require_once __DIR__ . '/../includes/risk_filter.php';
    require_once __DIR__ . '/../includes/similarity_checker.php';
    // 🎯 Full AI Mode - เพิ่ม modules ที่จำเป็น
    require_once __DIR__ . '/../includes/content_planner.php';
    require_once __DIR__ . '/../includes/keyword_analyzer.php';
    require_once __DIR__ . '/../includes/internal_link_builder.php';
    require_once __DIR__ . '/../includes/content_quality_checker.php';
    require_once __DIR__ . '/../includes/tone_controller.php';
    require_once __DIR__ . '/../includes/default_prompts.php';
    require_once __DIR__ . '/../includes/image_generator.php';
    require_once __DIR__ . '/../includes/telegram_client.php';

    $siteId = $payload['site_id'];
    $keywordId = $payload['keyword_id'] ?? null;

    // Get site
    $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
    if (!$site) {
        throw new Exception("Site not found: {$siteId}");
    }

    // If member site, load member's AI preference and build AIClient with it
    $mid = null;
    $aiSettings = null;
    if (($site['owner_type'] ?? '') === 'member' && !empty($site['owner_id'])) {
        $mid = (int)$site['owner_id'];
        $memberProviderId = getMemberSetting($mid, 'ai_provider_id', 0);
        $memberModel      = getMemberSetting($mid, 'ai_model_override', '');
        if ($memberProviderId) {
            $aiSettings = db()->fetchOne(
                "SELECT * FROM ai_settings WHERE id=? AND is_enabled=1 LIMIT 1",
                [(int)$memberProviderId]
            );
            if ($aiSettings && $memberModel) {
                $aiSettings['default_model'] = $memberModel;
            }
        }
    }

    // Check rate limit
    $ai = new AIClient($aiSettings ?: null);
    $provider = $ai->getProvider();

    $canRequest = rateLimiter()->canRequest($provider);
    if (!$canRequest['allowed']) {
        throw new Exception($canRequest['message']);
    }

    // ============================================
    // Quota check — block if member has no remaining article quota
    // ============================================
    if ($mid && !planManager()->canUse($mid, 'articles')) {
        echo "⚠️ Article quota exhausted for member #{$mid} — skipping.\n";
        return ['success' => false, 'article_id' => null, 'error' => 'quota_exceeded'];
    }

    // ============================================
    // STEP 1: ดึง Keyword จาก Content Plan หรือเลือกใหม่
    // ============================================
    $contentPlan = null;
    $keyword = null;

    // 1.1 ลองดึงจาก Content Plan ที่ AI วางไว้
    // *** FIX: เพิ่มการตรวจสอบว่า keyword ยังไม่เคยถูกใช้ในเว็บนี้ ***
    $contentPlan = db()->fetchOne("
        SELECT cp.*, k.search_volume, k.difficulty, k.traffic_potential, k.search_intent, k.topic
        FROM content_plan cp
        LEFT JOIN keywords k ON cp.primary_keyword_id = k.id
        WHERE cp.site_id = ?
          AND cp.status = 'planned'
          AND cp.planned_date <= CURDATE()
          " . getKeywordDeduplicationSQL('cp.primary_keyword') . "
        ORDER BY cp.planned_date ASC, cp.priority DESC
        LIMIT 1
    ", [$siteId, $siteId, $siteId]);

    if ($contentPlan) {
        echo "📅 Using Content Plan: {$contentPlan['primary_keyword']}\n";

        // ดึง keyword data จาก keywords table
        if ($contentPlan['primary_keyword_id']) {
            $keyword = db()->fetchOne("SELECT * FROM keywords WHERE id = ?", [$contentPlan['primary_keyword_id']]);
        }

        // ถ้าไม่มี keyword_id ให้สร้าง keyword array จาก content_plan
        if (!$keyword) {
            $keyword = [
                'id' => null,
                'keyword' => $contentPlan['primary_keyword'],
                'topic' => $contentPlan['topic'] ?? $site['main_topic'],
                'search_volume' => $contentPlan['search_volume'] ?? 0,
                'difficulty' => $contentPlan['difficulty'] ?? 0,
                'traffic_potential' => $contentPlan['traffic_potential'] ?? 0,
                'search_intent' => $contentPlan['search_intent'] ?? 'informational'
            ];
        }
    } else {
        // *** FIX: อัปเดต content_plan ที่ keyword ถูกใช้ไปแล้วให้เป็น 'skipped' ***
        db()->query("
            UPDATE content_plan cp
            SET cp.status = 'skipped'
            WHERE cp.site_id = ?
              AND cp.status = 'planned'
              AND cp.planned_date <= CURDATE()
              AND EXISTS (
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
        ", [$siteId, $siteId]);
    }

    // 1.2 ถ้ามี keyword_id ให้ใช้
    if (!$keyword && $keywordId) {
        $keyword = db()->fetchOne("SELECT * FROM keywords WHERE id = ?", [$keywordId]);
    }

    // 1.3 ถ้าไม่มี Content Plan ให้เลือก keyword ตาม topic AND language
    // *** FIX: เพิ่มการเช็คว่าเว็บนี้ยังไม่เคยใช้ keyword นี้ ***
    $siteLang = $site['language_code'] ?? 'th';
    if (!$keyword) {
        $keyword = db()->fetchOne("
            SELECT k.* FROM keywords k
            WHERE k.topic = ? AND k.language_code = ? AND k.is_active = 1
              " . getKeywordDeduplicationSQL() . "
            ORDER BY k.usage_count ASC,
                     CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                     (COALESCE(k.traffic_potential, 0) / GREATEST(COALESCE(k.difficulty, 1), 1)) DESC,
                     k.search_volume DESC
            LIMIT 1
        ", [$site['main_topic'], $siteLang, $siteId, $siteId]);
    }

    // 1.4 Fallback: ดึง keyword ตาม language แม้ต่าง topic (ยังไม่เคยใช้ในเว็บนี้)
    if (!$keyword) {
        $keyword = db()->fetchOne("
            SELECT k.* FROM keywords k
            WHERE k.language_code = ? AND k.is_active = 1
              " . getKeywordDeduplicationSQL() . "
            ORDER BY k.usage_count ASC, RAND()
            LIMIT 1
        ", [$siteLang, $siteId, $siteId]);
    }

    if (!$keyword) {
        throw new Exception("No keywords available");
    }

    // Modernize year in keyword
    $keyword['keyword'] = modernizeKeywordYear($keyword['keyword']);

    echo "🎯 Primary Keyword: {$keyword['keyword']}\n";

    // ============================================
    // STEP 2: 🎯 AI วิเคราะห์ Keyword Cluster
    // ============================================
    echo "🤖 AI Analyzing Keyword Cluster...\n";

    $keywordAnalyzer = new KeywordAnalyzer();
    $keywordCluster = $keywordAnalyzer->analyzeKeywordCluster($keyword, $siteId);

    $secondaryCount = count($keywordCluster['secondary'] ?? []);
    $longTailCount = count($keywordCluster['long_tail'] ?? []);
    $clusterMode = ($secondaryCount > 0 || $longTailCount > 0) ? 'AI-generated' : 'fallback';
    echo "✓ Keyword Cluster [{$clusterMode}] — Secondary: {$secondaryCount}, Long Tail: {$longTailCount}\n";

    // สร้าง Keyword Map สำหรับส่งให้ AI เขียนบทความ
    $keywordMap = $keywordAnalyzer->buildKeywordMapForArticle($keywordCluster);

    // ============================================
    // STEP 3: สร้าง Prompt พร้อม Keyword Strategy
    // ============================================
    // ดึง Prompt Template: member custom → admin default → hardcoded default
    $template = null;
    if (($site['owner_type'] ?? '') === 'member' && !empty($site['owner_id'])) {
        $template = db()->fetchOne(
            "SELECT * FROM prompt_templates
             WHERE template_type='article' AND owner_type='member' AND owner_id=? AND is_active=1
             LIMIT 1",
            [(int)$site['owner_id']]
        );
    }
    if (!$template) {
        $template = db()->fetchOne(
            "SELECT * FROM prompt_templates
             WHERE template_type='article' AND (owner_type='admin' OR owner_type IS NULL) AND is_active=1
             ORDER BY is_default DESC
             LIMIT 1"
        );
    }

    $defaultPrompt = getDefaultPrompts()['article'];
    $promptContent = $template['prompt_content'] ?? $defaultPrompt;

    // Resolve word count: member override → system setting → constant
    $minWords = (isset($mid) && $mid) ? getMemberSetting($mid, 'min_word_count') : null;
    $maxWords = (isset($mid) && $mid) ? getMemberSetting($mid, 'max_word_count') : null;
    $minWords = $minWords ?? getSetting('min_word_count', DEFAULT_ARTICLE_MIN_WORDS);
    $maxWords = $maxWords ?? getSetting('max_word_count', DEFAULT_ARTICLE_MAX_WORDS);

    // Build the prompt with Keyword Strategy
    $articlePrompt = str_replace(
        ['{keyword}', '{topic}', '{min_words}', '{max_words}', '{site_name}', '{site_niche}', '{current_date}', '{current_year}'],
        [$keyword['keyword'], $site['main_topic_th'] ?? $site['main_topic'], $minWords, $maxWords, $site['name'], $site['site_niche'] ?? '', date('Y-m-d'), date('Y')],
        $promptContent
    );

    // ถ้ามี site_niche ให้เพิ่มเป็น context ท้าย prompt
    if (!empty($site['site_niche'])) {
        $articlePrompt = "## WEBSITE CONTEXT\nเว็บไซต์นี้: {$site['site_niche']}\nเขียนบทความให้ตรงกับกลุ่มเป้าหมายและโทนของเว็บนี้\n\n---\n\n" . $articlePrompt;
    }

    // เพิ่ม Keyword Strategy ต่อท้าย prompt
    $articlePrompt .= "\n\n" . $keywordMap;
    $articlePrompt .= "\n\n## คำแนะนำเพิ่มเติมสำหรับ SEO
- กระจายคีย์เวิร์ดอย่างเป็นธรรมชาติภายในเนื้อหา ไม่ต้องทำตัวหนา ไม่ใช้ <strong>
- ให้คีย์เวิร์ดกลมกลืนไปกับประโยคปกติ ไม่ยัดใส่จนดู spam
- ใช้ Primary keyword ใน: Title, H1, ย่อหน้าแรก, สรุป
- ใช้ Secondary keywords ใน: H2, เนื้อหา
- ใช้ Long tail keywords เป็นหัวข้อย่อยหรือคำถาม FAQ
- ใช้ LSI keywords กระจายทั่วบทความอย่างเป็นธรรมชาติ
- ใช้ H2/H3 จัดโครงสร้างเนื้อหาแทนการใช้ <strong> เน้นหัวข้อ";

    // เพิ่ม Tone Controller สำหรับความหลากหลาย
    $contentType = $contentPlan['content_type'] ?? 'review';
    $topic = $keyword['topic'] ?? 'slots';

    $toneController = new ToneController();
    $toneRecommendation = $toneController->recommendTone($contentType, $topic);

    $toneController->setTone($toneRecommendation['tone'])
                   ->setStyle($toneRecommendation['style'])
                   ->setFormality($toneRecommendation['formality']);

    // ใช้ variation index จาก article count เพื่อให้แต่ละบทความมีรูปแบบต่างกัน
    $articleCount = db()->fetchColumn("SELECT COUNT(*) FROM articles WHERE site_id = ?", [$siteId]);
    $variationIndex = $articleCount % 5;

    $tonePrompt = $toneController->buildCompletePrompt([
        'variation_index' => $variationIndex,
        'content_type' => $contentType
    ]);

    $articlePrompt .= "\n\n" . $tonePrompt;

    // Final reminder - enforce critical requirements (ภาษาไทยอย่างเดียว)
    $minWords = getSetting('min_word_count', DEFAULT_ARTICLE_MIN_WORDS);
    $maxWords = getSetting('max_word_count', DEFAULT_ARTICLE_MAX_WORDS);
    $keyword_text = $keyword['keyword'];
    $articlePrompt .= "\n\n## 🚨 ย้ำกฎสำคัญ (Final Checklist)
- ⛔ ความยาว {$minWords}-{$maxWords} คำ ห้ามเกิน ห้ามขาด
- ⛔ ห้ามตัดจบกลางคัน ต้องเขียนให้จบสมบูรณ์ทุกส่วนรวมถึง FAQ และ Q&A
- ⛔ จบบทความที่ Q&A ห้ามมีส่วน \"สรุป\" หรือ \"บทสรุป\"
- ⛔ เนื้อหาใต้ H2 = 10-15 ประโยคต่อเนื่อง, ใต้ H3 = 6-10 ประโยค ใช้ <p> ยาวๆ ไม่แตกเป็นหลายย่อหน้าสั้นๆ
- ⛔ H2 ทุกอันต้องมีเนื้อหาก่อนถึง H3 แรก

## ⚠️ Checklist สิ่งที่ต้องมี (บังคับ)
- ✅ บรรทัดแรกสุด: <!-- META_DESCRIPTION: ... --> ไม่เกิน 160 ตัวอักษร ขึ้นต้นด้วย keyword หลัก
- ✅ H1 Title ไม่เกิน 60 ตัวอักษร ขึ้นต้นด้วย keyword หลัก
- ✅ ใต้ H1 = 1 ย่อหน้าสั้น 3-4 ประโยค เข้าเรื่องเลย ห้ามเขียนคำว่า \"บทนำ\"
- ✅ เนื้อหาหลัก 4-5 H2 แต่ละ H2 มี H3 ย่อย 2-3 หัวข้อ
- ✅ ตาราง HTML อย่างน้อย 1 ตาราง (<table> พร้อม <thead> และ <tbody>)
- ✅ FAQ \"คำถามที่พบบ่อยเกี่ยวกับ {$keyword_text}\" (H2) พร้อม H3 คำถาม 5 ข้อ
- ✅ Q&A \"ถาม-ตอบเรื่อง {$keyword_text}\" (H2) แบบบทสนทนา 3-5 คู่
- ✅ ใช้ HTML เท่านั้น ห้ามใช้ Markdown
- ❌ ห้ามใช้ <strong> กับ keywords
- ❌ ห้ามใช้ bullet points ในเนื้อหาหลัก ร้อยเรียงเป็นย่อหน้า";

    echo "🎨 Tone: {$toneRecommendation['tone']} (Variation #{$variationIndex})\n";

    // ============================================
    // STEP 4: Generate article
    // ============================================
    $siteLanguage = $site['language_code'] ?? 'th';

    $articlePrompt = "## ⚠️ IMPORTANT: เขียนเป็นภาษาไทยทั้งหมด\n\n" . $articlePrompt;
    echo "🤖 AI Generating article with Keyword Strategy...\n";
    $generateStart = microtime(true);

    $result = $ai->generate($articlePrompt, [
        'language'  => $siteLanguage,
        'member_id' => $mid ?: null,
    ]);

    $generateTime = round(microtime(true) - $generateStart, 2);
    echo "✓ Generated in {$generateTime}s, tokens: {$result['tokens_used']}\n";

    // Log usage
    rateLimiter()->logUsage(
        $provider,
        $result['input_tokens'] ?? 0,
        $result['output_tokens'] ?? 0,
        rateLimiter()->estimateCost($provider, $result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0),
        ['job_type' => 'generate_article', 'site_id' => $siteId, 'keyword' => $keyword['keyword']]
    );

    // Parse content
    $content = $result['content'];

    // Extract META_DESCRIPTION from AI output
    $metaDescription = '';
    if (preg_match('/<!--\s*META_DESCRIPTION:\s*(.+?)\s*-->/', $content, $metaMatch)) {
        $metaDescription = trim($metaMatch[1]);
        $metaDescription = mb_substr($metaDescription, 0, 160);
        $content = str_replace($metaMatch[0], '', $content);
    }

    preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $titleMatch);
    if (!$titleMatch) {
        preg_match('/^#\s*(.+)$/m', $content, $titleMatch);
    }
    $title = strip_tags($titleMatch[1] ?? "บทความเกี่ยวกับ {$keyword['keyword']}");

    // Clean up content - remove markdown code blocks and convert Markdown to HTML
    $content = preg_replace('/^```html\s*/im', '', $content);
    $content = preg_replace('/^```\s*$/m', '', $content);
    $content = convertMarkdownToHtml($content);
    $content = trim($content);

    // Generate slug (supports Thai and Unicode)
    $slug = $keyword['keyword'];
    $slug = preg_replace('/[\s\x{00A0}]+/u', '-', $slug); // Replace spaces with dash
    $slug = preg_replace('/[^\p{L}\p{N}\p{M}\-]+/u', '', $slug); // Keep letters, numbers, marks (Thai vowels/tones), dashes
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    // ============================================
    // STEP 5: 🎯 AI สร้าง Internal Links
    // ============================================
    if ($site['auto_internal_link'] ?? 1) {
        echo "🤖 AI Building Internal Links...\n";
        $linkBuilder = new InternalLinkBuilder();
        $linkResult  = $linkBuilder->buildInternalLinks($content, $siteId, $keyword['keyword']);
        $content     = $linkResult['content'];
        echo "✓ Added {$linkResult['links_added']} internal links\n";
    } else {
        echo "⏭ Internal links skipped (disabled for this site)\n";
    }

    // ============================================
    // STEP 6: Check similarity
    // ============================================
    $similarityChecker = new SimilarityChecker();
    $simCheck = $similarityChecker->checkSimilarity($content, $siteId);
    if ($simCheck['is_duplicate']) {
        echo "⚠️ Content is {$simCheck['max_similarity']}% similar to existing articles\n";
    }

    // ============================================
    // STEP 7: 🎯 AI Risk Filter
    // ============================================
    echo "🤖 AI Checking content risk...\n";
    $riskFilter = new RiskFilter();
    $riskCheck = $riskFilter->filterAndFix($content, $site['main_topic']);
    if ($riskCheck['modified']) {
        $content = $riskCheck['content'];
        echo "✓ Content modified: content filtered and fixed\n";
    }

    // ============================================
    // STEP 8: 🎯 AI Quality Check
    // ============================================
    echo "🤖 AI Quality Check...\n";

    $qualityChecker = new ContentQualityChecker();
    $qualityCheck = $qualityChecker->checkQuality($title, $content, $keywordCluster, $site);

    echo "✓ Quality Score: {$qualityCheck['score']}/100\n";

    // ============================================
    // STEP 9: Generate featured image if enabled
    // ============================================
    $featuredImageId = null;
    if ($site['featured_image_enabled']) {
        echo "🎨 Generating featured image (DALL-E)...\n";
        try {
            $imageGen = new ImageGenerator();
            $imageResult = $imageGen->generateAndDownload($title, $site['main_topic'], $keyword['keyword']);
            if ($imageResult['success']) {
                $wp = new WordPressClient($site);
                $altText = $imageGen->generateAltText($title, $keyword['keyword'], $site['main_topic']);
                $uploadResult = $wp->uploadMedia($imageResult['filepath'], $imageResult['filename'], $altText);
                if ($uploadResult['success']) {
                    $featuredImageId = $uploadResult['media_id'];
                    echo "✓ Image uploaded (ID: {$featuredImageId})\n";
                    @unlink($imageResult['filepath']);
                }
            }
        } catch (Exception $imgEx) {
            echo "⚠️ Image error: {$imgEx->getMessage()}\n";
        }
    }

    // ============================================
    // STEP 10: Save article
    // ============================================
    $articleId = db()->insert('articles', [
        'site_id' => $siteId,
        'title' => $title,
        'seo_title' => mb_substr($title, 0, 60),
        'slug' => $slug,
        'content' => $content,
        'excerpt' => truncateText(strip_tags($content), 200),
        'meta_title' => truncateText($title, 60),
        'meta_description' => $metaDescription ?: truncateText(strip_tags($content), 160),
        'primary_keyword' => $keyword['keyword'],
        'topic' => $keyword['topic'] ?? $site['main_topic'],
        'language_code' => $siteLanguage,
        'word_count' => countWords($content),
        'ai_provider' => $provider,
        'ai_model' => $ai->getModel(),
        'ai_tokens_used' => $result['tokens_used'] ?? 0,
        'generation_time' => $generateTime,
        'featured_image_id' => $featuredImageId,
        'status' => 'generated',
        'risk_score' => $riskCheck['risk_score'] ?? null,
        'has_responsible_gaming' => ($riskCheck['has_responsible_gaming'] ?? false) ? 1 : 0,
        'quality_score' => $qualityCheck['score'] ?? null
    ]);

    echo "💾 Article saved (ID: {$articleId})\n";

    // Consume article quota (increments monthly counter for paid plans; trial uses article count from DB)
    if ($mid) {
        planManager()->consume($mid, 'articles', 1);
    }

    // Save used keywords and deactivate similar ones from pool
    $deactivated = saveAndDeactivateUsedKeywords($articleId, $siteId, $keyword['keyword'], $keywordCluster);
    echo "🧹 Deactivated {$deactivated} similar keywords from pool\n";

    // Update keyword usage (only if keyword has id)
    if (!empty($keyword['id'])) {
        db()->query("UPDATE keywords SET usage_count = usage_count + 1, last_used = NOW() WHERE id = ?", [$keyword['id']]);
    }

    // Update Content Plan status if used
    if ($contentPlan) {
        db()->update('content_plan', [
            'status' => 'generated',
            'article_id' => $articleId
        ], 'id = ?', [$contentPlan['id']]);
        echo "📅 Content Plan updated to 'generated'\n";
    }

    // ============================================
    // STEP 11: Post to WordPress (skip for Trial plan, except gambling)
    // ============================================
    if ($mid && planManager()->isTrial($mid)) {
        echo "⚠️ Member is on Trial plan — auto-posting disabled. Article saved as 'generated'.\n";
        db()->update('articles', ['status' => 'generated'], 'id = ?', [$articleId]);
        return ['success' => true, 'article_id' => $articleId, 'trial_skip_publish' => true];
    }

    echo "📤 Posting to WordPress...\n";

    $wp = new WordPressClient($site);

    // Append FAQ Schema (JSON-LD) if FAQ section exists
    $contentWithSchema = appendFaqSchema($content);

    $postData = [
        'title' => $title,
        'content' => $contentWithSchema,
        'status' => $site['post_status'] ?? 'publish',
        'excerpt' => truncateText(strip_tags($content), 200),
        'slug' => $slug,
        'category_id' => $site['default_category_id'],
        'author_id' => $site['default_author_id'],
        'focus_keyword' => $keyword['keyword']
    ];

    if ($featuredImageId) {
        $postData['featured_media'] = $featuredImageId;
    }

    $postResult = $wp->createPost($postData);

    if ($postResult['success']) {
        db()->update('articles', [
            'wp_post_id' => $postResult['post_id'],
            'post_url' => $postResult['post_url'],
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$articleId]);

        // Update counters + clear MDES blocked flag on success
        db()->query("UPDATE sites SET articles_posted_today = articles_posted_today + 1, total_articles_posted = total_articles_posted + 1, last_post_time = NOW(), last_test_status = 'success', last_test_time = NOW(), is_mdes_blocked = 0 WHERE id = ?", [$siteId]);

        echo "✅ Posted successfully! URL: {$postResult['post_url']}\n";

        // Send notification
        $telegram = new TelegramClient();
        if ($telegram->isEnabled()) {
            $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);
            $telegram->notifyArticlePosted($article, $site);
        }
    } else {
        db()->update('articles', [
            'status' => 'failed',
            'error_message' => $postResult['message']
        ], 'id = ?', [$articleId]);
        echo "❌ Post failed: {$postResult['message']}\n";
    }

    return [
        'success' => $postResult['success'],
        'article_id' => $articleId,
        'post_url' => $postResult['post_url'] ?? null,
        'site' => $site['name'],
        'keyword' => $keyword['keyword'],
        'quality_score' => $qualityCheck['score'] ?? null
    ];
}
