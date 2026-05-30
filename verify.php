<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php'; 

// ==========================================
// 1. TRADINGVIEW CSV LOADING FUNCTION
// ==========================================
function fetchBarsFromCSV(string $filePath): array {
    $out = [];
    if (!file_exists($filePath)) {
        return $out;
    }
    
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ",");
        if (!$header) {
            fclose($handle);
            return $out;
        }
        
        $header = array_map(function($col) {
            return trim(str_replace('"', '', $col));
        }, $header);
        
        $timeIdx  = array_search('time', $header);
        $openIdx  = array_search('open', $header);
        $closeIdx = array_search('close', $header);
        
        if ($timeIdx === false || $openIdx === false || $closeIdx === false) {
            fclose($handle);
            return $out;
        }
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (isset($data[$timeIdx], $data[$openIdx], $data[$closeIdx])) {
                $out[] = [
                    'time'   => (int)$data[$timeIdx],
                    'open'   => (float)$data[$openIdx],
                    'close'  => (float)$data[$closeIdx]
                ];
            }
        }
        fclose($handle);
    }
    return $out;
}

// ==========================================
// 2. THE EVALUATOR
// ==========================================
function evaluatePatternTrade($candles, $direction, $targetPrice, $alertTimestamp, $sessionEndTimestamp) {
    $alertCandleIndex = -1;
    for ($i = 0; $i < count($candles); $i++) {
        if ($alertTimestamp >= $candles[$i]['time'] && $alertTimestamp < ($candles[$i]['time'] + 300)) {
            $alertCandleIndex = $i;
            break;
        }
    }
    if ($alertCandleIndex === -1) return ['result' => 'pending', 'reason' => 'Waiting for CSV candle data matching alert time.'];

    $consecutiveRed = 0; $consecutiveGreen = 0;
    for ($i = 0; $i < count($candles); $i++) {
        $c = $candles[$i];
        if ($c['close'] < $c['open']) { $consecutiveRed++; $consecutiveGreen = 0; }
        elseif ($c['close'] > $c['open']) { $consecutiveGreen++; $consecutiveRed = 0; }
        else { $consecutiveRed = 0; $consecutiveGreen = 0; }

        if ($i >= $alertCandleIndex) {
            if ($c['time'] > $sessionEndTimestamp) break;
            $waveLength = $i - $alertCandleIndex + 1;

            if ($direction === 'UP' && $c['close'] < $targetPrice) {
                if ($waveLength < 6) return ['result' => 'setup_not_formed', 'reason' => 'Too fast.'];
                if ($consecutiveRed >= 3) {
                    $c1 = $candles[$i + 1] ?? null; $c2 = $candles[$i + 2] ?? null;
                    if (!$c1) return ['result' => 'pending', 'reason' => 'Waiting for C1.'];
                    if ($c1['close'] > $c1['open']) return ['result' => 'win', 'reason' => 'Direct Win.'];
                    if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                    if ($c2['close'] > $c2['open']) return ['result' => 'win', 'reason' => 'MTG1 Win.'];
                    return ['result' => 'loss', 'reason' => 'Failed.'];
                }
                return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
            }

            if ($direction === 'DOWN' && $c['close'] > $targetPrice) {
                if ($waveLength < 6) return ['result' => 'setup_not_formed', 'reason' => 'Too fast.'];
                if ($consecutiveGreen >= 3) {
                    $c1 = $candles[$i + 1] ?? null; $c2 = $candles[$i + 2] ?? null;
                    if (!$c1) return ['result' => 'pending', 'reason' => 'Waiting for C1.'];
                    if ($c1['close'] < $c1['open']) return ['result' => 'win', 'reason' => 'Direct Win.'];
                    if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                    if ($c2['close'] < $c2['open']) return ['result' => 'win', 'reason' => 'MTG1 Win.'];
                    return ['result' => 'loss', 'reason' => 'Failed.'];
                }
                return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
            }
        }
    }
    return ['result' => 'pending', 'reason' => 'Target not broken.'];
}

// ==========================================
// 3. EXECUTION LOGIC (WITH DATE RANGE)
// ==========================================
$isWeb = isset($_SERVER['HTTP_HOST']); 
$istTimezone = new DateTimeZone('Asia/Kolkata');
$utcTimezone = new DateTimeZone('UTC');

// Default to the first of the current month and today if not set
$startDateStr = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDateStr = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$startDT_IST = new DateTime("{$startDateStr} 00:00:00", $istTimezone);
$endDT_IST = new DateTime("{$endDateStr} 23:59:59", $istTimezone);

$startUTC = (clone $startDT_IST)->setTimezone($utcTimezone);
$endUTC = (clone $endDT_IST)->setTimezone($utcTimezone);

if ($isWeb) {
    echo "<h2>🔍 Historical Trade Dashboard (Bulk Dataset Edition)</h2>
          <form method='GET' style='margin-bottom: 20px; padding: 15px; background: #f4f4f4; border-radius: 5px; display: inline-block;'>
              <label>Start Date: <input type='date' name='start_date' value='{$startDateStr}'></label>
              &nbsp;&nbsp;
              <label>End Date: <input type='date' name='end_date' value='{$endDateStr}'></label>
              &nbsp;&nbsp;
              <button type='submit' style='padding: 5px 15px;'>Run Bulk Evaluation</button>
          </form><br>";
}

// FETCH TRADES
$query = "SELECT raw_trade_id, pair_name, price_target, trade_direction, last_alert_time, trade_result 
          FROM prediction_trade_data 
          WHERE last_alert_time >= '{$startUTC->format('Y-m-d H:i:s')}' 
          AND last_alert_time <= '{$endUTC->format('Y-m-d H:i:s')}'";

// If Cron, only check pending. (You might want this active for web too if you only want to process pending ones)
if (!$isWeb) $query .= " AND trade_result = 'pending'";

$result = $conn->query($query);
if ($result) {
    $countProcessed = 0;
    while ($trade = $result->fetch_assoc()) {
        $pair = $trade['pair_name'];
        $cleanPair = str_replace(['/', '_', ' '], '', $pair);
        $csvFilePath = __DIR__ . "/dataset/FX_" . $cleanPair . ".csv";
        
        if (!file_exists($csvFilePath)) {
            if ($isWeb) echo "<span style='color:red;'>ID {$trade['raw_trade_id']} ({$pair}): Missing CSV file at {$csvFilePath}</span><br>";
            continue;
        }
        
        $candles = fetchBarsFromCSV($csvFilePath);
        if (empty($candles)) {
            if ($isWeb) echo "<span style='color:orange;'>ID {$trade['raw_trade_id']} ({$pair}): Empty or malformed CSV data.</span><br>";
            continue;
        }
        
        $dtUTC = new DateTime($trade['last_alert_time'], $utcTimezone);
        
        // Calculate the exact 21:30 IST session end time for the specific day this trade occurred
        $tradeDateIST = (clone $dtUTC)->setTimezone($istTimezone)->format('Y-m-d');
        $sessionEndIST = new DateTime("{$tradeDateIST} 21:30:00", $istTimezone);
        $sessionEndUTC = clone $sessionEndIST;
        $sessionEndUTC->setTimezone($utcTimezone);

        $eval = evaluatePatternTrade($candles, $trade['trade_direction'], (float)$trade['price_target'], $dtUTC->getTimestamp(), $sessionEndUTC->getTimestamp());
        
        if ($eval['result'] !== 'pending') {
            $conn->query("UPDATE prediction_trade_data SET trade_result = '{$eval['result']}', updated_at = NOW() WHERE raw_trade_id = " . (int)$trade['raw_trade_id']);
            if ($isWeb) echo "<span style='color:green;'>ID {$trade['raw_trade_id']} ({$tradeDateIST} - {$pair}): Updated to " . strtoupper($eval['result']) . " | {$eval['reason']}</span><br>";
        } else {
            if ($isWeb) echo "<span style='color:gray;'>ID {$trade['raw_trade_id']} ({$tradeDateIST} - {$pair}): Still PENDING | {$eval['reason']}</span><br>";
        }
        $countProcessed++;
    }
    if ($isWeb) echo "<h3>✅ Finished. Processed {$countProcessed} trades from {$startDateStr} to {$endDateStr}.</h3>";
}
?>