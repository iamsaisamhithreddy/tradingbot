<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy


// run a cron job for this every minute. 

require 'db.php'; // database connection

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function stmt_get_assoc($stmt) {
    $stmt->store_result();
    $variables = [];
    $row = [];
    $meta = $stmt->result_metadata();
    while ($field = $meta->fetch_field()) {
        $variables[] = &$row[$field->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $variables);
    $result = [];
    while ($stmt->fetch()) {
        $c = [];
        foreach($row as $key => $val) {
            $c[$key] = $val;
        }
        $result[] = $c;
    }
    return $result;
}

// Get distinct pairs from raw_trade_data
$pairSql = "SELECT DISTINCT pair_name FROM raw_trade_data";
$pairsResult = $conn->query($pairSql);

if (!$pairsResult) {
    die("Failed to get pairs: " . $conn->error);
}

while ($pairRow = $pairsResult->fetch_assoc()) {
    $pair = $pairRow['pair_name'];
    
    // Collect messages for this pair in an array
    $messages = [];
    $messages[] = "Validating pair: {$pair}...<br><br>";

    // Get all rows for this pair ordered by id DESC
    $sql = "SELECT * FROM raw_trade_data WHERE pair_name = ? ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $pair);
    $stmt->execute();
    $rows = stmt_get_assoc($stmt);
    $stmt->close();

    // Loop through each row and validate
    foreach ($rows as $row) {
        $id = $row['id'];

        // Read candle data from columns: O1, C1, H1, L1, ..., O5, C5, H5, L5
        $candles = [];
        for ($i=1; $i <= 5; $i++) {
            $candles[$i] = [
                'O' => floatval($row["O$i"]),
                'C' => floatval($row["C$i"]),
                'H' => floatval($row["H$i"]),
                'L' => floatval($row["L$i"]),
            ];
        }

        // *** New check: fail if any candle open == close ***
        $openEqualsClose = false;
        for ($i = 1; $i <= 5; $i++) {
            if ($candles[$i]['O'] == $candles[$i]['C']) {
                $openEqualsClose = true;
                break;
            }
        }
        if ($openEqualsClose) {
            $messages[] = "Row ID {$id} for pair {$pair} validation failed (open equals close in one or more candles).<br>";
            continue;
        }

        // --- Validation Logic ---

        //  Check first 3 candles same color:
        $color1 = ($candles[1]['C'] > $candles[1]['O']) ? 'green' : 'red';
        $color2 = ($candles[2]['C'] > $candles[2]['O']) ? 'green' : 'red';
        $color3 = ($candles[3]['C'] > $candles[3]['O']) ? 'green' : 'red';

        if (!($color1 == $color2 && $color2 == $color3)) {
            $messages[] = "Row ID {$id} for pair {$pair} validation failed (first 3 candles not same color).<br>";
            continue;
        }

        $trade_direction = ($color1 == 'green') ? 'UP' : 'DOWN';

        // Check 1 or 2 opposite candles after 3 same color candles
        $last_same_candle_open = $candles[3]['O'];
        $opposite_color = ($color1 == 'green') ? 'red' : 'green';

        $opposite_candles_count = 0;
        for ($j=4; $j<=5; $j++) {
            $current_color = ($candles[$j]['C'] > $candles[$j]['O']) ? 'green' : 'red';
            if ($current_color == $opposite_color) {
                $opposite_candles_count++;
            } else {
                break;
            }
        }

        if ($opposite_candles_count < 1 || $opposite_candles_count > 2) {
            $messages[] = "Row ID {$id} for pair {$pair} validation failed (need 1 or 2 opposite candles after 3 same-color).<br>";
            continue;
        }

        // Check if opposite candles touch opening price of last same-color candle
        $touches_opening = false;
        for ($k=4; $k < 4 + $opposite_candles_count; $k++) {
            if ($opposite_color == 'red') {
                if ($candles[$k]['L'] <= $last_same_candle_open) {
                    $touches_opening = true;
                    break;
                }
            } else {
                if ($candles[$k]['H'] >= $last_same_candle_open) {
                    $touches_opening = true;
                    break;
                }
            }
        }

        if ($touches_opening) {
            $messages[] = "Row ID {$id} for pair {$pair} validation failed (opposite candles touch opening price of last same-color candle).<br>";
            continue;
        }

        // Passed all checks: insert into prediction_trade_data
        $price_target = 0.0; // Placeholder

        $insert_sql = "INSERT INTO prediction_trade_data (raw_trade_id, pair_name, price_target, trade_direction) VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            $messages[] = "Prepare insert failed for row {$id}: " . $conn->error . "<br>";
            continue;
        }
        $insert_stmt->bind_param("isss", $id, $pair, $price_target, $trade_direction);

        try {
            $insert_stmt->execute();
            $messages[] = "Row ID {$id} for pair {$pair} validated and added to prediction_trade_data.<br>";
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $messages[] = "Row ID {$id} for pair {$pair} already exists in prediction_trade_data.<br>";
            } else {
                $messages[] = "Insert error for row {$id} for pair {$pair}: " . $e->getMessage() . "<br>";
            }
        }

        $insert_stmt->close();
    }

    // Now output only first 5 messages for this pair
    $max_show = 2;
    $total_messages = count($messages);

    for ($i = 0; $i < min($max_show, $total_messages); $i++) {
        echo $messages[$i];
    }

    if ($total_messages > $max_show) {
        echo "<i>... and " . ($total_messages - $max_show) . " more messages.</i><br>";
    }

    echo "<hr>";
}

$conn->close();
?>
