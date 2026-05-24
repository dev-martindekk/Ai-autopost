<?php
/**
 * AI AutoPost SEO System - Member Authentication
 * ===============================================
 * Member registration, login, and profile management
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/two_factor_auth.php';

// Member constants
if (!defined('MEMBER_SESSION_LIFETIME')) {
    define('MEMBER_SESSION_LIFETIME', 86400 * 7); // 7 days
}
if (!defined('MEMBER_MAX_LOGIN_ATTEMPTS')) {
    define('MEMBER_MAX_LOGIN_ATTEMPTS', 5);
}
if (!defined('MEMBER_LOCKOUT_TIME')) {
    define('MEMBER_LOCKOUT_TIME', 1800); // 30 minutes
}

class MemberAuth {
    private static $instance = null;
    private $member = null;

    private function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Load member if logged in
        if (isset($_SESSION['member_id'])) {
            $this->loadMember($_SESSION['member_id']);
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load member from database
     */
    private function loadMember(int $memberId): void {
        try {
            $member = db()->fetchOne(
                "SELECT id, username, email, full_name, phone, avatar_url,
                        membership_type, membership_expires, credits,
                        is_active, is_verified, two_factor_enabled, last_login
                 FROM members WHERE id = ? AND is_active = 1",
                [$memberId]
            );

            if ($member) {
                $this->member = $member;
            } else {
                $this->logout();
            }
        } catch (Exception $e) {
            logEvent('error', 'member_auth', 'Failed to load member', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Register new member
     */
    public function register(array $data): array {
        // Validate
        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return ['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'];
        }

        // Validate username
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $data['username'])) {
            return ['success' => false, 'message' => 'ชื่อผู้ใช้ต้องเป็นตัวอักษร ตัวเลข หรือ _ เท่านั้น (3-30 ตัว)'];
        }

        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง'];
        }

        // Validate password
        if (strlen($data['password']) < 8) {
            return ['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'];
        }

        if ($data['password'] !== ($data['confirm_password'] ?? '')) {
            return ['success' => false, 'message' => 'รหัสผ่านไม่ตรงกัน'];
        }

        try {
            // Check if username/email exists
            $exists = db()->fetchOne(
                "SELECT id, username, email FROM members WHERE username = ? OR email = ?",
                [$data['username'], $data['email']]
            );

            if ($exists) {
                if ($exists['username'] === $data['username']) {
                    return ['success' => false, 'message' => 'ชื่อผู้ใช้นี้ถูกใช้แล้ว'];
                }
                return ['success' => false, 'message' => 'อีเมลนี้ถูกใช้แล้ว'];
            }

            // Generate verification token
            $verificationToken = bin2hex(random_bytes(32));
            $verificationExpires = date('Y-m-d H:i:s', time() + 86400); // 24 hours

            // Create member
            $memberId = db()->insert('members', [
                'username' => $data['username'],
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'full_name' => $data['full_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'verification_token' => $verificationToken,
                'verification_expires' => $verificationExpires
            ]);

            // Log activity
            $this->logActivity($memberId, 'register', 'สมัครสมาชิกใหม่');

            logEvent('info', 'member_auth', 'New member registered', ['member_id' => $memberId]);

            return [
                'success' => true,
                'message' => 'สมัครสมาชิกสำเร็จ กรุณาตรวจสอบอีเมลเพื่อยืนยันบัญชี',
                'member_id' => $memberId,
                'verification_token' => $verificationToken
            ];

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $msg = $e->getMessage();
                if (stripos($msg, 'username') !== false) {
                    return ['success' => false, 'message' => 'ชื่อผู้ใช้นี้ถูกใช้แล้ว'];
                }
                if (stripos($msg, 'email') !== false) {
                    return ['success' => false, 'message' => 'อีเมลนี้ถูกใช้แล้ว'];
                }
                return ['success' => false, 'message' => 'ข้อมูลนี้ถูกใช้แล้ว'];
            }
            logEvent('error', 'member_auth', 'Registration failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        } catch (Exception $e) {
            logEvent('error', 'member_auth', 'Registration failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        }
    }

    /**
     * Verify email
     */
    public function verifyEmail(string $token): array {
        try {
            $member = db()->fetchOne(
                "SELECT id FROM members
                 WHERE verification_token = ? AND verification_expires > NOW() AND is_verified = 0",
                [$token]
            );

            if (!$member) {
                return ['success' => false, 'message' => 'ลิงก์ยืนยันไม่ถูกต้องหรือหมดอายุ'];
            }

            db()->update(
                'members',
                [
                    'is_verified' => 1,
                    'verification_token' => null,
                    'verification_expires' => null
                ],
                'id = ?',
                [$member['id']]
            );

            $this->logActivity($member['id'], 'email_verified', 'ยืนยันอีเมลสำเร็จ');

            return ['success' => true, 'message' => 'ยืนยันอีเมลสำเร็จ คุณสามารถเข้าสู่ระบบได้แล้ว'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Login member
     */
    public function login(string $username, string $password): array {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน'];
        }

        try {
            // Get member
            $member = db()->fetchOne(
                "SELECT * FROM members WHERE (username = ? OR email = ?)",
                [$username, $username]
            );

            if (!$member) {
                // Constant-time response to prevent username enumeration via timing
                password_verify($password, '$2y$12$invalidhashpadding..........................');
                logEvent('warning', 'member_auth', 'Login failed - member not found', ['username' => $username]);
                return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
            }

            // Check if locked
            if ($member['locked_until'] && strtotime($member['locked_until']) > time()) {
                $remaining = ceil((strtotime($member['locked_until']) - time()) / 60);
                return ['success' => false, 'message' => "บัญชีถูกล็อค กรุณารอ {$remaining} นาที"];
            }

            // Check if active
            if (!$member['is_active']) {
                return ['success' => false, 'message' => 'บัญชีนี้ถูกปิดการใช้งาน'];
            }

            // Check if verified
            if (!$member['is_verified']) {
                return ['success' => false, 'message' => 'กรุณายืนยันอีเมลก่อนเข้าสู่ระบบ'];
            }

            // Verify password
            if (!password_verify($password, $member['password_hash'])) {
                $this->incrementLoginAttempts($member['id']);

                $attemptsLeft = MEMBER_MAX_LOGIN_ATTEMPTS - $member['login_attempts'] - 1;
                if ($attemptsLeft <= 0) {
                    $this->lockAccount($member['id']);
                    return ['success' => false, 'message' => 'บัญชีถูกล็อคเนื่องจากพยายามเข้าสู่ระบบผิดพลาดหลายครั้ง'];
                }

                return ['success' => false, 'message' => "รหัสผ่านไม่ถูกต้อง (เหลืออีก {$attemptsLeft} ครั้ง)"];
            }

            // Check 2FA
            if ($member['two_factor_enabled'] && TwoFactorAuth::isEnabled($member['id'], 'member')) {
                $token = TwoFactorAuth::createPendingLogin($member['id'], 'member');
                $this->resetLoginAttempts($member['id']);

                return [
                    'success' => true,
                    'requires_2fa' => true,
                    'token' => $token,
                    'message' => 'กรุณายืนยันตัวตน 2FA',
                    'redirect' => '/member/verify_2fa.php?token=' . $token
                ];
            }

            // Login successful
            $this->createSession($member);
            $this->resetLoginAttempts($member['id']);
            $this->updateLastLogin($member['id']);

            $this->logActivity($member['id'], 'login', 'เข้าสู่ระบบ');
            logEvent('info', 'member_auth', 'Member logged in', ['member_id' => $member['id']]);

            return [
                'success' => true,
                'message' => 'เข้าสู่ระบบสำเร็จ',
                'redirect' => '/member/dashboard.php'
            ];

        } catch (Exception $e) {
            logEvent('error', 'member_auth', 'Login error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        }
    }

    /**
     * Complete login with 2FA
     */
    public function completeLoginWith2FA(string $token, string $code): array {
        try {
            $pending = TwoFactorAuth::getPendingLogin($token);

            if (!$pending || $pending['user_type'] !== 'member') {
                return ['success' => false, 'message' => 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่'];
            }

            $result = TwoFactorAuth::verify($pending['user_id'], 'member', $code);

            if (!$result['success']) {
                TwoFactorAuth::incrementPendingAttempts($token);
                return $result;
            }

            $member = db()->fetchOne(
                "SELECT * FROM members WHERE id = ? AND is_active = 1",
                [$pending['user_id']]
            );

            if (!$member) {
                return ['success' => false, 'message' => 'ไม่พบบัญชีผู้ใช้'];
            }

            $this->createSession($member);
            $this->updateLastLogin($member['id']);
            TwoFactorAuth::deletePendingLogin($token);

            $this->logActivity($member['id'], 'login_2fa', 'เข้าสู่ระบบด้วย 2FA');

            $response = [
                'success' => true,
                'message' => 'เข้าสู่ระบบสำเร็จ',
                'redirect' => '/member/dashboard.php'
            ];

            if ($result['method'] === 'backup') {
                $response['warning'] = "ใช้ Backup Code แล้ว (เหลือ {$result['remaining_codes']} รหัส)";
            }

            return $response;

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Create session
     */
    private function createSession(array $member): void {
        session_regenerate_id(true);

        $_SESSION['member_id'] = $member['id'];
        $_SESSION['member_username'] = $member['username'];
        $_SESSION['member_login_time'] = time();
        $_SESSION['member_ip'] = getClientIP();

        $this->member = $member;

        // Store in database + purge stale sessions
        try {
            db()->query(
                "INSERT INTO member_sessions (id, member_id, ip_address, user_agent, payload, last_activity)
                 VALUES (?, ?, ?, ?, '', ?)
                 ON DUPLICATE KEY UPDATE last_activity = VALUES(last_activity), ip_address = VALUES(ip_address)",
                [
                    session_id(),
                    $member['id'],
                    getClientIP(),
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    time()
                ]
            );
            // Clean up sessions inactive for more than 7 days
            db()->query(
                "DELETE FROM member_sessions WHERE last_activity < ?",
                [time() - 604800]
            );
        } catch (Exception $e) {
            // Non-critical
        }
    }

    /**
     * Logout
     */
    public function logout(): void {
        if (isset($_SESSION['member_id'])) {
            $this->logActivity($_SESSION['member_id'], 'logout', 'ออกจากระบบ');

            try {
                db()->delete('member_sessions', 'id = ?', [session_id()]);
            } catch (Exception $e) {}
        }

        $this->member = null;

        // Clear member session data
        unset($_SESSION['member_id']);
        unset($_SESSION['member_username']);
        unset($_SESSION['member_login_time']);
        unset($_SESSION['member_ip']);
    }

    /**
     * Check if logged in
     */
    public function isLoggedIn(): bool {
        if (!isset($_SESSION['member_id']) || !$this->member) {
            return false;
        }

        // Check session timeout
        if (isset($_SESSION['member_login_time']) &&
            (time() - $_SESSION['member_login_time']) > MEMBER_SESSION_LIFETIME) {
            $this->logout();
            return false;
        }

        // Validate IP
        if ($_SESSION['member_ip'] !== getClientIP()) {
            $this->logout();
            return false;
        }

        $_SESSION['member_login_time'] = time();
        return true;
    }

    /**
     * Get current member
     */
    public function getMember(): ?array {
        return $this->member;
    }

    /**
     * Get member ID
     */
    public function getMemberId(): ?int {
        return $this->member['id'] ?? null;
    }

    /**
     * Require authentication
     */
    public function requireAuth(): void {
        if (!$this->isLoggedIn()) {
            if (isAjax()) {
                jsonResponse(['success' => false, 'message' => 'Session expired'], 401);
            }
            redirect('/member/login.php');
        }
    }

    /**
     * Update profile
     */
    public function updateProfile(int $memberId, array $data): array {
        try {
            $updateData = [];

            if (!empty($data['full_name'])) {
                $updateData['full_name'] = $data['full_name'];
            }

            if (!empty($data['phone'])) {
                $updateData['phone'] = $data['phone'];
            }

            if (!empty($data['avatar_url'])) {
                $updateData['avatar_url'] = $data['avatar_url'];
            }

            if (empty($updateData)) {
                return ['success' => false, 'message' => 'ไม่มีข้อมูลที่ต้องอัพเดท'];
            }

            db()->update('members', $updateData, 'id = ?', [$memberId]);

            $this->logActivity($memberId, 'profile_update', 'อัพเดทข้อมูลส่วนตัว');

            return ['success' => true, 'message' => 'บันทึกข้อมูลสำเร็จ'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Change password
     */
    public function changePassword(int $memberId, string $currentPassword, string $newPassword): array {
        try {
            $member = db()->fetchOne("SELECT password_hash FROM members WHERE id = ?", [$memberId]);

            if (!$member || !password_verify($currentPassword, $member['password_hash'])) {
                return ['success' => false, 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'];
            }

            if (strlen($newPassword) < 8) {
                return ['success' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'];
            }

            db()->update(
                'members',
                ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
                'id = ?',
                [$memberId]
            );

            $this->logActivity($memberId, 'password_change', 'เปลี่ยนรหัสผ่าน');

            return ['success' => true, 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Request password reset
     */
    public function requestPasswordReset(string $email): array {
        try {
            $member = db()->fetchOne("SELECT id, email FROM members WHERE email = ?", [$email]);

            // Always return success to prevent email enumeration
            if (!$member) {
                return ['success' => true, 'message' => 'หากอีเมลนี้มีในระบบ เราจะส่งลิงก์รีเซ็ตรหัสผ่านไป'];
            }

            $resetToken = bin2hex(random_bytes(32));
            $resetExpires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            db()->update(
                'members',
                [
                    'reset_token' => $resetToken,
                    'reset_token_expires' => $resetExpires
                ],
                'id = ?',
                [$member['id']]
            );

            // TODO: Send email with reset link
            // sendEmail($member['email'], 'Reset Password', "Link: /member/reset_password.php?token={$resetToken}");

            $this->logActivity($member['id'], 'password_reset_request', 'ขอรีเซ็ตรหัสผ่าน');

            return [
                'success' => true,
                'message' => 'หากอีเมลนี้มีในระบบ เราจะส่งลิงก์รีเซ็ตรหัสผ่านไป',
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Reset password with token
     */
    public function resetPassword(string $token, string $newPassword): array {
        try {
            $member = db()->fetchOne(
                "SELECT id FROM members WHERE reset_token = ? AND reset_token_expires > NOW()",
                [$token]
            );

            if (!$member) {
                return ['success' => false, 'message' => 'ลิงก์รีเซ็ตไม่ถูกต้องหรือหมดอายุ'];
            }

            if (strlen($newPassword) < 8) {
                return ['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'];
            }

            db()->update(
                'members',
                [
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'reset_token' => null,
                    'reset_token_expires' => null
                ],
                'id = ?',
                [$member['id']]
            );

            $this->logActivity($member['id'], 'password_reset', 'รีเซ็ตรหัสผ่านสำเร็จ');

            return ['success' => true, 'message' => 'รีเซ็ตรหัสผ่านสำเร็จ คุณสามารถเข้าสู่ระบบได้แล้ว'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Helper methods
     */
    private function incrementLoginAttempts(int $memberId): void {
        db()->query("UPDATE members SET login_attempts = login_attempts + 1 WHERE id = ?", [$memberId]);
    }

    private function resetLoginAttempts(int $memberId): void {
        db()->query("UPDATE members SET login_attempts = 0, locked_until = NULL WHERE id = ?", [$memberId]);
    }

    private function lockAccount(int $memberId): void {
        $lockUntil = date('Y-m-d H:i:s', time() + MEMBER_LOCKOUT_TIME);
        db()->query("UPDATE members SET locked_until = ? WHERE id = ?", [$lockUntil, $memberId]);
    }

    private function updateLastLogin(int $memberId): void {
        db()->query("UPDATE members SET last_login = NOW() WHERE id = ?", [$memberId]);
    }

    private function logActivity(int $memberId, string $action, string $description): void {
        try {
            db()->insert('member_activity_logs', [
                'member_id' => $memberId,
                'action' => $action,
                'description' => $description,
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Non-critical
        }
    }

    /**
     * Check membership status
     */
    public function hasMembership(string $type = 'basic'): bool {
        if (!$this->member) {
            return false;
        }

        $hierarchy = ['free' => 0, 'basic' => 1, 'premium' => 2, 'vip' => 3];
        $memberLevel = $hierarchy[$this->member['membership_type']] ?? 0;
        $requiredLevel = $hierarchy[$type] ?? 0;

        // Check expiration
        if ($memberLevel > 0 && $this->member['membership_expires']) {
            if (strtotime($this->member['membership_expires']) < time()) {
                return false;
            }
        }

        return $memberLevel >= $requiredLevel;
    }
}

/**
 * Helper function
 */
function memberAuth(): MemberAuth {
    return MemberAuth::getInstance();
}
