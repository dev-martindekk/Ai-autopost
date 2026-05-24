<?php
/**
 * Test: Generate single article for sixma168
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('ALLOW_WEB_ACCESS', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/wordpress_client.php';
require_once __DIR__ . '/../includes/telegram_client.php';
require_once __DIR__ . '/../includes/image_generator.php';
require_once __DIR__ . '/../includes/risk_filter.php';
require_once __DIR__ . '/../includes/similarity_checker.php';
require_once __DIR__ . '/../includes/content_planner.php';
require_once __DIR__ . '/../includes/keyword_analyzer.php';
require_once __DIR__ . '/../includes/internal_link_builder.php';
require_once __DIR__ . '/../includes/content_quality_checker.php';
require_once __DIR__ . '/../includes/tone_controller.php';
require_once __DIR__ . '/../includes/ai_rate_limiter.php';
require_once __DIR__ . '/../includes/load_balancer.php';
require_once __DIR__ . '/../includes/default_prompts.php';

$siteId = 1;
foreach ($argv as $arg) {
    if (strpos($arg, '--site=') === 0) {
        $siteId = (int)substr($arg, 7);
    }
}
$ai = new AIClient();

$siteName = db()->fetchColumn("SELECT name FROM sites WHERE id = ?", [$siteId]);
echo "=== Test Generate Article for {$siteName} ===\n";
$aiSettings = db()->fetchOne("SELECT max_tokens FROM ai_settings WHERE is_primary = 1 AND is_enabled = 1");
echo "AI: {$ai->getProvider()} / {$ai->getModel()} / max_tokens: {$aiSettings['max_tokens']}\n\n";

$site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
$siteLanguage = $site['language_code'] ?? 'th';

// STEP 1: Get keyword from content plan or DB
$contentPlan = db()->fetchOne("
    SELECT cp.*, k.search_volume, k.difficulty, k.traffic_potential, k.search_intent, k.topic
    FROM content_plan cp
    LEFT JOIN keywords k ON cp.primary_keyword_id = k.id
    WHERE cp.site_id = ? AND cp.status = 'planned' AND cp.planned_date <= CURDATE()
      " . getKeywordDeduplicationSQL('cp.primary_keyword') . "
    ORDER BY cp.planned_date ASC, cp.priority DESC LIMIT 1
", [$siteId, $siteId, $siteId]);

$keyword = null;
if ($contentPlan) {
    echo "📅 Content Plan: {$contentPlan['primary_keyword']}\n";
    if ($contentPlan['primary_keyword_id']) {
        $keyword = db()->fetchOne("SELECT * FROM keywords WHERE id = ?", [$contentPlan['primary_keyword_id']]);
    }
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
}

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
    ", [$site['main_topic'], $siteLanguage, $siteId, $siteId]);
}

if (!$keyword) {
    die("No keywords available!\n");
}

// Modernize year in keyword
$keyword['keyword'] = modernizeKeywordYear($keyword['keyword']);

echo "🎯 Keyword: {$keyword['keyword']}\n";

// STEP 2: Keyword Cluster
echo "🤖 Analyzing keyword cluster...\n";
$keywordAnalyzer = new KeywordAnalyzer();
$keywordCluster = $keywordAnalyzer->analyzeKeywordCluster($keyword, $siteId);
$keywordMap = $keywordAnalyzer->buildKeywordMapForArticle($keywordCluster);
$secondaryCount = count($keywordCluster['secondary'] ?? []);
$longTailCount = count($keywordCluster['long_tail'] ?? []);
echo "✓ Secondary: {$secondaryCount}, Long tail: {$longTailCount}\n";

// STEP 3: Build prompt (use template or default)
$template = db()->fetchOne("
    SELECT * FROM prompt_templates
    WHERE is_active = 1
    ORDER BY is_default DESC LIMIT 1
");

$defaultPrompt = getDefaultPrompts()['article'];

$promptContent = $template ? $template['prompt_content'] : $defaultPrompt;

$articlePrompt = str_replace(
    ['{keyword}', '{topic}', '{min_words}', '{max_words}', '{site_name}', '{current_date}', '{current_year}'],
    [$keyword['keyword'], $site['main_topic_th'] ?? $site['main_topic'], getSetting('min_word_count', DEFAULT_ARTICLE_MIN_WORDS), getSetting('max_word_count', DEFAULT_ARTICLE_MAX_WORDS), $site['name'], date('Y-m-d'), date('Y')],
    $promptContent
);

$articlePrompt .= "\n\n" . $keywordMap;

// Language instruction
$articlePrompt = "## ⚠️ IMPORTANT: เขียนเป็นภาษาไทยทั้งหมด\n\n" . $articlePrompt;

// Tone
$contentType = $contentPlan['content_type'] ?? 'review';
$topic = $keyword['topic'] ?? 'slots';
$toneController = new ToneController();
$toneRec = $toneController->recommendTone($contentType, $topic);
$toneController->setTone($toneRec['tone'])->setStyle($toneRec['style'])->setFormality($toneRec['formality']);
$articleCount = db()->fetchColumn("SELECT COUNT(*) FROM articles WHERE site_id = ?", [$siteId]);
$tonePrompt = $toneController->buildCompletePrompt([
    'variation_index' => $articleCount % 5,
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

echo "📝 Prompt: " . mb_strlen($articlePrompt) . " chars\n";
echo "🎨 Tone: {$toneRec['tone']}\n\n";

// STEP 4: Generate
echo "🤖 Generating article with Claude Sonnet 4.5...\n";
$start = microtime(true);
$result = $ai->generate($articlePrompt, ['language' => $siteLanguage]);
$genTime = round(microtime(true) - $start, 2);
echo "✓ Generated in {$genTime}s | Tokens: {$result['tokens_used']}\n\n";

$content = $result['content'];

// Extract META_DESCRIPTION from AI output
$metaDescription = '';
if (preg_match('/<!--\s*META_DESCRIPTION:\s*(.+?)\s*-->/', $content, $metaMatch)) {
    $metaDescription = trim($metaMatch[1]);
    $metaDescription = mb_substr($metaDescription, 0, 160);
    $content = str_replace($metaMatch[0], '', $content);
}

// Extract title
preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $titleMatch);
if (!$titleMatch) {
    preg_match('/^#\s*(.+)$/m', $content, $titleMatch);
}
$title = strip_tags($titleMatch[1] ?? "บทความเกี่ยวกับ {$keyword['keyword']}");

// Clean markdown code blocks and convert Markdown to HTML
$content = preg_replace('/^```html\s*/im', '', $content);
$content = preg_replace('/^```\s*$/m', '', $content);
$content = convertMarkdownToHtml($content);
$content = trim($content);

// STEP 5: Internal Links
echo "🔗 Building Internal Links...\n";
$linkBuilder = new InternalLinkBuilder();
$linkResult = $linkBuilder->buildInternalLinks($content, $siteId, $keyword['keyword']);
$content = $linkResult['content'];
echo "✓ Added {$linkResult['links_added']} internal links\n\n";

// Stats
$wordCount = countWords($content);
$hasTable = preg_match('/<table/i', $content) ? 'YES' : 'NO';
$hasFAQ = preg_match('/คำถามที่พบบ่อย|FAQ/i', $content) ? 'YES' : 'NO';
$strongCount = preg_match_all('/<strong>/i', $content);
preg_match_all('/<h2/i', $content, $h2m);
preg_match_all('/<h3/i', $content, $h3m);
$h2Count = count($h2m[0]);
$h3Count = count($h3m[0]);

echo "=== ARTICLE STATS ===\n";
echo "Title: {$title} (" . mb_strlen($title) . " chars)\n";
echo "Meta Desc: " . ($metaDescription ?: '(auto)') . " (" . mb_strlen($metaDescription ?: '') . " chars)\n";
echo "Word count: {$wordCount}\n";
echo "Has table: {$hasTable}\n";
echo "Has FAQ: {$hasFAQ}\n";
echo "Strong tags: {$strongCount}\n";
echo "H2 count: {$h2Count}\n";
echo "H3 count: {$h3Count}\n";

// Save to DB
$slug = $keyword['keyword'];
$slug = preg_replace('/[\s\x{00A0}]+/u', '-', $slug);
$slug = preg_replace('/[^\p{L}\p{N}\p{M}\-]+/u', '', $slug);
$slug = preg_replace('/-+/', '-', $slug);
$slug = trim($slug, '-');

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
    'word_count' => $wordCount,
    'ai_provider' => $result['provider'],
    'ai_model' => $result['model'],
    'ai_tokens_used' => $result['tokens_used'],
    'generation_time' => $genTime,
    'status' => 'generated',
    'quality_score' => null
]);

// Update Content Plan status if used
if ($contentPlan) {
    db()->update('content_plan', [
        'status' => 'generated',
        'article_id' => $articleId
    ], 'id = ?', [$contentPlan['id']]);
}

// Save used keywords and deactivate similar ones
$deactivated = saveAndDeactivateUsedKeywords($articleId, $siteId, $keyword['keyword'], $keywordCluster);
echo "🧹 Saved " . (1 + count($keywordCluster['secondary'] ?? []) + count($keywordCluster['long_tail'] ?? [])) . " keywords to article_keywords\n";
echo "🧹 Deactivated {$deactivated} similar keywords from pool\n";

echo "\n=== SAVED ===\n";
echo "Article ID: {$articleId}\n";
echo "Status: generated (not published)\n";

echo "\n=== PREVIEW (first 1000 chars) ===\n";
echo mb_substr(strip_tags($content), 0, 1000) . "...\n";
