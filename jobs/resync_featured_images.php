<?php
/**
 * Re-sync featured images from WordPress for existing synced articles
 * One-time script to update articles that were synced without featured_image_url
 *
 * Usage: php resync_featured_images.php [--site-id=N]
 */

if (php_sapi_name() !== 'cli' && !defined('ALLOW_WEB_ACCESS')) {
    die('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wordpress_client.php';

echo "=== Re-sync Featured Images from WordPress ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Parse CLI arguments
$siteIdFilter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--site-id=')) {
        $siteIdFilter = (int) str_replace('--site-id=', '', $arg);
    }
}

try {
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

    $totalUpdated = 0;

    foreach ($sites as $site) {
        echo "--- Site: {$site['name']} (ID: {$site['id']}) ---\n";

        // Get articles that have wp_post_id but no featured_image_url
        $articles = db()->fetchAll(
            "SELECT id, wp_post_id, title FROM articles
             WHERE site_id = ? AND wp_post_id IS NOT NULL
             AND (featured_image_url IS NULL OR featured_image_url = '')",
            [$site['id']]
        );

        echo "  Articles without featured image: " . count($articles) . "\n";

        if (empty($articles)) {
            echo "  Nothing to update.\n\n";
            continue;
        }

        $apiUrl = rtrim($site['wp_api_url'], '/');
        $siteUpdated = 0;

        foreach ($articles as $article) {
            // Get the post from WordPress to find featured_media ID
            $postUrl = $apiUrl . '/posts/' . $article['wp_post_id'] . '?_fields=id,featured_media';
            $ch = curl_init($postUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $postResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $wpMediaId = $postResponse['featured_media'] ?? 0;
            if (!$wpMediaId) {
                continue;
            }

            // Get media URL
            $mediaUrl = $apiUrl . '/media/' . $wpMediaId . '?_fields=id,source_url';
            $ch = curl_init($mediaUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $mediaResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $featuredImageUrl = $mediaResponse['source_url'] ?? null;
            if (!$featuredImageUrl) {
                continue;
            }

            // Update article
            db()->query(
                "UPDATE articles SET featured_image_url = ?, featured_image_id = ? WHERE id = ?",
                [$featuredImageUrl, $wpMediaId, $article['id']]
            );

            echo "  ✅ [{$article['id']}] {$article['title']} => {$featuredImageUrl}\n";
            $siteUpdated++;
        }

        echo "  Updated: {$siteUpdated}\n\n";
        $totalUpdated += $siteUpdated;
    }

    echo "=== Total updated: {$totalUpdated} ===\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "Fatal error: {$e->getMessage()}\n";
    exit(1);
}
