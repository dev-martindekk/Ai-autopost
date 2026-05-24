<?php
/**
 * AI AutoPost SEO System - Two-Factor Authentication
 * ===================================================
 * TOTP-based 2FA implementation (Google Authenticator compatible)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class TwoFactorAuth {
    private const SECRET_LENGTH = 16;
    private const CODE_LENGTH = 6;
    private const TIME_STEP = 30; // seconds
    private const WINDOW = 1; // Allow 1 step before/after
    private const BACKUP_CODES_COUNT = 10;
    private const ISSUER = 'AI AutoPost SEO';

    // Base32 alphabet
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random secret key
     */
    public static function generateSecret(): string {
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Generate otpauth URI for Google Authenticator
     */
    public static function getOtpauthUri(string $secret, string $username, string $issuer = null): string {
        $issuer = $issuer ?? self::ISSUER;

        return "otpauth://totp/" . rawurlencode($issuer) . ":" . rawurlencode($username)
             . "?secret=" . $secret
             . "&issuer=" . rawurlencode($issuer)
             . "&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Generate QR code URL (kept for backward compatibility)
     */
    public static function getQRCodeUrl(string $secret, string $username, string $issuer = null): string {
        return self::getOtpauthUri($secret, $username, $issuer);
    }

    /**
     * Generate TOTP code for current time
     */
    public static function generateCode(string $secret, int $timestamp = null): string {
        $timestamp = $timestamp ?? time();
        $counter = floor($timestamp / self::TIME_STEP);

        // Decode base32 secret
        $secretKey = self::base32Decode($secret);

        // Pack counter as 64-bit big-endian
        $counter = pack('N*', 0, $counter);

        // Generate HMAC-SHA1
        $hash = hash_hmac('sha1', $counter, $secretKey, true);

        // Get offset from last nibble
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        // Get 4 bytes starting at offset
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );

        // Get last 6 digits
        $code = $code % pow(10, self::CODE_LENGTH);

        return str_pad($code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Verify TOTP code with time window
     */
    public static function verifyCode(string $secret, string $code): bool {
        $code = preg_replace('/\s+/', '', $code); // Remove spaces

        if (strlen($code) !== self::CODE_LENGTH) {
            return false;
        }

        $timestamp = time();

        // Check within window
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $checkTime = $timestamp + ($i * self::TIME_STEP);
            $validCode = self::generateCode($secret, $checkTime);

            if (hash_equals($validCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode Base32 string
     */
    private static function base32Decode(string $input): string {
        $input = strtoupper($input);
        $input = str_replace('=', '', $input);

        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $position = strpos(self::BASE32_CHARS, $char);

            if ($position === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $position;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }

    /**
     * Generate backup codes
     */
    public static function generateBackupCodes(): array {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODES_COUNT; $i++) {
            // Generate 8-character alphanumeric code
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= substr('0123456789ABCDEFGHJKLMNPQRSTUVWXYZ', random_int(0, 33), 1);
            }
            // Format as XXXX-XXXX
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }
        return $codes;
    }

    /**
     * Setup 2FA for a user
     */
    public static function setup(int $userId, string $userType = 'admin'): array {
        try {
            $secret = self::generateSecret();
            $backupCodes = self::generateBackupCodes();

            // Store in database (not enabled yet)
            db()->query(
                "INSERT INTO two_factor_auth (user_id, user_type, secret_key, is_enabled, recovery_codes)
                 VALUES (?, ?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE secret_key = VALUES(secret_key), is_enabled = 0, recovery_codes = VALUES(recovery_codes)",
                [$userId, $userType, $secret, json_encode($backupCodes)]
            );

            // Get username for QR code
            $table = $userType === 'admin' ? 'admin_users' : 'members';
            $user = db()->fetchOne("SELECT username, email FROM {$table} WHERE id = ?", [$userId]);

            if (!$user) {
                return ['success' => false, 'message' => 'ไม่พบผู้ใช้'];
            }

            $otpauthUri = self::getOtpauthUri($secret, $user['email'] ?? $user['username']);

            return [
                'success' => true,
                'secret' => $secret,
                'qr_url' => $otpauthUri,
                'otpauth_uri' => $otpauthUri,
                'backup_codes' => $backupCodes
            ];

        } catch (Exception $e) {
            logEvent('error', '2fa', 'Setup failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Enable 2FA after verification
     */
    public static function enable(int $userId, string $userType, string $code): array {
        try {
            // Get 2FA record
            $tfa = db()->fetchOne(
                "SELECT secret_key, recovery_codes FROM two_factor_auth
                 WHERE user_id = ? AND user_type = ? AND is_enabled = 0",
                [$userId, $userType]
            );

            if (!$tfa) {
                return ['success' => false, 'message' => 'กรุณาตั้งค่า 2FA ก่อน'];
            }

            // Verify code
            if (!self::verifyCode($tfa['secret_key'], $code)) {
                return ['success' => false, 'message' => 'รหัสยืนยันไม่ถูกต้อง'];
            }

            // Hash and store backup codes
            $backupCodes = json_decode($tfa['recovery_codes'], true);

            // Clear old backup codes
            db()->delete('two_factor_backup_codes', 'user_id = ? AND user_type = ?', [$userId, $userType]);

            // Store hashed backup codes
            foreach ($backupCodes as $backupCode) {
                db()->insert('two_factor_backup_codes', [
                    'user_id' => $userId,
                    'user_type' => $userType,
                    'code_hash' => password_hash(str_replace('-', '', $backupCode), PASSWORD_DEFAULT)
                ]);
            }

            // Enable 2FA
            db()->update(
                'two_factor_auth',
                ['is_enabled' => 1, 'recovery_codes' => null],
                'user_id = ? AND user_type = ?',
                [$userId, $userType]
            );

            // Update user table
            $table = $userType === 'admin' ? 'admin_users' : 'members';
            db()->update($table, ['two_factor_enabled' => 1], 'id = ?', [$userId]);

            logEvent('info', '2fa', '2FA enabled', ['user_id' => $userId, 'user_type' => $userType]);

            return [
                'success' => true,
                'message' => 'เปิดใช้งาน 2FA สำเร็จ',
                'backup_codes' => $backupCodes
            ];

        } catch (Exception $e) {
            logEvent('error', '2fa', 'Enable failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Disable 2FA
     */
    public static function disable(int $userId, string $userType, string $password): array {
        try {
            // Verify password
            $table = $userType === 'admin' ? 'admin_users' : 'members';
            $user = db()->fetchOne("SELECT password_hash FROM {$table} WHERE id = ?", [$userId]);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง'];
            }

            // Delete 2FA record
            db()->delete('two_factor_auth', 'user_id = ? AND user_type = ?', [$userId, $userType]);
            db()->delete('two_factor_backup_codes', 'user_id = ? AND user_type = ?', [$userId, $userType]);

            // Update user table
            db()->update($table, ['two_factor_enabled' => 0], 'id = ?', [$userId]);

            logEvent('info', '2fa', '2FA disabled', ['user_id' => $userId, 'user_type' => $userType]);

            return ['success' => true, 'message' => 'ปิดการใช้งาน 2FA สำเร็จ'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Verify 2FA code or backup code
     */
    public static function verify(int $userId, string $userType, string $code): array {
        try {
            // Get 2FA record
            $tfa = db()->fetchOne(
                "SELECT secret_key FROM two_factor_auth
                 WHERE user_id = ? AND user_type = ? AND is_enabled = 1",
                [$userId, $userType]
            );

            if (!$tfa) {
                return ['success' => false, 'message' => '2FA ไม่ได้เปิดใช้งาน'];
            }

            $code = preg_replace('/[\s-]/', '', $code);

            // Try TOTP code first
            if (strlen($code) === 6 && self::verifyCode($tfa['secret_key'], $code)) {
                // Update last used
                db()->update(
                    'two_factor_auth',
                    ['last_used_at' => date('Y-m-d H:i:s')],
                    'user_id = ? AND user_type = ?',
                    [$userId, $userType]
                );

                return ['success' => true, 'method' => 'totp'];
            }

            // Try backup code
            if (strlen($code) === 8) {
                $backupCodes = db()->fetchAll(
                    "SELECT id, code_hash FROM two_factor_backup_codes
                     WHERE user_id = ? AND user_type = ? AND is_used = 0",
                    [$userId, $userType]
                );

                foreach ($backupCodes as $backup) {
                    if (password_verify($code, $backup['code_hash'])) {
                        // Mark as used
                        db()->update(
                            'two_factor_backup_codes',
                            ['is_used' => 1, 'used_at' => date('Y-m-d H:i:s')],
                            'id = ?',
                            [$backup['id']]
                        );

                        logEvent('info', '2fa', 'Backup code used', ['user_id' => $userId, 'user_type' => $userType]);

                        // Check remaining codes
                        $remaining = db()->fetchOne(
                            "SELECT COUNT(*) as count FROM two_factor_backup_codes
                             WHERE user_id = ? AND user_type = ? AND is_used = 0",
                            [$userId, $userType]
                        );

                        return [
                            'success' => true,
                            'method' => 'backup',
                            'remaining_codes' => $remaining['count']
                        ];
                    }
                }
            }

            return ['success' => false, 'message' => 'รหัสยืนยันไม่ถูกต้อง'];

        } catch (Exception $e) {
            logEvent('error', '2fa', 'Verify failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Check if user has 2FA enabled
     */
    public static function isEnabled(int $userId, string $userType = 'admin'): bool {
        $tfa = db()->fetchOne(
            "SELECT is_enabled FROM two_factor_auth WHERE user_id = ? AND user_type = ?",
            [$userId, $userType]
        );

        return $tfa && $tfa['is_enabled'];
    }

    /**
     * Create pending 2FA login
     */
    public static function createPendingLogin(int $userId, string $userType): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        db()->insert('pending_2fa_logins', [
            'token' => $token,
            'user_id' => $userId,
            'user_type' => $userType,
            'ip_address' => getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'expires_at' => $expiresAt
        ]);

        return $token;
    }

    /**
     * Get pending login
     */
    public static function getPendingLogin(string $token): ?array {
        $pending = db()->fetchOne(
            "SELECT * FROM pending_2fa_logins
             WHERE token = ? AND expires_at > NOW() AND attempts < 5",
            [$token]
        );

        return $pending ?: null;
    }

    /**
     * Increment pending login attempts
     */
    public static function incrementPendingAttempts(string $token): void {
        db()->query(
            "UPDATE pending_2fa_logins SET attempts = attempts + 1 WHERE token = ?",
            [$token]
        );
    }

    /**
     * Delete pending login
     */
    public static function deletePendingLogin(string $token): void {
        db()->delete('pending_2fa_logins', 'token = ?', [$token]);
    }

    /**
     * Cleanup expired pending logins
     */
    public static function cleanupExpired(): void {
        db()->delete('pending_2fa_logins', 'expires_at < NOW()');
    }

    /**
     * Regenerate backup codes
     */
    public static function regenerateBackupCodes(int $userId, string $userType, string $password): array {
        try {
            // Verify password
            $table = $userType === 'admin' ? 'admin_users' : 'members';
            $user = db()->fetchOne("SELECT password_hash FROM {$table} WHERE id = ?", [$userId]);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง'];
            }

            // Check if 2FA is enabled
            if (!self::isEnabled($userId, $userType)) {
                return ['success' => false, 'message' => '2FA ไม่ได้เปิดใช้งาน'];
            }

            // Generate new backup codes
            $backupCodes = self::generateBackupCodes();

            // Clear old backup codes
            db()->delete('two_factor_backup_codes', 'user_id = ? AND user_type = ?', [$userId, $userType]);

            // Store new hashed backup codes
            foreach ($backupCodes as $backupCode) {
                db()->insert('two_factor_backup_codes', [
                    'user_id' => $userId,
                    'user_type' => $userType,
                    'code_hash' => password_hash(str_replace('-', '', $backupCode), PASSWORD_DEFAULT)
                ]);
            }

            logEvent('info', '2fa', 'Backup codes regenerated', ['user_id' => $userId, 'user_type' => $userType]);

            return [
                'success' => true,
                'message' => 'สร้าง Backup Codes ใหม่สำเร็จ',
                'backup_codes' => $backupCodes
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Get remaining backup codes count
     */
    public static function getRemainingBackupCodesCount(int $userId, string $userType): int {
        $result = db()->fetchOne(
            "SELECT COUNT(*) as count FROM two_factor_backup_codes
             WHERE user_id = ? AND user_type = ? AND is_used = 0",
            [$userId, $userType]
        );

        return $result['count'] ?? 0;
    }
}

/**
 * Helper function
 */
function twoFA(): TwoFactorAuth {
    return new TwoFactorAuth();
}
