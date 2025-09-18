<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php'; // database connection

// Get raw POST data
$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(400);
    die("No input data received");
}

// Decode JSON
$data = json_decode($input, true);
if (!$data) {
    http_response_code(400);
    die("Invalid JSON");
}

$ticker = $data['ticker'] ?? null;
$ohlc = $data['ohlc'] ?? [];

if (!$ticker || count($ohlc) !== 5) {
    http_response_code(400);
    die("Missing or incomplete data");
}

// Extract OHLC candles with index 1 to 5
$params = [];
for ($i = 0; $i < 5; $i++) {
    $c = $ohlc[$i];
    $idx = $i + 1;
    $params["O$idx"] = floatval($c['O']);
    $params["H$idx"] = floatval($c['H']);
    $params["L$idx"] = floatval($c['L']);
    $params["C$idx"] = floatval($c['C']);
}

// insert to raw data table 
$sql = "INSERT INTO raw_trade_data 
(pair_name, 
 O1, H1, L1, C1,
 O2, H2, L2, C2,
 O3, H3, L3, C3,
 O4, H4, L4, C4,
 O5, H5, L5, C5)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die("Prepare failed: " . $conn->error);
}

// Bind parameters — s = string, d = double
$stmt->bind_param("sdddddddddddddddddddd",
    $ticker,
    $params['O1'], $params['H1'], $params['L1'], $params['C1'],
    $params['O2'], $params['H2'], $params['L2'], $params['C2'],
    $params['O3'], $params['H3'], $params['L3'], $params['C3'],
    $params['O4'], $params['H4'], $params['L4'], $params['C4'],
    $params['O5'], $params['H5'], $params['L5'], $params['C5']
);

if (!$stmt->execute()) {
    http_response_code(500);
    die("Execute failed: " . $stmt->error);
}

$stmt->close();
$conn->close();

echo "Trade data inserted successfully for $ticker";
