<?php

include 'db.php';

$input      = file_get_contents('php://input');
$cleanInput = str_replace("\xC2\xA0", " ", $input);
$data       = json_decode(trim($cleanInput), true);

if (!$data || !isset($data['pair_name']) || !isset($data['current_price'])) {
    http_response_code(400);
    echo "Missing fields";
    exit;
}

$pair      = strtoupper($data['pair_name']);      // EUR/USD
$price     = (float)$data['current_price'];
$pairClean = str_replace('/', '', $pair);         // EURUSD

// Current 5-minute candle timestamp: floor to nearest 5 min boundary
$currentCandleTs = (int)(floor(time() / 300) * 300);

// update_price.php is in root -> CSVs are at root/dataset/dataset/
$csvFile = __DIR__ . '/dataset/dataset/FX_' . $pairClean . '.csv';

// ---------------------------------------------------------------
// 1. UPDATE CORRECT CANDLE ROW IN CSV BY TIMESTAMP
// ---------------------------------------------------------------
$csvStatus = '';
if (file_exists($csvFile)) {
    $rows   = [];
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);                   // time,open,high,low,close,Pattern Alert,Volume
    while (($row = fgetcsv($handle)) !== FALSE) {
        $rows[] = $row;
    }
    fclose($handle);

    // Search for the row matching the current candle timestamp
    // time=0 open=1 high=2 low=3 close=4 alert=5 volume=6
    $foundIdx = -1;
    foreach ($rows as $idx => $row) {
        if ((int)$row[0] === $currentCandleTs) {
            $foundIdx = $idx;
            break;
        }
    }

    if ($foundIdx !== -1) {
        // Found the candle — update close, and high/low if price broke them
        $rows[$foundIdx][4] = $price;
        if ($price > (float)$rows[$foundIdx][2]) $rows[$foundIdx][2] = $price; // new high
        if ($price < (float)$rows[$foundIdx][3]) $rows[$foundIdx][3] = $price; // new low
        $csvStatus = "Updated candle ts=$currentCandleTs close=$price";
    } else {
        // Candle not in CSV yet (gap or new candle before 5-min scan fires)
        // Insert new row: open=high=low=close=price, alert=0, volume=0
        $rows[] = [$currentCandleTs, $price, $price, $price, $price, 0, 0];

        // Keep sorted by timestamp
        usort($rows, function($a, $b) { return (int)$a[0] - (int)$b[0]; });

        $csvStatus = "Inserted new candle ts=$currentCandleTs price=$price";
    }

    // Write back
    $fp = fopen($csvFile, 'w');
    fputcsv($fp, $header);
    foreach ($rows as $row) fputcsv($fp, $row);
    fclose($fp);

} else {
    $csvStatus = "CSV not found: $csvFile";
}

// ---------------------------------------------------------------
// 2. KEEP MySQL live_price_data IN SYNC
// ---------------------------------------------------------------
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

echo json_encode([
    'status' => 'ok',
    'pair'   => $pair,
    'price'  => $price,
    'candle' => $currentCandleTs,
    'csv'    => $csvStatus
]);
?>