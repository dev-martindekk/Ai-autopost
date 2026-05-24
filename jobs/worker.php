<?php
/**
 * AI AutoPost SEO System - Worker Process
 * ========================================
 * CLI script to run background worker
 *
 * Usage:
 *   php worker.php                    # Run indefinitely
 *   php worker.php --max-jobs=10      # Run 10 jobs then exit
 *   php worker.php --job-type=generate_article  # Process only specific job type
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/queue_manager.php';
require_once __DIR__ . '/../includes/worker_manager.php';
require_once __DIR__ . '/../includes/ai_rate_limiter.php';

// Parse command line arguments
$options = getopt('', ['max-jobs::', 'job-type::']);
$maxJobs = isset($options['max-jobs']) ? (int)$options['max-jobs'] : 0;
$jobTypes = isset($options['job-type']) ? explode(',', $options['job-type']) : [];

echo "=== AI AutoPost SEO - Worker ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Max jobs: " . ($maxJobs ?: 'unlimited') . "\n";
echo "Job types: " . ($jobTypes ? implode(', ', $jobTypes) : 'all') . "\n\n";

// Create and run worker
$worker = new WorkerManager();
$worker->run($jobTypes, $maxJobs);
