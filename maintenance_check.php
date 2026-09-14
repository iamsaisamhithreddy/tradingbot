<?php
/**
 * maintenance_check.php
 * ─────────────────────
 * Include this at the very top of every public-facing PHP page:
 *   require_once __DIR__ . '/maintenance_check.php';
 *
 * Pages that are ALWAYS allowed through:
 *   - login.php
 *   - maintenance.php
 *   - toggle_maintenance.php
 *   - Any logged-in admin (they see the live site)
 */

$_MAINT_FILE = __DIR__ . '/maintenance.json';
$_MAINT_DATA = [];
$_MAINT_ON   = false;

if (file_exists($_MAINT_FILE)) {
    $_MAINT_DATA = json_decode(file_get_contents($_MAINT_FILE), true) ?? [];
    $_MAINT_ON   = !empty($_MAINT_DATA['enabled']);
}

if ($_MAINT_ON) {
    // Pages always exempt from maintenance redirect
    $exemptPages = ['login.php', 'maintenance.php', 'toggle_maintenance.php'];
    $currentPage = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

    // Allow admins through — start session safely
    if (session_status() === PHP_SESSION_NONE) session_start();
    $isAdmin = !empty($_SESSION['admin_logged_in']);

    if (!$isAdmin && !in_array($currentPage, $exemptPages)) {
        // Pass maintenance data to the page via query string (optional)
        $eta = urlencode($_MAINT_DATA['eta'] ?? '');
        $msg = urlencode($_MAINT_DATA['message'] ?? '');
        header("HTTP/1.1 503 Service Unavailable");
        header("Retry-After: 3600");
        header("Location: /maintenance.php?eta={$eta}&msg={$msg}");
        exit;
    }
}