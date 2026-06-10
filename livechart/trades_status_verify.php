<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../db.php'; 

// ==========================================
// 1. FOREXFACTORY API FUNCTIONS
// ==========================================
function fetchBars(string $instrument, int $limit = 500): array {
    $bust   = time() . rand(1000, 9999);
    $apiUrl = "https://mds-api.forexfactory.com/bars?to=0&interval=M5"
            . "&instrument={$instrument}&per_page={$limit}&extra_fields=&_={$bust}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($response, true);
    return !empty($decoded['data']) ? $decoded['data'] : [];
}

function formatBars(array $rawBars): array {
    $out = [];
    foreach (array_reverse($rawBars) as $bar) {
        $out[] = [
            'time'   => (int)$bar['timestamp'],
            'open'   => (float)$bar['open'],
            'close'  => (float)$bar['close']
        ];
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
    if ($alertCandleIndex === -1) return ['result' => 'pending', 'reason' => 'Waiting for API.'];

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
                        if ($waveLength < 3) { // 6 candle logic , after 2 candle confirmation , 1 candle price broken, hence 3 candles
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
                        if ($waveLength < 3) { // 6 candle logic , after 2 candle confirmation , 1 candle price broken, hence 3 candles
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
// 2.5. CSV DATASET ROUTING & LOADING FUNCTIONS
// ==========================================
function getCsvPath(string $pairName, string $alertTimeStr): string {
    $cleanPair = str_replace(['/', '_', ' '], '', $pairName);
    $fileName = "FX_" . $cleanPair . ".csv";
    $timestamp = strtotime($alertTimeStr);
    
    $cutoffEnd = strtotime('2026-03-01 00:00:00');
    
    $datasetDir = __DIR__ . '/../dataset';
    
    $path = '';
    if ($timestamp < $cutoffEnd) {
        $path = $datasetDir . "/JUN-2025 TO FEB-2026/" . $fileName;
    } else {
        $path = $datasetDir . "/dataset/" . $fileName;
    }
    
    if (!empty($path) && file_exists($path)) {
        return $path;
    }
    
    $path1 = $datasetDir . "/dataset/" . $fileName;
    $path2 = $datasetDir . "/JUN-2025 TO FEB-2026/" . $fileName;
    $path3 = $datasetDir . "/" . $fileName;
    
    if (file_exists($path1)) return $path1;
    if (file_exists($path2)) return $path2;
    return $path3;
}

function fetchBarsFromCSV(string $filePath, int $alertTimestamp): array {
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
        
        $startThreshold = $alertTimestamp - 3600;
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (isset($data[$timeIdx], $data[$openIdx], $data[$closeIdx])) {
                $timeVal = (int)floatval($data[$timeIdx]);
                if ($timeVal < $startThreshold) {
                    continue;
                }
                $out[] = [
                    'time'   => $timeVal,
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
// 3. EXECUTION LOGIC
// ==========================================
$isWeb = isset($_SERVER['HTTP_HOST']); // Detect if run in browser
$istTimezone = new DateTimeZone('Asia/Kolkata');
$utcTimezone = new DateTimeZone('UTC');

$targetDateStr = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$todayDate = (new DateTime($targetDateStr, $istTimezone))->format('Y-m-d');

$startTimeIST = new DateTime("{$todayDate} 12:30:00", $istTimezone);
$endTimeIST = new DateTime("{$todayDate} 21:30:00", $istTimezone);
$startTimeUTC = (clone $startTimeIST)->setTimezone($utcTimezone);
$endTimeUTC = (clone $endTimeIST)->setTimezone($utcTimezone);

// If running in browser, show UI
if ($isWeb) {
    echo "<h2>🔍 Historical Trade Dashboard</h2><form method='GET'><input type='date' name='date' value='{$todayDate}'><button type='submit'>Run Evaluation</button></form>";
}

// FETCH TRADES
$query = "SELECT p.raw_trade_id, p.pair_name, p.price_target, p.trade_direction, p.last_alert_time, p.trade_result, 
                 UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime 
          FROM prediction_trade_data p 
          INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id 
          WHERE p.last_alert_time >= '{$startTimeUTC->format('Y-m-d H:i:s')}' 
            AND p.last_alert_time <= '{$endTimeUTC->format('Y-m-d H:i:s')}'";
// If Cron, only check pending
if (!$isWeb) $query .= " AND p.trade_result = 'pending'";

$result = $conn->query($query);
while ($trade = $result->fetch_assoc()) {
    $pair = $trade['pair_name'];
    
    $dtUTC = new DateTime($trade['last_alert_time'], $utcTimezone);
    $triggerTimestamp = isset($trade['trigger_unixtime']) ? (int)$trade['trigger_unixtime'] : $dtUTC->getTimestamp();
    
    $currentDateIST = (new DateTime('now', $istTimezone))->format('Y-m-d');
    $yesterdayDateIST = (new DateTime('yesterday', $istTimezone))->format('Y-m-d');
    $tradeDateIST = (new DateTime("@" . $triggerTimestamp))->setTimezone($istTimezone)->format('Y-m-d');
    
    $useApi = ($tradeDateIST === $currentDateIST || $tradeDateIST === $yesterdayDateIST);
    
    $candles = [];
    if ($useApi) {
        $apiSym = (strpos($pair, '/') === false) ? substr($pair, 0, 3) . '/' . substr($pair, 3, 3) : $pair;
        $candles = formatBars(fetchBars(urlencode($apiSym), 1000));
    } else {
        $csvFilePath = getCsvPath($pair, $trade['last_alert_time']);
        $candles = fetchBarsFromCSV($csvFilePath, $triggerTimestamp);
    }
    
    $eval = evaluatePatternTrade($candles, $trade['trade_direction'], (float)$trade['price_target'], $triggerTimestamp, $endTimeUTC->getTimestamp());
    
    if ($eval['result'] !== 'pending') {
        $conn->query("UPDATE prediction_trade_data SET trade_result = '{$eval['result']}', updated_at = NOW() WHERE raw_trade_id = " . (int)$trade['raw_trade_id']);
        if ($isWeb) echo "ID {$trade['raw_trade_id']} ({$pair}): Updated to " . strtoupper($eval['result']) . " | {$eval['reason']}<br>";
    }
}
if ($isWeb) echo "Evaluated all trades for {$todayDate}.";
?>
