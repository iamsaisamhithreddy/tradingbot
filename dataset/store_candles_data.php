<?php
/**
 * Daily Candlestick Sync Job (CSV Tagging Only)
 * Fetches the latest 500 candles (M5), updates live prices, 
 * scans for strategy patterns, and tags the CSV "Pattern Alert" column.
 */

// Safely require db.php (adjust path if this script is in a subfolder)
if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_SERVER['HTTP_HOST'])) {
    header('Content-Type: text/plain; charset=UTF-8');
}

$forexPairs = [
    "AUD/CAD", "AUD/CHF", "AUD/JPY", "AUD/USD",
    "CAD/JPY", "CHF/JPY",
    "EUR/AUD", "EUR/CAD", "EUR/CHF", "EUR/GBP", "EUR/JPY", "EUR/USD",
    "GBP/AUD", "GBP/CAD", "GBP/CHF", "GBP/JPY", "GBP/USD",
    "USD/CAD", "USD/CHF", "USD/JPY"
];

$limit = 500; 
$datasetDir = __DIR__ . '/dataset';

echo "[" . date('Y-m-d H:i:s') . "] Starting Sync Cycle (CSV Tagging Only)...\n";
echo "Dataset Directory: " . realpath($datasetDir) . "\n";
echo "---------------------------------------------------\n";

// ── 1. CORE FUNCTIONS ───────────────────────────────────────

function fetchBars(string $instrument, int $limit) {
    $bust   = time() . rand(1000, 9999);
    $apiUrl = "https://mds-api.forexfactory.com/bars?to=0&interval=M5"
            . "&instrument=" . urlencode($instrument)
            . "&per_page={$limit}&extra_fields=&_={$bust}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Origin: https://www.forexfactory.com',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;
    $decoded = json_decode($response, true);
    return $decoded['data'] ?? null;
}

function formatBars(array $rawBars): array {
    $out = [];
    foreach (array_reverse($rawBars) as $bar) {
        $out[] = [
            'time'  => (int)$bar['timestamp'],
            'open'  => (float)$bar['open'],
            'high'  => (float)$bar['high'],
            'low'   => (float)$bar['low'],
            'close' => (float)$bar['close'],
            'alert' => 0 // Initialize alert column as 0
        ];
    }
    return $out;
}

function getLastCsvTimestamp(string $filePath): int {
    if (!file_exists($filePath)) return 0;
    
    $f = fopen($filePath, 'r');
    if (!$f) return 0;
    
    $cursor = -1;
    if (fseek($f, $cursor, SEEK_END) !== 0) {
        fclose($f); return 0;
    }
    
    $char = fgetc($f);
    while (($char === "\n" || $char === "\r" || $char === " ") && $cursor > -100) {
        fseek($f, --$cursor, SEEK_END);
        $char = fgetc($f);
    }
    
    $line = '';
    while ($char !== false && $char !== "\n" && $char !== "\r") {
        $line = $char . $line;
        if (fseek($f, --$cursor, SEEK_END) !== 0) break;
        $char = fgetc($f);
    }
    fclose($f);
    
    $data = str_getcsv($line);
    return (isset($data[0]) && is_numeric($data[0])) ? (int)$data[0] : 0;
}

// Memory-only scanner: Directly mutates the $candles array by reference
function tagStrategyAlerts(array &$candles) 
{
    $streak = 0; $streakBullish = null; $streakOpen = null;
    $oppositeCount = 0; $waitingForConfirmation = false;
    $waitingForNextClose = false;

    for ($i = 0; $i < count($candles); $i++) 
    {
        $candle = $candles[$i];
        $isGreen = $candle['close'] > $candle['open'];
        $thisBullish = $isGreen;

        if ($waitingForNextClose) {
            // TAG THE CANDLE IN MEMORY
            $candles[$i]['alert'] = 1;
            
            $waitingForNextClose = false;
            $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
            $waitingForConfirmation = false; $oppositeCount = 0;
            continue; 
        }

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
                $triggerAlert = false;
                if ($oppositeCount === 2) {
                    if (($streakBullish && $candle['low'] <= $streakOpen) || (!$streakBullish && $candle['high'] >= $streakOpen)) {
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    } else {
                        $triggerAlert = true;
                    }
                } elseif ($oppositeCount === 1) {
                    $triggerAlert = true;
                }

                if ($triggerAlert) {
                    $dt = new DateTime('@' . $candle['time']);
                    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $timeInt = (int)$dt->format('Hi'); 
                    $isTimeValid = ($timeInt >= 1230 && $timeInt <= 2130);

                    if ($isTimeValid && $i >= 4) {
                        $waitingForNextClose = true; 
                    } else {
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    }
                }
            }
        }
    }
}

// Ensure the dataset directory exists
if (!is_dir($datasetDir)) {
    mkdir($datasetDir, 0755, true);
}

$summary = [];

// ── 2. MAIN LOOP ──────────────────────────────────────────

foreach ($forexPairs as $pair) {
    $cleanSymbol = str_replace('/', '', $pair);
    $csvFilePath = $datasetDir . '/FX_' . $cleanSymbol . '.csv';
    
    echo "Processing {$pair}... ";
    
    // 1. Fetch recent bars
    $rawBars = fetchBars($pair, $limit);
    if (!$rawBars) {
        echo "❌ Failed to fetch candles.\n";
        $summary[$pair] = "Failed to fetch";
        continue;
    }
    
    $candles = formatBars($rawBars);
    if (empty($candles)) {
        echo "⚠️ Empty dataset.\n";
        $summary[$pair] = "No data returned";
        continue;
    }

    // 2. LIVE PRICE UPDATE (Kept active for frontend terminal)
    $lastCandle = end($candles);
    if ($lastCandle && isset($WebsiteURL)) {
        $updateUrl = rtrim($WebsiteURL, '/') . '/update_price.php';
        $payload = json_encode([
            'pair_name' => $cleanSymbol, 
            'current_price' => $lastCandle['close']
        ]);
        
        $chUpdate = curl_init($updateUrl);
        curl_setopt($chUpdate, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($chUpdate, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($chUpdate, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($chUpdate, CURLOPT_TIMEOUT, 1); 
        curl_exec($chUpdate);
        curl_close($chUpdate);
    }

    // 3. SCAN STRATEGY (Tags array in memory, no webhooks fired)
    tagStrategyAlerts($candles);

    // 4. PREPARE CSV WRITE
    $lastTimestamp = getLastCsvTimestamp($csvFilePath);
    $newCandles = [];
    $alertFoundCount = 0;

    foreach ($candles as $c) {
        if ($c['time'] > $lastTimestamp) {
            $newCandles[] = $c;
            if ($c['alert'] === 1) $alertFoundCount++;
        }
    }
    
    $newCount = count($newCandles);
    if ($newCount === 0) {
        echo "✅ Up to date.\n";
        $summary[$pair] = "0 new";
        continue;
    }
    
    // Append new candles to CSV
    $fileMode = file_exists($csvFilePath) ? 'a' : 'w';
    $f = fopen($csvFilePath, $fileMode);
    if (!$f) {
        echo "❌ Write error.\n";
        $summary[$pair] = "Write error";
        continue;
    }
    
    if ($fileMode === 'w') {
        fputcsv($f, ['time', 'open', 'high', 'low', 'close', 'Pattern Alert', 'Volume']);
    }
    
    foreach ($newCandles as $c) {
        fputcsv($f, [
            $c['time'],
            $c['open'],
            $c['high'],
            $c['low'],
            $c['close'],
            $c['alert'], // <--- Writes 1 if pattern found, 0 if not
            0        
        ]);
    }
    fclose($f);
    
    $alertText = $alertFoundCount > 0 ? " (⚠️ {$alertFoundCount} ALERTS TAGGED)" : "";
    echo "🎉 Saved {$newCount} new candles{$alertText}.\n";
    $summary[$pair] = "Appended {$newCount} candles{$alertText}";
    
    usleep(500000); // 0.5s sleep to avoid hammering APIs
}

echo "---------------------------------------------------\n";
echo "📊 SYNC SUMMARY:\n";
foreach ($summary as $pair => $status) {
    echo " - {$pair}: {$status}\n";
}
echo "[" . date('Y-m-d H:i:s') . "] Cycle Complete.\n";
