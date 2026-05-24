<?php
/**
 * AI AutoPost SEO System - Ahrefs Keyword Importer
 * =================================================
 * Import keywords from Ahrefs CSV exports
 * Includes: Volume, Difficulty, Traffic Potential, Search Intent
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class AhrefsImporter {

    private $importStats = [
        'total' => 0,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => []
    ];

    /**
     * Import keywords from Ahrefs CSV file
     * @param string $filepath Path to CSV file
     * @param string $topic Topic category
     * @param array $options Import options
     */
    public function importFromCSV(string $filepath, string $topic, array $options = []): array {
        // Default options
        $options = array_merge([
            'min_volume' => 100,           // Minimum search volume
            'max_difficulty' => 80,         // Maximum keyword difficulty
            'skip_branded' => true,         // Skip branded keywords
            'limit' => 0,                   // 0 = no limit
            'update_existing' => true,      // Update if keyword exists
            'language_code' => 'th'         // Language code for keywords
        ], $options);

        if (!file_exists($filepath)) {
            return ['success' => false, 'message' => 'File not found: ' . $filepath];
        }

        // Detect encoding and convert if needed
        $content = file_get_contents($filepath);

        // Check for UTF-16 BOM
        if (substr($content, 0, 2) === "\xFF\xFE") {
            // UTF-16 LE
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (substr($content, 0, 2) === "\xFE\xFF") {
            // UTF-16 BE
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Parse CSV
        $lines = explode("\n", $content);
        $header = null;
        $keywords = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Parse TSV (tab-separated)
            $fields = str_getcsv($line, "\t", '"');

            if ($header === null) {
                // First line is header
                $header = $this->normalizeHeader($fields);
                continue;
            }

            // Map fields to header
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $fields[$i] ?? '';
            }

            $this->importStats['total']++;

            // Extract data
            $keyword = trim($row['keyword'] ?? '');
            $volume = (int)($row['volume'] ?? 0);
            $difficulty = (int)($row['difficulty'] ?? 0);
            $trafficPotential = (int)($row['traffic_potential'] ?? 0);
            $intents = $row['intents'] ?? '';
            $cpc = floatval($row['cpc'] ?? 0);

            // Skip empty keywords
            if (empty($keyword)) {
                $this->importStats['skipped']++;
                continue;
            }

            // Apply filters
            if ($volume < $options['min_volume']) {
                $this->importStats['skipped']++;
                continue;
            }

            if ($difficulty > $options['max_difficulty']) {
                $this->importStats['skipped']++;
                continue;
            }

            // Skip branded keywords (contain .com, .co, etc.)
            if ($options['skip_branded'] && $this->isBrandedKeyword($keyword)) {
                $this->importStats['skipped']++;
                continue;
            }

            // Parse search intent
            $searchIntent = $this->parseSearchIntent($intents);

            // Check if keyword exists (same keyword + topic + language)
            // ป้องกัน keyword ซ้ำในระบบ
            $existing = db()->fetchOne(
                "SELECT id FROM keywords WHERE keyword = ? AND topic = ? AND language_code = ?",
                [$keyword, $topic, $options['language_code']]
            );

            if ($existing) {
                if ($options['update_existing']) {
                    // Update existing keyword with Ahrefs data
                    db()->update('keywords', [
                        'search_volume' => $volume,
                        'difficulty' => $difficulty,
                        'traffic_potential' => $trafficPotential,
                        'search_intent' => $searchIntent,
                        'cpc' => $cpc,
                        'data_source' => 'ahrefs',
                        'last_updated' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                    $this->importStats['updated']++;
                } else {
                    $this->importStats['skipped']++;
                }
            } else {
                // Insert new keyword
                try {
                    $insertData = [
                        'keyword' => $keyword,
                        'topic' => $topic,
                        'language_code' => $options['language_code'],
                        'search_volume' => $volume,
                        'difficulty' => $difficulty,
                        'traffic_potential' => $trafficPotential,
                        'search_intent' => $searchIntent,
                        'cpc' => $cpc,
                        'data_source' => 'ahrefs',
                        'is_active' => 1,
                        'usage_count' => 0,
                        'priority' => $this->calculatePriority($volume, $difficulty, $trafficPotential)
                    ];
                    // Add owner data if provided
                    if (!empty($options['owner_type']) && !empty($options['owner_id'])) {
                        $insertData['owner_type'] = $options['owner_type'];
                        $insertData['owner_id'] = $options['owner_id'];
                    }
                    db()->insert('keywords', $insertData);
                    $this->importStats['imported']++;
                } catch (Exception $e) {
                    $this->importStats['errors'][] = "Line {$lineNum}: " . $e->getMessage();
                }
            }

            // Check limit
            if ($options['limit'] > 0 && $this->importStats['imported'] >= $options['limit']) {
                break;
            }
        }

        return [
            'success' => true,
            'message' => "Imported {$this->importStats['imported']} keywords, updated {$this->importStats['updated']}, skipped {$this->importStats['skipped']}",
            'stats' => $this->importStats
        ];
    }

    /**
     * Normalize CSV header names
     */
    private function normalizeHeader(array $fields): array {
        $mapping = [
            '#' => 'row_num',
            'term group' => 'term_group',
            'keyword' => 'keyword',
            'country' => 'country',
            'difficulty' => 'difficulty',
            'volume' => 'volume',
            'cpc' => 'cpc',
            'cps' => 'cps',
            'parent keyword' => 'parent_keyword',
            'last update' => 'last_update',
            'serp features' => 'serp_features',
            'global volume' => 'global_volume',
            'traffic potential' => 'traffic_potential',
            'global traffic potential' => 'global_traffic_potential',
            'first seen' => 'first_seen',
            'intents' => 'intents',
            'languages' => 'languages'
        ];

        $normalized = [];
        foreach ($fields as $field) {
            $key = strtolower(trim($field));
            $normalized[] = $mapping[$key] ?? $key;
        }

        return $normalized;
    }

    /**
     * Check if keyword is branded (contains domain names)
     */
    private function isBrandedKeyword(string $keyword): bool {
        $patterns = [
            '/\.(com|co|net|org|io|me|site|lat|xyz|live|online|app|dev)/i',
            '/www\./i',
            '/https?:\/\//i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse Ahrefs intent string to our format
     */
    private function parseSearchIntent(string $intents): string {
        $intents = strtolower($intents);

        // Priority: Transactional > Commercial > Informational > Navigational
        if (strpos($intents, 'transactional') !== false) {
            return 'transactional';
        }
        if (strpos($intents, 'commercial') !== false) {
            return 'commercial';
        }
        if (strpos($intents, 'informational') !== false) {
            return 'informational';
        }
        if (strpos($intents, 'navigational') !== false) {
            return 'navigational';
        }

        return 'informational'; // Default
    }

    /**
     * Calculate keyword priority score (1-10)
     * Higher volume + lower difficulty + higher traffic potential = higher priority
     */
    private function calculatePriority(int $volume, int $difficulty, int $trafficPotential): int {
        $score = 0;

        // Volume score (0-4 points)
        if ($volume >= 100000) $score += 4;
        elseif ($volume >= 50000) $score += 3;
        elseif ($volume >= 10000) $score += 2;
        elseif ($volume >= 1000) $score += 1;

        // Difficulty score (0-3 points, lower is better)
        if ($difficulty <= 20) $score += 3;
        elseif ($difficulty <= 40) $score += 2;
        elseif ($difficulty <= 60) $score += 1;

        // Traffic potential score (0-3 points)
        if ($trafficPotential >= 50000) $score += 3;
        elseif ($trafficPotential >= 10000) $score += 2;
        elseif ($trafficPotential >= 1000) $score += 1;

        return max(1, min(10, $score));
    }

    /**
     * Import all CSV files from a directory
     */
    public function importFromDirectory(string $directory, array $topicMapping = []): array {
        $results = [];

        // Default topic mapping based on filename
        $defaultMapping = [
            'general' => 'general'
        ];

        $topicMapping = array_merge($defaultMapping, $topicMapping);

        $files = glob($directory . '/*.csv');

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

            echo "Importing: {$filename} (topic: {$topic})\n";

            $result = $this->importFromCSV($file, $topic, [
                'min_volume' => 500,
                'max_difficulty' => 70,
                'skip_branded' => true
            ]);

            $results[$filename] = $result;

            // Reset stats for next file
            $this->importStats = [
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => []
            ];
        }

        return $results;
    }

    /**
     * Get import statistics
     */
    public function getStats(): array {
        return $this->importStats;
    }
}

/**
 * Helper function
 */
function ahrefsImporter(): AhrefsImporter {
    return new AhrefsImporter();
}
