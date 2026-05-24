<?php
/**
 * Generate Featured Images for published articles that don't have one
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(600);

define('ALLOW_WEB_ACCESS', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/image_generator.php';
require_once __DIR__ . '/../includes/wordpress_client.php';

// Accept article IDs as args, or process all published without featured image
$articleIds = [];
if (!empty($argv[1])) {
    $articleIds = array_map('intval', explode(',', $argv[1]));
}

$siteId = (int)($argv[2] ?? 1);
$site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
if (!$site) {
    die("Site not found!\n");
}

$wp = new WordPressClient($site);
$gen = new ImageGenerator();

if (!empty($articleIds)) {
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $articles = db()->fetchAll("SELECT * FROM articles WHERE id IN ({$placeholders})", $articleIds);
} else {
    $articles = db()->fetchAll("SELECT * FROM articles WHERE site_id = ? AND status = 'published' AND (featured_image_url IS NULL OR featured_image_url = '') ORDER BY id", [$siteId]);
}

echo "=== Generate Featured Images ===\n";
echo "Articles to process: " . count($articles) . "\n\n";

$success = 0;
$failed = 0;

foreach ($articles as $article) {
    echo "--- [{$article['id']}] {$article['title']} ---\n";
    echo "WP Post: {$article['wp_post_id']} | Keyword: {$article['primary_keyword']}\n";

    // Generate image
    echo "Generating with DALL-E 3...\n";
    $imgResult = $gen->generateForArticle($article['title'], $site['main_topic'] ?? 'casino', $article['primary_keyword'] ?? '');

    if (!$imgResult['success']) {
        echo "FAILED: {$imgResult['message']}\n\n";
        $failed++;
        continue;
    }
    echo "Generated!\n";

    // Upload to WordPress
    echo "Uploading to WordPress...\n";
    $upload = $wp->uploadMediaFromUrl($imgResult['image_url'], $article['primary_keyword'] ?? $article['title']);

    if (!$upload['success']) {
        echo "Upload FAILED: {$upload['message']}\n\n";
        $failed++;
        continue;
    }
    echo "Uploaded! Media ID: {$upload['media_id']}\n";

    // Set as featured image
    if ($article['wp_post_id']) {
        $wp->updatePost($article['wp_post_id'], ['featured_media' => $upload['media_id']]);
        echo "Featured image set!\n";
    }

    // Save to DB
    $imgUrl = $upload['source_url'] ?? $imgResult['image_url'];
    db()->query("UPDATE articles SET featured_image_url = ? WHERE id = ?", [$imgUrl, $article['id']]);

    echo "DONE!\n\n";
    $success++;
}

echo "=== Complete ===\n";
echo "Success: {$success} | Failed: {$failed}\n";
