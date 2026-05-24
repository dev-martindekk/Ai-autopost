<?php
$pageTitle = 'ตั้งค่า AI';
require_once __DIR__ . '/header.php';

$memberId = (int)$currentMember['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_ai') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $model      = trim($_POST['selected_model'] ?? '');

        if ($providerId === 0) {
            // Reset to system default
            db()->query(
                "DELETE FROM member_settings WHERE member_id=? AND setting_key IN ('ai_provider_id','ai_model_override')",
                [$memberId]
            );
            setFlash('success', 'รีเซ็ตกลับค่าเริ่มต้นของระบบแล้ว');
        } else {
            // Verify provider is enabled and has API key
            $provider = db()->fetchOne(
                "SELECT id FROM ai_settings WHERE id=? AND is_enabled=1 AND api_key IS NOT NULL AND api_key != ''",
                [$providerId]
            );
            if ($provider) {
                setMemberSetting($memberId, 'ai_provider_id', $providerId, 'int');
                setMemberSetting($memberId, 'ai_model_override', $model, 'string');
                setFlash('success', 'บันทึกการตั้งค่า AI สำเร็จ');
            } else {
                setFlash('error', 'Provider นี้ไม่พร้อมใช้งาน');
            }
        }
        redirect('/member/settings_ai.php');
    }
}

// Only show providers that are enabled AND have an API key configured
$providers = db()->fetchAll(
    "SELECT * FROM ai_settings
     WHERE is_enabled = 1 AND api_key IS NOT NULL AND api_key != ''
     ORDER BY is_primary DESC, provider_name"
);

// Current member preference
$currentProviderId = (int)getMemberSetting($memberId, 'ai_provider_id', 0);
$currentModel      = getMemberSetting($memberId, 'ai_model_override', '');

// System primary provider (for display in banner)
$systemPrimary = db()->fetchOne(
    "SELECT * FROM ai_settings WHERE is_primary=1 AND is_enabled=1 LIMIT 1"
);

$flash = getFlash();

$providerIcons = [
    'claude'      => 'fa-robot',
    'openai'      => 'fa-brain',
    'gemini'      => 'fa-gem',
    'deepseek'    => 'fa-water',
    'openrouter'  => 'fa-route',
];
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<?php if (empty($providers)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>ยังไม่มี AI Provider ที่พร้อมใช้งาน</strong> — Admin ยังไม่ได้ตั้งค่า API Key กรุณาติดต่อ Admin
</div>
<?php require_once __DIR__ . '/footer.php'; exit; ?>
<?php endif; ?>

<!-- Info Banner -->
<div class="alert alert-info mb-4">
    <i class="fas fa-info-circle me-2"></i>
    <strong>AI ระบบ</strong> — เราออกค่า API ให้ คุณเลือกได้ว่าจะใช้ AI ตัวไหนเขียนบทความ
    <?php if (!$currentProviderId && $systemPrimary): ?>
    <small class="d-block mt-1 text-muted">ค่าเริ่มต้น: <strong><?= sanitize($systemPrimary['display_name']) ?></strong> — <?= sanitize($systemPrimary['default_model']) ?></small>
    <?php endif; ?>
</div>

<style>
.model-option { cursor:pointer; padding:6px 14px; font-size:13px; }
.model-option:hover { background:#e9ecef !important; color:#000 !important; }
.model-option.selected { background:#6366F1 !important; color:#fff !important; }
.model-group-header { font-size:11px; color:#6c757d; background:#F8FAFC; padding:4px 14px; position:sticky; top:0; z-index:1; font-weight:700; text-transform:uppercase; letter-spacing:.6px; }
.provider-card { cursor:pointer; transition:all .2s; }
.model-picker-card { overflow: visible !important; }
.model-picker-card .card-body { overflow: visible; }
.model-picker-card .card-header { border-radius: var(--border-radius) var(--border-radius) 0 0; }
.provider-card.is-selected { border-color:#6366F1 !important; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
.provider-card .check-ring { width:22px; height:22px; border-radius:50%; border:2px solid #CBD5E1; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all .2s; }
.provider-card.is-selected .check-ring { background:#6366F1; border-color:#6366F1; }
.provider-card .check-ring i { color:#fff; font-size:11px; display:none; }
.provider-card.is-selected .check-ring i { display:block; }
</style>

<form method="POST" id="aiForm">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="save_ai">
    <input type="hidden" name="provider_id" id="selectedProviderId" value="<?= $currentProviderId ?>">
    <input type="hidden" name="selected_model" id="selectedModelInput" value="<?= sanitize($currentModel) ?>">

<div class="row g-3 mb-4">
    <!-- System Default Card -->
    <div class="col-md-6 col-lg-4">
        <div class="card provider-card h-100 <?= !$currentProviderId ? 'is-selected' : '' ?>"
             onclick="selectProvider(0, '', '')">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="check-ring mt-1"><i class="fas fa-check"></i></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold mb-1"><i class="fas fa-cog me-2 text-secondary"></i>ค่าเริ่มต้นของระบบ</div>
                    <?php if ($systemPrimary): ?>
                    <small class="text-muted d-block"><?= sanitize($systemPrimary['display_name']) ?></small>
                    <small class="text-muted"><?= sanitize($systemPrimary['default_model']) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Provider Cards -->
    <?php foreach ($providers as $p):
        $isSelected = ($currentProviderId == $p['id']);
        $icon = $providerIcons[$p['provider_name']] ?? 'fa-cog';
        $models = json_decode($p['available_models'] ?? '[]', true) ?: [];
        $defaultModel = $p['default_model'] ?? '';
        // Use member's saved model if this provider is selected
        $displayModel = ($isSelected && $currentModel) ? $currentModel : $defaultModel;
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card provider-card h-100 <?= $isSelected ? 'is-selected' : '' ?>"
             onclick="selectProvider(<?= $p['id'] ?>, '<?= sanitize($displayModel) ?>', '<?= $p['provider_name'] ?>')"
             data-provider-id="<?= $p['id'] ?>">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="check-ring mt-1"><i class="fas fa-check"></i></div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span class="fw-semibold"><i class="fas <?= $icon ?> me-2 text-primary"></i><?= sanitize($p['display_name']) ?></span>
                        <?php if ($p['is_primary']): ?>
                        <span class="badge bg-primary" style="font-size:9px;">Primary</span>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted d-block">
                        <?= count($models) ?> models
                        <?php if ($p['last_test_status'] === 'success'): ?>
                        <span class="ms-2 text-success"><i class="fas fa-circle" style="font-size:8px;"></i> Connected</span>
                        <?php endif; ?>
                    </small>
                    <?php if ($isSelected): ?>
                    <small class="text-primary fw-semibold mt-1 d-block"><?= sanitize($displayModel) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Model Picker (shows when a provider is selected) -->
<div id="modelPickerSection" style="display:<?= $currentProviderId ? 'block' : 'none' ?>;">
    <?php foreach ($providers as $p):
        $models = json_decode($p['available_models'] ?? '[]', true) ?: [];
        $isSelected = ($currentProviderId == $p['id']);
        $displayModel = ($isSelected && $currentModel) ? $currentModel : $p['default_model'];
    ?>
    <div class="card mb-4 model-picker-card" id="picker-<?= $p['id'] ?>"
         style="display:<?= $isSelected ? 'block' : 'none' ?>">
        <div class="card-header">
            <i class="fas fa-sliders-h me-2 text-primary"></i>
            เลือก Model สำหรับ <strong><?= sanitize($p['display_name']) ?></strong>
            <span class="badge bg-dark ms-2" id="currentModelBadge-<?= $p['id'] ?>"><?= sanitize($displayModel) ?></span>
        </div>
        <div class="card-body">
            <div class="model-selector position-relative" data-provider="<?= $p['id'] ?>">
                <input type="text"
                       class="form-control model-search mb-2"
                       placeholder="ค้นหา model... (คลิกเพื่อดูทั้งหมด)"
                       data-provider="<?= $p['id'] ?>"
                       value="<?= sanitize($displayModel) ?>"
                       autocomplete="off">
                <div class="model-dropdown border rounded shadow-sm bg-white d-none"
                     id="model-list-<?= $p['id'] ?>"
                     style="position:fixed; z-index:9999; max-height:320px; overflow-y:auto; min-width:300px;">
                    <?php if ($p['provider_name'] === 'openrouter'):
                        // Group by provider prefix
                        $grouped = [];
                        foreach ($models as $m) {
                            $parts = explode('/', $m, 2);
                            $grp = count($parts) > 1 ? $parts[0] : 'other';
                            $grouped[$grp][] = $m;
                        }
                        ksort($grouped);
                        foreach ($grouped as $grp => $grpModels):
                    ?>
                    <div class="model-group-header"><?= sanitize($grp) ?></div>
                    <?php foreach ($grpModels as $m):
                        $isCurrent = ($m === $displayModel);
                    ?>
                    <div class="model-option <?= $isCurrent ? 'selected' : '' ?>"
                         data-value="<?= sanitize($m) ?>"
                         data-search="<?= strtolower($m) ?>"
                         data-provider="<?= $p['id'] ?>">
                        <?= sanitize($m) ?>
                        <?php if ($isCurrent): ?><i class="fas fa-check ms-2"></i><?php endif; ?>
                    </div>
                    <?php endforeach; endforeach;
                    else:
                        foreach ($models as $m):
                            $isCurrent = ($m === $displayModel);
                    ?>
                    <div class="model-option <?= $isCurrent ? 'selected' : '' ?>"
                         data-value="<?= sanitize($m) ?>"
                         data-search="<?= strtolower($m) ?>"
                         data-provider="<?= $p['id'] ?>">
                        <?= sanitize($m) ?>
                        <?php if ($isCurrent): ?><i class="fas fa-check ms-2"></i><?php endif; ?>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php if (empty($models)): ?>
            <small class="text-muted"><i class="fas fa-info-circle me-1"></i>ไม่มีรายการ model — ใช้ช่องด้านบนพิมพ์ชื่อ model เองได้</small>
            <?php else: ?>
            <small class="text-muted"><?= count($models) ?> models พร้อมใช้งาน</small>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Save Bar -->
<div class="d-flex gap-3 align-items-center">
    <button type="submit" class="btn btn-primary px-4">
        <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
    </button>
    <?php if ($currentProviderId): ?>
    <button type="button" class="btn btn-outline-secondary"
            onclick="selectProvider(0,'','');document.getElementById('aiForm').submit();">
        <i class="fas fa-undo me-2"></i>รีเซ็ตเป็นค่าระบบ
    </button>
    <span style="font-size:13px;color:#10B981;">
        <i class="fas fa-check-circle me-1"></i>
        ใช้งาน:
        <?php
        $cp = array_filter($providers, fn($p) => $p['id'] == $currentProviderId);
        $cp = reset($cp);
        echo $cp ? sanitize($cp['display_name']) : '';
        echo $currentModel ? ' / ' . sanitize($currentModel) : ' (default model)';
        ?>
    </span>
    <?php endif; ?>
</div>
</form>

<script>
// Map provider_id → default model (for reset when switching)
const providerDefaults = {
    <?php foreach ($providers as $p): ?>
    <?= $p['id'] ?>: '<?= sanitize($p['default_model']) ?>',
    <?php endforeach; ?>
};

function selectProvider(providerId, model, providerName) {
    // Update hidden inputs
    document.getElementById('selectedProviderId').value = providerId;
    document.getElementById('selectedModelInput').value = model || (providerDefaults[providerId] || '');

    // Update card visuals
    document.querySelectorAll('.provider-card').forEach(c => c.classList.remove('is-selected'));
    if (providerId === 0) {
        document.querySelectorAll('.provider-card')[0].classList.add('is-selected');
    } else {
        const card = document.querySelector('.provider-card[data-provider-id="' + providerId + '"]');
        if (card) card.classList.add('is-selected');
    }

    // Show/hide model pickers
    document.querySelectorAll('.model-picker-card').forEach(c => c.style.display = 'none');
    const section = document.getElementById('modelPickerSection');
    if (providerId === 0) {
        section.style.display = 'none';
    } else {
        section.style.display = 'block';
        const picker = document.getElementById('picker-' + providerId);
        if (picker) {
            picker.style.display = 'block';
            // Pre-fill search with current model
            const search = picker.querySelector('.model-search');
            if (search && !search.value) search.value = providerDefaults[providerId] || '';
        }
    }
}

function positionDropdown(searchEl) {
    const pid  = searchEl.dataset.provider;
    const list = document.getElementById('model-list-' + pid);
    const rect = searchEl.getBoundingClientRect();
    list.style.left  = rect.left + 'px';
    list.style.top   = (rect.bottom + 4) + 'px';
    list.style.width = rect.width + 'px';
}

// Model search
document.addEventListener('click', function(e) {
    const searchEl = e.target.closest('.model-search');
    if (searchEl) {
        const pid = searchEl.dataset.provider;
        const list = document.getElementById('model-list-' + pid);
        positionDropdown(searchEl);
        list.classList.remove('d-none');
        return;
    }
    if (!e.target.closest('.model-selector') && !e.target.closest('.model-dropdown')) {
        document.querySelectorAll('.model-dropdown').forEach(d => d.classList.add('d-none'));
    }
});

window.addEventListener('scroll', function() {
    document.querySelectorAll('.model-search').forEach(s => {
        const pid = s.dataset.provider;
        const list = document.getElementById('model-list-' + pid);
        if (!list.classList.contains('d-none')) positionDropdown(s);
    });
}, true);

document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('model-search')) return;
    const pid = e.target.dataset.provider;
    const q   = e.target.value.toLowerCase();
    const list = document.getElementById('model-list-' + pid);
    list.classList.remove('d-none');
    list.querySelectorAll('.model-option').forEach(opt => {
        opt.style.display = opt.dataset.search.includes(q) ? '' : 'none';
    });
    list.querySelectorAll('.model-group-header').forEach(hdr => {
        let sib = hdr.nextElementSibling;
        let hasVisible = false;
        while (sib && !sib.classList.contains('model-group-header')) {
            if (sib.style.display !== 'none') { hasVisible = true; break; }
            sib = sib.nextElementSibling;
        }
        hdr.style.display = hasVisible ? '' : 'none';
    });
});

document.addEventListener('click', function(e) {
    const opt = e.target.closest('.model-option');
    if (!opt) return;
    const pid = opt.dataset.provider;
    const val = opt.dataset.value;

    // Update hidden input + search box + badge
    document.getElementById('selectedModelInput').value = val;
    document.querySelector('#picker-' + pid + ' .model-search').value = val;
    const badge = document.getElementById('currentModelBadge-' + pid);
    if (badge) badge.textContent = val;

    // Visual selection
    document.querySelectorAll('#model-list-' + pid + ' .model-option').forEach(o => o.classList.remove('selected'));
    opt.classList.add('selected');

    // Close dropdown
    document.getElementById('model-list-' + pid).classList.add('d-none');
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
