<?php
/**
 * Import Ahrefs Keyword CSV Files
 * ================================
 * Imports keywords from Ahrefs CSV exports (UTF-16 LE, tab-separated)
 * into the keywords table.
 *
 * Usage: php import_ahrefs_keywords.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// File → topic mapping
$files = [
    'google_th_แทงบอล_matching-terms_terms_2025-12-04_23-59-11.csv' => 'sports',
];

$baseDir = __DIR__ . '/../ahrefskeywords/';
$siteId = 1; // rose678

// Intent mapping from Ahrefs codes
$intentMap = [
    'I' => 'informational',
    'N' => 'navigational',
    'C' => 'commercial',
    'T' => 'transactional',
];

$totalImported = 0;
$totalSkipped = 0;
$totalErrors = 0;

foreach ($files as $filename => $topic) {
    $filepath = $baseDir . $filename;

    if (!file_exists($filepath)) {
        echo "⚠ File not found: {$filename}\n";
        continue;
    }

    echo "\n📂 Processing: {$filename} (topic: {$topic})\n";

    // Read and convert from UTF-16 LE to UTF-8
    $raw = file_get_contents($filepath);
    $content = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');

    // Remove BOM if present
    $content = preg_replace('/^\x{FEFF}/u', '', $content);

    $lines = explode("\n", $content);
    $header = null;
    $imported = 0;
    $skipped = 0;

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Parse tab-separated, quoted fields
        $fields = array_map(function($f) {
            return trim($f, " \t\r\n\"");
        }, explode("\t", $line));

        // Parse header
        if ($header === null) {
            $header = $fields;
            continue;
        }

        // Map fields by header
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $fields[$i] ?? '';
        }

        $keyword = trim($row['Keyword'] ?? '');
        if (empty($keyword)) continue;

        // Skip if keyword already exists for this site+topic
        $existing = db()->fetchColumn(
            "SELECT COUNT(*) FROM keywords WHERE keyword = ? AND site_id = ? AND topic = ?",
            [$keyword, $siteId, $topic]
        );

        if ($existing > 0) {
            $skipped++;
            continue;
        }

        // Parse search intent (Ahrefs uses comma-separated single letters)
        $intentRaw = trim($row['Intents'] ?? '');
        $intent = 'informational'; // default
        if (!empty($intentRaw)) {
            $firstIntent = strtoupper(substr(trim($intentRaw), 0, 1));
            $intent = $intentMap[$firstIntent] ?? 'informational';
        }

        // Parse volume and difficulty
        $volume = (int) str_replace(',', '', $row['Volume'] ?? '0');
        $difficulty = (int) ($row['Difficulty'] ?? '0');
        $cpc = (float) str_replace(',', '', $row['CPC'] ?? '0');
        $trafficPotential = (int) str_replace(',', '', $row['Traffic potential'] ?? '0');

        // Determine keyword type based on word count / length
        $wordCount = mb_substr_count($keyword, ' ') + 1;
        $keywordType = 'primary';
        if ($wordCount >= 4 || mb_strlen($keyword) > 30) {
            $keywordType = 'long_tail';
        } elseif ($wordCount >= 2) {
            $keywordType = 'secondary';
        }

        // Determine priority based on volume and difficulty
        $priority = 10; // default
        if ($volume >= 1000 && $difficulty <= 30) {
            $priority = 1; // high priority: high volume + low difficulty
        } elseif ($volume >= 500 && $difficulty <= 50) {
            $priority = 3;
        } elseif ($volume >= 100) {
            $priority = 5;
        } elseif ($volume >= 10) {
            $priority = 7;
        }

        try {
            db()->insert('keywords', [
                'site_id' => $siteId,
                'keyword' => $keyword,
                'keyword_type' => $keywordType,
                'topic' => $topic,
                'language_code' => 'th',
                'search_volume' => $volume,
                'difficulty' => $difficulty,
                'traffic_potential' => $trafficPotential,
                'search_intent' => $intent,
                'cpc' => $cpc,
                'data_source' => 'ahrefs',
                'last_updated' => date('Y-m-d H:i:s'),
                'priority' => $priority,
                'is_active' => 1,
            ]);
            $imported++;
        } catch (Exception $e) {
            $totalErrors++;
            if ($totalErrors <= 5) {
                echo "  ✗ Error importing '{$keyword}': {$e->getMessage()}\n";
            }
        }
    }

    echo "  ✓ Imported: {$imported} | Skipped (duplicates): {$skipped}\n";
    $totalImported += $imported;
    $totalSkipped += $skipped;
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "📊 Total imported: {$totalImported}\n";
echo "📊 Total skipped: {$totalSkipped}\n";
echo "📊 Total errors: {$totalErrors}\n";

// Show summary by topic
echo "\n📋 Keywords by topic:\n";
$summary = db()->fetchAll("SELECT topic, COUNT(*) as cnt, AVG(search_volume) as avg_vol FROM keywords WHERE site_id = ? GROUP BY topic ORDER BY cnt DESC", [$siteId]);
foreach ($summary as $row) {
    echo "  {$row['topic']}: {$row['cnt']} keywords (avg volume: " . round($row['avg_vol']) . ")\n";
}

echo "\nDone!\n";
