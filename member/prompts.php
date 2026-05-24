<?php
$pageTitle = 'Prompts & ตั้งค่า SEO';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/default_prompts.php';

$memberId     = (int)$currentMember['id'];
$promptTypes  = getPromptTypes();
$defaultPrompts = getDefaultPrompts();

// ตรวจสอบประเภทสมาชิก
$contentType   = getMemberSetting($memberId, 'content_type', 'general');
$isGambling    = ($contentType === 'gambling');
$gamblingPrompts = $isGambling ? getGamblingPrompts() : [];

// Gambling members: override default สำหรับ system + article
if ($isGambling) {
    foreach ($gamblingPrompts as $type => $content) {
        $defaultPrompts[$type] = $content;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';
    $type   = $_POST['template_type'] ?? '';

    if ($action === 'save_word_settings') {
        $minWords      = max(500, min(10000, (int)($_POST['min_words'] ?? 2500)));
        $maxWords      = max(500, min(20000, (int)($_POST['max_words'] ?? 4000)));
        $internalLinks = max(0, min(20, (int)($_POST['internal_links'] ?? 1)));
        if ($maxWords <= $minWords) $maxWords = $minWords + 500;

        setMemberSetting($memberId, 'min_word_count', $minWords, 'int');
        setMemberSetting($memberId, 'max_word_count', $maxWords, 'int');
        setMemberSetting($memberId, 'internal_links_per_article', $internalLinks, 'int');
        setFlash('success', "บันทึกสำเร็จ — คำ: {$minWords}–{$maxWords}, Internal Links: {$internalLinks}");
        redirect('/member/prompts.php#seo-settings');
    }

    if ($action === 'reset_word_settings') {
        db()->query(
            "DELETE FROM member_settings WHERE member_id=? AND setting_key IN ('min_word_count','max_word_count','internal_links_per_article')",
            [$memberId]
        );
        setFlash('success', 'รีเซ็ตกลับค่าเริ่มต้นของระบบแล้ว');
        redirect('/member/prompts.php#seo-settings');
    }

    if ($action === 'save_prompt') {
        $content = trim($_POST['prompt_content'] ?? '');

        if (empty($type) || !isset($promptTypes[$type])) {
            setFlash('error', 'ประเภท Prompt ไม่ถูกต้อง');
        } elseif (empty($content)) {
            setFlash('error', 'กรุณากรอกเนื้อหา Prompt');
        } else {
            $existing = db()->fetchOne(
                "SELECT id FROM prompt_templates
                 WHERE template_type=? AND owner_type='member' AND owner_id=?",
                [$type, $memberId]
            );
            $data = [
                'name'           => $promptTypes[$type]['name'] . ' (Member #' . $memberId . ')',
                'template_type'  => $type,
                'language_code'  => 'th',
                'prompt_content' => $content,
                'owner_type'     => 'member',
                'owner_id'       => $memberId,
                'is_default'     => 0,
                'is_active'      => 1,
            ];
            if ($existing) {
                db()->update('prompt_templates', $data, 'id = ?', [$existing['id']]);
            } else {
                db()->insert('prompt_templates', $data);
            }
            setFlash('success', 'บันทึก "' . $promptTypes[$type]['name'] . '" เรียบร้อยแล้ว');
        }

    } elseif ($action === 'reset_prompt') {
        if ($type && isset($promptTypes[$type])) {
            $gp = getGamblingPrompts();
            $isGamblingReset = (getMemberSetting($memberId, 'content_type', 'general') === 'gambling')
                               && isset($gp[$type]);

            if ($isGamblingReset) {
                // Gambling member: reset กลับเป็น gambling default (ไม่ใช่ general)
                $existing = db()->fetchOne(
                    "SELECT id FROM prompt_templates WHERE template_type=? AND owner_type='member' AND owner_id=?",
                    [$type, $memberId]
                );
                $data = [
                    'name'           => $promptTypes[$type]['name'] . ' — Gambling (Member #' . $memberId . ')',
                    'template_type'  => $type,
                    'topic'          => 'casino',
                    'language_code'  => 'th',
                    'prompt_content' => $gp[$type],
                    'owner_type'     => 'member',
                    'owner_id'       => $memberId,
                    'is_default'     => 0,
                    'is_active'      => 1,
                ];
                if ($existing) {
                    db()->update('prompt_templates', $data, 'id = ?', [$existing['id']]);
                } else {
                    db()->insert('prompt_templates', $data);
                }
                setFlash('success', 'รีเซ็ต "' . $promptTypes[$type]['name'] . '" กลับ Gambling Default แล้ว');
            } else {
                db()->query(
                    "DELETE FROM prompt_templates WHERE template_type=? AND owner_type='member' AND owner_id=?",
                    [$type, $memberId]
                );
                setFlash('success', 'รีเซ็ต "' . $promptTypes[$type]['name'] . '" กลับค่าเริ่มต้นแล้ว');
            }
        }
    }

        redirect('/member/prompts.php#prompt-' . $type);
}

$flash = getFlash();

// Load all member's saved prompts keyed by type
$memberPrompts = [];
$rows = db()->fetchAll(
    "SELECT template_type, prompt_content, usage_count, updated_at
     FROM prompt_templates WHERE owner_type='member' AND owner_id=? AND is_active=1",
    [$memberId]
);
foreach ($rows as $r) {
    $memberPrompts[$r['template_type']] = $r;
}

// Load admin/system saved prompts as fallback
$adminPrompts = [];
$adminRows = db()->fetchAll(
    "SELECT template_type, prompt_content FROM prompt_templates
     WHERE (owner_type='admin' OR owner_type IS NULL) AND is_active=1"
);
foreach ($adminRows as $r) {
    $adminPrompts[$r['template_type']] = $r['prompt_content'];
}

// Build system defaults map for JS
// Gambling member: system/article default = gambling prompt; ที่เหลือใช้ admin → hardcode
$jsDefaults = [];
foreach ($promptTypes as $type => $info) {
    if ($isGambling && isset($gamblingPrompts[$type])) {
        $jsDefaults[$type] = $gamblingPrompts[$type];
    } else {
        $jsDefaults[$type] = $adminPrompts[$type] ?? ($defaultPrompts[$type] ?? '');
    }
}

$configuredCount = count($memberPrompts);
$totalCount      = count($promptTypes);

// SEO settings
$sysMin   = getSetting('min_word_count', defined('DEFAULT_ARTICLE_MIN_WORDS') ? DEFAULT_ARTICLE_MIN_WORDS : 2500);
$sysMax   = getSetting('max_word_count', defined('DEFAULT_ARTICLE_MAX_WORDS') ? DEFAULT_ARTICLE_MAX_WORDS : 4000);
$sysLinks = getSetting('internal_links_per_article', 1);
$curMin   = getMemberSetting($memberId, 'min_word_count');
$curMax   = getMemberSetting($memberId, 'max_word_count');
$curLinks = getMemberSetting($memberId, 'internal_links_per_article');
$hasSeoOverride = ($curMin !== null || $curMax !== null || $curLinks !== null);
$displayMin   = $curMin   ?? $sysMin;
$displayMax   = $curMax   ?? $sysMax;
$displayLinks = $curLinks ?? $sysLinks;
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> mb-4">
    <i class="fas fa-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?> me-2"></i>
    <?= sanitize($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1E293B;">Prompt Templates</h4>
        <p class="text-muted mb-0">จัดการ Prompt ที่ AI ใช้สร้างเนื้อหาให้คุณ (<?= $configuredCount ?>/<?= $totalCount ?> ปรับแต่งแล้ว)</p>
    </div>
    <?php if ($isGambling): ?>
    <span class="badge fs-6 px-3 py-2" style="background:rgba(239,68,68,.15);color:#B91C1C;border:1px solid rgba(239,68,68,.3);">
        <i class="fas fa-dice me-2"></i>โหมดพนัน — Gambling Prompts
    </span>
    <?php endif; ?>
</div>

<?php if ($isGambling): ?>
<div class="alert mb-4" style="background:rgba(239,68,68,.06);border-left:4px solid #EF4444;color:#7F1D1D;font-size:13px;">
    <i class="fas fa-dice me-2" style="color:#EF4444;"></i>
    บัญชีนี้ใช้ <strong>Gambling Prompts</strong> — AI จะเขียนเนื้อหาเกี่ยวกับสล็อต บาคาร่า คาสิโนโดยอัตโนมัติ
    ปุ่ม <strong>Copy จากค่าเริ่มต้น</strong> จะนำ Gambling Default มาให้แก้ไข และปุ่ม <strong>รีเซ็ต</strong> จะกลับเป็น Gambling Default (ไม่ใช่ prompt ทั่วไป)
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-card-icon"><i class="fas fa-pen-nib"></i></div>
            <div class="stat-card-value"><?= $totalCount ?></div>
            <div class="stat-card-label">Prompt ทั้งหมด</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-gradient-success">
            <div class="stat-card-icon"><i class="fas fa-star"></i></div>
            <div class="stat-card-value"><?= $configuredCount ?></div>
            <div class="stat-card-label">ปรับแต่งแล้ว (Custom)</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-card-icon"><i class="fas fa-cog"></i></div>
            <div class="stat-card-value"><?= $totalCount - $configuredCount ?></div>
            <div class="stat-card-label">ใช้ค่าเริ่มต้น</div>
        </div>
    </div>
</div>

<!-- SEO Settings -->
<div class="card mb-4" id="seo-settings">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-sliders-h me-2 text-primary"></i>ตั้งค่า SEO บทความ</span>
        <?php if ($hasSeoOverride): ?>
        <span class="badge bg-success">Custom</span>
        <?php else: ?>
        <span class="badge bg-secondary">ค่าเริ่มต้นของระบบ</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save_word_settings">

            <!-- Presets -->
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:13px;">เลือกแบบสำเร็จรูป</label>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary seo-preset"
                            data-min="500" data-max="800" data-links="1">
                        <i class="fas fa-feather me-1"></i>สั้นมาก<br><small class="text-muted">500–800 คำ</small>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info seo-preset"
                            data-min="800" data-max="1200" data-links="2">
                        <i class="fas fa-align-left me-1"></i>สั้น<br><small class="text-muted">800–1,200 คำ</small>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary seo-preset"
                            data-min="1500" data-max="2500" data-links="3">
                        <i class="fas fa-align-justify me-1"></i>กลาง <span class="badge bg-primary ms-1" style="font-size:9px;">แนะนำ</span><br><small class="text-muted">1,500–2,500 คำ</small>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success seo-preset"
                            data-min="2500" data-max="4000" data-links="5">
                        <i class="fas fa-file-alt me-1"></i>ยาว<br><small class="text-muted">2,500–4,000 คำ</small>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning seo-preset"
                            data-min="4000" data-max="6000" data-links="7">
                        <i class="fas fa-book me-1"></i>ยาวมาก<br><small class="text-muted">4,000–6,000 คำ</small>
                    </button>
                </div>
            </div>
            <hr class="my-3">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">จำนวนคำขั้นต่ำ</label>
                    <input type="number" class="form-control" name="min_words" value="<?= $displayMin ?>" min="500" max="10000" step="100">
                    <small class="text-muted">ใช้แทน <code>{min_words}</code></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">จำนวนคำสูงสุด</label>
                    <input type="number" class="form-control" name="max_words" value="<?= $displayMax ?>" min="500" max="20000" step="100">
                    <small class="text-muted">ใช้แทน <code>{max_words}</code></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Internal Links / บทความ</label>
                    <input type="number" class="form-control" name="internal_links" value="<?= $displayLinks ?>" min="0" max="20">
                    <small class="text-muted">0 = ปิด Internal Links</small>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>บันทึก</button>
                <?php if ($hasSeoOverride): ?>
                <button type="submit" name="action" value="reset_word_settings" class="btn btn-outline-secondary btn-sm"
                        onclick="return confirm('รีเซ็ตกลับค่าเริ่มต้น?')">
                    <i class="fas fa-undo me-1"></i>รีเซ็ต
                </button>
                <?php endif; ?>
            </div>
        </form>
        <div class="mt-3 small text-muted">
            <?php if ($hasSeoOverride): ?>
            <i class="fas fa-check-circle text-success me-1"></i>ใช้การตั้งค่าของคุณ: <?= $displayMin ?>–<?= $displayMax ?> คำ | <?= $displayLinks ?> internal links/บทความ
            <?php else: ?>
            <i class="fas fa-info-circle me-1"></i>ค่าเริ่มต้นระบบ: <?= $sysMin ?>–<?= $sysMax ?> คำ | <?= $sysLinks ?> internal links/บทความ
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Alert info -->
<div class="alert alert-info mb-4" style="font-size:13px;">
    <i class="fas fa-info-circle me-2"></i>
    Prompt ที่คุณปรับแต่งจะถูกใช้<strong>เฉพาะบทความของคุณเท่านั้น</strong>
    — Prompt ที่ยังไม่ได้ปรับแต่งจะใช้ค่าเริ่มต้นของระบบโดยอัตโนมัติ
</div>

<!-- Accordion -->
<div class="accordion" id="promptsAccordion">
<?php foreach ($promptTypes as $type => $info):
    $saved       = $memberPrompts[$type] ?? null;
    $isCustom    = !empty($saved);
    $usageCount  = $saved['usage_count'] ?? 0;
    $updatedAt   = $saved['updated_at'] ?? null;

    // Fallback: member → admin → hardcoded default
    if ($isCustom) {
        $displayContent = $saved['prompt_content'];
    } elseif (!empty($adminPrompts[$type])) {
        $displayContent = $adminPrompts[$type];
    } else {
        $displayContent = $defaultPrompts[$type] ?? '';
    }
    $hasDefault = !empty($jsDefaults[$type]);
?>
<div class="accordion-item mb-2 border rounded" id="prompt-<?= $type ?>" style="overflow:hidden;">
    <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#collapse-<?= $type ?>"
                style="background:#fff;border-radius:0;">
            <div class="d-flex align-items-center w-100 me-3">
                <i class="fas <?= $info['icon'] ?> me-3 text-primary" style="width:20px;font-size:15px;"></i>
                <div class="flex-grow-1">
                    <strong style="font-size:14px;"><?= sanitize($info['name']) ?></strong>
                    <small class="text-muted d-block" style="font-size:12px;"><?= sanitize($info['desc']) ?></small>
                </div>
                <div class="text-end me-3 d-flex gap-2 align-items-center">
                    <?php if ($isCustom): ?>
                    <span class="badge bg-success"><i class="fas fa-star me-1"></i>Custom</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">ค่าเริ่มต้น</span>
                    <?php endif; ?>
                    <?php if ($usageCount > 0): ?>
                    <span class="badge bg-info">ใช้ <?= number_format($usageCount) ?> ครั้ง</span>
                    <?php endif; ?>
                </div>
            </div>
        </button>
    </h2>

    <div id="collapse-<?= $type ?>" class="accordion-collapse collapse">
        <div class="accordion-body" style="background:#FAFBFF;">

            <?php if ($updatedAt): ?>
            <small class="text-muted d-block mb-2">
                <i class="fas fa-clock me-1"></i>แก้ไขล่าสุด: <?= timeAgo($updatedAt) ?>
            </small>
            <?php elseif (!$isCustom): ?>
            <small class="text-info d-block mb-2">
                <i class="fas fa-info-circle me-1"></i>แสดง prompt เริ่มต้นของระบบ — แก้ไขแล้วกดบันทึกเพื่อใช้ prompt ของคุณเอง
            </small>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_prompt">
                <input type="hidden" name="template_type" value="<?= $type ?>">

                <textarea class="form-control font-monospace mb-3" name="prompt_content"
                          id="ta-<?= $type ?>" rows="14"
                          style="font-size:12px;line-height:1.5;resize:vertical;"
                          placeholder="ใส่ prompt ที่นี่..."><?= htmlspecialchars($displayContent) ?></textarea>

                <?php if (!empty($info['vars'])): ?>
                <div class="mb-3">
                    <small class="text-muted fw-semibold me-2">ตัวแปรที่ใช้ได้ (คลิกเพื่อแทรก):</small>
                    <?php foreach ($info['vars'] as $var): ?>
                    <code class="me-1 px-2 py-1 rounded" style="cursor:pointer;background:#EEF2FF;color:#4F46E5;border:1px solid #C7D2FE;font-size:11px;"
                          onclick="insertVar('ta-<?= $type ?>','<?= $var ?>')"><?= $var ?></code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <?php if ($isCustom): ?>
                        <small class="text-success"><i class="fas fa-check-circle me-1"></i>Prompt นี้จะถูกใช้แทน prompt ระบบ</small>
                        <?php else: ?>
                        <small class="text-muted"><i class="fas fa-info-circle me-1"></i>กรอก prompt แล้วกดบันทึกเพื่อใช้แทนค่าเริ่มต้น</small>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($hasDefault): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="copyDefault('ta-<?= $type ?>','<?= $type ?>')">
                            <i class="fas fa-copy me-1"></i>Copy จาก<?= ($isGambling && isset($gamblingPrompts[$type])) ? 'Gambling Default' : 'ค่าเริ่มต้น' ?>
                        </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save me-1"></i>บันทึก
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($isCustom): ?>
            <hr class="my-3">
            <?php $resetLabel = ($isGambling && isset($gamblingPrompts[$type])) ? 'รีเซ็ตกลับ Gambling Default' : 'รีเซ็ตกลับค่าเริ่มต้น'; ?>
            <form method="POST" onsubmit="return confirm('<?= $resetLabel ?>?')">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="reset_prompt">
                <input type="hidden" name="template_type" value="<?= $type ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-undo me-1"></i><?= $resetLabel ?>
                </button>
            </form>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Help Card -->
<div class="card mt-4">
    <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>วิธีใช้งาน</div>
    <div class="card-body" style="font-size:13px;">
        <ul class="ps-3 mb-0" style="line-height:2;">
            <li>Prompt ที่ <span class="badge bg-success">Custom</span> จะถูกใช้แทน prompt ระบบ <strong>เฉพาะบทความของคุณ</strong></li>
            <li>Prompt ที่ <span class="badge bg-secondary">ค่าเริ่มต้น</span> ยังใช้ prompt ของระบบ — กรอกแล้วกดบันทึกเพื่อ override</li>
            <li>ปุ่ม <strong>Copy จากค่าเริ่มต้น</strong> จะนำ prompt ระบบมาใส่ใน editor เพื่อให้แก้ไขต่อ (ยังไม่บันทึก)</li>
            <li>กด <strong>รีเซ็ตกลับค่าเริ่มต้น</strong> เพื่อยกเลิก Custom prompt และใช้ค่าของระบบ</li>
            <li>ใช้ตัวแปรเช่น <code>{keyword}</code>, <code>{min_words}</code> ระบบจะแทรกค่าจริงอัตโนมัติเมื่อสร้างบทความ</li>
        </ul>
    </div>
</div>

<script>
const promptDefaults = <?= json_encode($jsDefaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// SEO Preset buttons
document.querySelectorAll('.seo-preset').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelector('[name="min_words"]').value = this.dataset.min;
        document.querySelector('[name="max_words"]').value = this.dataset.max;
        document.querySelector('[name="internal_links"]').value = this.dataset.links;
        document.querySelectorAll('.seo-preset').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
    });
});

function insertVar(taId, v) {
    const ta = document.getElementById(taId);
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0, s) + v + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
}

function copyDefault(taId, type) {
    const content = promptDefaults[type] || '';
    document.getElementById(taId).value = content;
    document.getElementById(taId).focus();
}

// Auto-open accordion if URL has hash
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash) {
        const target = document.querySelector(window.location.hash + ' .accordion-collapse');
        if (target) {
            new bootstrap.Collapse(target, { show: true });
            setTimeout(() => document.querySelector(window.location.hash).scrollIntoView({ behavior: 'smooth' }), 300);
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
