<?php
/**
 * AI AutoPost SEO System - Authentication
 * =======================================
 * Admin authentication and session management
 */

// Start output buffering to allow redirects
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/two_factor_auth.php';

class Auth {
    private static $instance = null;
    private $user = null;

    private function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Load user if logged in
        if (isset($_SESSION['user_id'])) {
            $this->loadUser($_SESSION['user_id']);
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load user from database (admin or member)
     */
    private function loadUser(int $userId): void {
        try {
            $userType = $_SESSION['user_type'] ?? 'admin';

            if ($userType === 'member') {
                $user = db()->fetchOne(
                    "SELECT id, username, email, full_name, 'member' as role,
                            membership_type, credits, is_active, last_login
                     FROM members WHERE id = ? AND is_active = 1",
                    [$userId]
                );
            } else {
                $user = db()->fetchOne(
                    "SELECT id, username, email, full_name, role, is_active, last_login
                     FROM admin_users WHERE id = ? AND is_active = 1",
                    [$userId]
                );
            }

            if ($user) {
                $user['user_type'] = $userType;
                $this->user = $user;
            } else {
                $this->logout();
            }
        } catch (Exception $e) {
            logEvent('error', 'auth', 'Failed to load user', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Attempt to login user (admin or member)
     */
    public function login(string $username, string $password): array {
        // Validate input
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน'];
        }

        try {
            // Try admin_users first
            $user = db()->fetchOne(
                "SELECT *, 'admin' as user_type FROM admin_users WHERE (username = ? OR email = ?)",
                [$username, $username]
            );

            // If not found in admin_users, try members
            if (!$user) {
                $user = db()->fetchOne(
                    "SELECT *, 'member' as user_type, 'member' as role FROM members WHERE (username = ? OR email = ?)",
                    [$username, $username]
                );
            }

            // User not found in both tables
            if (!$user) {
                logEvent('warning', 'auth', 'Login failed - user not found', ['username' => $username]);
                return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
            }

            $userType = $user['user_type'];
            $table = $userType === 'member' ? 'members' : 'admin_users';

            // Check if account is locked
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60);
                return [
                    'success' => false,
                    'message' => "บัญชีถูกล็อค กรุณารอ {$remainingTime} นาที"
                ];
            }

            // Check if account is active
            if (!$user['is_active']) {
                return ['success' => false, 'message' => 'บัญชีนี้ถูกปิดการใช้งาน'];
            }

            // Check if member is verified
            if ($userType === 'member' && !$user['is_verified']) {
                return ['success' => false, 'message' => 'บัญชียังไม่ได้รับการยืนยัน'];
            }

            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                $this->incrementLoginAttempts($user['id'], $table);

                $attemptsLeft = MAX_LOGIN_ATTEMPTS - $user['login_attempts'] - 1;
                if ($attemptsLeft <= 0) {
                    $this->lockAccount($user['id'], $table);
                    logEvent('warning', 'auth', 'Account locked due to failed attempts', ['user_id' => $user['id'], 'type' => $userType]);
                    return [
                        'success' => false,
                        'message' => 'บัญชีถูกล็อคเนื่องจากพยายามเข้าสู่ระบบผิดพลาดหลายครั้ง'
                    ];
                }

                logEvent('warning', 'auth', 'Login failed - wrong password', ['username' => $username]);
                return [
                    'success' => false,
                    'message' => "รหัสผ่านไม่ถูกต้อง (เหลืออีก {$attemptsLeft} ครั้ง)"
                ];
            }

            // Check if 2FA is enabled
            if ($user['two_factor_enabled'] && TwoFactorAuth::isEnabled($user['id'], $userType)) {
                // Create pending 2FA login
                $token = TwoFactorAuth::createPendingLogin($user['id'], $userType);

                $this->resetLoginAttempts($user['id'], $table);

                return [
                    'success' => true,
                    'requires_2fa' => true,
                    'token' => $token,
                    'user_type' => $userType,
                    'message' => 'กรุณายืนยันตัวตน 2FA',
                    'redirect' => ADMIN_URL . '/verify_2fa.php?token=' . $token
                ];
            }

            // Login successful (no 2FA)
            $this->createSession($user, $userType);
            $this->resetLoginAttempts($user['id'], $table);
            $this->updateLastLogin($user['id'], $table);

            logEvent('info', 'auth', 'User logged in', ['user_id' => $user['id']]);

            return [
                'success' => true,
                'message' => 'เข้าสู่ระบบสำเร็จ',
                'redirect' => ADMIN_URL . '/index.php'
            ];

        } catch (Exception $e) {
            logEvent('error', 'auth', 'Login error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'];
        }
    }

    /**
     * Create user session
     */
    private function createSession(array $user, string $userType = 'admin'): void {
        // Regenerate session ID for security
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user_type'] = $userType;
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = getClientIP();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $user['user_type'] = $userType;
        $this->user = $user;

        // Store session in database
        try {
            db()->query(
                "INSERT INTO admin_sessions (id, user_id, ip_address, user_agent, payload, last_activity)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_activity = VALUES(last_activity)",
                [
                    session_id(),
                    $user['id'],
                    getClientIP(),
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    json_encode($_SESSION),
                    time()
                ]
            );
        } catch (Exception $e) {
            // Non-critical error
        }
    }

    /**
     * Logout user
     */
    public function logout(): void {
        // Remove session from database
        if (isset($_SESSION['user_id'])) {
            try {
                db()->delete('admin_sessions', 'id = ?', [session_id()]);
            } catch (Exception $e) {
                // Non-critical
            }

            logEvent('info', 'auth', 'User logged out', ['user_id' => $_SESSION['user_id']]);
        }

        // Clear session
        $this->user = null;
        $_SESSION = [];

        // Destroy session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool {
        if (!isset($_SESSION['user_id']) || !$this->user) {
            return false;
        }

        // Check session timeout
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }

        // Validate session (IP and user agent)
        if ($_SESSION['ip_address'] !== getClientIP()) {
            logEvent('warning', 'auth', 'Session hijacking attempt - IP mismatch', [
                'session_ip' => $_SESSION['ip_address'],
                'current_ip' => getClientIP()
            ]);
            $this->logout();
            return false;
        }

        // Update last activity
        $_SESSION['login_time'] = time();

        return true;
    }

    /**
     * Get current user
     */
    public function getUser(): ?array {
        return $this->user;
    }

    /**
     * Get user ID
     */
    public function getUserId(): ?int {
        return $this->user['id'] ?? null;
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $role): bool {
        if (!$this->user) {
            return false;
        }

        $roleHierarchy = ['super_admin' => 4, 'admin' => 3, 'staff' => 2, 'editor' => 1];
        $userLevel = $roleHierarchy[$this->user['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$role] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Require authentication (redirect if not logged in)
     */
    public function requireAuth(): void {
        if (!$this->isLoggedIn() || $this->isMember()) {
            if (isAjax()) {
                jsonResponse(['success' => false, 'message' => 'Session expired'], 401);
            }
            if ($this->isMember()) {
                $this->logout();
                setFlash('warning', 'กรุณาเข้าสู่ระบบด้วยบัญชี Admin');
            } else {
                setFlash('warning', 'กรุณาเข้าสู่ระบบ');
            }
            redirect(ADMIN_URL . '/login.php');
        }
    }

    /**
     * Require specific role
     */
    public function requireRole(string $role): void {
        $this->requireAuth();

        if (!$this->hasRole($role)) {
            if (isAjax()) {
                jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
            }
            setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
            redirect(ADMIN_URL . '/index.php');
        }
    }

    public function isStaff(): bool {
        return $this->hasRole('staff');
    }

    public function isSuperAdmin(): bool {
        return ($this->user['role'] ?? '') === 'super_admin';
    }

    public function getRole(): string {
        return $this->user['role'] ?? '';
    }


    /**
     * Increment failed login attempts
     */
    private function incrementLoginAttempts(int $userId, string $table = 'admin_users'): void {
        db()->query(
            "UPDATE {$table} SET login_attempts = login_attempts + 1 WHERE id = ?",
            [$userId]
        );
    }

    /**
     * Reset login attempts
     */
    private function resetLoginAttempts(int $userId, string $table = 'admin_users'): void {
        db()->query(
            "UPDATE {$table} SET login_attempts = 0, locked_until = NULL WHERE id = ?",
            [$userId]
        );
    }

    /**
     * Lock account
     */
    private function lockAccount(int $userId, string $table = 'admin_users'): void {
        $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
        db()->query(
            "UPDATE {$table} SET locked_until = ? WHERE id = ?",
            [$lockUntil, $userId]
        );
    }

    /**
     * Update last login time
     */
    private function updateLastLogin(int $userId, string $table = 'admin_users'): void {
        db()->query(
            "UPDATE {$table} SET last_login = NOW() WHERE id = ?",
            [$userId]
        );
    }

    /**
     * Register new admin user
     */
    public function registerUser(array $data): array {
        // Validate
        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return ['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'];
        }

        if (!isValidEmail($data['email'])) {
            return ['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง'];
        }

        if (strlen($data['password']) < 8) {
            return ['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'];
        }

        try {
            // Check if username/email exists
            $exists = db()->fetchOne(
                "SELECT id FROM admin_users WHERE username = ? OR email = ?",
                [$data['username'], $data['email']]
            );

            if ($exists) {
                return ['success' => false, 'message' => 'ชื่อผู้ใช้หรืออีเมลนี้มีอยู่แล้ว'];
            }

            // Create user
            $userId = db()->insert('admin_users', [
                'username' => $data['username'],
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'full_name' => $data['full_name'] ?? null,
                'role' => $data['role'] ?? 'admin'
            ]);

            logEvent('info', 'auth', 'New user registered', ['user_id' => $userId]);

            return ['success' => true, 'message' => 'สร้างบัญชีสำเร็จ', 'user_id' => $userId];

        } catch (Exception $e) {
            logEvent('error', 'auth', 'Registration failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        }
    }

    /**
     * Complete login with 2FA verification
     */
    public function completeLoginWith2FA(string $token, string $code): array {
        try {
            // Get pending login
            $pending = TwoFactorAuth::getPendingLogin($token);

            if (!$pending) {
                return ['success' => false, 'message' => 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่'];
            }

            // Verify 2FA code
            $result = TwoFactorAuth::verify($pending['user_id'], $pending['user_type'], $code);

            if (!$result['success']) {
                TwoFactorAuth::incrementPendingAttempts($token);
                return $result;
            }

            $userType = $pending['user_type'];
            $table = $userType === 'member' ? 'members' : 'admin_users';

            // Get user from appropriate table
            if ($userType === 'member') {
                $user = db()->fetchOne(
                    "SELECT *, 'member' as role FROM members WHERE id = ? AND is_active = 1",
                    [$pending['user_id']]
                );
            } else {
                $user = db()->fetchOne(
                    "SELECT * FROM admin_users WHERE id = ? AND is_active = 1",
                    [$pending['user_id']]
                );
            }

            if (!$user) {
                return ['success' => false, 'message' => 'ไม่พบผู้ใช้งาน'];
            }

            // Create session with userType
            $this->createSession($user, $userType);
            $this->updateLastLogin($user['id'], $table);

            // Delete pending login
            TwoFactorAuth::deletePendingLogin($token);

            logEvent('info', 'auth', 'User logged in with 2FA', ['user_id' => $user['id'], 'type' => $userType]);

            $response = [
                'success' => true,
                'message' => 'เข้าสู่ระบบสำเร็จ',
                'redirect' => ADMIN_URL . '/index.php'
            ];

            // Warn if backup code used
            if ($result['method'] === 'backup') {
                $response['warning'] = "ใช้ Backup Code แล้ว (เหลือ {$result['remaining_codes']} รหัส)";
            }

            return $response;

        } catch (Exception $e) {
            logEvent('error', 'auth', '2FA login error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Change password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array {
        try {
            $user = db()->fetchOne("SELECT password_hash FROM admin_users WHERE id = ?", [$userId]);

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'];
            }

            if (strlen($newPassword) < 8) {
                return ['success' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'];
            }

            db()->update(
                'admin_users',
                ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
                'id = ?',
                [$userId]
            );

            logEvent('info', 'auth', 'Password changed', ['user_id' => $userId]);

            return ['success' => true, 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];
        }
    }

    /**
     * Check if current user is a member
     */
    public function isMember(): bool {
        return ($this->user['user_type'] ?? 'admin') === 'member';
    }

    /**
     * Check if current user is an admin
     */
    public function isAdmin(): bool {
        return ($this->user['user_type'] ?? 'admin') === 'admin';
    }

    /**
     * Get owner type for current user
     */
    public function getOwnerType(): string {
        return $this->user['user_type'] ?? 'admin';
    }

    /**
     * Get owner ID for current user
     */
    public function getOwnerId(): ?int {
        return $this->user['id'] ?? null;
    }

    /**
     * Get SQL WHERE condition for owner filtering
     * Returns array with [condition_string, params_array]
     */
    public function getOwnerCondition(string $tableAlias = ''): array {
        $prefix = $tableAlias ? "{$tableAlias}." : '';
        $ownerType = $this->getOwnerType();
        $ownerId = $this->getOwnerId();

        // Super admin sees everything
        if ($this->hasRole('super_admin')) {
            return ['1=1', []];
        }

        return [
            "{$prefix}owner_type = ? AND {$prefix}owner_id = ?",
            [$ownerType, $ownerId]
        ];
    }

    /**
     * Get owner data for inserting new records
     */
    public function getOwnerData(): array {
        return [
            'owner_type' => $this->getOwnerType(),
            'owner_id' => $this->getOwnerId()
        ];
    }
}

/**
 * Helper function to get auth instance
 */
function auth(): Auth {
    return Auth::getInstance();
}
