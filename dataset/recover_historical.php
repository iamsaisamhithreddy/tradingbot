<?php
// ============================================================
// HISTORICAL TRADE DATA RECOVERY
// Place at:  public_html/dataset/recover_historical.php
// CSV path:  public_html/dataset/BEFORE-JUN-2025/PAIR/FX_PAIR-YYYY-MM-DD.csv
// Run:       ?start_date=2020-01-01&end_date=2025-06-30
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../db.php';

$IST = new DateTimeZone('Asia/Kolkata');
$UTC = new DateTimeZone('UTC');

// -------------------------------------------------------
// CONFIG
// -------------------------------------------------------
$csvRoot     = __DIR__ . '/BEFORE-JUN-2025';
$startDate   = $_GET['start_date'] ?? '2020-01-01';
$endDate     = $_GET['end_date']   ?? '2025-06-30';
$windowStart = 1230;
$windowEnd   = 2130;

$forexPairs = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'USDCAD','USDCHF','USDJPY'
];

echo '<pre>';
echo "====================================================\n";
echo " HISTORICAL RECOVERY: {$startDate} → {$endDate}\n";
echo " CSV Root: {$csvRoot}\n";
echo " Price calc fn: O3 (candle 3 open)\n";
echo "====================================================\n\n";

$totalFiles    = 0;
$totalRawIns   = 0;
$totalPredIns  = 0;
$totalSkipTime = 0;
$totalSkipDupe = 0;

// -------------------------------------------------------
// DATE LOOP
// -------------------------------------------------------
$current = new DateTime($startDate, $IST);
$endDt   = new DateTime($endDate,   $IST);

while ($current <= $endDt) {
    $targetDate = $current->format('Y-m-d');
    $foundAny   = false;

    foreach ($forexPairs as $pair) {

        $filePath = "{$csvRoot}/{$pair}/FX_{$pair}-{$targetDate}.csv";
        if (!file_exists($filePath)) {
            continue;
        }

        $foundAny = true;
        $totalFiles++;

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || count($lines) <= 1) {
            unset($lines);
            continue;
        }

        array_shift($lines); // drop header

        $allCandles = [];
        foreach ($lines as $line) {
            $d = str_getcsv($line);
            if (count($d) < 6 || !is_numeric(trim($d[0]))) continue;
            $t = (int)trim($d[0]);
            if ($t < 1000000000) continue;

            $dt = new DateTime('@' . $t);
            $dt->setTimezone($IST);
            if ($dt->format('Y-m-d') !== $targetDate) continue;

            $allCandles[] = [
                'time'  => $t,
                'open'  => (float)$d[1],
                'high'  => (float)$d[2],
                'low'   => (float)$d[3],
                'close' => (float)$d[4],
                'alert' => (int)$d[5],
                'dt'    => $dt,
            ];
        }
        unset($lines);

        if (count($allCandles) < 5) {
            unset($allCandles);
            continue;
        }

        usort($allCandles, fn($a, $b) => $a['time'] <=> $b['time']);

        for ($i = 4; $i < count($allCandles); $i++) {
            $candle = $allCandles[$i];
            $alert  = $candle['alert'];

            if ($alert !== 1 && $alert !== -1) continue;

            $timeInt = (int)$candle['dt']->format('Hi');
            if ($timeInt < $windowStart || $timeInt > $windowEnd) {
                $totalSkipTime++;
                continue;
            }

            $direction = ($alert === 1) ? 'UP' : 'DOWN';

            // Timestamps derived from CSV UNIX time — NOT today
            $candleUtc = clone $candle['dt'];
            $candleUtc->setTimezone($UTC);
            $createdAtUTC    = $candleUtc->format('Y-m-d H:i:s');
            $lastAlertTimeIST = $candle['dt']->format('Y-m-d H:i:s');

            // ---- raw_trade_data: check or insert ----
            $chk = $conn->prepare(
                "SELECT id FROM raw_trade_data WHERE pair_name = ? AND created_at = ? LIMIT 1"
            );
            $chk->bind_param('ss', $pair, $createdAtUTC);
            $chk->execute();
            $chk->store_result();

            if ($chk->num_rows > 0) {
                $chk->bind_result($rawId);
                $chk->fetch();
                $chk->close();
                $totalSkipDupe++;
            } else {
                $chk->close();

                // Build 5-candle payload: candle[i-4] → candle[i]
                $ohlc = [];
                for ($j = 4; $j >= 0; $j--) {
                    $c      = $allCandles[$i - $j];
                    $ohlc[] = ['O' => $c['open'], 'H' => $c['high'], 'L' => $c['low'], 'C' => $c['close']];
                }

                $stmt = $conn->prepare(
                    "INSERT INTO raw_trade_data
                     (pair_name,O1,H1,L1,C1,O2,H2,L2,C2,O3,H3,L3,C3,O4,H4,L4,C4,O5,H5,L5,C5,created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param(
                    'sdddddddddddddddddddds',
                    $pair,
                    $ohlc[0]['O'],$ohlc[0]['H'],$ohlc[0]['L'],$ohlc[0]['C'],
                    $ohlc[1]['O'],$ohlc[1]['H'],$ohlc[1]['L'],$ohlc[1]['C'],
                    $ohlc[2]['O'],$ohlc[2]['H'],$ohlc[2]['L'],$ohlc[2]['C'],
                    $ohlc[3]['O'],$ohlc[3]['H'],$ohlc[3]['L'],$ohlc[3]['C'],
                    $ohlc[4]['O'],$ohlc[4]['H'],$ohlc[4]['L'],$ohlc[4]['C'],
                    $createdAtUTC
                );

                if (!$stmt->execute()) {
                    echo "  ❌ raw INSERT FAILED [{$pair} {$lastAlertTimeIST}]: {$stmt->error}\n";
                    $stmt->close();
                    continue;
                }
                $rawId = $conn->insert_id;
                $stmt->close();
                $totalRawIns++;

                // Price target = open of candle 3 (the last same-color candle's open).
                // For UP: this is below entry — price must retrace down to it.
                // For DOWN: this is above entry — price must retrace up to it.
                $priceTarget = $ohlc[2]['O'];

                // ---- prediction_trade_data: upsert ----
                $stmt2 = $conn->prepare(
                    "INSERT INTO prediction_trade_data
                     (raw_trade_id, pair_name, price_target, trade_direction, sent_status, trade_result, last_alert_time, updated_at)
                     VALUES (?, ?, ?, ?, 1, 'pending', ?, NOW())
                     ON DUPLICATE KEY UPDATE
                         price_target      = VALUES(price_target),
                         trade_direction   = VALUES(trade_direction),
                         sent_status       = 1,
                         trade_result      = IF(trade_result = 'pending', 'pending', trade_result),
                         last_alert_time   = VALUES(last_alert_time),
                         updated_at        = NOW()"
                );
                $stmt2->bind_param('isdss', $rawId, $pair, $priceTarget, $direction, $lastAlertTimeIST);

                if (!$stmt2->execute()) {
                    echo "  ❌ pred UPSERT FAILED [{$pair}]: {$stmt2->error}\n";
                    $stmt2->close();
                    continue;
                }
                $stmt2->close();
                $totalPredIns++;

                echo "  ✓ {$pair} | {$direction} | {$lastAlertTimeIST} IST | Price: {$priceTarget}\n";
            }
        }

        unset($allCandles);
    }

    if ($foundAny) {
        echo "[{$targetDate}] processed.\n";
    }

    // Flush output so browser shows progress on long runs
    if (ob_get_level()) { ob_flush(); }
    flush();

    $current->modify('+1 day');
}

echo "\n====================================================\n";
echo " DONE\n";
echo " Files processed      : {$totalFiles}\n";
echo " raw_trade_data INS   : {$totalRawIns}\n";
echo " prediction_trade INS : {$totalPredIns}\n";
echo " Skipped (time window): {$totalSkipTime}\n";
echo " Skipped (DB dupes)   : {$totalSkipDupe}\n";
echo "====================================================\n";
echo '</pre>';
?>