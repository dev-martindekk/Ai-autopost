<?php
/**
 * AI AutoPost SEO System - Article Generation Job (Full AI Mode)
 * ==============================================================
 * Cron job to generate and post articles automatically
 * 🎯 QUALITY MODE: ใช้ AI ทุกขั้นตอนเหมือน run_article.php
 *
 * Schedule: 0 6-22/2 * * * (Every 2 hours from 6am to 10pm)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/wordpress_client.php';
require_once __DIR__ . '/../includes/telegram_client.php';
require_once __DIR__ . '/../includes/image_generator.php';
require_once __DIR__ . '/../includes/risk_filter.php';
require_once __DIR__ . '/../includes/similarity_checker.php';
// 🎯 Full AI Mode - เพิ่ม modules ที่จำเป็น
require_once __DIR__ . '/../includes/content_planner.php';
require_once __DIR__ . '/../includes/keyword_analyzer.php';
require_once __DIR__ . '/../includes/internal_link_builder.php';
require_once __DIR__ . '/../includes/content_quality_checker.php';
require_once __DIR__ . '/../includes/tone_controller.php';
require_once __DIR__ . '/../includes/default_prompts.php';

// Start time tracking
$startTime = microtime(true);
$jobLog = [];

echo "=== AI AutoPost SEO - Article Generation Job (Full AI Mode) ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Get AI client
    $ai = new AIClient();
    echo "AI Provider: {$ai->getProvider()} ({$ai->getModel()})\n";

    // Current time info (Asia/Bangkok)
    $currentHour = (int) date('H');
    $currentDayOfWeek = (int) date('N'); // 1=Mon, 7=Sun
    echo "Current hour: {$currentHour}, Day: {$currentDayOfWeek}\n";

    // Get active sites that haven't reached daily limit
    $sites = db()->fetchAll("
        SELECT * FROM sites
        WHERE posting_enabled = 1
          AND articles_posted_today < daily_article_limit
        ORDER BY priority ASC
    ");

    if (empty($sites)) {
        echo "No sites available for posting (all reached daily limits or disabled)\n";
        exit(0);
    }

    // Filter sites by their schedule settings
    $eligibleSites = [];
    foreach ($sites as $site) {
        $startHour = (int)($site['posting_start_hour'] ?? 6);
        $endHour = (int)($site['posting_end_hour'] ?? 22);
        $intervalHours = (int)($site['posting_interval_hours'] ?? 2);
        $postingDays = explode(',', $site['posting_days'] ?? '1,2,3,4,5,6,7');

        // Check if today is a posting day
        $isPostingDay = in_array((string)$currentDayOfWeek, $postingDays);

        if (!$isPostingDay) {
            // Not a posting day — but check for manual plans that override the schedule
            $manualPlan = db()->fetchOne("
                SELECT id FROM content_plan
                WHERE site_id = ? AND is_manual = 1 AND status = 'planned'
                  AND planned_date = CURDATE()
                LIMIT 1
            ", [$site['id']]);

            if (!$manualPlan) {
                echo "  [{$site['name']}] Skipped - not a posting day\n";
                continue;
            }
            echo "  [{$site['name']}] Not a posting day, but has manual plan for today — proceeding\n";
            // Mark as manual-only so STEP 1 picks only manual plans
            $site['_manual_only'] = true;
        }

        // Check if current hour is within posting hours (manual plans still respect time window)
        if ($currentHour < $startHour || $currentHour > $endHour) {
            echo "  [{$site['name']}] Skipped - outside posting hours ({$startHour}:00-{$endHour}:00)\n";
            continue;
        }

        // Check if current hour matches the interval schedule
        if (($currentHour - $startHour) % $intervalHours !== 0) {
            echo "  [{$site['name']}] Skipped - not on interval (every {$intervalHours}h from {$startHour}:00)\n";
            continue;
        }

        // Check last post time to avoid double-posting in the same hour
        if (!empty($site['last_post_time'])) {
            $lastPostHour = (int) date('H', strtotime($site['last_post_time']));
            $lastPostDate = date('Y-m-d', strtotime($site['last_post_time']));
            if ($lastPostDate === date('Y-m-d') && $lastPostHour === $currentHour) {
                echo "  [{$site['name']}] Skipped - already posted this hour\n";
                continue;
            }
        }

        $eligibleSites[] = $site;
    }

    $sites = $eligibleSites;

    if (empty($sites)) {
        echo "No sites eligible at this time\n";
        exit(0);
    }

    echo "Found " . count($sites) . " site(s) ready for posting\n\n";

    // Get telegram client
    $telegram = new TelegramClient();

    // Process each site
    foreach ($sites as $site) {
        echo "--- Processing: {$site['name']} ---\n";

        try {
            // Check remaining quota
            $remaining = $site['daily_article_limit'] - $site['articles_posted_today'];
            if ($remaining <= 0) {
                echo "  Daily limit reached, skipping\n";
                continue;
            }

            // ============================================
            // STEP 1: ดึง Keyword จาก Content Plan หรือเลือกใหม่
            // ============================================
            $contentPlan = null;
            $keyword = null;

            // 1.1 ลองดึงจาก Content Plan ที่วางไว้
            // Manual plans: priority สูงสุด + ถ้าวันนี้ไม่ใช่วันโพสต์ดึงเฉพาะ manual เท่านั้น
            $manualOnlyFilter = !empty($site['_manual_only']) ? "AND cp.is_manual = 1 AND cp.planned_date = CURDATE()" : "";
            $contentPlan = db()->fetchOne("
                SELECT cp.*, k.search_volume, k.difficulty, k.traffic_potential, k.search_intent, k.topic
                FROM content_plan cp
                LEFT JOIN keywords k ON cp.primary_keyword_id = k.id
                WHERE cp.site_id = ?
                  AND cp.status = 'planned'
                  AND cp.planned_date <= CURDATE()
                  {$manualOnlyFilter}
                  " . getKeywordDeduplicationSQL('cp.primary_keyword') . "
                ORDER BY cp.is_manual DESC, cp.planned_date ASC, cp.priority DESC
                LIMIT 1
            ", [$site['id'], $site['id'], $site['id']]);

            if ($contentPlan) {
                echo "  📅 Using Content Plan: {$contentPlan['primary_keyword']}\n";
                echo "  Content Type: {$contentPlan['content_type']}, Intent: {$contentPlan['search_intent']}\n";

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
                $skippedPlans = db()->query("
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
                ", [$site['id'], $site['id']]);
            }

            // 1.2 ถ้าไม่มี Content Plan ให้เลือก keyword ตาม topic และ language
            // *** FIX: เพิ่มการเช็คว่าเว็บนี้ยังไม่เคยใช้ keyword นี้ ***
            if (!$keyword) {
                echo "  No Content Plan - selecting keyword automatically\n";
                $siteLang = $site['language_code'] ?? 'th';

                // ลองหา keyword ที่ตรงกับภาษาของเว็บก่อน (ที่ยังไม่เคยใช้ในเว็บนี้)
                $keyword = db()->fetchOne("
                    SELECT k.* FROM keywords k
                    WHERE k.topic = ? AND k.language_code = ? AND k.is_active = 1
                      " . getKeywordDeduplicationSQL() . "
                    ORDER BY k.usage_count ASC,
                             CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                             (COALESCE(k.traffic_potential, 0) / GREATEST(COALESCE(k.difficulty, 1), 1)) DESC,
                             k.search_volume DESC
                    LIMIT 1
                ", [$site['main_topic'], $siteLang, $site['id'], $site['id']]);

                // ถ้าไม่มี keyword ภาษาตรง ให้ลองหาจาก topic อย่างเดียว (ที่ยังไม่เคยใช้ในเว็บนี้)
                if (!$keyword) {
                    echo "  No {$siteLang} keywords found, trying any language...\n";
                    $keyword = db()->fetchOne("
                        SELECT k.* FROM keywords k
                        WHERE k.topic = ? AND k.is_active = 1
                          " . getKeywordDeduplicationSQL() . "
                        ORDER BY k.usage_count ASC,
                                 CASE WHEN k.data_source = 'ahrefs' THEN 0 ELSE 1 END,
                                 (COALESCE(k.traffic_potential, 0) / GREATEST(COALESCE(k.difficulty, 1), 1)) DESC,
                                 k.search_volume DESC
                        LIMIT 1
                    ", [$site['main_topic'], $site['id'], $site['id']]);
                }
            }

            // 1.3 Fallback: ดึง keyword ใดก็ได้ (ที่ยังไม่เคยใช้ในเว็บนี้)
            if (!$keyword) {
                $keyword = db()->fetchOne("
                    SELECT k.* FROM keywords k
                    WHERE k.is_active = 1
                      " . getKeywordDeduplicationSQL() . "
                    ORDER BY k.usage_count ASC, RAND()
                    LIMIT 1
                ", [$site['id'], $site['id']]);
            }

            if (!$keyword) {
                echo "  No keywords available, skipping\n";
                continue;
            }

            // Modernize year in keyword
            $keyword['keyword'] = modernizeKeywordYear($keyword['keyword']);

            echo "  🎯 Primary Keyword: {$keyword['keyword']}\n";
            if (!empty($keyword['search_volume'])) {
                echo "  Volume: " . number_format($keyword['search_volume']) . ", Difficulty: " . ($keyword['difficulty'] ?? '-') . "\n";
            }

            // ============================================
            // STEP 2: 🎯 AI วิเคราะห์ Keyword Cluster
            // ============================================
            echo "  🤖 AI Analyzing Keyword Cluster...\n";

            $keywordAnalyzer = new KeywordAnalyzer();
            $keywordCluster = $keywordAnalyzer->analyzeKeywordCluster($keyword, $site['id']);

            $secondaryCount = count($keywordCluster['secondary'] ?? []);
            $longTailCount = count($keywordCluster['long_tail'] ?? []);
            echo "  ✓ Found Secondary: {$secondaryCount}, Long Tail: {$longTailCount}\n";

            // สร้าง Keyword Map สำหรับส่งให้ AI เขียนบทความ
            $keywordMap = $keywordAnalyzer->buildKeywordMapForArticle($keywordCluster);

            // ============================================
            // STEP 3: สร้าง Prompt พร้อม Keyword Strategy
            // ============================================
            // Get language from site settings
            $siteLanguage = $site['language_code'] ?? 'th';
            echo "  🌐 Site language: {$siteLanguage}\n";

            // ดึง Prompt Template (ใช้ตัวเดียวร่วมกันทุกหมวด)
            $template = db()->fetchOne("
                SELECT * FROM prompt_templates
                WHERE is_active = 1
                ORDER BY is_default DESC
                LIMIT 1
            ");

            // Use shared default prompt from default_prompts.php
            $defaultPrompt = getDefaultPrompts()['article'];

            $promptContent = $template['prompt_content'] ?? $defaultPrompt;

            // Build the prompt with Keyword Strategy
            $articlePrompt = str_replace(
                ['{keyword}', '{topic}', '{min_words}', '{max_words}', '{site_name}', '{current_date}', '{current_year}'],
                [$keyword['keyword'], $site['main_topic_th'] ?? $site['main_topic'], getSetting('min_word_count', DEFAULT_ARTICLE_MIN_WORDS), getSetting('max_word_count', DEFAULT_ARTICLE_MAX_WORDS), $site['name'], date('Y-m-d'), date('Y')],
                $promptContent
            );

            // เพิ่ม Keyword Strategy ต่อท้าย prompt
            $articlePrompt .= "\n\n" . $keywordMap;

            // ============================================
            // เพิ่มคำสั่ง SEO/AIO/GEO/AEO/SXO และ Keywords ที่ต้องกระจายในเนื้อหา
            // ============================================
            $boldKeywords = [];
            // รวบรวม keywords ทั้งหมดสำหรับกระจายในเนื้อหา
            if (!empty($keywordCluster['secondary'])) {
                foreach ($keywordCluster['secondary'] as $kw) {
                    $boldKeywords[] = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
                }
            }
            if (!empty($keywordCluster['long_tail'])) {
                foreach ($keywordCluster['long_tail'] as $kw) {
                    $boldKeywords[] = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
                }
            }
            if (!empty($keywordCluster['lsi_keywords'])) {
                $boldKeywords = array_merge($boldKeywords, array_slice($keywordCluster['lsi_keywords'], 0, 5));
            }
            $boldKeywords = array_filter(array_unique($boldKeywords));
            $boldKeywordsList = implode(', ', $boldKeywords);

            $articlePrompt .= "\n\n## เน้นทำ SEO, AIO, GEO, AEO, SXO
- SEO: เนื้อหาที่ search engine ชอบ มีโครงสร้างดี
- AIO (AI Optimization): เนื้อหาที่ AI สามารถเข้าใจและอ้างอิงได้
- GEO (Generative Engine Optimization): เขียนให้ AI สามารถสรุปและตอบคำถามได้
- AEO (Answer Engine Optimization): ตอบคำถามตรงประเด็น มีโครงสร้างชัดเจน
- SXO (Search Experience Optimization): ประสบการณ์ผู้อ่านดี อ่านแล้วได้ประโยชน์

## Keywords ที่ต้องกระจายในเนื้อหา
กระจายคีย์เวิร์ดต่อไปนี้ให้กลมกลืนไปกับเนื้อหาอย่างเป็นธรรมชาติ ไม่ต้องทำตัวหนา ไม่ต้องใช้ <strong> ให้คีย์เวิร์ดอ่านเป็นส่วนหนึ่งของประโยคปกติ:
{$boldKeywordsList}";

            // เพิ่ม Tone Controller สำหรับความหลากหลาย
            $contentType = $contentPlan['content_type'] ?? 'review';
            $topicForTone = $keyword['topic'] ?? $site['main_topic'] ?? 'slots';

            $toneController = new ToneController();
            $toneRecommendation = $toneController->recommendTone($contentType, $topicForTone);

            $toneController->setTone($toneRecommendation['tone'])
                           ->setStyle($toneRecommendation['style'])
                           ->setFormality($toneRecommendation['formality']);

            // ใช้ variation index จาก article count เพื่อให้แต่ละบทความมีรูปแบบต่างกัน
            $articleCount = db()->fetchColumn("SELECT COUNT(*) FROM articles WHERE site_id = ?", [$site['id']]);
            $variationIndex = $articleCount % 5;

            $tonePrompt = $toneController->buildCompletePrompt([
                'variation_index' => $variationIndex,
                'content_type' => $contentType
            ]);

            $articlePrompt .= "\n\n" . $tonePrompt;

            // Final reminder - enforce critical requirements
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

            echo "  🎨 Tone: {$toneRecommendation['tone']} / {$toneRecommendation['style']} (Variation #{$variationIndex})\n";

            // ============================================
            // STEP 4: Generate article
            // ============================================
            echo "  🤖 AI Generating article with Keyword Strategy...\n";
            $generateStart = microtime(true);

            // Pass language to AI for proper system prompt
            $result = $ai->generate($articlePrompt, ['language' => $siteLanguage]);

            $generateTime = round(microtime(true) - $generateStart, 2);
            echo "  ✓ Generated in {$generateTime}s, tokens: {$result['tokens_used']}\n";

            // Parse the generated content
            $content = $result['content'];

            // Extract META_DESCRIPTION from AI output (before removing it from content)
            $metaDescription = '';
            if (preg_match('/<!--\s*META_DESCRIPTION:\s*(.+?)\s*-->/', $content, $metaMatch)) {
                $metaDescription = trim($metaMatch[1]);
                $metaDescription = mb_substr($metaDescription, 0, 160);
                $content = str_replace($metaMatch[0], '', $content);
            }

            // Extract title from content (first H1 or first line)
            preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $titleMatch);
            if (!$titleMatch) {
                preg_match('/^#\s*(.+)$/m', $content, $titleMatch);
            }

            $title = $titleMatch[1] ?? "บทความเกี่ยวกับ {$keyword['keyword']}";
            $title = strip_tags($title);

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
            echo "  🤖 AI Building Internal Links...\n";

            $linkBuilder = new InternalLinkBuilder();
            $linkResult = $linkBuilder->buildInternalLinks($content, $site['id'], $keyword['keyword']);

            $content = $linkResult['content'];
            echo "  ✓ Added {$linkResult['links_added']} internal links\n";

            // ============================================
            // STEP 6: Check similarity
            // ============================================
            echo "  Checking content similarity...\n";
            $similarityChecker = new SimilarityChecker();
            $simCheck = $similarityChecker->checkSimilarity($content, $site['id']);
            if ($simCheck['is_duplicate']) {
                echo "  ⚠️ WARNING: Content is {$simCheck['max_similarity']}% similar to existing articles\n";
            } else {
                echo "  ✓ Content is unique\n";
            }

            // ============================================
            // STEP 7: 🎯 AI Risk Filter
            // ============================================
            echo "  🤖 AI Checking content risk...\n";
            $riskFilter = new RiskFilter();
            $riskCheck = $riskFilter->filterAndFix($content, $site['main_topic']);
            if ($riskCheck['modified']) {
                $content = $riskCheck['content'];
                echo "  ✓ Content modified: content filtered and fixed\n";
            } else {
                echo "  ✓ Content passed risk check\n";
            }

            // ============================================
            // STEP 8: 🎯 AI Quality Check
            // ============================================
            echo "  🤖 AI Quality Check...\n";

            $qualityChecker = new ContentQualityChecker();
            $qualityCheck = $qualityChecker->checkQuality($title, $content, $keywordCluster, $site);

            echo "  ✓ Quality Score: {$qualityCheck['score']}/100\n";

            // Log issues
            foreach ($qualityCheck['issues'] as $issue) {
                if ($issue['severity'] === 'critical' || $issue['severity'] === 'warning') {
                    echo "  ⚠️ [{$issue['type']}] {$issue['message']}\n";
                }
            }

            if (!$qualityCheck['should_publish']) {
                echo "  ⚠️ Quality below threshold, but continuing...\n";
            }

            // Calculate word count
            $wordCount = countWords($content);
            echo "  📝 Word count: " . number_format($wordCount) . "\n";

            // ============================================
            // STEP 9: Generate featured image if enabled
            // ============================================
            $featuredImageId = null;
            if ($site['featured_image_enabled']) {
                echo "  🎨 Generating featured image (DALL-E)...\n";
                try {
                    $imageGen = new ImageGenerator();
                    $imageResult = $imageGen->generateAndDownload($title, $site['main_topic'], $keyword['keyword']);
                    if ($imageResult['success']) {
                        $wp = new WordPressClient($site);
                        // ใช้แค่ keyword อย่างเดียวสำหรับ alt_text และ title
                        $altText = $imageGen->generateAltText($title, $keyword['keyword'], $site['main_topic']);
                        $imageTitle = $keyword['keyword']; // Title = keyword อย่างเดียว
                        $uploadResult = $wp->uploadMedia($imageResult['filepath'], $imageResult['filename'], $altText, $imageTitle);
                        if ($uploadResult['success']) {
                            $featuredImageId = $uploadResult['media_id'];
                            echo "  ✓ Image uploaded (ID: {$featuredImageId})\n";
                            @unlink($imageResult['filepath']);
                        }
                    }
                } catch (Exception $imgEx) {
                    echo "  ⚠️ Image error: {$imgEx->getMessage()}\n";
                }
            }

            // ============================================
            // STEP 10: Save article to database
            // ============================================
            $articleId = db()->insert('articles', [
                'site_id' => $site['id'],
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
                'word_count' => $wordCount,
                'ai_provider' => $result['provider'],
                'ai_model' => $result['model'],
                'ai_tokens_used' => $result['tokens_used'],
                'generation_time' => $generateTime,
                'featured_image_id' => $featuredImageId,
                'status' => 'generated',
                'risk_score' => $riskCheck['risk_score'] ?? null,
                'has_responsible_gaming' => ($riskCheck['has_responsible_gaming'] ?? false) ? 1 : 0,
                'quality_score' => $qualityCheck['score'] ?? null
            ]);

            echo "  💾 Article saved (ID: {$articleId})\n";

            // Save used keywords and deactivate similar ones from pool
            $deactivated = saveAndDeactivateUsedKeywords($articleId, $site['id'], $keyword['keyword'], $keywordCluster);
            echo "  🧹 Deactivated {$deactivated} similar keywords from pool\n";

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
                echo "  📅 Content Plan updated to 'generated'\n";
            }

            // ============================================
            // STEP 11: Post to WordPress
            // ============================================
            echo "  📤 Posting to WordPress...\n";

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
                'focus_keyword' => $keyword['keyword'] // ส่ง keyword ไป Yoast/Rank Math
            ];

            if ($featuredImageId) {
                $postData['featured_media'] = $featuredImageId;
            }

            $postResult = $wp->createPost($postData);

            if ($postResult['success']) {
                // Update article status
                db()->update('articles', [
                    'wp_post_id' => $postResult['post_id'],
                    'post_url' => $postResult['post_url'],
                    'status' => 'published',
                    'published_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$articleId]);

                // Update site stats
                db()->query("
                    UPDATE sites
                    SET articles_posted_today = articles_posted_today + 1,
                        total_articles_posted = total_articles_posted + 1,
                        last_post_time = NOW()
                    WHERE id = ?
                ", [$site['id']]);

                echo "  ✅ Posted successfully! URL: {$postResult['post_url']}\n";

                // Send telegram notification
                if ($telegram->isEnabled()) {
                    $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);
                    $telegram->notifyArticlePosted($article, $site);
                }

                $jobLog[] = [
                    'success' => true,
                    'site' => $site['name'],
                    'title' => $title,
                    'url' => $postResult['post_url'],
                    'quality_score' => $qualityCheck['score'] ?? null
                ];

            } else {
                // Update article as failed
                db()->update('articles', [
                    'status' => 'failed',
                    'error_message' => $postResult['message']
                ], 'id = ?', [$articleId]);

                echo "  ❌ Post failed: {$postResult['message']}\n";

                // Send error notification
                if ($telegram->isEnabled()) {
                    $telegram->notifyError('WordPress Post Failed', $postResult['message'], [
                        'site' => $site['name']
                    ]);
                }

                $jobLog[] = [
                    'success' => false,
                    'site' => $site['name'],
                    'error' => $postResult['message']
                ];
            }

        } catch (Exception $e) {
            echo "  ❌ Error: {$e->getMessage()}\n";

            // Log error
            logEvent('error', 'job', 'Article generation failed', [
                'site_id' => $site['id'],
                'error' => $e->getMessage()
            ]);

            $jobLog[] = [
                'success' => false,
                'site' => $site['name'],
                'error' => $e->getMessage()
            ];
        }

        echo "\n";

        // Small delay between sites
        sleep(2);
    }

    // Reset daily counters if last_post_time is from a previous day
    $resetCount = db()->fetchColumn("SELECT COUNT(*) FROM sites WHERE articles_posted_today > 0 AND DATE(last_post_time) < CURDATE()");
    if ($resetCount > 0) {
        db()->query("UPDATE sites SET articles_posted_today = 0 WHERE DATE(last_post_time) < CURDATE()");
        echo "Daily counters reset for {$resetCount} site(s)\n";
    }

} catch (Exception $e) {
    echo "FATAL ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Article generation job failed', ['error' => $e->getMessage()]);
}

// Summary
$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);

echo "=== Job Complete (Full AI Mode) ===\n";
echo "Duration: {$duration}s\n";
echo "Processed: " . count($jobLog) . " article(s)\n";
echo "Success: " . count(array_filter($jobLog, fn($l) => $l['success'])) . "\n";
echo "Failed: " . count(array_filter($jobLog, fn($l) => !$l['success'])) . "\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

// Log job completion
logEvent('info', 'job', 'Article generation job completed (Full AI Mode)', [
    'duration' => $duration,
    'processed' => count($jobLog),
    'success' => count(array_filter($jobLog, fn($l) => $l['success'])),
    'failed' => count(array_filter($jobLog, fn($l) => !$l['success']))
]);
