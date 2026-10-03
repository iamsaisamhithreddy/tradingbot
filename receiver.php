<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

// Log every time the file is pinged
$logFile = __DIR__ . '/receiver_debug.txt';
$timestamp = "[" . date('Y-m-d H:i:s') . "] ";
file_put_contents($logFile, $timestamp . "Webhook triggered.\n", FILE_APPEND);


$input = file_get_contents('php://input');
file_put_contents($logFile, $timestamp . "Raw Payload: " . $input . "\n", FILE_APPEND);

if (!$input) {
    file_put_contents($logFile, $timestamp . "Error: No input data\n", FILE_APPEND);
    die("No input");
}

$data = json_decode($input, true);
$ticker = $data['ticker'] ?? null;
$ohlc   = $data['ohlc'] ?? [];


if (!$ticker || count($ohlc) !== 5) {
    file_put_contents($logFile, $timestamp . "Error: Missing data fields\n", FILE_APPEND);
    die("Missing data");
}

$params = [];
for ($i = 0; $i < 5; $i++) {
    $c = $ohlc[$i];
    $idx = $i + 1;
    $params["O$idx"] = floatval($c['O']);
    $params["H$idx"] = floatval($c['H']);
    $params["L$idx"] = floatval($c['L']);
    $params["C$idx"] = floatval($c['C']);
}


$sql = "INSERT INTO raw_trade_data 
(pair_name, O1, H1, L1, C1, O2, H2, L2, C2, O3, H3, L3, C3, O4, H4, L4, C4, O5, H5, L5, C5)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    file_put_contents($logFile, $timestamp . "SQL Prepare Error: " . $conn->error . "\n", FILE_APPEND);
    die("Prepare failed");
}


$stmt->bind_param("sdddddddddddddddddddd",
    $ticker, 
    $params['O1'], $params['H1'], $params['L1'], $params['C1'],
    $params['O2'], $params['H2'], $params['L2'], $params['C2'],
    $params['O3'], $params['H3'], $params['L3'], $params['C3'],
    $params['O4'], $params['H4'], $params['L4'], $params['C4'],
    $params['O5'], $params['H5'], $params['L5'], $params['C5']
);

if (!$stmt->execute()) {
    file_put_contents($logFile, $timestamp . "SQL Execute Error: " . $stmt->error . "\n", FILE_APPEND);
    die("Execute failed");
}

$stmt->close();
$conn->close();

file_put_contents($logFile, $timestamp . "SUCCESS: Inserted $ticker\n\n", FILE_APPEND);
echo "Success";
?>