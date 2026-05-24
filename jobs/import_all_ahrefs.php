<?php
/**
 * Import ALL Ahrefs keywords from CSV files
 * No volume/difficulty filters - import everything
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

define('ALLOW_WEB_ACCESS', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Map filename pattern to topic
$topicMap = [
    
    
    'general' => 'general',
    
    ,
];

$csvDir = __DIR__ . '/../ahrefskeywords';
$files = glob($csvDir . '/*.csv');

if (empty($files)) {
    die("No CSV files found in {$csvDir}\n");
}

$totalImported = 0;
$totalSkipped = 0;
$totalFiles = count($files);

echo "=== Import ALL Ahrefs Keywords ===\n";
echo "Files: {$totalFiles}\n\n";

foreach ($files as $file) {
    $filename = basename($file);
    echo "📄 {$filename}\n";

    // Detect topic from filename
    $topic = 'general';
    foreach ($topicMap as $keyword => $topicName) {
        if (strpos($filename, $keyword) !== false) {
            $topic = $topicName;
            break;
        }
    }
    echo "   Topic: {$topic}\n";

    // Read and convert from UTF-16LE to UTF-8
    $raw = file_get_contents($file);
    $content = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');

    // Remove BOM
    $content = preg_replace('/^\x{FEFF}/u', '', $content);

    $lines = explode("\n", $content);
    $header = null;
    $imported = 0;
    $skipped = 0;

    // Start transaction for batch insert
    db()->beginTransaction();

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Parse TSV with quotes
        $fields = str_getcsv($line, "\t", '"');

        if ($header === null) {
            // Map header names to indices
            $header = [];
            foreach ($fields as $i => $col) {
                $header[trim($col)] = $i;
            }
            continue;
        }

        if (count($fields) < 5) continue;

        // Use header column names to find correct indices
        $kwIdx = $header['Keyword'] ?? 1;
        $diffIdx = $header['Difficulty'] ?? ($header['KD'] ?? 3);
        $volIdx = $header['Volume'] ?? 4;
        $cpcIdx = $header['CPC'] ?? 5;
        $tpIdx = $header['Traffic potential'] ?? 11;
        $intentIdx = $header['Intents'] ?? 14;

        $keyword = trim($fields[$kwIdx] ?? '');
        $difficulty = (int)($fields[$diffIdx] ?? 0);
        $volume = (int)($fields[$volIdx] ?? 0);
        $cpc = (float)($fields[$cpcIdx] ?? 0);
        $trafficPotential = (int)($fields[$tpIdx] ?? 0);
        $intents = trim($fields[$intentIdx] ?? '');

        // Skip empty keywords
        if (empty($keyword)) continue;

        // Skip keywords that are URLs or contain domain names
        if (preg_match('/\.(com|net|org|co|io|th|me|lat|xyz|cc|asia)\b/i', $keyword)) {
            $skipped++;
            continue;
        }

        // Skip keywords with http/www
        if (preg_match('/https?:|www\./i', $keyword)) {
            $skipped++;
            continue;
        }

        // Skip very long keywords (likely spam)
        if (mb_strlen($keyword) > 200) {
            $skipped++;
            continue;
        }

        // Determine search intent
        $searchIntent = 'informational';
        if (stripos($intents, 'Transactional') !== false) {
            $searchIntent = 'transactional';
        } elseif (stripos($intents, 'Commercial') !== false) {
            $searchIntent = 'commercial';
        } elseif (stripos($intents, 'Navigational') !== false) {
            $searchIntent = 'navigational';
        }

        // Check duplicate
        $exists = db()->fetchColumn("SELECT id FROM keywords WHERE keyword = ? AND topic = ?", [$keyword, $topic]);
        if ($exists) {
            $skipped++;
            continue;
        }

        // Insert
        try {
            db()->insert('keywords', [
                'keyword' => $keyword,
                'topic' => $topic,
                'language_code' => 'th',
                'search_volume' => $volume,
                'difficulty' => $difficulty,
                'traffic_potential' => $trafficPotential,
                'search_intent' => $searchIntent,
                'cpc' => $cpc,
                'data_source' => 'ahrefs',
                'is_active' => 1,
                'usage_count' => 0,
                'priority' => 10,
            ]);
            $imported++;
        } catch (Exception $e) {
            $skipped++;
        }

        // Commit every 1000 rows
        if ($imported % 1000 === 0 && $imported > 0) {
            db()->commit();
            db()->beginTransaction();
            echo "   ... {$imported} imported\n";
        }
    }

    db()->commit();

    echo "   ✅ Imported: {$imported} | Skipped: {$skipped}\n\n";
    $totalImported += $imported;
    $totalSkipped += $skipped;
}

echo "=== DONE ===\n";
echo "Total imported: {$totalImported}\n";
echo "Total skipped: {$totalSkipped}\n";

// Show stats
$stats = db()->fetchAll("SELECT topic, COUNT(*) as cnt FROM keywords GROUP BY topic ORDER BY cnt DESC");
echo "\nKeywords by topic:\n";
foreach ($stats as $s) {
    echo "  {$s['topic']}: {$s['cnt']}\n";
}
