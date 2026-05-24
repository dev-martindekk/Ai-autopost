#!/usr/bin/env php
<?php
/**
 * AI AutoPost SEO System - Ahrefs Keywords Import Script
 * ======================================================
 * Import keywords from Ahrefs CSV exports
 *
 * Usage: php import_ahrefs.php [directory] [options]
 * Options:
 *   --min-volume=N    Minimum search volume (default: 100)
 *   --max-difficulty=N Maximum keyword difficulty (default: 80)
 *   --limit=N         Max keywords per file (default: 0 = no limit)
 *   --skip-branded    Skip branded keywords (default: true)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ahrefs_importer.php';

// Parse CLI arguments
$options = getopt('', ['min-volume::', 'max-difficulty::', 'limit::', 'skip-branded::']);
$directory = $argv[1] ?? '/root/ai-autopost-seo/ahrefskeywords';

$importOptions = [
    'min_volume' => isset($options['min-volume']) ? (int)$options['min-volume'] : 100,
    'max_difficulty' => isset($options['max-difficulty']) ? (int)$options['max-difficulty'] : 80,
    'limit' => isset($options['limit']) ? (int)$options['limit'] : 0,
    'skip_branded' => !isset($options['skip-branded']) || $options['skip-branded'] !== 'false',
    'update_existing' => true
];

echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║     AI AutoPost SEO - Ahrefs Keywords Import              ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

echo "Directory: {$directory}\n";
echo "Options:\n";
echo "  - Min Volume: {$importOptions['min_volume']}\n";
echo "  - Max Difficulty: {$importOptions['max_difficulty']}\n";
echo "  - Limit per file: " . ($importOptions['limit'] ?: 'No limit') . "\n";
echo "  - Skip Branded: " . ($importOptions['skip_branded'] ? 'Yes' : 'No') . "\n\n";

if (!is_dir($directory)) {
    echo "❌ Error: Directory not found: {$directory}\n";
    exit(1);
}

// Topic mapping based on Thai keywords in filename
$topicMapping = [
    'general' => 'general',
    
    
    
    
];

// Get all CSV files
$files = glob($directory . '/*.csv');

if (empty($files)) {
    echo "❌ No CSV files found in {$directory}\n";
    exit(1);
}

echo "Found " . count($files) . " CSV files to import\n\n";
echo str_repeat("-", 60) . "\n";

$totalStats = [
    'files' => 0,
    'total' => 0,
    'imported' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => []
];

$importer = new AhrefsImporter();

foreach ($files as $file) {
    $filename = basename($file);

    // Detect topic from filename
    $topic = 'general';
    foreach ($topicMapping as $keyword => $topicName) {
        if (mb_strpos($filename, $keyword) !== false) {
            $topic = $topicName;
            break;
        }
    }

    echo "\n📁 Processing: {$filename}\n";
    echo "   Topic: {$topic}\n";

    $startTime = microtime(true);

    $result = $importer->importFromCSV($file, $topic, $importOptions);

    $duration = round(microtime(true) - $startTime, 2);

    if ($result['success']) {
        $stats = $result['stats'];
        echo "   ✅ {$result['message']} ({$duration}s)\n";

        $totalStats['files']++;
        $totalStats['total'] += $stats['total'];
        $totalStats['imported'] += $stats['imported'];
        $totalStats['updated'] += $stats['updated'];
        $totalStats['skipped'] += $stats['skipped'];

        if (!empty($stats['errors'])) {
            $totalStats['errors'] = array_merge($totalStats['errors'], $stats['errors']);
        }
    } else {
        echo "   ❌ Failed: {$result['message']}\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "📊 IMPORT SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Files processed: {$totalStats['files']}\n";
echo "Total rows:      {$totalStats['total']}\n";
echo "Imported:        {$totalStats['imported']}\n";
echo "Updated:         {$totalStats['updated']}\n";
echo "Skipped:         {$totalStats['skipped']}\n";

if (!empty($totalStats['errors'])) {
    echo "\n⚠️  Errors (" . count($totalStats['errors']) . "):\n";
    foreach (array_slice($totalStats['errors'], 0, 10) as $error) {
        echo "   - {$error}\n";
    }
    if (count($totalStats['errors']) > 10) {
        echo "   ... and " . (count($totalStats['errors']) - 10) . " more errors\n";
    }
}

// Show database stats
echo "\n📈 Database Stats:\n";
$keywordCount = db()->fetchColumn("SELECT COUNT(*) FROM keywords WHERE data_source = 'ahrefs'");
echo "   Total Ahrefs keywords in DB: {$keywordCount}\n";

$topicStats = db()->fetchAll("
    SELECT topic, COUNT(*) as count,
           ROUND(AVG(search_volume)) as avg_volume,
           ROUND(AVG(difficulty)) as avg_difficulty
    FROM keywords
    WHERE data_source = 'ahrefs'
    GROUP BY topic
    ORDER BY count DESC
");

if (!empty($topicStats)) {
    echo "\n   By Topic:\n";
    foreach ($topicStats as $stat) {
        echo "   - {$stat['topic']}: {$stat['count']} keywords (avg vol: {$stat['avg_volume']}, avg diff: {$stat['avg_difficulty']})\n";
    }
}

echo "\n✨ Import completed!\n";
