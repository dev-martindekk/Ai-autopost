<?php
/**
 * AI AutoPost SEO System - Telegram Settings
 * ===========================================
 */

require_once __DIR__ . '/../includes/auth.php';
auth()->requireAuth();

$pageTitle = 'ตั้งค่า Telegram';
require_once __DIR__ . '/header.php';

// Get owner info for filtering
$ownerData = auth()->getOwnerData();
$ownerType = $ownerData['owner_type'];
$ownerId = $ownerData['owner_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Session หมดอายุ กรุณาลองใหม่');
        redirect(ADMIN_URL . '/settings_telegram.php');
    }

    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'save') {
            $botToken = trim($_POST['bot_token'] ?? '');
            $chatId = trim($_POST['chat_id'] ?? '');
            $threadId = trim($_POST['thread_id'] ?? '') ?: null;
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $notifyOnPost = isset($_POST['notify_on_post']) ? 1 : 0;
            $notifyOnError = isset($_POST['notify_on_error']) ? 1 : 0;
            $notifyWeeklyReport = 0;

            // Check if settings exist for this owner
            $existing = db()->fetchOne(
                "SELECT id FROM telegram_settings WHERE setting_name = 'default' AND owner_type = ? AND owner_id = ?",
                [$ownerType, $ownerId]
            );

            $data = [
                'chat_id' => $chatId,
                'thread_id' => $threadId,
                'is_enabled' => $isEnabled,
                'notify_on_post' => $notifyOnPost,
                'notify_on_error' => $notifyOnError,
                'notify_weekly_report' => $notifyWeeklyReport
            ];

            // Only update bot_token if provided
            if (!empty($botToken)) {
                $data['bot_token'] = $botToken;
            }

            if ($existing) {
                db()->update('telegram_settings', $data, 'id = ?', [$existing['id']]);
            } else {
                $data['setting_name'] = 'default';
                $data['bot_token'] = $botToken;
                $data['owner_type'] = $ownerType;
                $data['owner_id'] = $ownerId;
                db()->insert('telegram_settings', $data);
            }

            logEvent('info', 'settings', 'Telegram settings updated');
            setFlash('success', 'บันทึกการตั้งค่า Telegram สำเร็จ');
        }
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    redirect(ADMIN_URL . '/settings_telegram.php');
}

// Get current settings for this owner
$settings = db()->fetchOne(
    "SELECT * FROM telegram_settings WHERE setting_name = 'default' AND owner_type = ? AND owner_id = ?",
    [$ownerType, $ownerId]
);
if (!$settings) {
    $settings = [
        'bot_token' => '',
        'chat_id' => '',
        'thread_id' => '',
        'is_enabled' => 1,
        'notify_on_post' => 1,
        'notify_on_error' => 1,
        'notify_weekly_report' => 1,
        'last_test_status' => 'pending',
        'last_test_time' => null
    ];
}
?>

<div class="page-header">
    <h1 class="page-title">ตั้งค่า Telegram</h1>
    <p class="page-subtitle">ตั้งค่า Bot Token และ Chat ID สำหรับรับแจ้งเตือน</p>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="fab fa-telegram me-2"></i>Telegram Bot Settings
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="save">

                    <!-- Bot Token -->
                    <div class="mb-4">
                        <label class="form-label">Bot Token</label>
                        <div class="input-group">
                            <input type="password"
                                   class="form-control"
                                   name="bot_token"
                                   id="bot_token"
                                   placeholder="<?= !empty($settings['bot_token']) ? '••••••••' : '123456789:ABCdefGHIjklMNOpqrsTUVwxyz' ?>"
                                   autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleBotToken()">
                                <i class="fas fa-eye" id="toggleBotIcon"></i>
                            </button>
                        </div>
                        <?php if (!empty($settings['bot_token'])): ?>
                            <small class="text-muted">
                                <i class="fas fa-check-circle text-success"></i>
                                Bot Token ถูกตั้งค่าแล้ว (ปล่อยว่างเพื่อคงค่าเดิม)
                            </small>
                        <?php else: ?>
                            <small class="text-muted">
                                สร้าง Bot ได้ที่ <a href="https://t.me/BotFather" target="_blank">@BotFather</a>
                            </small>
                        <?php endif; ?>
                    </div>

                    <!-- Chat ID -->
                    <div class="mb-4">
                        <label class="form-label">Chat ID</label>
                        <input type="text"
                               class="form-control"
                               name="chat_id"
                               value="<?= sanitize($settings['chat_id']) ?>"
                               placeholder="-1001234567890">
                        <small class="text-muted">
                            Chat ID ของกลุ่มหรือผู้ใช้ที่ต้องการรับแจ้งเตือน
                            (ใช้ <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> เพื่อดู Chat ID)
                        </small>
                    </div>

                    <!-- Thread ID (for forum groups) -->
                    <div class="mb-4">
                        <label class="form-label">Thread ID (Optional)</label>
                        <input type="text"
                               class="form-control"
                               name="thread_id"
                               value="<?= sanitize($settings['thread_id'] ?? '') ?>"
                               placeholder="123">
                        <small class="text-muted">
                            ใช้สำหรับกลุ่มที่เปิด Forum Topics (ไม่จำเป็นต้องกรอกถ้าไม่ใช้)
                        </small>
                    </div>

                    <hr class="my-4">

                    <!-- Notification Settings -->
                    <h6 class="mb-3"><i class="fas fa-bell me-2"></i>การแจ้งเตือน</h6>

                    <div class="mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="is_enabled"
                                   id="is_enabled"
                                   <?= $settings['is_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_enabled">
                                <strong>เปิดใช้งาน Telegram</strong>
                            </label>
                        </div>
                    </div>

                    <div class="ms-4">
                        <div class="form-check mb-2">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="notify_on_post"
                                   id="notify_on_post"
                                   <?= ($settings['notify_on_post'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notify_on_post">
                                <i class="fas fa-user-plus text-success me-1"></i>
                                แจ้งเตือนเมื่อมีสมาชิกใหม่รอยืนยัน
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="notify_on_error"
                                   id="notify_on_error"
                                   <?= ($settings['notify_on_error'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notify_on_error">
                                <i class="fas fa-receipt text-warning me-1"></i>
                                แจ้งเตือนเมื่อมีสลิปรอตรวจสอบ
                            </label>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Test Status -->
                    <?php if ($settings['last_test_time']): ?>
                        <div class="mb-4">
                            <small class="text-muted">
                                ทดสอบล่าสุด: <?= timeAgo($settings['last_test_time']) ?>
                                <?php if ($settings['last_test_status'] === 'success'): ?>
                                    <span class="text-success"><i class="fas fa-check"></i> สำเร็จ</span>
                                <?php elseif ($settings['last_test_status'] === 'failed'): ?>
                                    <span class="text-danger"><i class="fas fa-times"></i> ล้มเหลว</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endif; ?>

                    <!-- Buttons -->
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
                        </button>
                        <button type="button" class="btn btn-outline-success" id="testTelegramBtn">
                            <i class="fas fa-paper-plane me-2"></i>ส่งข้อความทดสอบ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Instructions Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle me-2"></i>วิธีตั้งค่า
            </div>
            <div class="card-body">
                <h6>1. สร้าง Bot</h6>
                <p class="small text-muted">
                    ไปที่ <a href="https://t.me/BotFather" target="_blank">@BotFather</a>
                    แล้วใช้คำสั่ง <code>/newbot</code> เพื่อสร้าง Bot ใหม่
                </p>

                <h6>2. รับ Bot Token</h6>
                <p class="small text-muted">
                    หลังสร้าง Bot สำเร็จ จะได้รับ Token ในรูปแบบ:
                    <code>123456789:ABCdef...</code>
                </p>

                <h6>3. หา Chat ID</h6>
                <p class="small text-muted">
                    - เพิ่ม Bot เข้ากลุ่ม<br>
                    - ใช้ <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> หรือ
                    <a href="https://t.me/getidsbot" target="_blank">@getidsbot</a> เพื่อดู Chat ID
                </p>

                <h6>4. ทดสอบการเชื่อมต่อ</h6>
                <p class="small text-muted mb-0">
                    กดปุ่ม "ส่งข้อความทดสอบ" เพื่อตรวจสอบว่าการตั้งค่าถูกต้อง
                </p>
            </div>
        </div>

        <!-- Recent Notifications -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-history me-2"></i>การแจ้งเตือนล่าสุด
            </div>
            <div class="card-body">
                <?php
                try {
                    $recentLogs = db()->fetchAll("
                        SELECT * FROM system_logs
                        WHERE category = 'telegram'
                        ORDER BY created_at DESC
                        LIMIT 5
                    ");
                } catch (Exception $e) {
                    $recentLogs = [];
                }
                ?>

                <?php if (empty($recentLogs)): ?>
                    <p class="text-muted text-center mb-0">
                        <i class="fas fa-inbox"></i><br>
                        ยังไม่มีการแจ้งเตือน
                    </p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($recentLogs as $log): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <small class="text-muted"><?= timeAgo($log['created_at']) ?></small>
                                <br>
                                <?php if ($log['log_type'] === 'error'): ?>
                                    <span class="text-danger"><i class="fas fa-times-circle"></i></span>
                                <?php else: ?>
                                    <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                <?php endif; ?>
                                <?= sanitize(truncateText($log['message'], 50)) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Test Result Modal -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fab fa-telegram me-2"></i>ทดสอบ Telegram</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="testResult" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">กำลังทดสอบ...</span>
                    </div>
                    <p class="mt-3 mb-0">กำลังส่งข้อความทดสอบ...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$adminUrl = ADMIN_URL;
$pageScripts = '<script>
// Toggle bot token visibility
function toggleBotToken() {
    const input = document.getElementById(\'bot_token\');
    const icon = document.getElementById(\'toggleBotIcon\');

    if (input.type === \'password\') {
        input.type = \'text\';
        icon.classList.remove(\'fa-eye\');
        icon.classList.add(\'fa-eye-slash\');
    } else {
        input.type = \'password\';
        icon.classList.remove(\'fa-eye-slash\');
        icon.classList.add(\'fa-eye\');
    }
}

// Test Telegram connection
$(\'#testTelegramBtn\').click(function() {
    const botToken = $(\'#bot_token\').val();
    const chatId = $(\'input[name="chat_id"]\').val();

    if (!chatId) {
        toastr.error(\'กรุณากรอก Chat ID\');
        return;
    }

    $(\'#testResult\').html(\'<div class="spinner-border text-primary" role="status"><span class="visually-hidden">กำลังทดสอบ...</span></div><p class="mt-3 mb-0">กำลังส่งข้อความทดสอบ...</p>\');

    const modal = new bootstrap.Modal(document.getElementById(\'testModal\'));
    modal.show();

    $.ajax({
        url: \'' . $adminUrl . '/ajax/test_telegram.php\',
        method: \'POST\',
        data: {
            csrf_token: csrfToken,
            bot_token: botToken,
            chat_id: chatId
        },
        success: function(response) {
            if (response.success) {
                let html = \'<div class="text-success">\' +
                    \'<i class="fas fa-check-circle fa-3x mb-3"></i>\' +
                    \'<h5>ส่งข้อความสำเร็จ!</h5>\' +
                    \'<p class="mb-0">\' + response.message + \'</p>\';
                if (response.bot_name) {
                    html += \'<p class="text-muted mt-2">Bot: @\' + response.bot_username + \'</p>\';
                }
                html += \'</div>\';
                $(\'#testResult\').html(html);
            } else {
                $(\'#testResult\').html(\'<div class="text-danger"><i class="fas fa-times-circle fa-3x mb-3"></i><h5>ส่งข้อความล้มเหลว</h5><p class="mb-0">\' + response.message + \'</p></div>\');
            }
        },
        error: function() {
            $(\'#testResult\').html(\'<div class="text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><h5>เกิดข้อผิดพลาด</h5><p class="mb-0">ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์</p></div>\');
        }
    });
});
</script>';
?>

<?php require_once __DIR__ . '/footer.php'; ?>
