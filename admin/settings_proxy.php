<?php
$pageTitle = 'Anti-Block Proxy';
require_once __DIR__ . '/../includes/auth.php';
auth()->requireRole('admin');
require_once __DIR__ . '/header.php';

$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $enabled = isset($_POST['proxy_enabled']) ? '1' : '0';
    $mode    = in_array($_POST['proxy_mode'] ?? '', ['doh', 'proxy']) ? $_POST['proxy_mode'] : 'doh';
    $dohUrl  = trim($_POST['proxy_doh_url'] ?? 'https://cloudflare-dns.com/dns-query');
    $host    = trim($_POST['proxy_host'] ?? 'warp-proxy');
    $port    = max(1, min(65535, (int)($_POST['proxy_port'] ?? 1080)));
    $type    = in_array($_POST['proxy_type'] ?? '', ['socks5', 'http']) ? $_POST['proxy_type'] : 'socks5';

    setSetting('proxy_enabled',  $enabled, 'string');
    setSetting('proxy_mode',     $mode,    'string');
    setSetting('proxy_doh_url',  $dohUrl,  'string');
    setSetting('proxy_host',     $host,    'string');
    setSetting('proxy_port',     (string)$port, 'string');
    setSetting('proxy_type',     $type,    'string');

    logEvent('info', 'proxy', 'Proxy settings updated', ['enabled' => $enabled, 'mode' => $mode]);
    setFlash('success', 'บันทึกการตั้งค่า Anti-Block เรียบร้อยแล้ว');
    redirect(ADMIN_URL . '/settings_proxy.php');
}

$enabled = getSetting('proxy_enabled', '0') === '1';
$mode    = getSetting('proxy_mode', 'doh');
$dohUrl  = getSetting('proxy_doh_url', 'https://cloudflare-dns.com/dns-query');
$host    = getSetting('proxy_host', 'warp-proxy');
$port    = getSetting('proxy_port', '1080');
$type    = getSetting('proxy_type', 'socks5');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-title">Anti-Block Proxy / WARP</h1>
    <p class="page-subtitle">ตั้งค่าให้ระบบโพสบทความผ่าน Proxy หรือ DoH เมื่อเว็บลูกค้าถูก MDES บล็อก</p>
</div>

<div class="row g-4">
<div class="col-lg-7">

<div class="card">
    <div class="card-header"><i class="fas fa-shield-halved me-2 text-primary"></i>การตั้งค่า</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <!-- Enable toggle -->
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="proxy_enabled"
                           id="proxyEnabled" <?= $enabled ? 'checked' : '' ?> onchange="toggleFields()">
                    <label class="form-check-label fw-bold" for="proxyEnabled">
                        เปิดใช้งาน Anti-Block
                    </label>
                </div>
                <small class="text-muted">เมื่อเปิด ระบบจะส่ง request ผ่าน Proxy/DoH ทุกครั้งที่โพสไปยัง WordPress</small>
            </div>

            <div id="proxyFields" <?= $enabled ? '' : 'style="opacity:.4;pointer-events:none;"' ?>>

                <!-- Mode -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">โหมด</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="proxy_mode" id="modeDoh"
                                   value="doh" <?= $mode === 'doh' ? 'checked' : '' ?> onchange="switchMode()">
                            <label class="form-check-label" for="modeDoh">
                                <strong>DoH</strong> (DNS over HTTPS)
                                <div class="text-muted" style="font-size:12px;">ใช้ DNS ทางเลือก — เหมาะกับไซต์ถูกบล็อกระดับ DNS</div>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="proxy_mode" id="modeProxy"
                                   value="proxy" <?= $mode === 'proxy' ? 'checked' : '' ?> onchange="switchMode()">
                            <label class="form-check-label" for="modeProxy">
                                <strong>SOCKS5 / HTTP Proxy</strong>
                                <div class="text-muted" style="font-size:12px;">ส่งผ่าน WARP หรือ proxy server — เหมาะกับทุกกรณี</div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- DoH settings -->
                <div id="dohSettings" <?= $mode !== 'doh' ? 'style="display:none;"' : '' ?>>
                    <div class="mb-3">
                        <label class="form-label">DoH URL</label>
                        <input type="url" name="proxy_doh_url" class="form-control"
                               value="<?= sanitize($dohUrl) ?>"
                               placeholder="https://cloudflare-dns.com/dns-query">
                        <small class="text-muted">
                            Cloudflare: <code>https://cloudflare-dns.com/dns-query</code> |
                            Google: <code>https://dns.google/dns-query</code>
                        </small>
                    </div>
                </div>

                <!-- Proxy settings -->
                <div id="proxySettings" <?= $mode !== 'proxy' ? 'style="display:none;"' : '' ?>>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Proxy Host</label>
                            <input type="text" name="proxy_host" class="form-control"
                                   value="<?= sanitize($host) ?>" placeholder="warp-proxy หรือ 127.0.0.1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Port</label>
                            <input type="number" name="proxy_port" class="form-control"
                                   value="<?= (int)$port ?>" min="1" max="65535">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Protocol</label>
                            <select name="proxy_type" class="form-select">
                                <option value="socks5" <?= $type === 'socks5' ? 'selected' : '' ?>>SOCKS5</option>
                                <option value="http"   <?= $type === 'http'   ? 'selected' : '' ?>>HTTP</option>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info py-2" style="font-size:13px;">
                        <i class="fas fa-info-circle me-1"></i>
                        สำหรับ Cloudflare WARP: ติดตั้ง <code>warp-cli</code> บน server แล้วตั้ง
                        Host = <code>warp-proxy</code>, Port = <code>1080</code>, Type = <code>SOCKS5</code>
                    </div>
                </div>

            </div><!-- /proxyFields -->

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
            </button>
        </form>
    </div>
</div>

</div>

<!-- Right: Info -->
<div class="col-lg-5">
    <div class="card border-warning">
        <div class="card-header bg-warning bg-opacity-10 text-warning">
            <i class="fas fa-triangle-exclamation me-2"></i>เมื่อไรควรเปิด?
        </div>
        <div class="card-body" style="font-size:14px;">
            <ul class="mb-0 ps-3">
                <li class="mb-2">เว็บลูกค้าถูก <strong>MDES บล็อก</strong> (เข้าไม่ได้จาก server ไทย)</li>
                <li class="mb-2">ระบบโพสแล้วได้ error <code>Could not resolve host</code></li>
                <li class="mb-2">เว็บใช้โดเมน <code>.co</code> หรือโดเมนที่ถูกปิดกั้น</li>
            </ul>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><i class="fas fa-circle-info me-2 text-info"></i>วิธีทำงาน</div>
        <div class="card-body" style="font-size:13px;">
            <p><strong>DoH Mode</strong><br>
            เปลี่ยน DNS resolver เป็น Cloudflare/Google แทน ISP
            — แก้ปัญหาบล็อกระดับ DNS ได้ แต่ไม่ผ่าน IP block</p>
            <p class="mb-0"><strong>Proxy Mode (WARP)</strong><br>
            ส่ง HTTP request ทั้งหมดผ่าน WARP proxy
            — ผ่านได้ทั้ง DNS block และ IP block
            เหมาะกับกรณีที่เว็บถูกบล็อกสมบูรณ์</p>
        </div>
    </div>

    <div class="card mt-3 border-danger">
        <div class="card-header bg-danger bg-opacity-10 text-danger">
            <i class="fas fa-gauge-high me-2"></i>ผลกระทบด้าน Performance
        </div>
        <div class="card-body" style="font-size:13px;">
            <p class="mb-0">การเปิด Proxy/DoH จะ<strong>เพิ่ม latency</strong>
            ให้กับทุก WordPress API call
            แนะนำให้เปิดเฉพาะไซต์ที่ถูกบล็อกเท่านั้น</p>
        </div>
    </div>
</div>
</div>

<script>
function toggleFields() {
    const on = document.getElementById('proxyEnabled').checked;
    document.getElementById('proxyFields').style.opacity = on ? '1' : '.4';
    document.getElementById('proxyFields').style.pointerEvents = on ? '' : 'none';
}
function switchMode() {
    const isDoh = document.getElementById('modeDoh').checked;
    document.getElementById('dohSettings').style.display   = isDoh ? '' : 'none';
    document.getElementById('proxySettings').style.display = isDoh ? 'none' : '';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
