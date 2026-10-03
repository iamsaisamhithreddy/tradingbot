<?php
$file = __DIR__ . '/flash.flag';

$action = $_GET['action'] ?? 'get';

if ($action === 'set') {
    $state = ($_GET['state'] === '1') ? '1' : '0';
    file_put_contents($file, $state);
    echo json_encode(['status' => 'ok', 'flash' => $state]);
    exit;
}

if ($action === 'get') {
    header('Content-Type: text/plain');
    echo file_exists($file) ? trim(file_get_contents($file)) : '0';
    exit;
}

if ($action === 'clear') {
    file_put_contents($file, '0');
    echo json_encode(['status' => 'cleared']);
    exit;
}