<?php
/**
 * AI AutoPost SEO System - Sync WordPress Posts
 * ==============================================
 * ดึงบทความที่เผยแพร่แล้วจาก WordPress เข้ามาเก็บในระบบ
 * เพื่อใช้ทำ Internal Link กับบทความที่ลูกค้าเขียนเองหรือมาจากระบบอื่น
 *
 * Schedule: 0 2 * * * (Every day at 2am)
 * Usage: php job_sync_wp_posts.php [--site-id=N]
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wordpress_client.php';

echo "=== AI AutoPost SEO - Sync WordPress Posts ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Parse CLI arguments
$siteIdFilter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--site-id=')) {
        $siteIdFilter = (int) str_replace('--site-id=', '', $arg);
    }
}

try {
    // Get active sites
    $where = "posting_enabled = 1";
    $params = [];
    if ($siteIdFilter) {
        $where .= " AND id = ?";
        $params[] = $siteIdFilter;
    }

    $sites = db()->fetchAll("SELECT * FROM sites WHERE {$where}", $params);

    if (empty($sites)) {
        echo "No active sites found.\n";
        exit(0);
    }

    $totalSynced = 0;
    $totalSkipped = 0;

    foreach ($sites as $site) {
        echo "--- Site: {$site['name']} (ID: {$site['id']}) ---\n";

        try {
            $wp = new WordPressClient($site);

            // Test connection first
            if (!$wp->testConnection()) {
                echo "  ❌ Cannot connect to WordPress. Skipping.\n\n";
                continue;
            }

            // Get existing wp_post_ids in our system for this site
            $existingIds = db()->fetchAll(
                "SELECT wp_post_id FROM articles WHERE site_id = ? AND wp_post_id IS NOT NULL",
                [$site['id']]
            );
            $existingMap = array_flip(array_column($existingIds, 'wp_post_id'));

            // Fetch all published posts from WordPress (paginated)
            $page = 1;
            $perPage = 50;
            $siteSynced = 0;
            $siteSkipped = 0;

            while (true) {
                $posts = $wp->getPosts([
                    'per_page' => $perPage,
                    'page' => $page,
                    'status' => 'publish',
                    'orderby' => 'date',
                    'order' => 'desc'
                ]);

                // Empty or error = done
                if (empty($posts) || isset($posts['code'])) {
                    break;
                }

                foreach ($posts as $post) {
                    $wpPostId = $post['id'] ?? null;
                    if (!$wpPostId) continue;

                    // Skip if already exists
                    if (isset($existingMap[$wpPostId])) {
                        $siteSkipped++;
                        continue;
                    }

                    // Extract data from WordPress post
                    $title = strip_tags($post['title']['rendered'] ?? '');
                    $content = $post['content']['rendered'] ?? '';
                    $excerpt = strip_tags($post['excerpt']['rendered'] ?? '');
                    $slug = $post['slug'] ?? '';
                    $postUrl = $post['link'] ?? '';
                    $publishedAt = $post['date'] ?? null;
                    $wordCount = countWords($content);

                    // Try to extract primary keyword from Yoast/RankMath meta
                    $primaryKeyword = '';
                    $metaDescription = '';

                    // Yoast SEO
                    if (!empty($post['yoast_head_json']['focuskw'])) {
                        $primaryKeyword = $post['yoast_head_json']['focuskw'];
                    }
                    if (!empty($post['yoast_head_json']['description'])) {
                        $metaDescription = $post['yoast_head_json']['description'];
                    }

                    // RankMath
                    if (empty($primaryKeyword) && !empty($post['rank_math_focus_keyword'])) {
                        $primaryKeyword = $post['rank_math_focus_keyword'];
                    }
                    if (empty($metaDescription) && !empty($post['rank_math_description'])) {
                        $metaDescription = $post['rank_math_description'];
                    }

                    // Fallback: use title as keyword
                    if (empty($primaryKeyword)) {
                        $primaryKeyword = $title;
                    }

                    // Get featured image URL from WordPress media endpoint
                    $featuredImageUrl = null;
                    $featuredImageId = null;
                    $wpMediaId = $post['featured_media'] ?? 0;
                    if ($wpMediaId) {
                        $featuredImageId = $wpMediaId;
                        $mediaUrl = rtrim($site['wp_api_url'], '/') . '/media/' . $wpMediaId;
                        $ch = curl_init($mediaUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        $mediaResponse = json_decode(curl_exec($ch), true);
                        curl_close($ch);
                        $featuredImageUrl = $mediaResponse['source_url'] ?? null;
                    }

                    // Insert into articles table
                    // Use published_at as created_at so Dashboard stats show correct dates
                    db()->insert('articles', [
                        'site_id' => $site['id'],
                        'wp_post_id' => $wpPostId,
                        'title' => $title,
                        'slug' => $slug,
                        'content' => $content,
                        'excerpt' => truncateText($excerpt, 200),
                        'meta_description' => truncateText($metaDescription, 500),
                        'primary_keyword' => $primaryKeyword,
                        'topic' => $site['main_topic'] ?? 'general',
                        'language_code' => $site['language_code'] ?? 'th',
                        'word_count' => $wordCount,
                        'status' => 'published',
                        'post_url' => $postUrl,
                        'published_at' => $publishedAt,
                        'created_at' => $publishedAt,
                        'featured_image_url' => $featuredImageUrl,
                        'featured_image_id' => $featuredImageId,
                        'ai_provider' => 'wordpress_sync',
                        'ai_model' => 'imported',
                    ]);

                    $existingMap[$wpPostId] = true;
                    $siteSynced++;
                }

                // If fewer results than per_page, we've reached the last page
                if (count($posts) < $perPage) {
                    break;
                }

                $page++;
            }

            echo "  ✅ Synced: {$siteSynced} new | Skipped: {$siteSkipped} existing\n\n";
            $totalSynced += $siteSynced;
            $totalSkipped += $siteSkipped;

            logEvent('info', 'sync', "WordPress sync completed for site {$site['name']}", [
                'site_id' => $site['id'],
                'synced' => $siteSynced,
                'skipped' => $siteSkipped
            ], $site['id']);

        } catch (Exception $e) {
            echo "  ❌ Error: {$e->getMessage()}\n\n";
            logEvent('error', 'sync', "WordPress sync failed for site {$site['name']}: {$e->getMessage()}", [
                'site_id' => $site['id']
            ], $site['id']);
        }
    }

    echo "=== Summary ===\n";
    echo "Total synced: {$totalSynced}\n";
    echo "Total skipped: {$totalSkipped}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "Fatal error: {$e->getMessage()}\n";
    logEvent('error', 'sync', 'WordPress sync job failed: ' . $e->getMessage());
    exit(1);
}
