<?php
/**
 * AI AutoPost SEO System - Logout
 * ================================
 */

require_once __DIR__ . '/../includes/auth.php';

auth()->logout();
setFlash('success', 'ออกจากระบบสำเร็จ');
redirect(ADMIN_URL . '/login.php');
