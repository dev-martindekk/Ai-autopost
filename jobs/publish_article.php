<?php
/**
 * Publish article to WordPress
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('ALLOW_WEB_ACCESS', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wordpress_client.php';

$articleId = (int)($argv[1] ?? 0);
if (!$articleId) {
    die("Usage: php publish_article.php <article_id> [site_id]\n");
}
$siteId = (int)($argv[2] ?? 1);

// Get article
$article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);
if (!$article) {
    die("Article not found!\n");
}

echo "=== Publish Article to WordPress ===\n";
echo "Article: {$article['title']}\n";
echo "Word count: {$article['word_count']}\n\n";

// Get site
$site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
if (!$site) {
    die("Site not found!\n");
}

echo "Site: {$site['name']}\n";
echo "URL: {$site['base_url']}\n";
echo "API: {$site['wp_api_url']}\n\n";

// Create WordPress client
$wp = new WordPressClient($site);

// Test connection first
echo "Testing connection...\n";
$testResult = $wp->testConnection();
if (!$testResult['success']) {
    die("Connection failed: {$testResult['message']}\n");
}
echo "Connected: {$testResult['site_name']} (User: {$testResult['user_name']})\n\n";

// Publish article
echo "Publishing article...\n";
$result = $wp->createPost([
    'title' => $article['title'],
    'content' => appendFaqSchema($article['content']),
    'status' => 'publish',
    'slug' => $article['slug'],
    'excerpt' => $article['excerpt'] ?? '',
    'meta_title' => $article['meta_title'] ?? '',
    'meta_description' => $article['meta_description'] ?? '',
    'focus_keyword' => $article['primary_keyword'] ?? ''
]);

if ($result['success']) {
    echo "Published!\n";
    echo "Post ID: {$result['post_id']}\n";
    echo "URL: {$result['post_url']}\n";

    // Update article status in DB + clear MDES blocked flag on success
    db()->query("UPDATE articles SET status = 'published', wp_post_id = ?, post_url = ?, published_at = NOW() WHERE id = ?", [
        $result['post_id'],
        $result['post_url'],
        $articleId
    ]);
    db()->query("UPDATE sites SET last_test_status = 'success', last_test_time = NOW(), is_mdes_blocked = 0 WHERE id = ?", [$article['site_id']]);
    echo "\nArticle status updated to 'published'\n";

    // Generate Featured Image with DALL-E 3
    if ($site['featured_image_enabled']) {
        require_once __DIR__ . '/../includes/image_generator.php';

        echo "\nGenerating Featured Image with DALL-E 3...\n";
        try {
            $gen = new ImageGenerator();
            $imgResult = $gen->generateForArticle(
                $article['title'],
                $site['main_topic'] ?? 'casino',
                $article['primary_keyword'] ?? ''
            );

            if ($imgResult['success']) {
                echo "Image generated! Uploading to WordPress...\n";
                $uploadResult = $wp->uploadMediaFromUrl($imgResult['image_url'], $article['primary_keyword'] ?? $article['title']);

                if ($uploadResult['success']) {
                    $mediaId = $uploadResult['media_id'];
                    $wp->updatePost($result['post_id'], ['featured_media' => $mediaId]);
                    echo "Featured image set! (Media ID: {$mediaId})\n";

                    // Save to DB
                    db()->query("UPDATE articles SET featured_image_url = ? WHERE id = ?", [
                        $uploadResult['source_url'] ?? $imgResult['image_url'],
                        $articleId
                    ]);
                } else {
                    echo "Upload failed: {$uploadResult['message']}\n";
                }
            } else {
                echo "Image generation failed: {$imgResult['message']}\n";
            }
        } catch (Exception $e) {
            echo "Featured image error: {$e->getMessage()}\n";
        }
    }
} else {
    echo "Failed: {$result['message']}\n";
}
