<?php
// ============================================================
// RECOVER YESTERDAY'S MISSED ALERTS
// Script location: public_html/dataset/recover_yesterday.php
// CSV location:    public_html/dataset/dataset/PAIR/FX_PAIR-YYYY-MM-DD.csv
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../db.php';

// -------------------------------------------------------
// CONFIGURATION
// -------------------------------------------------------
$csvRoot   = __DIR__ . '/dataset';
$logFile   = __DIR__ . '/sent_alerts_memory.txt';
$evalScript = __DIR__ . '/evaluate_win_loss.php';

$IST = new DateTimeZone('Asia/Kolkata');
$yesterday = new DateTime('yesterday', $IST);
$targetDate = $yesterday->format('Y-m-d');

// IST recovery window: 12:30 to 21:30
$windowStart = 1230;
$windowEnd   = 2130;

$forexPairs = [
    'AUDCAD', 'AUDCHF', 'AUDJPY', 'AUDUSD',
    'CADJPY', 'CHFJPY',
    'EURAUD', 'EURCAD', 'EURCHF', 'EURGBP', 'EURJPY', 'EURUSD',
    'GBPAUD', 'GBPCAD', 'GBPCHF', 'GBPJPY', 'GBPUSD',
    'USDCAD', 'USDCHF', 'USDJPY'
];

echo '<pre>';
echo "====================================================\n";
echo " RECOVERY SCRIPT — Target Date: {$targetDate} IST\n";
echo " Time Window: 12:30 to 21:30 IST\n";
echo "====================================================\n\n";

// -------------------------------------------------------
// PATH DEBUGGER
// -------------------------------------------------------
echo "================ PATH DEBUG ================\n";
echo "Script __DIR__: " . __DIR__ . "\n";
echo "CSV root: {$csvRoot}\n";
echo "CSV root exists: " . (is_dir($csvRoot) ? 'YES' : 'NO') . "\n";
echo "CSV root readable: " . (is_readable($csvRoot) ? 'YES' : 'NO') . "\n";

if (is_dir($csvRoot)) {
    $rootItems = scandir($csvRoot);
    echo "Pair folders/files visible under CSV root:\n";
    foreach ($rootItems as $item) {
        if ($item === '.' || $item === '..') continue;
        echo "  - {$item}" . (is_dir($csvRoot . '/' . $item) ? ' [DIR]' : '') . "\n";
    }
}

echo "============================================\n\n";

$totalInserted = 0;
$totalSkipped  = 0;
$totalAlerts   = 0;

$sentAlerts = file_exists($logFile)
    ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

foreach ($forexPairs as $pair) {

    // Exact expected path:
    // /public_html/dataset/dataset/AUDCAD/FX_AUDCAD-2026-09-11.csv
    $pairFolder = $csvRoot . '/' . $pair;
    $filePath = $pairFolder . "/FX_{$pair}-{$targetDate}.csv";

    echo "[$pair] Pair folder: {$pairFolder}\n";
    echo "[$pair] Checking exact path: {$filePath}\n";

    if (!is_dir($pairFolder)) {
        echo "[$pair] ⚠ Pair folder not found — skipping.\n";
        $totalSkipped++;
        continue;
    }

    if (!file_exists($filePath)) {
        echo "[$pair] ⚠ Date-specific CSV not found — skipping.\n";
        echo "[$pair] Files inside pair folder:\n";
        $pairItems = scandir($pairFolder);
        foreach ($pairItems as $item) {
            if ($item !== '.' && $item !== '..') echo "    - {$item}\n";
        }
        $totalSkipped++;
        continue;
    }

    echo "[$pair] ✓ CSV found.\n";

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) <= 1) {
        echo "[$pair] ⚠ Empty/unreadable CSV — skipping.\n";
        $totalSkipped++;
        continue;
    }

    array_shift($lines); // remove header
    $allCandles = [];

    foreach ($lines as $line) {
        $data = str_getcsv($line);
        if (count($data) < 6 || !is_numeric(trim($data[0]))) continue;

        $t = (int)trim($data[0]);
        if ($t < 1000000000) continue;

        $dt = new DateTime('@' . $t);
        $dt->setTimezone($IST);

        if ($dt->format('Y-m-d') !== $targetDate) continue;

        $allCandles[] = [
            'time'  => $t,
            'open'  => (float)$data[1],
            'high'  => (float)$data[2],
            'low'   => (float)$data[3],
            'close' => (float)$data[4],
            'alert' => (int)$data[5],
            'dt'    => $dt
        ];
    }

    usort($allCandles, function ($a, $b) {
        return $a['time'] <=> $b['time'];
    });

    if (empty($allCandles)) {
        echo "[$pair] No candles found for {$targetDate}.\n";
        continue;
    }

    echo "[$pair] Found " . count($allCandles) . " candles for {$targetDate}.\n";

    for ($i = 4; $i < count($allCandles); $i++) {
        $candle = $allCandles[$i];
        $alert = $candle['alert'];

        if ($alert !== 1 && $alert !== -1) continue;

        $totalAlerts++;
        $alertId = $pair . '_' . $candle['time'];
        $timeInt = (int)$candle['dt']->format('Hi');
        $direction = ($alert === 1) ? 'UP' : 'DOWN';

        if ($timeInt < $windowStart || $timeInt > $windowEnd) {
            echo "  ⏰ SKIPPED outside window: {$pair} @ " . $candle['dt']->format('H:i') . " IST\n";
            $totalSkipped++;
            continue;
        }

        $alreadySent = in_array($alertId, $sentAlerts, true);
        if ($alreadySent) {
            echo "  ✅ ALREADY SENT: {$pair} @ " . $candle['dt']->format('H:i') . " IST — checking DB row...\n";
        }

        $ohlcPayload = [];
        for ($j = 4; $j >= 0; $j--) {
            $c = $allCandles[$i - $j];
            $ohlcPayload[] = [
                'O' => $c['open'], 'H' => $c['high'],
                'L' => $c['low'], 'C' => $c['close']
            ];
        }

        $candleUtc = clone $candle['dt'];
        $candleUtc->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $candleUtc->format('Y-m-d H:i:s');

        $chk = $conn->prepare("SELECT id FROM raw_trade_data WHERE pair_name = ? AND created_at = ? LIMIT 1");
        if (!$chk) {
            echo "  ❌ DB prepare failed: " . $conn->error . "\n";
            continue;
        }
        $chk->bind_param('ss', $pair, $createdAt);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $chk->bind_result($rawId);
            $chk->fetch();
            $chk->close();
            echo "  ℹ Existing raw_trade_data id={$rawId}\n";
        } else {
            $chk->close();

            $sqlRaw = "INSERT INTO raw_trade_data
                (pair_name,O1,H1,L1,C1,O2,H2,L2,C2,O3,H3,L3,C3,O4,H4,L4,C4,O5,H5,L5,C5,created_at)
                VALUES (?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?)";

            $stmt = $conn->prepare($sqlRaw);
            if (!$stmt) {
                echo "  ❌ raw_trade_data prepare failed: " . $conn->error . "\n";
                continue;
            }

            $stmt->bind_param(
                'sdddddddddddddddddddds',
                $pair,
                $ohlcPayload[0]['O'], $ohlcPayload[0]['H'], $ohlcPayload[0]['L'], $ohlcPayload[0]['C'],
                $ohlcPayload[1]['O'], $ohlcPayload[1]['H'], $ohlcPayload[1]['L'], $ohlcPayload[1]['C'],
                $ohlcPayload[2]['O'], $ohlcPayload[2]['H'], $ohlcPayload[2]['L'], $ohlcPayload[2]['C'],
                $ohlcPayload[3]['O'], $ohlcPayload[3]['H'], $ohlcPayload[3]['L'], $ohlcPayload[3]['C'],
                $ohlcPayload[4]['O'], $ohlcPayload[4]['H'], $ohlcPayload[4]['L'], $ohlcPayload[4]['C'],
                $createdAt
            );

            if (!$stmt->execute()) {
                echo "  ❌ raw_trade_data INSERT FAILED: {$stmt->error}\n";
                $stmt->close();
                continue;
            }

            $rawId = $conn->insert_id;
            $stmt->close();
            echo "  ✓ Inserted raw_trade_data id={$rawId}\n";
        }

        $priceTarget = $ohlcPayload[4]['C'];
        $lastAlertTimeIst = $candle['dt']->format('Y-m-d H:i:s');

        $sqlPred = "INSERT INTO prediction_trade_data
            (raw_trade_id,pair_name,price_target,trade_direction,sent_status,trade_result,last_alert_time,updated_at)
            VALUES (?, ?, ?, ?, 1, 'pending', ?, NOW())
            ON DUPLICATE KEY UPDATE
                price_target=VALUES(price_target),
                trade_direction=VALUES(trade_direction),
                sent_status=1,
                trade_result=IF(trade_result IN ('recovered','pending'),'pending',trade_result),
                last_alert_time=VALUES(last_alert_time),
                updated_at=NOW()";

        $stmt2 = $conn->prepare($sqlPred);
        if (!$stmt2) {
            echo "  ❌ prediction prepare failed: " . $conn->error . "\n";
            continue;
        }

        $stmt2->bind_param('isdss', $rawId, $pair, $priceTarget, $direction, $lastAlertTimeIst);

        if (!$stmt2->execute()) {
            echo "  ❌ prediction INSERT FAILED: {$stmt2->error}\n";
            $stmt2->close();
            continue;
        }
        $stmt2->close();

        if (!$alreadySent) {
            file_put_contents($logFile, $alertId . PHP_EOL, FILE_APPEND);
            $sentAlerts[] = $alertId;
            $totalInserted++;
            echo "  🚨 RECOVERED: {$pair} | {$direction} | @ " . $candle['dt']->format('H:i') . " IST | Price: {$priceTarget}\n";
        } else {
            echo "  🔄 UPSERTED prediction row: {$pair} | {$direction}\n";
        }
    }
}

echo "\n====================================================\n";
echo " DONE.\n";
echo " Total alerts found : {$totalAlerts}\n";
echo " Inserted (recovered): {$totalInserted}\n";
echo " Skipped             : {$totalSkipped}\n";
echo "====================================================\n\n";

echo "🔄 Auto-triggering evaluator for {$targetDate}...\n";

if (file_exists($evalScript)) {
    $_GET['trigger_evaluation'] = '1';
    $_GET['duration'] = 'custom';
    $_GET['start_date'] = $targetDate;
    $_GET['end_date'] = $targetDate;
    $_GET['limit'] = '500';
    $_GET['cron'] = '1';

    ob_start();
    include $evalScript;
    $evalOutput = ob_get_clean();

    $evalLines = explode("\n", strip_tags($evalOutput));
    foreach ($evalLines as $line) {
        $line = trim($line);
        if ($line !== '') echo "  [evaluator] {$line}\n";
    }
    echo "\n✅ Evaluator finished for {$targetDate}.\n";
} else {
    echo "⚠ evaluate_win_loss.php not found at: {$evalScript}\n";
}

echo '</pre>';
?>
