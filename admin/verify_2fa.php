<?php
/**
 * AI AutoPost SEO System - 2FA Verification
 * ==========================================
 */

require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (auth()->isLoggedIn()) {
    redirect(ADMIN_URL . '/index.php');
}

$token = $_GET['token'] ?? '';
$error = '';

// Validate token
$pending = TwoFactorAuth::getPendingLogin($token);
if (!$pending) {
    setFlash('error', 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่');
    redirect(ADMIN_URL . '/login.php');
}

// Handle verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        $error = 'Session หมดอายุ กรุณาลองใหม่';
    } else {
        $result = auth()->completeLoginWith2FA($token, $code);

        if ($result['success']) {
            if (!empty($result['warning'])) {
                setFlash('warning', $result['warning']);
            }
            redirect($result['redirect']);
        } else {
            $error = $result['message'];

            // Re-check if token is still valid
            $pending = TwoFactorAuth::getPendingLogin($token);
            if (!$pending) {
                setFlash('error', 'พยายามยืนยันตัวตนผิดพลาดหลายครั้ง กรุณาเข้าสู่ระบบใหม่');
                redirect(ADMIN_URL . '/login.php');
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ยืนยันตัวตน 2FA - AI AutoPost SEO</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            font-family: 'Prompt', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .verify-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            max-width: 420px;
            width: 100%;
        }

        .verify-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .verify-header h1 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .verify-header p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }

        .verify-body {
            padding: 40px 30px;
        }

        .code-input {
            letter-spacing: 8px;
            font-size: 24px;
            text-align: center;
            font-family: monospace;
        }

        .btn-verify {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-size: 16px;
            font-weight: 500;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .alert {
            border-radius: 10px;
            border: none;
        }

        .shield-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
        }

        .timer {
            font-size: 12px;
            color: #888;
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #e0e0e0;
        }

        .divider span {
            padding: 0 10px;
            color: #888;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-header">
            <div class="shield-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>ยืนยันตัวตน 2FA</h1>
            <p>ป้อนรหัสจาก Google Authenticator</p>
        </div>

        <div class="verify-body">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= sanitize($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="verifyForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="mb-4">
                    <label class="form-label text-muted small">รหัส 6 หลัก</label>
                    <input type="text"
                           name="code"
                           class="form-control form-control-lg code-input"
                           placeholder="000000"
                           maxlength="6"
                           pattern="[0-9]{6}"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           required
                           autofocus>
                    <div class="timer mt-2 text-center">
                        <i class="fas fa-clock me-1"></i>
                        รหัสเปลี่ยนทุก 30 วินาที
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-verify mb-3">
                    <i class="fas fa-check me-2"></i>ยืนยัน
                </button>
            </form>

            <div class="divider">
                <span>หรือ</span>
            </div>

            <form method="POST" id="backupForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <p class="text-muted small text-center mb-2">
                    ไม่สามารถเข้าถึงแอปได้? ใช้ Backup Code
                </p>

                <div class="input-group mb-3">
                    <input type="text"
                           name="code"
                           class="form-control"
                           placeholder="XXXX-XXXX"
                           maxlength="9"
                           pattern="[A-Z0-9]{4}-[A-Z0-9]{4}">
                    <button type="submit" class="btn btn-outline-secondary">
                        ใช้
                    </button>
                </div>
            </form>

            <div class="text-center mt-4">
                <a href="login.php" class="text-muted small">
                    <i class="fas fa-arrow-left me-1"></i>
                    กลับหน้าเข้าสู่ระบบ
                </a>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit when 6 digits entered
        document.querySelector('.code-input').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                // Small delay for better UX
                setTimeout(() => {
                    document.getElementById('verifyForm').submit();
                }, 100);
            }
        });

        // Format backup code
        document.querySelector('#backupForm input').addEventListener('input', function(e) {
            let value = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
            if (value.length > 4) {
                value = value.slice(0, 4) + '-' + value.slice(4, 8);
            }
            this.value = value;
        });
    </script>
</body>
</html>
