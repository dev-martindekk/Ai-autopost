<?php
// 2FA settings have been merged into profile.php
require_once __DIR__ . '/../includes/auth.php';
auth()->requireAuth();
redirect(ADMIN_URL . '/profile.php');
