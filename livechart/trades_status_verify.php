<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php'; 

// ==========================================
// 1. FOREXFACTORY API FUNCTIONS (UPGRADED)
// ==========================================
function fetchHistoricalBars(string $instrument, int $from, int $to): array {
    $bust   = time() . rand(1000, 9999);
    // Utilizing the historical endpoint with specific timestamps and 9999 limit
    $apiUrl = "https://mds-api.forexfactory.com/bars?"
            . "instrument={$instrument}&interval=M5"
            . "&from={$from}&to={$to}&per_page=9999&extra_fields=&_={$bust}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15, // Increased slightly for larger historical payloads
        CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($response, true);
    return !empty($decoded['data']) ? $decoded['data'] : [];
}

function formatBars(array $rawBars): array {
    $out = [];
    // The historical endpoint might return data in chronological order already, 
    // but array_reverse ensures we process from oldest to newest based on your original logic.
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
// 2. THE EVALUATOR (UNTOUCHED)
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
// 3. EXECUTION LOGIC (UPGRADED API CALLS)
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

// We add a 2-hour buffer (7200 seconds) before and after the session 
// so the evaluator has enough candles for the setup streak and C1/C2 resolutions
$fetchFrom = $startTimeUTC->getTimestamp() - 7200;
$fetchTo = $endTimeUTC->getTimestamp() + 7200;

// If running in browser, show UI
if ($isWeb) {
    echo "<h2>🔍 Historical Trade Dashboard</h2><form method='GET'><input type='date' name='date' value='{$todayDate}'><button type='submit'>Run Evaluation</button></form>";
}

// FETCH TRADES
$query = "SELECT raw_trade_id, pair_name, price_target, trade_direction, last_alert_time, trade_result FROM prediction_trade_data WHERE last_alert_time >= '{$startTimeUTC->format('Y-m-d H:i:s')}' AND last_alert_time <= '{$endTimeUTC->format('Y-m-d H:i:s')}'";
// If Cron, only check pending
if (!$isWeb) $query .= " AND trade_result = 'pending'";

$result = $conn->query($query);
while ($trade = $result->fetch_assoc()) {
    $pair = $trade['pair_name'];
    $apiSym = (strpos($pair, '/') === false) ? substr($pair, 0, 3) . '/' . substr($pair, 3, 3) : $pair;
    
    // Use the new historical fetcher
    $candles = formatBars(fetchHistoricalBars(urlencode($apiSym), $fetchFrom, $fetchTo));
    
    $dtUTC = new DateTime($trade['last_alert_time'], $utcTimezone);
    $eval = evaluatePatternTrade($candles, $trade['trade_direction'], (float)$trade['price_target'], $dtUTC->getTimestamp(), $endTimeUTC->getTimestamp());
    
    if ($eval['result'] !== 'pending') {
        $conn->query("UPDATE prediction_trade_data SET trade_result = '{$eval['result']}', updated_at = NOW() WHERE raw_trade_id = " . (int)$trade['raw_trade_id']);
        if ($isWeb) echo "ID {$trade['raw_trade_id']} ({$pair}): Updated to " . strtoupper($eval['result']) . " | {$eval['reason']}<br>";
    }
}
if ($isWeb) echo "Evaluated all trades for {$todayDate}.";
?>