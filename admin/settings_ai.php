<?php
/**
 * AI AutoPost SEO System - AI Settings
 * =====================================
 */

require_once __DIR__ . '/../includes/auth.php';
auth()->requireAuth();

$pageTitle = 'ตั้งค่า AI';
require_once __DIR__ . '/header.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Session หมดอายุ กรุณาลองใหม่');
        redirect(ADMIN_URL . '/settings_ai.php');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_provider') {
            $providerId = (int)$_POST['provider_id'];
            $apiKey = trim($_POST['api_key'] ?? '');
            $defaultModel = trim($_POST['default_model'] ?? '');
            $temperature = (float)$_POST['temperature'];
            $maxTokens = (int)$_POST['max_tokens'];
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

            // Encrypt API key if provided
            $updateData = [
                'default_model' => $defaultModel,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'is_enabled' => $isEnabled
            ];

            // Only update API key if user entered a new one (not masked value)
            if (!empty($apiKey) && strpos($apiKey, '****') === false) {
                $updateData['api_key'] = encrypt($apiKey);
            }

            // If setting as primary, unset other primaries first
            if ($isPrimary) {
                db()->query("UPDATE ai_settings SET is_primary = 0");
                $updateData['is_primary'] = 1;
            }

            db()->update('ai_settings', $updateData, 'id = ?', [$providerId]);

            logEvent('info', 'settings', 'AI settings updated', ['provider_id' => $providerId]);
            setFlash('success', 'บันทึกการตั้งค่าสำเร็จ');

        } elseif ($action === 'test_connection') {
            // Handle via AJAX
        }

    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    redirect(ADMIN_URL . '/settings_ai.php');
}

// Get AI providers
$providers = db()->fetchAll("SELECT * FROM ai_settings ORDER BY is_primary DESC, provider_name");

// Detect new models per provider (in available but not in known)
$newModelsMap = [];
foreach ($providers as $p) {
    $available = json_decode($p['available_models'] ?? '[]', true) ?: [];
    $known = json_decode($p['known_models'] ?? '[]', true) ?: [];
    $newModelsMap[$p['id']] = $known ? array_diff($available, $known) : [];
}
?>

<style>
.model-option { cursor: pointer; }
.model-option:hover { background: #e9ecef !important; color: #000 !important; }
.model-option.bg-primary:hover { background: #0d6efd !important; color: #fff !important; }
.model-group-header { font-size: 0.75rem; color: #6c757d; position: sticky; top: 0; z-index: 1; }
</style>

<div class="page-header">
    <h1 class="page-title">ตั้งค่า AI</h1>
    <p class="page-subtitle">จัดการ API Keys และการตั้งค่าสำหรับ AI Providers ต่างๆ</p>
</div>

<!-- Primary Provider Info -->
<?php
$primaryProvider = null;
foreach ($providers as $p) {
    if ($p['is_primary']) {
        $primaryProvider = $p;
        break;
    }
}
?>

<?php if ($primaryProvider): ?>
<div class="alert alert-info mb-4">
    <i class="fas fa-info-circle me-2"></i>
    <strong>Primary AI Provider:</strong> <?= sanitize($primaryProvider['display_name']) ?>
    (<?= sanitize($primaryProvider['default_model']) ?>)
    <?php if ($primaryProvider['last_test_status'] === 'success'): ?>
        <span class="badge bg-success ms-2">Connected</span>
    <?php elseif ($primaryProvider['last_test_status'] === 'failed'): ?>
        <span class="badge bg-danger ms-2">Failed</span>
    <?php else: ?>
        <span class="badge bg-warning ms-2">Not Tested</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <?php foreach ($providers as $provider): ?>
        <div class="col-lg-6">
            <div class="card <?= $provider['is_primary'] ? 'border-primary' : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <?php
                        $providerIcons = [
                            'claude' => 'fa-robot',
                            'openai' => 'fa-brain',
                            'gemini' => 'fa-gem',
                            'deepseek' => 'fa-water',
                            'openrouter' => 'fa-route'
                        ];
                        $icon = $providerIcons[$provider['provider_name']] ?? 'fa-cog';
                        $providerNewModels = $newModelsMap[$provider['id']] ?? [];
                        ?>
                        <i class="fas <?= $icon ?> me-2"></i>
                        <?= sanitize($provider['display_name']) ?>
                        <?php if ($provider['is_primary']): ?>
                            <span class="badge bg-primary ms-2">Primary</span>
                        <?php endif; ?>
                        <?php if (!empty($providerNewModels)): ?>
                            <span class="badge bg-danger ms-1"><?= count($providerNewModels) ?> ใหม่</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($provider['is_enabled']): ?>
                            <span class="badge bg-success">เปิดใช้งาน</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">ปิดใช้งาน</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" class="provider-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="update_provider">
                        <input type="hidden" name="provider_id" value="<?= $provider['id'] ?>">

                        <!-- API Key -->
                        <div class="mb-3">
                            <label class="form-label">API Key</label>
                            <?php
                            $maskedKey = '';
                            $hasKey = !empty($provider['api_key']);
                            if ($hasKey) {
                                try {
                                    $decrypted = decrypt($provider['api_key']);
                                    $maskedKey = maskApiKey($decrypted);
                                } catch (Exception $e) {
                                    $maskedKey = '••••••••';
                                }
                            }
                            ?>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control api-key-input"
                                       name="api_key"
                                       value="<?= $hasKey ? htmlspecialchars($maskedKey) : '' ?>"
                                       placeholder="<?= $hasKey ? '' : 'กรอก API Key' ?>"
                                       autocomplete="new-password"
                                       data-masked="<?= $hasKey ? '1' : '0' ?>"
                                       onfocus="clearMaskedKey(this)">
                                <button type="button" class="btn btn-outline-secondary toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <?php if ($hasKey): ?>
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success"></i>
                                    API Key ถูกตั้งค่าแล้ว (คลิกที่ช่องเพื่อเปลี่ยน หรือปล่อยไว้เพื่อคงค่าเดิม)
                                </small>
                            <?php endif; ?>
                        </div>

                        <!-- Default Model Display -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <label class="form-label mb-0 me-2">Default Model</label>
                                <span class="badge bg-dark"><?= sanitize($provider['default_model']) ?></span>
                                <?php
                                $models = json_decode($provider['available_models'], true) ?? [];
                                $providerNewModels = $newModelsMap[$provider['id']] ?? [];
                                ?>
                                <span class="text-muted ms-2 small">(<?= count($models) ?> models)</span>
                            </div>

                            <!-- Searchable model selector -->
                            <div class="model-selector position-relative" data-provider="<?= $provider['id'] ?>">
                                <input type="text"
                                       class="form-control model-search"
                                       placeholder="ค้นหา model... (กดเพื่อดูทั้งหมด)"
                                       data-provider="<?= $provider['id'] ?>"
                                       autocomplete="off">
                                <input type="hidden" name="default_model" id="model-value-<?= $provider['id'] ?>" value="<?= sanitize($provider['default_model']) ?>">
                                <div class="model-dropdown border rounded shadow-sm bg-white position-absolute w-100 d-none" id="model-list-<?= $provider['id'] ?>" style="z-index:1050; max-height:300px; overflow-y:auto;">
                                    <?php
                                    // Group models by prefix for OpenRouter
                                    if ($provider['provider_name'] === 'openrouter') {
                                        $grouped = [];
                                        foreach ($models as $model) {
                                            $parts = explode('/', $model, 2);
                                            $group = count($parts) > 1 ? $parts[0] : 'other';
                                            $grouped[$group][] = $model;
                                        }
                                        ksort($grouped);
                                        foreach ($grouped as $group => $groupModels):
                                    ?>
                                        <div class="px-2 py-1 bg-light border-bottom fw-bold small text-uppercase model-group-header"><?= sanitize($group) ?></div>
                                        <?php foreach ($groupModels as $model):
                                            $isNew = in_array($model, $providerNewModels);
                                            $isDefault = $provider['default_model'] === $model;
                                        ?>
                                            <div class="model-option px-3 py-1 cursor-pointer <?= $isDefault ? 'bg-primary text-white' : '' ?> <?= $isNew ? 'border-start border-3 border-success' : '' ?>"
                                                 data-value="<?= sanitize($model) ?>"
                                                 data-search="<?= strtolower($model) ?>">
                                                <?= sanitize($model) ?>
                                                <?php if ($isNew): ?><span class="badge bg-success ms-1">ใหม่</span><?php endif; ?>
                                                <?php if ($isDefault): ?><i class="fas fa-check ms-1"></i><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endforeach;
                                    } else {
                                        // Non-grouped for other providers
                                        foreach ($models as $model):
                                            $isNew = in_array($model, $providerNewModels);
                                            $isDefault = $provider['default_model'] === $model;
                                    ?>
                                        <div class="model-option px-3 py-2 cursor-pointer <?= $isDefault ? 'bg-primary text-white' : '' ?> <?= $isNew ? 'border-start border-3 border-success' : '' ?>"
                                             data-value="<?= sanitize($model) ?>"
                                             data-search="<?= strtolower($model) ?>">
                                            <?= sanitize($model) ?>
                                            <?php if ($isNew): ?><span class="badge bg-success ms-1">ใหม่</span><?php endif; ?>
                                            <?php if ($isDefault): ?><i class="fas fa-check ms-1"></i><?php endif; ?>
                                        </div>
                                    <?php endforeach;
                                    } ?>
                                </div>
                            </div>

                            <?php if (!empty($providerNewModels)): ?>
                                <div class="mt-2">
                                    <?php foreach (array_slice($providerNewModels, 0, 5) as $nm): ?>
                                        <span class="badge bg-success me-1 mb-1"><?= sanitize($nm) ?> ใหม่!</span>
                                    <?php endforeach; ?>
                                    <?php if (count($providerNewModels) > 5): ?>
                                        <span class="badge bg-secondary">+<?= count($providerNewModels) - 5 ?> อื่นๆ</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Temperature & Max Tokens -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Temperature</label>
                                <input type="number"
                                       class="form-control"
                                       name="temperature"
                                       min="0"
                                       max="2"
                                       step="0.1"
                                       value="<?= $provider['temperature'] ?>">
                                <small class="text-muted">0 = precise, 2 = creative</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Max Tokens</label>
                                <input type="number"
                                       class="form-control"
                                       name="max_tokens"
                                       min="100"
                                       max="128000"
                                       value="<?= $provider['max_tokens'] ?>">
                            </div>
                        </div>

                        <!-- Options -->
                        <div class="mb-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="is_enabled"
                                       id="enabled_<?= $provider['id'] ?>"
                                       <?= $provider['is_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="enabled_<?= $provider['id'] ?>">
                                    เปิดใช้งาน Provider นี้
                                </label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input primary-radio"
                                       type="checkbox"
                                       name="is_primary"
                                       id="primary_<?= $provider['id'] ?>"
                                       <?= $provider['is_primary'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="primary_<?= $provider['id'] ?>">
                                    ใช้เป็น Primary Provider
                                </label>
                            </div>
                        </div>

                        <!-- Test Status -->
                        <?php if ($provider['last_test_time']): ?>
                            <div class="mb-3">
                                <small class="text-muted">
                                    ทดสอบล่าสุด: <?= timeAgo($provider['last_test_time']) ?>
                                    <?php if ($provider['last_test_status'] === 'success'): ?>
                                        <span class="text-success"><i class="fas fa-check"></i> สำเร็จ</span>
                                    <?php elseif ($provider['last_test_status'] === 'failed'): ?>
                                        <span class="text-danger"><i class="fas fa-times"></i> ล้มเหลว</span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endif; ?>

                        <!-- Credit Balance -->
                        <?php if (in_array($provider['provider_name'], ['openrouter', 'openai']) && !empty($provider['api_key'])): ?>
                        <div class="credit-display mb-3 d-none" id="credit-<?= $provider['id'] ?>">
                            <div class="p-3 rounded" style="background:#F0FDF4;border:1px solid #BBF7D0;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold text-success" style="font-size:13px;">
                                        <i class="fas fa-wallet me-1"></i>เครดิตคงเหลือ
                                    </span>
                                    <span class="credit-remaining fw-bold text-success" style="font-size:18px;">—</span>
                                </div>
                                <div class="credit-details text-muted" style="font-size:11px;"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Buttons -->
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="fas fa-save me-2"></i>บันทึก
                            </button>
                            <?php if (in_array($provider['provider_name'], ['openrouter', 'openai']) && !empty($provider['api_key'])): ?>
                            <button type="button"
                                    class="btn btn-outline-warning check-credits-btn"
                                    data-provider-id="<?= $provider['id'] ?>"
                                    data-provider-name="<?= sanitize($provider['provider_name']) ?>"
                                    title="เช็คเครดิตคงเหลือ">
                                <i class="fas fa-coins me-1"></i>เครดิต
                            </button>
                            <?php endif; ?>
                            <button type="button"
                                    class="btn btn-outline-info refresh-models-btn"
                                    data-provider-id="<?= $provider['id'] ?>"
                                    data-provider-name="<?= sanitize($provider['provider_name']) ?>"
                                    title="ดึงรายการ Model ล่าสุด">
                                <i class="fas fa-sync me-1"></i>Models
                            </button>
                            <button type="button"
                                    class="btn btn-outline-success test-connection-btn"
                                    data-provider-id="<?= $provider['id'] ?>"
                                    data-provider-name="<?= sanitize($provider['provider_name']) ?>">
                                <i class="fas fa-plug me-2"></i>ทดสอบ
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Test Connection Modal -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plug me-2"></i>ทดสอบการเชื่อมต่อ AI</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="testResult" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">กำลังทดสอบ...</span>
                    </div>
                    <p class="mt-3 mb-0">กำลังทดสอบการเชื่อมต่อ...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Refresh Models Modal -->
<div class="modal fade" id="refreshModelsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sync me-2"></i>รายการ Models ล่าสุด</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="refreshResult" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-3 mb-0">กำลังดึงรายการ models...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$adminUrl = ADMIN_URL;
$pageScripts = '<script>
// Clear masked API key on focus, restore on blur if unchanged
function clearMaskedKey(input) {
    if (input.dataset.masked === "1") {
        input.dataset.originalMasked = input.value;
        input.value = "";
        input.type = "text";
        input.placeholder = "กรอก API Key ใหม่ หรือคลิกที่อื่นเพื่อคงค่าเดิม";
        input.dataset.masked = "0";

        input.addEventListener("blur", function handler() {
            if (input.value === "") {
                input.value = input.dataset.originalMasked;
                input.type = "password";
                input.placeholder = "";
                input.dataset.masked = "1";
            }
            input.removeEventListener("blur", handler);
        });
    }
}

// Toggle password visibility
$(\'.toggle-password\').click(function() {
    const input = $(this).closest(\'.input-group\').find(\'input\');
    const icon = $(this).find(\'i\');

    if (input.attr(\'type\') === \'password\') {
        input.attr(\'type\', \'text\');
        icon.removeClass(\'fa-eye\').addClass(\'fa-eye-slash\');
    } else {
        input.attr(\'type\', \'password\');
        icon.removeClass(\'fa-eye-slash\').addClass(\'fa-eye\');
    }
});

// Test connection
$(\'.test-connection-btn\').click(function() {
    const providerId = $(this).data(\'provider-id\');
    const providerName = $(this).data(\'provider-name\');

    $(\'#testResult\').html(\'<div class="spinner-border text-primary" role="status"><span class="visually-hidden">กำลังทดสอบ...</span></div><p class="mt-3 mb-0">กำลังทดสอบการเชื่อมต่อกับ \' + providerName + \'...</p>\');

    const modal = new bootstrap.Modal(document.getElementById(\'testModal\'));
    modal.show();

    $.ajax({
        url: \'' . $adminUrl . '/ajax/test_ai.php\',
        method: \'POST\',
        data: {
            csrf_token: csrfToken,
            provider_id: providerId
        },
        success: function(response) {
            if (response.success) {
                let html = \'<div class="text-success">\' +
                    \'<i class="fas fa-check-circle fa-3x mb-3"></i>\' +
                    \'<h5>เชื่อมต่อสำเร็จ!</h5>\' +
                    \'<p class="mb-0">\' + response.message + \'</p>\';
                if (response.response) {
                    html += \'<div class="mt-3 p-3 bg-light rounded text-start"><small>\' + response.response + \'</small></div>\';
                }
                html += \'</div>\';
                $(\'#testResult\').html(html);
            } else {
                $(\'#testResult\').html(\'<div class="text-danger"><i class="fas fa-times-circle fa-3x mb-3"></i><h5>การเชื่อมต่อล้มเหลว</h5><p class="mb-0">\' + response.message + \'</p></div>\');
            }
        },
        error: function() {
            $(\'#testResult\').html(\'<div class="text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><h5>เกิดข้อผิดพลาด</h5><p class="mb-0">ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์</p></div>\');
        }
    });
});

// Handle primary radio (only one can be primary)
$(\'.primary-radio\').change(function() {
    if ($(this).is(\':checked\')) {
        $(\'.primary-radio\').not(this).prop(\'checked\', false);
    }
});

// Model search & select
$(\'.model-search\').on(\'focus\', function() {
    const pid = $(this).data(\'provider\');
    $(\'#model-list-\' + pid).removeClass(\'d-none\');
}).on(\'input\', function() {
    const pid = $(this).data(\'provider\');
    const q = $(this).val().toLowerCase();
    const list = $(\'#model-list-\' + pid);
    list.removeClass(\'d-none\');
    list.find(\'.model-option\').each(function() {
        const match = $(this).data(\'search\').includes(q);
        $(this).toggle(match);
    });
    // Show/hide group headers based on visible children
    list.find(\'.model-group-header\').each(function() {
        const hasVisible = $(this).nextUntil(\'.model-group-header\', \'.model-option:visible\').length > 0;
        $(this).toggle(hasVisible);
    });
});

$(document).on(\'click\', \'.model-option\', function() {
    const val = $(this).data(\'value\');
    const selector = $(this).closest(\'.model-selector\');
    const pid = selector.data(\'provider\');
    $(\'#model-value-\' + pid).val(val);
    selector.find(\'.model-search\').val(val);
    $(\'#model-list-\' + pid).addClass(\'d-none\');
    // Update visual selection
    $(\'#model-list-\' + pid).find(\'.model-option\').removeClass(\'bg-primary text-white\');
    $(this).addClass(\'bg-primary text-white\');
});

// Close dropdown on outside click
$(document).on(\'click\', function(e) {
    if (!$(e.target).closest(\'.model-selector\').length) {
        $(\'.model-dropdown\').addClass(\'d-none\');
    }
});

// Check Credits
$(\'.check-credits-btn\').click(function() {
    const btn = $(this);
    const providerId = btn.data(\'provider-id\');
    const providerName = btn.data(\'provider-name\');
    const creditBox = $(\'#credit-\' + providerId);

    btn.prop(\'disabled\', true).html(\'<span class="spinner-border spinner-border-sm me-1"></span>กำลังเช็ค...\');

    $.ajax({
        url: \'' . $adminUrl . '/ajax/check_credits.php\',
        method: \'POST\',
        data: { csrf_token: csrfToken, provider_id: providerId },
        timeout: 15000,
        success: function(res) {
            btn.prop(\'disabled\', false).html(\'<i class="fas fa-coins me-1"></i>เครดิต\');
            creditBox.removeClass(\'d-none\');

            if (!res.success) {
                creditBox.find(\'.credit-remaining\').text(\'—\').removeClass(\'text-success\').addClass(\'text-danger\');
                creditBox.find(\'.credit-details\').text(res.message);
                creditBox.find(\'> div\').css({background:\'#FFF1F2\', borderColor:\'#FECDD3\'});
                return;
            }

            creditBox.find(\'> div\').css({background:\'#F0FDF4\', borderColor:\'#BBF7D0\'});
            creditBox.find(\'.credit-remaining\').removeClass(\'text-danger\').addClass(\'text-success\');

            if (providerName === \'openrouter\') {
                const usage = \'$\' + parseFloat(res.usage).toFixed(4);
                let remainingText, details;

                if (res.remaining !== null) {
                    remainingText = \'$\' + parseFloat(res.remaining).toFixed(4);
                    if (res.is_prepaid) {
                        details = \'ยอดคงเหลือ (Prepaid) · ใช้ไปแล้ว: \' + usage;
                    } else {
                        const limit = \'$\' + parseFloat(res.limit).toFixed(2);
                        details = \'ใช้ไปแล้ว: \' + usage + \' / วงเงิน: \' + limit;
                    }
                } else {
                    remainingText = \'ดูที่ openrouter.ai\';
                    details = \'ใช้ไปแล้ว: \' + usage + (res.is_free_tier ? \' (Free Tier)\' : \' (Prepaid — ดึงยอดไม่ได้)\');
                    creditBox.find(\'.credit-remaining\').css(\'font-size\', \'13px\');
                }

                if (res.is_free_tier) details += \' · Free Tier\';
                if (res.label)        details += \' · \' + res.label;
                if (res.rate_limit)   details += \' · \' + (res.rate_limit.requests || \'?\') + \' req/\' + (res.rate_limit.interval || \'?\');

                creditBox.find(\'.credit-remaining\').text(remainingText);
                creditBox.find(\'.credit-details\').text(details);
            } else if (providerName === \'openai\') {
                const remaining = res.remaining !== null ? \'$\' + res.remaining.toFixed(2) : \'ไม่ทราบ\';
                creditBox.find(\'.credit-remaining\').text(remaining);
                let details = \'ใช้ไปแล้ว: $\' + res.usage.toFixed(4);
                if (res.limit) details += \' / วงเงิน: $\' + parseFloat(res.limit).toFixed(2);
                if (res.period) details += \' · รอบบิล: \' + res.period;
                creditBox.find(\'.credit-details\').text(details);
            }
        },
        error: function() {
            btn.prop(\'disabled\', false).html(\'<i class="fas fa-coins me-1"></i>เครดิต\');
            creditBox.removeClass(\'d-none\');
            creditBox.find(\'.credit-remaining\').text(\'Error\').removeClass(\'text-success\').addClass(\'text-danger\');
            creditBox.find(\'.credit-details\').text(\'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์\');
        }
    });
});

// Refresh Models
$(\'.refresh-models-btn\').click(function() {
    const providerId = $(this).data(\'provider-id\');
    const providerName = $(this).data(\'provider-name\');
    const btn = $(this);

    btn.prop(\'disabled\', true).find(\'i\').addClass(\'fa-spin\');

    $(\'#refreshResult\').html(\'<div class="spinner-border text-primary" role="status"></div><p class="mt-3 mb-0">กำลังดึงรายการ models จาก \' + providerName + \'...</p>\');

    const modal = new bootstrap.Modal(document.getElementById(\'refreshModelsModal\'));
    modal.show();

    $.ajax({
        url: \'' . $adminUrl . '/ajax/refresh_models.php\',
        method: \'POST\',
        data: { csrf_token: csrfToken, provider_id: providerId },
        timeout: 60000,
        success: function(res) {
            btn.prop(\'disabled\', false).find(\'i\').removeClass(\'fa-spin\');
            if (res.success) {
                let html = \'<div class="text-success mb-3"><i class="fas fa-check-circle fa-2x"></i></div>\';
                html += \'<h5>\' + res.message + \'</h5>\';
                html += \'<p>พบ <strong>\' + res.total_models + \'</strong> models\';
                if (res.new_count > 0) {
                    html += \' | <span class="text-success"><strong>\' + res.new_count + \' ใหม่!</strong></span>\';
                }
                html += \'</p>\';

                if (res.new_models && res.new_models.length > 0) {
                    html += \'<div class="alert alert-success text-start"><strong>Models ใหม่:</strong><ul class="mb-0 mt-1">\';
                    res.new_models.forEach(function(m) {
                        html += \'<li><span class="badge bg-success me-1">ใหม่</span> \' + m + \'</li>\';
                    });
                    html += \'</ul></div>\';
                }

                if (res.models && res.models.length > 0) {
                    html += \'<div class="text-start" style="max-height:300px;overflow-y:auto;">\';
                    html += \'<table class="table table-sm table-hover"><thead><tr><th>Model</th><th>Context</th></tr></thead><tbody>\';
                    res.models.forEach(function(m) {
                        const isNew = res.new_models && res.new_models.includes(m.id);
                        html += \'<tr\' + (isNew ? \' class="table-success"\' : \'\') + \'>\';
                        html += \'<td>\' + (isNew ? \'<span class="badge bg-success me-1">ใหม่</span> \' : \'\') + m.id + \'</td>\';
                        html += \'<td>\' + (m.context_length ? (m.context_length / 1000).toFixed(0) + \'K\' : \'-\') + \'</td>\';
                        html += \'</tr>\';
                    });
                    html += \'</tbody></table></div>\';
                }

                html += \'<button class="btn btn-primary mt-2" onclick="location.reload()"><i class="fas fa-refresh me-1"></i>รีโหลดหน้า</button>\';
                $(\'#refreshResult\').html(html);
            } else {
                $(\'#refreshResult\').html(\'<div class="text-danger"><i class="fas fa-times-circle fa-2x mb-2"></i><p>\' + res.message + \'</p></div>\');
            }
        },
        error: function() {
            btn.prop(\'disabled\', false).find(\'i\').removeClass(\'fa-spin\');
            $(\'#refreshResult\').html(\'<div class="text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>ไม่สามารถเชื่อมต่อได้</p></div>\');
        }
    });
});
</script>';
?>

<?php require_once __DIR__ . '/footer.php'; ?>
