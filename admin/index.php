<?php
require_once __DIR__ . '/../includes/auth.php';
auth()->requireAuth();
redirect(ADMIN_URL . '/saas_dashboard.php');
