<?php
// =========================================================================
// control.php  —  ESP32 on/off flag store + control API
// Place at: https://tradeedify.site/control.php
//
//   ?action=get              -> plain "1" or "0"   (the ESP32 polls this)
//   ?action=set&state=1|0    -> sets the flag       (the dashboard calls this)
//   ?action=status           -> JSON {running:"1"}  (dashboard reads this)
// =========================================================================
$flagFile = __DIR__ . '/esp_running.flag';

$action = isset($_GET['action']) ? $_GET['action'] : 'get';

if ($action === 'get') {
    header('Content-Type: text/plain');
    echo file_exists($flagFile) ? trim(file_get_contents($flagFile)) : '0';
    exit;
}

if ($action === 'set') {
    $state = (isset($_GET['state']) && $_GET['state'] === '1') ? '1' : '0';
    file_put_contents($flagFile, $state);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'running' => $state]);
    exit;
}

if ($action === 'status') {
    header('Content-Type: application/json');
    $state = file_exists($flagFile) ? trim(file_get_contents($flagFile)) : '0';
    // also expose last-modified time so the dashboard can show when it changed
    $when  = file_exists($flagFile) ? date('Y-m-d H:i:s', filemtime($flagFile)) : 'never';
    echo json_encode(['running' => $state, 'changed' => $when]);
    exit;
}

http_response_code(400);
echo 'Unknown action';