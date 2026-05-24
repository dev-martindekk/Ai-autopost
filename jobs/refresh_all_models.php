<?php
/**
 * AI AutoPost SEO - Auto Refresh All AI Models
 * =============================================
 * Cron job: Fetch latest models from all AI providers
 * New models get tagged in the admin AI settings page
 *
 * Usage: php refresh_all_models.php
 * Cron:  0 0,6,12,18 * * * php /path/to/refresh_all_models.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(120);

define('ALLOW_WEB_ACCESS', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== Refresh All AI Models ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$providers = db()->fetchAll("SELECT * FROM ai_settings WHERE is_enabled = 1");

// We use OpenRouter API as the main source for all providers
$orProvider = null;
foreach ($providers as $p) {
    if ($p['provider_name'] === 'openrouter' && !empty($p['api_key'])) {
        $orProvider = $p;
        break;
    }
}

// Fetch OpenRouter model list (covers anthropic, google, deepseek, openai, etc.)
$orModels = [];
if ($orProvider) {
    $orKey = decrypt($orProvider['api_key']);
    if ($orKey) {
        $ch = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$orKey}"],
            CURLOPT_TIMEOUT => 30,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $data = json_decode($res, true);
            $orModels = $data['data'] ?? [];
            echo "OpenRouter API: " . count($orModels) . " models fetched\n";
        } else {
            echo "OpenRouter API failed: HTTP {$code}\n";
        }
    }
}

// Provider prefix mapping (OpenRouter prefix => ai_settings provider_name)
$prefixMap = [
    'anthropic' => 'claude',
    'google' => 'gemini',
    'deepseek' => 'deepseek',
];

$totalNew = 0;

foreach ($providers as $provider) {
    $pid = $provider['id'];
    $name = $provider['provider_name'];
    $oldKnown = json_decode($provider['known_models'] ?? '[]', true) ?: [];
    $currentModels = json_decode($provider['available_models'] ?? '[]', true) ?: [];
    $defaultModel = $provider['default_model'];

    echo "\n--- {$provider['display_name']} (id={$pid}) ---\n";

    $fetchedModels = [];

    if ($name === 'openrouter') {
        // OpenRouter: include all major providers
        $allowedPrefixes = ['anthropic/', 'openai/', 'google/', 'meta-llama/', 'deepseek/', 'mistralai/', 'qwen/', 'x-ai/', 'amazon/', 'nvidia/', 'cohere/'];
        foreach ($orModels as $m) {
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($m['id'], $prefix)) {
                    $fetchedModels[] = $m['id'];
                    break;
                }
            }
        }
    } elseif ($name === 'openai') {
        // OpenAI: fetch directly from their API
        $apiKey = decrypt($provider['api_key']);
        if ($apiKey) {
            $ch = curl_init('https://api.openai.com/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}"],
                CURLOPT_TIMEOUT => 30,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $data = json_decode($res, true);
                foreach ($data['data'] ?? [] as $m) {
                    $id = $m['id'];
                    if (preg_match('/^(gpt-[345]|o[134])/', $id)) {
                        if (preg_match('/(instruct|embedding|tts|whisper|realtime|audio|transcribe|image|search|moderation|sora|codex)/', $id)) continue;
                        $fetchedModels[] = $id;
                    }
                }
            }
        }
        // Also supplement from OpenRouter
        foreach ($orModels as $m) {
            if (str_starts_with($m['id'], 'openai/')) {
                $short = str_replace('openai/', '', $m['id']);
                if (!in_array($short, $fetchedModels)) {
                    $fetchedModels[] = $short;
                }
            }
        }
    } else {
        // Claude, Gemini, DeepSeek: get from OpenRouter model list
        $orPrefix = array_search($name, $prefixMap);
        if ($orPrefix && !empty($orModels)) {
            foreach ($orModels as $m) {
                if (str_starts_with($m['id'], $orPrefix . '/')) {
                    $shortName = str_replace($orPrefix . '/', '', $m['id']);
                    $fetchedModels[] = $shortName;
                }
            }
        }

        // Also try direct API if key exists
        if ($name === 'gemini' && !empty($provider['api_key'])) {
            $apiKey = decrypt($provider['api_key']);
            if ($apiKey) {
                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200) {
                    $data = json_decode($res, true);
                    foreach ($data['models'] ?? [] as $m) {
                        $id = str_replace('models/', '', $m['name'] ?? '');
                        if ($id && !in_array($id, $fetchedModels)) {
                            $fetchedModels[] = $id;
                        }
                    }
                }
            }
        }
    }

    if (empty($fetchedModels)) {
        echo "  No models fetched, keeping current list\n";
        continue;
    }

    $fetchedModels = array_unique($fetchedModels);
    sort($fetchedModels);

    // Put default model first
    if (in_array($defaultModel, $fetchedModels)) {
        $fetchedModels = array_values(array_diff($fetchedModels, [$defaultModel]));
        array_unshift($fetchedModels, $defaultModel);
    }

    // Detect new models
    $newModels = array_diff($fetchedModels, $oldKnown);
    $removedModels = array_diff($oldKnown, $fetchedModels);

    echo "  Total: " . count($fetchedModels) . " models\n";
    if (!empty($newModels)) {
        echo "  NEW: " . count($newModels) . " models\n";
        foreach ($newModels as $nm) {
            echo "    + {$nm}\n";
        }
        $totalNew += count($newModels);
    }
    if (!empty($removedModels)) {
        echo "  Removed: " . count($removedModels) . " models\n";
    }

    // Update DB: available_models gets the full list, known_models keeps OLD known
    // so that new ones show "ใหม่" tag until next refresh acknowledges them
    db()->update('ai_settings', [
        'available_models' => json_encode(array_values($fetchedModels)),
        // known_models stays as old list so diff shows "new" in UI
        // It will be updated to match available_models on NEXT run
    ], 'id = ?', [$pid]);

    // Update known_models to current fetched (new models will show as "new" until next run)
    // Wait: we want "new" to persist until user sees them. So update known_models to OLD available.
    // This way: available_models has all models, known_models has previous run's models.
    // Diff = new models since last refresh.
    db()->update('ai_settings', [
        'known_models' => json_encode(array_values($currentModels)),
    ], 'id = ?', [$pid]);
}

echo "\n=== Done ===\n";
echo "Total new models detected: {$totalNew}\n";

if ($totalNew > 0) {
    logEvent('info', 'ai_models', "Auto-refresh found {$totalNew} new AI models", ['new_count' => $totalNew]);
}
