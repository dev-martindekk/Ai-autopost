<?php
/**
 * AI AutoPost SEO System - Internal Links Job
 * ============================================
 * Cron job to create internal links between articles
 *
 * Schedule: 0 * / 4 * * * (Every 4 hours)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wordpress_client.php';

echo "=== AI AutoPost SEO - Internal Links Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Get max links per article setting
    $maxLinksPerArticle = getSetting('internal_links_per_article', 1);

    // Get articles that need internal links
    $articles = db()->fetchAll("
        SELECT a.*, s.name as site_name, s.auto_internal_link
        FROM articles a
        JOIN sites s ON a.site_id = s.id
        WHERE a.status = 'published'
          AND a.internal_links_count < ?
          AND s.auto_internal_link = 1
        ORDER BY a.created_at DESC
        LIMIT 50
    ", [$maxLinksPerArticle]);

    if (empty($articles)) {
        echo "No articles need internal links\n";
        exit(0);
    }

    echo "Found " . count($articles) . " article(s) to process\n\n";

    $totalLinksCreated = 0;

    foreach ($articles as $article) {
        echo "Processing: {$article['title']}\n";

        // Find related articles from same site and topic
        $relatedArticles = db()->fetchAll("
            SELECT id, title, post_url, primary_keyword
            FROM articles
            WHERE site_id = ?
              AND id != ?
              AND status = 'published'
              AND post_url IS NOT NULL
            ORDER BY
                CASE WHEN topic = ? THEN 0 ELSE 1 END,
                created_at DESC
            LIMIT 10
        ", [$article['site_id'], $article['id'], $article['topic']]);

        if (empty($relatedArticles)) {
            echo "  No related articles found\n";
            continue;
        }

        // Check existing links from this article
        $existingLinks = db()->fetchAll("
            SELECT target_article_id FROM internal_links
            WHERE source_article_id = ?
        ", [$article['id']]);

        $existingTargetIds = array_column($existingLinks, 'target_article_id');

        // Find suitable links
        $linksToCreate = [];
        foreach ($relatedArticles as $related) {
            if (in_array($related['id'], $existingTargetIds)) {
                continue; // Already linked
            }

            // Check if keyword/title appears in content
            $keyword = $related['primary_keyword'] ?: $related['title'];
            if (stripos($article['content'], $keyword) !== false) {
                $linksToCreate[] = [
                    'target' => $related,
                    'anchor' => $keyword
                ];

                if (count($linksToCreate) >= $maxLinksPerArticle - count($existingLinks)) {
                    break;
                }
            }
        }

        if (empty($linksToCreate)) {
            echo "  No suitable links found\n";
            continue;
        }

        echo "  Creating " . count($linksToCreate) . " link(s)\n";

        // Create links in content
        $updatedContent = $article['content'];

        foreach ($linksToCreate as $link) {
            $anchor = $link['anchor'];
            $url = $link['target']['post_url'];

            // Only replace first occurrence
            $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?!["\'])/iu';
            $replacement = '<a href="' . $url . '">$1</a>';

            $updatedContent = preg_replace($pattern, $replacement, $updatedContent, 1, $count);

            if ($count > 0) {
                // Save link record
                db()->insert('internal_links', [
                    'site_id' => $article['site_id'],
                    'source_article_id' => $article['id'],
                    'target_article_id' => $link['target']['id'],
                    'anchor_text' => $anchor,
                    'link_position' => 'body'
                ]);

                $totalLinksCreated++;
                echo "    - Linked: {$anchor} -> {$link['target']['title']}\n";
            }
        }

        // Update article content on WordPress
        if ($totalLinksCreated > 0) {
            try {
                $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$article['site_id']]);
                $wp = new WordPressClient($site);

                $updateResult = $wp->updatePost($article['wp_post_id'], [
                    'content' => $updatedContent
                ]);

                if ($updateResult['success']) {
                    // Update local article
                    db()->update('articles', [
                        'content' => $updatedContent,
                        'internal_links_count' => count($existingLinks) + count($linksToCreate)
                    ], 'id = ?', [$article['id']]);

                    echo "  Updated on WordPress\n";
                } else {
                    echo "  WordPress update failed: {$updateResult['message']}\n";
                }
            } catch (Exception $e) {
                echo "  Error updating WordPress: {$e->getMessage()}\n";
            }
        }

        echo "\n";

        // Small delay
        usleep(500000);
    }

} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    logEvent('error', 'job', 'Internal links job failed', ['error' => $e->getMessage()]);
}

echo "=== Job Complete ===\n";
echo "Total links created: {$totalLinksCreated}\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

logEvent('info', 'job', 'Internal links job completed', ['links_created' => $totalLinksCreated]);
