<?php
// Include the db.php file from one level up to access $WebsiteURL
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

// ── Input params ─────────────────────────────────────────────
$symbol     = isset($_GET['symbol'])    ? trim($_GET['symbol'])   : 'BTC/USD';
$limit      = isset($_GET['limit'])     ? (int)$_GET['limit']     : 10000;
$since      = isset($_GET['since'])     ? (int)$_GET['since']     : 0;   // last known timestamp
$mode       = isset($_GET['mode'])      ? $_GET['mode']           : 'full'; // full | delta | stream

// ── Fetch from Local CSV instead of ForexFactory ──────────────
function fetchBarsFromCSV(string $symbol, int $limit, int $since = 0): array {
    $cleanSymbol = str_replace(['/', '_', ' '], '', $symbol);
    $fileName = "FX_" . $cleanSymbol . ".csv";

    // Dynamic Directory Lookup Loop (Checks absolute paths across parent/child structures)
    $baseDirs = [
        __DIR__ . '/dataset/dataset',
        __DIR__ . '/dataset',
        __DIR__ . '/../dataset/dataset',
        __DIR__ . '/../dataset'
    ];

    $filePath = '';
    foreach ($baseDirs as $dir) {
        if (file_exists($dir . '/' . $fileName)) {
            $filePath = $dir . '/' . $fileName;
            break;
        }
    }
    
    // If file is still not found anywhere on disk
    if (empty($filePath)) {
        return ['error' => "CSV database file not found for symbol: $cleanSymbol. Please ensure data was pushed via add.php first."];
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) <= 1) {
        return ['error' => 'CSV file is empty or only has headers'];
    }

    array_shift($lines); // Remove the header row

    $out = [];
    foreach ($lines as $line) {
        $data = str_getcsv($line);
        if (count($data) >= 5 && is_numeric($data[0])) {
            $time = (int)$data[0];
            
            // Skip older candles if we are in 'delta' or 'stream' mode
            if ($since > 0 && $time <= $since) {
                continue;
            }

            // Extract the pre-calculated alert from column 6 (Index 5)
            $alertFlag = isset($data[5]) ? (int)$data[5] : 0;

            $out[] = [
                'time'      => $time,
                'open'      => (float)$data[1],
                'high'      => (float)$data[2],
                'low'       => (float)$data[3],
                'close'     => (float)$data[4],
                'alert'     => ($alertFlag !== 0), 
                'alert_dir' => $alertFlag, // 1 for UP, -1 for DOWN
                'volume'    => isset($data[6]) ? (float)$data[6] : 0,
            ];
        }
    }

    // Apply the limit (take the last N items)
    if ($limit > 0 && count($out) > $limit) {
        $out = array_slice($out, -$limit);
    }

    return $out;
}

// ── Compute basic stats for the last N candles ───────────────
function computeStats(array $candles, int $n = 20): array {
    if (empty($candles) || isset($candles['error'])) return [];

    $slice  = array_slice($candles, -$n);
    $closes = array_column($slice, 'close');
    if (empty($closes)) return [];

    $avg = array_sum($closes) / count($closes);
    $variance = array_sum(array_map(fn($c) => ($c - $avg) ** 2, $closes)) / count($closes);

    $last    = end($candles);
    $prev    = $candles[count($candles) - 2] ?? $last;
    $change  = $last['close'] - $prev['close'];
    $changePct = $prev['close'] != 0 ? round(($change / $prev['close']) * 100, 4) : 0;

    return [
        'last_price'    => $last['close'],
        'change'        => round($change, 5),
        'change_pct'    => $changePct,
        'sma'           => round($avg, 5),
        'volatility'    => round(sqrt($variance), 5),
        'high_24h'      => max(array_column($candles, 'high')),
        'low_24h'       => min(array_column($candles, 'low')),
        'candle_count'  => count($candles),
    ];
}

//  MODE: stream  — Server-Sent Events (true persistent feed)
if ($mode === 'stream') {
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');
    ob_end_flush();

    $lastTimestamp = $since;
    $retryMs       = 5000;

    echo "retry: {$retryMs}\n\n";

    $maxCycles = 60;
    $cycle     = 0;

    while ($cycle < $maxCycles) {
        $cycle++;
        
        $newCandles = fetchBarsFromCSV($symbol, 100, $lastTimestamp);

        if (isset($newCandles['error'])) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => $newCandles['error']]) . "\n\n";
        } else if (!empty($newCandles)) {
            $lastTimestamp = end($newCandles)['time'];
            $payload = [
                'type'        => 'delta',
                'symbol'      => $symbol,
                'candles'     => $newCandles,
                'latest_time' => $lastTimestamp,
                'server_time' => time(),
                'stats'       => computeStats($newCandles),
            ];
            echo "event: candles\n";
            echo "data: " . json_encode($payload) . "\n\n";
        } else {
            echo "event: heartbeat\n";
            echo "data: " . json_encode(['server_time' => time(), 'symbol' => $symbol]) . "\n\n";
        }

        if (ob_get_level() > 0) ob_flush();
        flush();

        if (connection_aborted()) break;

        sleep(5);
    }

    echo "event: close\n";
    echo "data: " . json_encode(['reason' => 'max_cycles_reached']) . "\n\n";
    exit;
}

//  MODE: delta  — Only return candles newer than ?since=
if ($mode === 'delta') {
    $newCandles = fetchBarsFromCSV($symbol, $limit, $since);

    if (isset($newCandles['error'])) {
        http_response_code(400); // Bad Request instead of server crash
        echo json_encode($newCandles);
        exit;
    }

    echo json_encode([
        'type'        => 'delta',
        'symbol'      => $symbol,
        'candles'     => $newCandles,
        'latest_time' => !empty($newCandles) ? end($newCandles)['time'] : $since,
        'server_time' => time(),
        'has_new'     => count($newCandles) > 0,
        'stats'       => count($newCandles) > 0 ? computeStats($newCandles) : null,
    ]);
    exit;
}

//  MODE: full  — Original full load (default)
$candles = fetchBarsFromCSV($symbol, $limit, 0);

if (isset($candles['error'])) {
    http_response_code(400); // Clear client visibility code
    echo json_encode($candles);
    exit;
}

echo json_encode([
    'type'         => 'full',
    'symbol'       => $symbol,
    'candlesticks' => $candles,
    'startDate'    => $candles[0]['time']        ?? 0,
    'endDate'      => end($candles)['time']      ?? 0,
    'server_time'  => time(),
    'stats'        => computeStats($candles),
]);
?>