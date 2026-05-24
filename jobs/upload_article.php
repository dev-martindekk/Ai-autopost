<?php
/**
 * Upload a generated article to WordPress
 * Usage: php upload_article.php --id=521
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wordpress_client.php';

$articleId = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--id=') === 0) {
        $articleId = (int)substr($arg, 5);
    }
}

if (!$articleId) {
    echo "Usage: php upload_article.php --id=<article_id>\n";
    exit(1);
}

$article = db()->fetchOne("SELECT * FROM articles WHERE id = ?", [$articleId]);
if (!$article) {
    echo "Article not found: {$articleId}\n";
    exit(1);
}

$site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$article['site_id']]);
echo "Site: {$site['name']} ({$site['base_url']})\n";
echo "Title: {$article['title']}\n";
echo "Title chars: " . mb_strlen($article['title']) . "\n";
echo "Meta Title: {$article['meta_title']}\n";
echo "Meta Desc: {$article['meta_description']}\n";
echo "Desc chars: " . mb_strlen($article['meta_description']) . "\n";
echo "Words: {$article['word_count']}\n\n";

echo "Uploading to WordPress...\n";
$wp = new WordPressClient($site);
$result = $wp->createPost([
    'title' => $article['title'],
    'content' => appendFaqSchema($article['content']),
    'status' => 'publish',
    'slug' => $article['slug'],
    'excerpt' => $article['excerpt'],
    'meta' => [
        'yoast_wpseo_title' => $article['meta_title'],
        'yoast_wpseo_metadesc' => $article['meta_description'],
    ],
    'categories' => $site['default_category_id'] ? [$site['default_category_id']] : [],
    'author' => $site['default_author_id'] ?? 1,
    'featured_media' => $article['featured_image_id'] ?? 0,
]);

if ($result['success']) {
    $postUrl = $result['post_url'] ?? $result['url'] ?? '';
    echo "Published! Post ID: {$result['post_id']}\n";
    echo "URL: {$postUrl}\n";

    db()->update('articles', [
        'wp_post_id' => $result['post_id'],
        'post_url' => $postUrl,
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$articleId]);

    db()->query("UPDATE sites SET articles_posted_today = articles_posted_today + 1, total_articles_posted = total_articles_posted + 1, last_post_time = NOW() WHERE id = ?", [$site['id']]);

    echo "DB updated.\n";
} else {
    echo "FAILED: " . ($result['message'] ?? 'Unknown error') . "\n";
}
