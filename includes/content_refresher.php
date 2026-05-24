<?php
/**
 * AI AutoPost SEO System - Content Refresher
 * ==========================================
 * Updates and improves old articles
 * Keeps content fresh and relevant
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';
require_once __DIR__ . '/wordpress_client.php';
require_once __DIR__ . '/risk_filter.php';

class ContentRefresher {
    private $ai;

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * Find articles that need refreshing
     */
    public function findStaleArticles(int $siteId, int $daysOld = 60, int $limit = 10): array {
        return db()->fetchAll("
            SELECT a.*,
                   DATEDIFF(NOW(), a.published_at) as days_since_published,
                   DATEDIFF(NOW(), COALESCE(a.updated_at, a.published_at)) as days_since_updated
            FROM articles a
            WHERE a.site_id = ?
              AND a.status = 'published'
              AND a.published_at < DATE_SUB(NOW(), INTERVAL ? DAY)
              AND (a.updated_at IS NULL OR a.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY))
            ORDER BY a.published_at ASC
            LIMIT ?
        ", [$siteId, $daysOld, $daysOld / 2, $limit]);
    }

    /**
     * Refresh an article
     */
    public function refresh(int $articleId): array {
        $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        // Get site info
        $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$article['site_id']]);

        // Get related articles for internal linking
        $relatedArticles = db()->fetchAll("
            SELECT id, title, post_url, primary_keyword
            FROM articles
            WHERE site_id = ? AND id != ? AND status = 'published' AND post_url IS NOT NULL
            ORDER BY published_at DESC
            LIMIT 10
        ", [$article['site_id'], $articleId]);

        // Build refresh prompt
        $prompt = $this->buildRefreshPrompt($article, $relatedArticles);

        // Generate refreshed content
        $result = $this->ai->execute('content_refresh', $prompt, ['max_tokens' => 4000]);

        if (empty($result['content'])) {
            return ['success' => false, 'message' => 'AI failed to refresh content'];
        }

        // Clean content
        $newContent = $result['content'];
        $newContent = preg_replace('/^```html\s*/im', '', $newContent);
        $newContent = preg_replace('/^```\s*/m', '', $newContent);
        $newContent = convertMarkdownToHtml($newContent);
        $newContent = trim($newContent);

        // Run through risk filter
        $riskFilter = new RiskFilter();
        $filtered = $riskFilter->filterAndFix($newContent, $article['topic']);
        $newContent = $filtered['content'];

        // Calculate new word count
        $textOnly = strip_tags($newContent);
        $thaiChars = mb_strlen(preg_replace('/[^\x{0E00}-\x{0E7F}]/u', '', $textOnly));
        $wordCount = str_word_count($textOnly) + ($thaiChars / 2);

        // Update in WordPress if connected
        $wpUpdated = false;
        if ($article['wp_post_id'] && $site) {
            try {
                $wpClient = new WordPressClient($site);
                $updateResult = $wpClient->updatePost($article['wp_post_id'], [
                    'content' => $newContent
                ]);
                $wpUpdated = $updateResult['success'];
            } catch (Exception $e) {
                // Log but continue
            }
        }

        // Update in database
        db()->update('articles', [
            'content' => $newContent,
            'word_count' => (int)$wordCount,
            'updated_at' => date('Y-m-d H:i:s'),
            'has_responsible_gaming' => 1
        ], 'id = ?', [$articleId]);

        return [
            'success' => true,
            'message' => 'Article refreshed successfully',
            'word_count' => (int)$wordCount,
            'wp_updated' => $wpUpdated,
            'risk_filtered' => $filtered['modified']
        ];
    }

    /**
     * Build refresh prompt
     */
    private function buildRefreshPrompt(array $article, array $relatedArticles): string {
        $relatedLinks = '';
        foreach ($relatedArticles as $related) {
            $relatedLinks .= "- {$related['title']} ({$related['post_url']}) - Keyword: {$related['primary_keyword']}\n";
        }

        $currentYear = date('Y');

        $customPrompt = getPromptTemplate('content_refresh', '');
        if ($customPrompt) {
            return str_replace(
                ['{title}', '{keyword}', '{topic}', '{content}', '{year}', '{related_links}'],
                [$article['title'], $article['primary_keyword'], $article['topic'], $article['content'], $currentYear, $relatedLinks],
                $customPrompt
            );
        }

        return <<<PROMPT
คุณเป็น SEO Content Editor ปรับปรุงบทความเก่าให้ทันสมัยและมีคุณค่ามากขึ้น

## บทความเดิม
**หัวข้อ:** {$article['title']}
**Keyword:** {$article['primary_keyword']}
**หมวด:** {$article['topic']}

**เนื้อหา:**
{$article['content']}

**บทความที่เกี่ยวข้อง (สำหรับ Internal Link):**
{$relatedLinks}

## สิ่งที่ต้องทำ
1. อัปเดตข้อมูลให้เป็นปี {$currentYear} — แก้ปีเก่า อัปเดตข้อมูลที่ล้าสมัย
2. เพิ่มเนื้อหาส่วนที่บาง — ขยายให้ลึกขึ้น มีรายละเอียดมากขึ้น
3. เพิ่ม FAQ ใหม่ 2-3 ข้อ ที่ยังไม่มี
4. เสริม EEAT — เพิ่มสัญญาณประสบการณ์/ความเชี่ยวชาญ
5. ใส่ Internal Link — เชื่อมโยงไปบทความที่เกี่ยวข้องด้วย <a href="URL">anchor text</a>
6. ตรวจสอบว่าเนื้อหาครบถ้วนและเป็นประโยชน์ต่อผู้อ่าน — เพิ่มในส่วนที่ยังขาด

## กฎ
- รักษา keyword หลักเดิม
- ใช้ keyword เป็นธรรมชาติ
- เนื้อหารวมต้องไม่ต่ำกว่า 1,500 คำ
- ใช้ HTML (H2, H3, p, ul, li) เท่านั้น ห้ามใช้ Markdown (##, ---, **, *) เด็ดขาด
- เขียนภาษาไทยเป็นธรรมชาติ เหมือนคนจริงเขียน

ส่งกลับเฉพาะ HTML ของบทความที่ปรับปรุงแล้ว ห้ามมีคำอธิบายอื่น ห้ามครอบ code block
PROMPT;
    }

    /**
     * Quick refresh - just add internal links
     */
    public function quickRefresh(int $articleId): array {
        $article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);

        if (!$article) {
            return ['success' => false, 'message' => 'Article not found'];
        }

        $content = $article['content'];
        $changes = [];

        // Add internal links
        $relatedArticles = db()->fetchAll("
            SELECT title, post_url, primary_keyword
            FROM articles
            WHERE site_id = ? AND id != ? AND status = 'published' AND post_url IS NOT NULL
            ORDER BY published_at DESC
            LIMIT 10
        ", [$article['site_id'], $articleId]);

        $linksAdded = 0;
        foreach ($relatedArticles as $related) {
            $anchor = $related['primary_keyword'] ?: $related['title'];
            if (mb_stripos($content, $anchor) !== false && $linksAdded < 3) {
                $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?![^<]*<\/a>)/iu';
                $replacement = '<a href="' . htmlspecialchars($related['post_url']) . '">$1</a>';
                $content = preg_replace($pattern, $replacement, $content, 1, $count);
                if ($count > 0) {
                    $linksAdded++;
                }
            }
        }

        if ($linksAdded > 0) {
            $changes[] = "Added {$linksAdded} internal links";
        }

        if (empty($changes)) {
            return ['success' => true, 'message' => 'No changes needed', 'changes' => []];
        }

        // Update database
        db()->update('articles', [
            'content' => $content,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$articleId]);

        // Update WordPress
        $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$article['site_id']]);
        if ($article['wp_post_id'] && $site) {
            try {
                $wpClient = new WordPressClient($site);
                $wpClient->updatePost($article['wp_post_id'], ['content' => $content]);
            } catch (Exception $e) {
                // Log but continue
            }
        }

        return [
            'success' => true,
            'message' => 'Quick refresh completed',
            'changes' => $changes
        ];
    }
}

/**
 * Helper function
 */
function contentRefresher(): ContentRefresher {
    return new ContentRefresher();
}
