<?php
/**
 * AI AutoPost SEO System - Initial Setup
 * =======================================
 * Run this once via CLI to create the initial admin user.
 * Usage: docker exec ai-autopost-web php /var/www/html/setup.php
 *
 * Web access is blocked — CLI only.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Setup is only accessible via CLI.');
}

define('APP_ROOT', __DIR__);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

echo "=== AI AutoPost SEO — Initial Setup ===\n\n";

// ── Admin user ────────────────────────────────────────────────────────────────
$existingAdmin = db()->fetchOne("SELECT id FROM admin_users WHERE username = 'admin'");

if ($existingAdmin) {
    echo "Admin user already exists.\n";
    echo "Do you want to reset the admin password? (y/n): ";
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        echo "Password reset skipped.\n";
    } else {
        $password = promptPassword("New admin password (min 12 chars): ");
        if (strlen($password) < 12) {
            echo "Error: password must be at least 12 characters.\n";
            exit(1);
        }
        db()->update('admin_users', [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])
        ], 'id = ?', [$existingAdmin['id']]);
        echo "Admin password updated.\n";
    }
} else {
    echo "Creating admin account...\n";

    $email = trim(readline("Admin email [admin@example.com]: ") ?: 'admin@example.com');

    $password = promptPassword("Admin password (min 12 chars): ");
    if (strlen($password) < 12) {
        echo "Error: password must be at least 12 characters.\n";
        exit(1);
    }
    $confirm = promptPassword("Confirm password: ");
    if ($password !== $confirm) {
        echo "Error: passwords do not match.\n";
        exit(1);
    }

    db()->insert('admin_users', [
        'username'      => 'admin',
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'full_name'     => 'System Administrator',
        'role'          => 'super_admin',
    ]);

    echo "\nAdmin account created.\n";
    echo "  Username: admin\n";
    echo "  Email:    {$email}\n";
    echo "\n";
}

// ── Encryption key ────────────────────────────────────────────────────────────
$existingKey = getSetting('encryption_key');
if (empty($existingKey)) {
    $encryptionKey = bin2hex(random_bytes(32));
    setSetting('encryption_key', $encryptionKey, 'string');
    echo "Encryption key generated and saved to database.\n";
}

echo "\nSetup complete!\n";

// ── Helper: read password without echo ───────────────────────────────────────
function promptPassword(string $prompt): string {
    echo $prompt;
    // Disable echo on Unix; fallback for Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $pass = rtrim(fgets(STDIN), "\n");
        system('stty echo');
        echo "\n";
    } else {
        $pass = rtrim(fgets(STDIN), "\n");
    }
    return $pass;
}
