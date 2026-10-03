<?php
/**
 * toggle_maintenance.php
 * AJAX endpoint — called from the admin dashboard kill switch.
 * Always allow through (exempt from maintenance_check.php).
 */

session_start();
header('Content-Type: application/json');

// Must be logged in as admin
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';   // 'enable' | 'disable' | 'update'

$maintenanceFile = __DIR__ . '/maintenance.json';
$data = [];
if (file_exists($maintenanceFile)) {
    $data = json_decode(file_get_contents($maintenanceFile), true) ?? [];
}

switch ($action) {

    case 'enable':
        $data['enabled']    = true;
        $data['message']    = trim($input['message'] ?? $data['message'] ?? 'We are performing scheduled maintenance.');
        $data['eta']        = trim($input['eta'] ?? '');
        $data['started_at'] = date('Y-m-d H:i:s');
        $data['started_by'] = $_SESSION['admin_username'] ?? 'admin';
        break;

    case 'disable':
        $data['enabled']    = false;
        $data['started_at'] = '';
        $data['started_by'] = '';
        // Keep message & eta for next time
        break;

    case 'update':
        // Update message/eta without toggling state
        if (isset($input['message'])) $data['message'] = trim($input['message']);
        if (isset($input['eta']))     $data['eta']     = trim($input['eta']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}

$written = file_put_contents($maintenanceFile, json_encode($data, JSON_PRETTY_PRINT));

if ($written === false) {
    echo json_encode(['success' => false, 'error' => 'Could not write maintenance.json — check file permissions.']);
    exit;
}

echo json_encode([
    'success' => true,
    'state'   => $data,
]);