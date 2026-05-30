<?php

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

// ── Input params ─────────────────────────────────────────────
$symbol     = isset($_GET['symbol'])    ? trim($_GET['symbol'])   : 'BTC/USD';
$limit      = isset($_GET['limit'])     ? (int)$_GET['limit']     : 10000;
$since      = isset($_GET['since'])     ? (int)$_GET['since']     : 0;   // last known timestamp
$mode       = isset($_GET['mode'])      ? $_GET['mode']           : 'full'; // full | delta | stream
$instrument = urlencode($symbol);

// ── Fetch from ForexFactory ─
function fetchBars(string $instrument, int $limit): array {
    $bust   = time() . rand(1000, 9999);
    $apiUrl = "https://mds-api.forexfactory.com/bars?to=0&interval=M5"
            . "&instrument={$instrument}&per_page={$limit}&extra_fields=&_={$bust}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Origin: https://www.forexfactory.com',
            'Referer: https://www.forexfactory.com/',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['error' => "cURL error: $curlErr"];
    }
    if ($httpCode !== 200) {
        return ['error' => "API returned HTTP $httpCode"];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON from API'];
    }
    if (empty($decoded['data'])) {
        return ['error' => 'No data returned from API'];
    }

    return $decoded['data'];
}

// ── Format raw bars into Lightweight Charts format ──
function formatBars(array $rawBars): array {
    $out = [];
    foreach (array_reverse($rawBars) as $bar) {
        $out[] = [
            'time'   => (int)$bar['timestamp'],
            'open'   => (float)$bar['open'],
            'high'   => (float)$bar['high'],
            'low'    => (float)$bar['low'],
            'close'  => (float)$bar['close'],
            'volume' => isset($bar['volume']) ? (float)$bar['volume'] : 0,
        ];
    }
    return $out;
}

// ── Apply Pine Script Pattern Strategy & Trigger Webhook ────
function applyStrategy(array &$candles, string $symbol): void {
    $streak = 0;
    $streakBullish = null;
    $streakOpen = null;
    $oppositeCount = 0;
    $waitingForConfirmation = false;

    // Memory Log to prevent duplicate sends
    $logFile = __DIR__ . '/sent_alerts_memory.txt';
    $sentAlerts = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    // ⭐ Variables to track ONLY the absolute latest alert
    $latestAlertIndex = -1;
    $latestBullishStreak = null;

    // 1. The State Machine Loop 
    for ($i = 0; $i < count($candles); $i++) {
        $candle = &$candles[$i];
        $isGreen = $candle['close'] > $candle['open'];
        $thisBullish = $isGreen;
        $candle['alert'] = false; // Default state

        if (!$waitingForConfirmation) {
            if ($streak === 0) {
                $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
            } elseif ($thisBullish === $streakBullish) {
                $streak += 1; $streakOpen = $candle['open'];
            } else {
                if ($streak >= 4) {
                    $waitingForConfirmation = true; $oppositeCount = 1;
                    if (($streakBullish && $candle['low'] <= $streakOpen) || (!$streakBullish && $candle['high'] >= $streakOpen)) {
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    }
                } else {
                    $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                }
            }
        } else {
            if ($thisBullish !== $streakBullish) {
                $oppositeCount += 1;
                if ($oppositeCount > 2) {
                    $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                    $waitingForConfirmation = false; $oppositeCount = 0;
                }
            } else {
                if ($oppositeCount === 2) {
                    if (($streakBullish && $candle['low'] <= $streakOpen) || (!$streakBullish && $candle['high'] >= $streakOpen)) {
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    } else {
                        // RECORD THE LATEST ALERT 
                        $candles[$i]['alert'] = true;
                        $latestAlertIndex = $i;
                        $latestBullishStreak = $streakBullish;

                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    }
                } elseif ($oppositeCount === 1) {
                    // RECORD THE LATEST ALERT (Do not send yet!)
                    $candles[$i]['alert'] = true;
                    $latestAlertIndex = $i;
                    $latestBullishStreak = $streakBullish;

                    $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                    $waitingForConfirmation = false; $oppositeCount = 0;
                }
            }
        }
    }

    // 2. ⭐ ONLY Fire Webhook for the ABSOLUTE LATEST alert found in the loop
    if ($latestAlertIndex !== -1 && $latestAlertIndex >= 4) {
        
        $cleanSymbol = str_replace('/', '', $symbol); // Removes slash (AUD/CAD -> AUDCAD)
        $alertId = $cleanSymbol . '_' . $candles[$latestAlertIndex]['time']; 
        
        // ⚠️ TEMPORARILY FORCE FIRE: Ignored time check for debugging so it always sends
        $isLiveAlert = true; 

        if (!in_array($alertId, $sentAlerts) && $isLiveAlert) {
            $ohlcPayload = [];
            for ($j = 4; $j >= 0; $j--) {
                $c = $candles[$latestAlertIndex - $j];
                $ohlcPayload[] = [
                    'O' => $c['open'], 'H' => $c['high'], 
                    'L' => $c['low'],  'C' => $c['close']
                ];
            }

            $direction = $latestBullishStreak ? 'SELL' : 'BUY';
            $targetPrice = $candles[$latestAlertIndex]['close'];

            $receiverUrl = 'https://saireddy.site/receiver.php'; 
            
            $ch = curl_init($receiverUrl);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'ticker' => $cleanSymbol, 
                'dir'    => $direction,
                'target' => $targetPrice,
                'ohlc'   => $ohlcPayload
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            // ⭐ Increased timeout to 5 seconds and disabled strict SSL checks to ensure it goes through
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
            curl_exec($ch);
            curl_close($ch);

            file_put_contents($logFile, $alertId . PHP_EOL, FILE_APPEND);
            $sentAlerts[] = $alertId;
        }
    }
}

// ── Compute basic stats for the last N candles ───────────────
function computeStats(array $candles, int $n = 20): array {
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
        $rawBars = fetchBars($instrument, 100);

        if (isset($rawBars['error'])) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => $rawBars['error']]) . "\n\n";
        } else {
            $candles = formatBars($rawBars);
            applyStrategy($candles, $symbol); 

            $newCandles = $lastTimestamp > 0
                ? array_values(array_filter($candles, fn($c) => $c['time'] > $lastTimestamp))
                : $candles;

            if (!empty($newCandles)) {
                $lastTimestamp = end($newCandles)['time'];
                $payload = [
                    'type'        => empty($newCandles) ? 'heartbeat' : 'delta',
                    'symbol'      => $symbol,
                    'candles'     => $newCandles,
                    'latest_time' => $lastTimestamp,
                    'server_time' => time(),
                    'stats'       => computeStats($candles),
                ];
                echo "event: candles\n";
                echo "data: " . json_encode($payload) . "\n\n";
            } else {
                echo "event: heartbeat\n";
                echo "data: " . json_encode(['server_time' => time(), 'symbol' => $symbol]) . "\n\n";
            }
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
    $rawBars = fetchBars($instrument, 100);

    if (isset($rawBars['error'])) {
        http_response_code(502);
        echo json_encode($rawBars);
        exit;
    }

    $candles    = formatBars($rawBars);
    applyStrategy($candles, $symbol); 
    
    $newCandles = $since > 0
        ? array_values(array_filter($candles, fn($c) => $c['time'] > $since))
        : $candles;

    echo json_encode([
        'type'        => 'delta',
        'symbol'      => $symbol,
        'candles'     => $newCandles,
        'latest_time' => !empty($candles) ? end($candles)['time'] : 0,
        'server_time' => time(),
        'has_new'     => count($newCandles) > 0,
        'stats'       => computeStats($candles),
    ]);
    exit;
}


//  MODE: full  — Original full load (default)

$rawBars = fetchBars($instrument, $limit);

if (isset($rawBars['error'])) {
    http_response_code(502);
    echo json_encode($rawBars);
    exit;
}

$candles = formatBars($rawBars);
applyStrategy($candles, $symbol);

echo json_encode([
    'type'         => 'full',
    'symbol'       => $symbol,
    'candlesticks' => $candles,
    'startDate'    => $candles[0]['time']        ?? 0,
    'endDate'      => end($candles)['time']       ?? 0,
    'server_time'  => time(),
    'stats'        => computeStats($candles),
]);
?>