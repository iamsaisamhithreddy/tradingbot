<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

include 'db.php';

// Get raw POST 
$input = file_get_contents('php://input');

// Debug log
file_put_contents("webhook_log.txt", date("Y-m-d H:i:s") . " RAW: " . $input . "\n", FILE_APPEND);

// Normalize: remove any UTF-8 non-breaking spaces
$cleanInput = str_replace("\xC2\xA0", " ", $input);

// Decode JSON
$data = json_decode(trim($cleanInput), true);

// Error handling
if (!$data) {
    file_put_contents(
        "webhook_log.txt",
        date("Y-m-d H:i:s") . " JSON ERROR: " . json_last_error_msg() . " INPUT: " . $cleanInput . "\n",
        FILE_APPEND
    );
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}

if (!isset($data['pair_name']) || !isset($data['current_price'])) {
    file_put_contents(
        "webhook_log.txt",
        date("Y-m-d H:i:s") . " MISSING FIELDS: " . print_r($data, true) . "\n",
        FILE_APPEND
    );
    http_response_code(400);
    echo "Missing fields";
    exit;
}

$pair = strtoupper($data['pair_name']);
$price = (float)$data['current_price'];

// Check if pair exists
$result = $conn->query("SELECT id FROM live_price_data WHERE pair_name='$pair' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $stmt = $conn->prepare("UPDATE live_price_data SET current_price=?, updated_at=NOW() WHERE pair_name=?");
    $stmt->bind_param("ds", $price, $pair);
    $stmt->execute();
} else {
    $stmt = $conn->prepare("INSERT INTO live_price_data (pair_name, current_price, updated_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("sd", $pair, $price);
    $stmt->execute();
}

// Clear log after successful DB update
file_put_contents("webhook_log.txt", "");

echo "Latest price updated for $pair: $price";
?>
