<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

// ── Input params ─────────────────────────────────────────────
$symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : 'BTC/USD';
$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 10000;
$since  = isset($_GET['since'])  ? (int)$_GET['since']  : 0;
$mode   = isset($_GET['mode'])   ? $_GET['mode']         : 'full';

// ── Normalise symbol → pair string (e.g. "EUR/USD" → "EURUSD") ─
function normalisePair(string $symbol): string {
    return strtoupper(str_replace(['/', '_', ' '], '', $symbol));
}

// ── Build all CSV paths for a given pair (3 sources) ─────────
//
//  Source A  dataset/JUN-2025 TO FEB-2026/FX_{PAIR}.csv          flat file
//  Source B  dataset/dataset/FX_{PAIR}.csv                        flat file
//  Source C  dataset/dataset/{PAIR}/FX_{PAIR}-YYYY-MM-DD.csv      one file per day
//            (only files dated >= 2026-09-11 are included)
//
function getAllCsvPaths(string $pair): array {
    $base = __DIR__;          // directory of this script

    $paths = [];

    // Source A — archive flat file
    $sourceA = $base . '/../dataset/JUN-2025 TO FEB-2026/FX_' . $pair . '.csv';
    if (file_exists($sourceA)) {
        $paths[] = ['file' => $sourceA, 'source' => 'A'];
    }

    // Source B — main flat file (May 2026 → Sep 10 2026, Mar/Apr absent)
    $sourceB = $base . '/../dataset/dataset/FX_' . $pair . '.csv';
    if (file_exists($sourceB)) {
        $paths[] = ['file' => $sourceB, 'source' => 'B'];
    }

    // Source C — daily files from Sep 11 2026 onwards
    $dailyDir    = $base . '/../dataset/dataset/' . $pair;
    $dailyGlob   = glob($dailyDir . '/FX_' . $pair . '-*.csv');
    if ($dailyGlob) {
        sort($dailyGlob); // lexicographic = chronological
        foreach ($dailyGlob as $file) {
            // Keep only files with date >= 2026-09-11
            if (preg_match('/(\d{4}-\d{2}-\d{2})\.csv$/', $file, $m)) {
                if ($m[1] >= '2026-09-11') {
                    $paths[] = ['file' => $file, 'source' => 'C', 'date' => $m[1]];
                }
            }
        }
    }

    return $paths;
}

// ── Read candles from a single CSV file ──────────────────────
// Returns raw rows; Mar/Apr rows are silently skipped.
function readCsvFile(string $filePath, int $since = 0): array {
    $out    = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return $out;

    fgetcsv($handle); // skip header row

    while (($data = fgetcsv($handle)) !== false) {
        if (count($data) < 5 || !is_numeric($data[0])) continue;
        $ts = (int)$data[0];

        // Skip March (3) and April (4) — no data exists across any source
        $month = (int)date('n', $ts);
        if ($month === 3 || $month === 4) continue;

        if ($since > 0 && $ts <= $since) continue;

        $alertFlag = isset($data[5]) ? (int)$data[5] : 0;
        $out[] = [
            'time'      => $ts,
            'open'      => (float)$data[1],
            'high'      => (float)$data[2],
            'low'       => (float)$data[3],
            'close'     => (float)$data[4],
            'alert'     => ($alertFlag !== 0),
            'alert_dir' => $alertFlag,
            'volume'    => isset($data[6]) ? (float)$data[6] : 0,
        ];
    }
    fclose($handle);
    return $out;
}

// ── Merge all sources into one deduplicated, sorted candle list ─
//
// Returns:
//   ['candles' => [...], 'source_counts' => ['A' => n, 'B' => n, 'C' => n]]
//
// Deduplication: $byDate[$ts] keeps the first-seen candle for each timestamp,
// so overlaps between sources don't double-count.
//
function analyseFiles(string $pair, int $since = 0): array {
    $allPaths = getAllCsvPaths($pair);

    if (empty($allPaths)) {
        return ['error' => "No dataset files found for pair: $pair"];
    }

    $byTs         = [];     // ts → candle  (dedup map)
    $sourceCounts = ['A' => 0, 'B' => 0, 'C' => 0];

    foreach ($allPaths as $entry) {
        $rows = readCsvFile($entry['file'], $since);
        $src  = $entry['source'];

        foreach ($rows as $row) {
            $ts = $row['time'];
            if (!isset($byTs[$ts])) {
                $byTs[$ts] = $row;
            }
            $sourceCounts[$src]++;
        }
    }

    ksort($byTs); // sort by timestamp ascending
    return [
        'candles'       => array_values($byTs),
        'source_counts' => $sourceCounts,
    ];
}

// ── Main fetch: applies $limit and $since on the merged set ──
function fetchBarsFromCSV(string $symbol, int $limit, int $since = 0): array {
    $pair   = normalisePair($symbol);
    $result = analyseFiles($pair, $since);

    if (isset($result['error'])) {
        return $result;   // bubble up error
    }

    $candles = $result['candles'];

    // Apply limit (take last N)
    if ($limit > 0 && count($candles) > $limit) {
        $candles = array_slice($candles, -$limit);
    }

    return $candles;     // plain array for callers that don't need source counts
}

// ── fetchBarsWithMeta: like fetchBarsFromCSV but returns source counts too ─
function fetchBarsWithMeta(string $symbol, int $limit, int $since = 0): array {
    $pair   = normalisePair($symbol);
    $result = analyseFiles($pair, $since);

    if (isset($result['error'])) {
        return $result;
    }

    $candles = $result['candles'];
    if ($limit > 0 && count($candles) > $limit) {
        $candles = array_slice($candles, -$limit);
    }

    return [
        'candles'       => $candles,
        'source_counts' => $result['source_counts'],
    ];
}

// ── Stats helper ──────────────────────────────────────────────
function computeStats(array $candles, int $n = 20): array {
    if (empty($candles) || isset($candles['error'])) return [];
    $slice  = array_slice($candles, -$n);
    $closes = array_column($slice, 'close');
    if (empty($closes)) return [];
    $avg      = array_sum($closes) / count($closes);
    $variance = array_sum(array_map(fn($c) => ($c - $avg) ** 2, $closes)) / count($closes);
    $last     = end($candles);
    $prev     = $candles[count($candles) - 2] ?? $last;
    $change   = $last['close'] - $prev['close'];
    $changePct = $prev['close'] != 0 ? round(($change / $prev['close']) * 100, 4) : 0;
    return [
        'last_price'   => $last['close'],
        'change'       => round($change, 5),
        'change_pct'   => $changePct,
        'sma'          => round($avg, 5),
        'volatility'   => round(sqrt($variance), 5),
        'high_24h'     => max(array_column($candles, 'high')),
        'low_24h'      => min(array_column($candles, 'low')),
        'candle_count' => count($candles),
    ];
}

// ── MODE: stream ─────────────────────────────────────────────
if ($mode === 'stream') {
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');
    if (ob_get_level() > 0) { ob_end_flush(); }

    $lastTimestamp = $since;
    $retryMs       = 5000;
    echo "retry: {$retryMs}\n\n";

    $maxCycles = 60;
    $cycle     = 0;

    while ($cycle < $maxCycles) {
        $cycle++;
        $newCandles = fetchBarsFromCSV($symbol, 100, $lastTimestamp);

        if (isset($newCandles['error'])) {
            echo "event: error\ndata: " . json_encode(['error' => $newCandles['error']]) . "\n\n";
        } elseif (!empty($newCandles)) {
            $lastTimestamp = end($newCandles)['time'];
            echo "event: candles\ndata: " . json_encode([
                'type'        => 'delta',
                'symbol'      => $symbol,
                'candles'     => $newCandles,
                'latest_time' => $lastTimestamp,
                'server_time' => time(),
                'stats'       => computeStats($newCandles),
            ]) . "\n\n";
        } else {
            echo "event: heartbeat\ndata: " . json_encode(['server_time' => time(), 'symbol' => $symbol]) . "\n\n";
        }

        if (ob_get_level() > 0) ob_flush();
        flush();
        if (connection_aborted()) break;
        sleep(5);
    }

    echo "event: close\ndata: " . json_encode(['reason' => 'max_cycles_reached']) . "\n\n";
    exit;
}

// ── MODE: delta ──────────────────────────────────────────────
if ($mode === 'delta') {
    $newCandles = fetchBarsFromCSV($symbol, $limit, $since);
    if (isset($newCandles['error'])) {
        http_response_code(400);
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

// ── MODE: full ───────────────────────────────────────────────
$meta = fetchBarsWithMeta($symbol, $limit, 0);
if (isset($meta['error'])) {
    http_response_code(400);
    echo json_encode($meta);
    exit;
}

$candles = $meta['candles'];
echo json_encode([
    'type'          => 'full',
    'symbol'        => $symbol,
    'candlesticks'  => $candles,
    'startDate'     => $candles[0]['time']   ?? 0,
    'endDate'       => end($candles)['time'] ?? 0,
    'server_time'   => time(),
    'stats'         => computeStats($candles),
    'source_counts' => $meta['source_counts'],  // e.g. {"A":12000,"B":8500,"C":288}
]);