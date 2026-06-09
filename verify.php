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
// 1.5. ROUTING LOGIC FOR DATASET DIRECTORIES
// ==========================================
function getCsvPath(string $pairName, string $alertTimeStr): string {
    $cleanPair = str_replace(['/', '_', ' '], '', $pairName);
    $fileName = "FX_" . $cleanPair . ".csv";
    $timestamp = strtotime($alertTimeStr);
    
    $cutoffStart = strtotime('2025-06-01 00:00:00');
    $cutoffEnd = strtotime('2026-03-01 00:00:00');
    $ffStart = strtotime('2026-05-22 00:00:00');
    
    $path = '';
    if ($timestamp < $cutoffEnd) {
        $path = __DIR__ . "/dataset/JUN-2025 TO FEB-2026/" . $fileName;
    } else {
        $path = __DIR__ . "/dataset/dataset/" . $fileName;
    }
    
    if (!empty($path) && file_exists($path)) {
        return $path;
    }
    
    $path1 = __DIR__ . "/dataset/dataset/" . $fileName;
    $path2 = __DIR__ . "/dataset/JUN-2025 TO FEB-2026/" . $fileName;
    $path3 = __DIR__ . "/dataset/" . $fileName;
    
    if (file_exists($path1)) return $path1;
    if (file_exists($path2)) return $path2;
    return $path3;
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

    $consecutiveRed = 0; 
    $consecutiveGreen = 0;
    
    // Find the first opposite candle at or after the alert candle index
    $startWaveIndex = $alertCandleIndex;
    for ($j = $alertCandleIndex; $j < count($candles); $j++) {
        $cTemp = $candles[$j];
        if ($direction === 'UP') {
            if ($cTemp['close'] < $cTemp['open']) {
                $startWaveIndex = $j;
                break;
            }
        } elseif ($direction === 'DOWN') {
            if ($cTemp['close'] > $cTemp['open']) {
                $startWaveIndex = $j;
                break;
            }
        }
    }

    $targetBroken = false;
    
    for ($i = 0; $i < count($candles); $i++) {
        $c = $candles[$i];
        if ($c['close'] < $c['open']) { 
            $consecutiveRed++; 
            $consecutiveGreen = 0; 
        } elseif ($c['close'] > $c['open']) { 
            $consecutiveGreen++; 
            $consecutiveRed = 0; 
        } else { 
            $consecutiveRed = 0; 
            $consecutiveGreen = 0; 
        }

        if ($i >= $alertCandleIndex) {
            if ($c['time'] > $sessionEndTimestamp) {
                break;
            }
            
            // Calculate wave length starting from the first opposite candle (or fallback to alert index if current index is before it)
            $waveLength = $i - $startWaveIndex + 1;
            if ($i < $startWaveIndex) {
                $waveLength = $i - $alertCandleIndex + 1;
            }

            if ($direction === 'UP') {
                if ($c['close'] < $targetPrice) {
                    if (!$targetBroken) {
                        if ($waveLength < 6) {
                            return ['result' => 'setup_not_formed', 'reason' => 'Too fast.'];
                        }
                        $targetBroken = true;
                    }
                    if ($consecutiveRed >= 3) {
                        $c1 = $candles[$i + 1] ?? null; 
                        $c2 = $candles[$i + 2] ?? null;
                        if (!$c1) return ['result' => 'pending', 'reason' => 'Waiting for C1.'];
                        if ($c1['close'] > $c1['open']) {
                            return ['result' => 'win', 'reason' => 'Direct Win.'];
                        }
                        if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                        if ($c2['close'] > $c2['open']) {
                            return ['result' => 'win', 'reason' => 'MTG1 Win.'];
                        }
                        return ['result' => 'loss', 'reason' => 'Failed.'];
                    }
                }
                // If target was already broken, but the red streak was reset (opposite candle occurred)
                if ($targetBroken && $consecutiveRed === 0) {
                    return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
                }
            }

            if ($direction === 'DOWN') {
                if ($c['close'] > $targetPrice) {
                    if (!$targetBroken) {
                        if ($waveLength < 6) {
                            return ['result' => 'setup_not_formed', 'reason' => 'Too fast.'];
                        }
                        $targetBroken = true;
                    }
                    if ($consecutiveGreen >= 3) {
                        $c1 = $candles[$i + 1] ?? null; 
                        $c2 = $candles[$i + 2] ?? null;
                        if (!$c1) return ['result' => 'pending', 'reason' => 'Waiting for C1.'];
                        if ($c1['close'] < $c1['open']) {
                            return ['result' => 'win', 'reason' => 'Direct Win.'];
                        }
                        if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                        if ($c2['close'] < $c2['open']) {
                            return ['result' => 'win', 'reason' => 'MTG1 Win.'];
                        }
                        return ['result' => 'loss', 'reason' => 'Failed.'];
                    }
                }
                // If target was already broken, but the green streak was reset (opposite candle occurred)
                if ($targetBroken && $consecutiveGreen === 0) {
                    return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
                }
            }
        }
    }
    
    if ($targetBroken) {
        $lastCandle = end($candles);
        if ($lastCandle && $lastCandle['time'] >= $sessionEndTimestamp) {
            return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
        } else {
            return ['result' => 'pending', 'reason' => 'Waiting for setup streak to form.'];
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
$query = "SELECT p.raw_trade_id, p.pair_name, p.price_target, p.trade_direction, p.last_alert_time, p.trade_result, 
                 UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
          FROM prediction_trade_data p 
          INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
          WHERE p.last_alert_time >= '{$startUTC->format('Y-m-d H:i:s')}' 
          AND p.last_alert_time <= '{$endUTC->format('Y-m-d H:i:s')}'";

// If Cron, only check pending. (You might want this active for web too if you only want to process pending ones)
if (!$isWeb) $query .= " AND p.trade_result = 'pending'";

$result = $conn->query($query);
if ($result) {
    $countProcessed = 0;
    while ($trade = $result->fetch_assoc()) {
        $pair = $trade['pair_name'];
        $csvFilePath = getCsvPath($pair, $trade['last_alert_time']);
        
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
        $triggerTimestamp = isset($trade['trigger_unixtime']) ? (int)$trade['trigger_unixtime'] : $dtUTC->getTimestamp();
        
        // Calculate the exact 21:30 IST session end time for the specific day this trade occurred
        $tradeDateIST = (new DateTime("@" . $triggerTimestamp))->setTimezone($istTimezone)->format('Y-m-d');
        $sessionEndIST = new DateTime("{$tradeDateIST} 21:30:00", $istTimezone);
        $sessionEndUTC = clone $sessionEndIST;
        $sessionEndUTC->setTimezone($utcTimezone);

        $eval = evaluatePatternTrade($candles, $trade['trade_direction'], (float)$trade['price_target'], $triggerTimestamp, $sessionEndUTC->getTimestamp());
        
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
