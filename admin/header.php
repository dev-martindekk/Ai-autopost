<?php
/**
 * AI AutoPost SEO System - Admin Header
 * ======================================
 * Modern UI Design v2.0
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/html; charset=UTF-8');

// Require authentication
auth()->requireAuth();

$currentUser = auth()->getUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Generate CSRF token
$csrfToken = generateCsrfToken();


?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?? 'Dashboard' ?> - AI AutoPost SEO</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=2">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <!-- Toastr -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #6366F1;
            --primary-dark: #4F46E5;
            --primary-light: #818CF8;
            --secondary: #8B5CF6;
            --success: #10B981;
            --success-light: #34D399;
            --danger: #EF4444;
            --danger-light: #F87171;
            --warning: #F59E0B;
            --warning-light: #FBBF24;
            --info: #06B6D4;
            --info-light: #22D3EE;
            --dark: #1E1B4B;
            --sidebar-bg: linear-gradient(180deg, #0F172A 0%, #1E1B4B 50%, #312E81 100%);
            --sidebar-width: 280px;
            --header-height: 70px;
            --border-radius: 16px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 25px 50px -12px rgb(0 0 0 / 0.25);
        }

        * {
            font-family: 'Inter', 'Prompt', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
            min-height: 100vh;
        }

        /* ====== SIDEBAR ====== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-xl);
        }

        .sidebar-brand {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .sidebar-brand-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
            animation: pulse-glow 2s ease-in-out infinite;
        }

        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3); }
            50% { box-shadow: 0 8px 24px rgba(99, 102, 241, 0.5); }
        }

        .sidebar-brand-text {
            color: white;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .sidebar-brand-text small {
            display: block;
            font-size: 11px;
            font-weight: 400;
            opacity: 0.6;
            letter-spacing: 0;
        }

        /* Scrollable Navigation */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 16px 0;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.2) transparent;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .nav-section-title {
            color: rgba(255, 255, 255, 0.4);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 16px 24px 8px;
            margin-top: 8px;
        }

        .sidebar-nav .nav-link {
            color: rgba(255, 255, 255, 0.7);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 3px solid transparent;
            margin: 2px 12px 2px 0;
            border-radius: 0 12px 12px 0;
            font-size: 14px;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .sidebar-nav .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.2) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .sidebar-nav .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.05);
            border-left-color: rgba(99, 102, 241, 0.5);
        }

        .sidebar-nav .nav-link:hover::before {
            opacity: 1;
        }

        .sidebar-nav .nav-link.active {
            color: white;
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.3) 0%, rgba(139, 92, 246, 0.1) 100%);
            border-left-color: var(--primary);
            font-weight: 600;
        }

        .sidebar-nav .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
            transition: transform 0.2s;
        }

        .sidebar-nav .nav-link:hover i {
            transform: scale(1.1);
        }

        .sidebar-nav .nav-link .badge {
            font-size: 9px;
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar-footer-text {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
            text-align: center;
        }

        /* ====== MAIN CONTENT ====== */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /* ====== HEADER ====== */
        .main-header {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            height: var(--header-height);
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            background: white;
            border-radius: 14px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
        }

        .header-stat:hover {
            box-shadow: var(--shadow);
            transform: translateY(-1px);
        }

        .header-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .header-stat-value {
            font-size: 20px;
            font-weight: 700;
            line-height: 1;
            color: #1E293B;
        }

        .header-stat-label {
            font-size: 11px;
            color: #64748B;
            font-weight: 500;
        }

        .user-dropdown .dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px 8px 8px;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            background: white;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .user-dropdown .dropdown-toggle:hover {
            box-shadow: var(--shadow);
        }

        .user-dropdown .dropdown-toggle::after {
            display: none;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .dropdown-menu {
            border: none;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            padding: 8px;
            margin-top: 8px;
        }

        .dropdown-item {
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 500;
            transition: all 0.15s;
        }

        .dropdown-item:hover {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
        }

        /* ====== CONTENT AREA ====== */
        .content-area {
            padding: 32px;
        }

        /* ====== CARDS ====== */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            background: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 20px 24px;
            font-weight: 600;
            font-size: 15px;
            color: #1E293B;
        }

        .card-body {
            padding: 24px;
        }

        /* ====== STAT CARDS ====== */
        .stat-card {
            border-radius: var(--border-radius);
            padding: 24px;
            color: white;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            right: -30px;
            bottom: -30px;
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            right: 20px;
            top: -20px;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .stat-card-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 16px;
            backdrop-filter: blur(10px);
        }

        .stat-card-value {
            font-size: 36px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -1px;
        }

        .stat-card-label {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 8px;
            font-weight: 500;
        }

        /* Gradient Backgrounds */
        .bg-gradient-primary {
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
        }

        .bg-gradient-success {
            background: linear-gradient(135deg, #10B981 0%, #34D399 100%);
        }

        .bg-gradient-warning {
            background: linear-gradient(135deg, #F59E0B 0%, #FBBF24 100%);
        }

        .bg-gradient-info {
            background: linear-gradient(135deg, #06B6D4 0%, #22D3EE 100%);
        }

        .bg-gradient-danger {
            background: linear-gradient(135deg, #EF4444 0%, #F87171 100%);
        }

        .bg-gradient-purple {
            background: linear-gradient(135deg, #8B5CF6 0%, #A78BFA 100%);
        }

        .bg-gradient-teal {
            background: linear-gradient(135deg, #14B8A6 0%, #2DD4BF 100%);
        }

        .bg-gradient-secondary {
            background: linear-gradient(135deg, #64748B 0%, #94A3B8 100%);
        }

        .bg-gradient-orange {
            background: linear-gradient(135deg, #F97316 0%, #FB923C 100%);
        }

        .bg-gradient-pink {
            background: linear-gradient(135deg, #EC4899 0%, #F472B6 100%);
        }

        .bg-gradient-dark {
            background: linear-gradient(135deg, #1E293B 0%, #334155 100%);
        }

        /* ====== BUTTONS ====== */
        .btn {
            border-radius: 12px;
            font-weight: 600;
            padding: 10px 20px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
            color: white;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), var(--info-light));
            color: white;
            box-shadow: 0 4px 14px rgba(6, 182, 212, 0.3);
        }

        .btn-outline-primary {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .btn-xs {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 8px;
        }

        .btn-sm {
            padding: 8px 14px;
            font-size: 13px;
            border-radius: 10px;
        }

        /* ====== FORMS ====== */
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 2px solid #E2E8F0;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
            font-size: 14px;
        }

        /* ====== TABLES ====== */
        .table {
            margin-bottom: 0;
        }

        .table thead th {
            font-weight: 600;
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
            color: white;
            border: none;
            padding: 16px 20px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .table thead th:first-child {
            border-radius: 12px 0 0 0;
        }

        .table thead th:last-child {
            border-radius: 0 12px 0 0;
        }

        .table tbody tr {
            transition: all 0.2s ease;
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.03), rgba(139, 92, 246, 0.03));
        }

        .table td {
            vertical-align: middle;
            border-color: #F1F5F9;
            padding: 16px 20px;
            font-size: 14px;
            color: #475569;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* ====== BADGES ====== */
        .badge {
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            letter-spacing: 0.3px;
        }

        .badge-sm {
            padding: 4px 8px;
            font-size: 10px;
        }

        /* ====== ALERTS ====== */
        .alert {
            border: none;
            border-radius: 14px;
            padding: 16px 20px;
            font-weight: 500;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(52, 211, 153, 0.1));
            color: #047857;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(248, 113, 113, 0.1));
            color: #B91C1C;
            border-left: 4px solid var(--danger);
        }

        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(251, 191, 36, 0.1));
            color: #B45309;
            border-left: 4px solid var(--warning);
        }

        .alert-info {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1), rgba(34, 211, 238, 0.1));
            color: #0E7490;
            border-left: 4px solid var(--info);
        }

        /* ====== PROGRESS ====== */
        .progress {
            height: 8px;
            border-radius: 4px;
            background: #E2E8F0;
            overflow: hidden;
        }

        .progress-bar {
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        /* ====== MODALS ====== */
        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
        }

        .modal-header {
            border-bottom: 1px solid #F1F5F9;
            padding: 20px 24px;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #F1F5F9;
            padding: 16px 24px;
        }

        /* ====== RESPONSIVE ====== */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .header-stat {
                display: none;
            }

            .content-area {
                padding: 20px;
            }
        }

        /* ====== SCROLLBAR ====== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #F1F5F9;
        }

        ::-webkit-scrollbar-thumb {
            background: #CBD5E1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94A3B8;
        }

        /* ====== ANIMATIONS ====== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }

        /* ====== TOAST (Notification) ====== */
        #toast-container {
            top: 80px !important;
            right: 20px !important;
        }

        #toast-container > div {
            min-width: 320px !important;
            max-width: 450px !important;
            padding: 20px 24px 20px 60px !important;
            border-radius: 16px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2) !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            opacity: 1 !important;
        }

        #toast-container > div:before {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 22px;
        }

        #toast-container > .toast-success {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%) !important;
        }

        #toast-container > .toast-success:before {
            content: "\f058";
        }

        #toast-container > .toast-error {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%) !important;
        }

        #toast-container > .toast-error:before {
            content: "\f06a";
        }

        #toast-container > .toast-warning {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%) !important;
        }

        #toast-container > .toast-warning:before {
            content: "\f071";
        }

        #toast-container > .toast-info {
            background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%) !important;
        }

        #toast-container > .toast-info:before {
            content: "\f05a";
        }

        #toast-container .toast-title {
            font-size: 16px !important;
            font-weight: 700 !important;
            margin-bottom: 6px !important;
        }

        #toast-container .toast-message {
            font-size: 14px !important;
            line-height: 1.5 !important;
        }

        #toast-container .toast-close-button {
            font-size: 20px !important;
            font-weight: 300 !important;
            top: 8px !important;
            right: 12px !important;
            opacity: 0.8 !important;
        }

        #toast-container .toast-close-button:hover {
            opacity: 1 !important;
        }

        #toast-container .toast-progress {
            height: 4px !important;
            border-radius: 0 0 16px 16px !important;
            background: rgba(255, 255, 255, 0.4) !important;
        }

        /* Animation */
        .toast {
            animation: slideInRight 0.4s ease-out !important;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* ====== DATATABLE ====== */
        .dataTables_wrapper {
            padding: 15px 0;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 10px;
            border: 2px solid #E2E8F0;
            padding: 8px 14px;
            margin-left: 8px;
            margin-right: 8px;
        }

        .dataTables_wrapper .dataTables_info {
            padding: 15px 5px;
            color: #64748B;
        }

        .dataTables_wrapper .dataTables_paginate {
            padding: 15px 5px;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 10px !important;
            margin: 0 3px;
            border: none !important;
            padding: 8px 14px !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, var(--primary), var(--secondary)) !important;
            color: white !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current) {
            background: #F1F5F9 !important;
            color: var(--primary) !important;
        }

        /* Card table padding */
        .card .dataTables_wrapper {
            padding: 15px 20px;
        }

        .card .table-responsive {
            padding: 0 15px;
        }

        /* ====== STATUS BADGES ====== */
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(52, 211, 153, 0.15));
            color: #047857;
        }

        .status-danger, .status-failed {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(248, 113, 113, 0.15));
            color: #B91C1C;
        }

        .status-warning, .status-pending {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(251, 191, 36, 0.15));
            color: #B45309;
        }

        .status-info {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.15), rgba(34, 211, 238, 0.15));
            color: #0E7490;
        }

        /* ====== EMPTY STATE ====== */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 4rem;
            background: linear-gradient(135deg, #E2E8F0, #CBD5E1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }

        .empty-state h5 {
            color: #64748B;
            font-weight: 600;
        }

        .empty-state p {
            color: #94A3B8;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon">
                <i class="fas fa-robot"></i>
            </div>
            <div class="sidebar-brand-text">
                AI AutoPost
                <small>SEO Management System</small>
            </div>
        </div>

        <div class="sidebar-nav">
            <!-- SaaS Management (staff and above) -->
            <?php if (auth()->isStaff()): ?>
            <div class="nav-section-title">SaaS Management</div>
            <a class="nav-link <?= $currentPage === 'saas_dashboard' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/saas_dashboard.php">
                <i class="fas fa-chart-line"></i>
                <span>SaaS Dashboard</span>
            </a>
            <a class="nav-link <?= in_array($currentPage, ['members_list','members_edit']) ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/members_list.php">
                <i class="fas fa-users"></i>
                <span>สมาชิก</span>
                <?php
                    try {
                        $pendingSlips = db()->fetchColumn("SELECT COUNT(*) FROM payment_slips WHERE status = 'pending'");
                        if ($pendingSlips > 0) echo "<span class='badge bg-danger ms-auto'>{$pendingSlips}</span>";
                    } catch (Exception $e) {}
                ?>
            </a>
            <a class="nav-link <?= in_array($currentPage, ['slips_list','slips_review']) ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/slips_list.php">
                <i class="fas fa-receipt"></i>
                <span>ตรวจสลิป</span>
            </a>
            <?php if (auth()->hasRole('admin')): ?>
            <a class="nav-link <?= in_array($currentPage, ['plans_list','plans_edit']) ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/plans_list.php">
                <i class="fas fa-box-open"></i>
                <span>Plans</span>
            </a>
            <a class="nav-link <?= $currentPage === 'invite_codes' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/invite_codes.php">
                <i class="fas fa-ticket"></i>
                <span>Invite Codes</span>
            </a>
            <?php endif; ?>
            <?php if (auth()->isSuperAdmin()): ?>
            <a class="nav-link <?= $currentPage === 'staff_list' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/staff_list.php">
                <i class="fas fa-user-shield"></i>
                <span>Staff</span>
            </a>
            <?php endif; ?>
            <a class="nav-link <?= $currentPage === 'security' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/security.php">
                <i class="fas fa-shield-alt"></i>
                <span>Security</span>
            </a>
            <?php endif; ?>

            <div class="nav-section-title">Configuration</div>
            <a class="nav-link <?= $currentPage === 'settings_ai' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/settings_ai.php">
                <i class="fas fa-microchip"></i>
                <span>AI Providers</span>
            </a>
            <a class="nav-link <?= $currentPage === 'settings_telegram' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/settings_telegram.php">
                <i class="fab fa-telegram"></i>
                <span>Telegram</span>
            </a>

            <a class="nav-link <?= $currentPage === 'settings_payment' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/settings_payment.php">
                <i class="fas fa-qrcode"></i>
                <span>การชำระเงิน</span>
            </a>
            <a class="nav-link <?= $currentPage === 'settings_proxy' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/settings_proxy.php">
                <i class="fas fa-shield-halved"></i>
                <span>Anti-Block Proxy</span>
            </a>
            <a class="nav-link <?= $currentPage === 'settings_email' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/settings_email.php">
                <i class="fas fa-envelope"></i>
                <span>Email (SMTP)</span>
            </a>

            <div class="nav-section-title">Logs</div>
            <a class="nav-link <?= $currentPage === 'logs' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/logs.php">
                <i class="fas fa-scroll"></i>
                <span>System Logs</span>
            </a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/profile.php">
                <i class="fas fa-user-gear"></i>
                <span>Profile</span>
            </a>
            <a class="nav-link <?= $currentPage === 'security_2fa' ? 'active' : '' ?>" href="<?= ADMIN_URL ?>/security_2fa.php">
                <i class="fas fa-shield-alt"></i>
                <span>2FA Security</span>
            </a>
            <a class="nav-link text-danger" href="<?= ADMIN_URL ?>/logout.php">
                <i class="fas fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <div class="sidebar-footer-text">
                <i class="fas fa-shield-halved me-1"></i>
                AI AutoPost SEO v2.0
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="main-header">
            <div class="header-left">
                <button class="btn btn-link d-lg-none p-0" onclick="toggleSidebar()">
                    <i class="fas fa-bars fa-lg text-dark"></i>
                </button>
                <div class="d-none d-md-block">
                    <h5 class="mb-0 fw-bold" style="color: #1E293B;"><?= $pageTitle ?? 'Dashboard' ?></h5>
                </div>
            </div>

            <div class="header-right">
                <div class="dropdown user-dropdown">
                    <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            <?= strtoupper(substr($currentUser['username'], 0, 1)) ?>
                        </div>
                        <div class="d-none d-md-block text-start">
                            <div class="fw-semibold" style="color: #1E293B;"><?= sanitize($currentUser['username']) ?></div>
                            <small class="text-muted"><?= sanitize($currentUser['role']) ?></small>
                        </div>
                        <i class="fas fa-chevron-down text-muted ms-2" style="font-size: 10px;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= ADMIN_URL ?>/profile.php">
                            <i class="fas fa-user me-2 text-primary"></i>Profile
                        </a></li>
                        <li><a class="dropdown-item" href="<?= ADMIN_URL ?>/settings_ai.php">
                            <i class="fas fa-cog me-2 text-secondary"></i>Settings
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= ADMIN_URL ?>/logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area fade-in">
