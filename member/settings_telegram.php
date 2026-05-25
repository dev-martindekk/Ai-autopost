<?php
$pageTitle = 'ตั้งค่า Telegram';
require_once __DIR__ . '/header.php';

$memberId  = (int)$currentMember['id'];
$ownerType = 'member';
$ownerId   = $memberId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $botToken           = trim($_POST['bot_token'] ?? '');
        $chatId             = trim($_POST['chat_id'] ?? '');
        $threadId           = trim($_POST['thread_id'] ?? '') ?: null;
        $isEnabled          = isset($_POST['is_enabled']) ? 1 : 0;
        $notifyOnPost       = isset($_POST['notify_on_post']) ? 1 : 0;
        $notifyOnError      = isset($_POST['notify_on_error']) ? 1 : 0;
        $notifyWeeklyReport = isset($_POST['notify_weekly_report']) ? 1 : 0;

        $existing = db()->fetchOne(
            "SELECT id, bot_token FROM telegram_settings WHERE setting_name='default' AND owner_type=? AND owner_id=?",
            [$ownerType, $ownerId]
        );

        $data = [
            'chat_id'               => $chatId,
            'thread_id'             => $threadId,
            'is_enabled'            => $isEnabled,
            'notify_on_post'        => $notifyOnPost,
            'notify_on_error'       => $notifyOnError,
            'notify_weekly_report'  => $notifyWeeklyReport,
        ];

        if (!empty($botToken)) $data['bot_token'] = $botToken;

        if ($existing) {
            db()->update('telegram_settings', $data, 'id=?', [$existing['id']]);
        } else {
            $data['setting_name'] = 'default';
            $data['bot_token']    = $botToken;
            $data['owner_type']   = $ownerType;
            $data['owner_id']     = $ownerId;
            db()->insert('telegram_settings', $data);
        }

        setFlash('success', 'บันทึกการตั้งค่า Telegram สำเร็จ');
    } elseif ($action === 'test') {
        require_once __DIR__ . '/../includes/telegram_client.php';
        $saved = db()->fetchOne(
            "SELECT * FROM telegram_settings WHERE setting_name='default' AND owner_type=? AND owner_id=?",
            [$ownerType, $ownerId]
        );
        if (!$saved || empty($saved['bot_token']) || empty($saved['chat_id'])) {
            setFlash('error', 'กรุณาบันทึก Bot Token และ Chat ID ก่อนทดสอบ');
        } else {
            $tg = new TelegramClient($saved);
            $result = $tg->sendMessage("🤖 <b>AI AutoPost SEO</b>\n\nทดสอบการแจ้งเตือน Telegram สำเร็จ!");
            if ($result['success']) setFlash('success', 'ส่งข้อความทดสอบสำเร็จ');
            else setFlash('error', 'ส่งข้อความล้มเหลว: ' . ($result['message'] ?? 'Unknown error'));
        }
    }

    redirect('/member/settings_telegram.php');
}

// Load settings
$settings = db()->fetchOne(
    "SELECT * FROM telegram_settings WHERE setting_name='default' AND owner_type=? AND owner_id=?",
    [$ownerType, $ownerId]
) ?: [
    'bot_token' => '', 'chat_id' => '', 'thread_id' => '', 'is_enabled' => 1,
    'notify_on_post' => 1, 'notify_on_error' => 1, 'notify_weekly_report' => 1,
];

$flash = getFlash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<div class="row">
<div class="col-lg-8">
    <div class="card">
        <div class="card-header"><i class="fab fa-telegram me-2" style="color:#229ED9;"></i>Telegram Bot Settings</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save">

                <div class="mb-4">
                    <label class="form-label">Bot Token</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="bot_token" id="bot_token"
                               placeholder="<?= !empty($settings['bot_token']) ? '••••••••' : '123456789:ABCdefGHIjklMNOpqrsTUVwxyz' ?>"
                               autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleField('bot_token','toggleBotIcon')">
                            <i class="fas fa-eye" id="toggleBotIcon"></i>
                        </button>
                    </div>
                    <?php if (!empty($settings['bot_token'])): ?>
                    <small class="text-success"><i class="fas fa-check-circle me-1"></i>Bot Token ถูกตั้งค่าแล้ว (ปล่อยว่างเพื่อคงค่าเดิม)</small>
                    <?php else: ?>
                    <small class="text-muted">สร้าง Bot ได้ที่ <a href="https://t.me/BotFather" target="_blank">@BotFather</a></small>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <label class="form-label">Chat ID</label>
                    <input type="text" class="form-control" name="chat_id"
                           value="<?= sanitize($settings['chat_id']) ?>" placeholder="-1001234567890">
                    <small class="text-muted">ใช้ <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> เพื่อดู Chat ID ของคุณ</small>
                </div>

                <div class="mb-4">
                    <label class="form-label">Thread ID <small class="text-muted">(สำหรับ Forum/Topic)</small></label>
                    <input type="text" class="form-control" name="thread_id"
                           value="<?= sanitize($settings['thread_id'] ?? '') ?>" placeholder="ปล่อยว่างถ้าไม่ใช้">
                </div>

                <div class="mb-4">
                    <label class="form-label d-block">การแจ้งเตือน</label>
                    <?php foreach ([
                        ['is_enabled',           'เปิดใช้งาน Telegram'],
                        ['notify_on_post',        'แจ้งเตือนเมื่อโพสต์บทความสำเร็จ'],
                        ['notify_on_error',       'แจ้งเตือนเมื่อเกิดข้อผิดพลาด'],
                        ['notify_weekly_report',  'รายงานประจำสัปดาห์'],
                    ] as [$name, $label]): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="<?= $name ?>" id="<?= $name ?>"
                               <?= ($settings[$name] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= $name ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>บันทึก
                    </button>
                </div>
            </form>

            <?php if (!empty($settings['bot_token']) && !empty($settings['chat_id'])): ?>
            <hr>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="test">
                <button type="submit" class="btn btn-outline-info">
                    <i class="fas fa-paper-plane me-2"></i>ทดสอบส่งข้อความ
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="col-lg-4">
    <div class="card">
        <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>วิธีตั้งค่า</div>
        <div class="card-body">
            <ol class="small ps-3 mb-0" style="line-height:2;">
                <li>เปิด Telegram แล้วคุยกับ <a href="https://t.me/BotFather" target="_blank">@BotFather</a></li>
                <li>พิมพ์ <code>/newbot</code> และตั้งชื่อ Bot</li>
                <li>คัดลอก Token มาใส่ในช่อง Bot Token</li>
                <li>เพิ่ม Bot เข้ากลุ่มหรือใช้ <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> เพื่อรับ Chat ID</li>
                <li>กดบันทึกและทดสอบส่งข้อความ</li>
            </ol>
        </div>
    </div>
</div>
</div>

<script>
function toggleField(id, iconId) {
    const el = document.getElementById(id);
    const icon = document.getElementById(iconId);
    if (el.type === 'password') { el.type = 'text'; icon.className = 'fas fa-eye-slash'; }
    else { el.type = 'password'; icon.className = 'fas fa-eye'; }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
