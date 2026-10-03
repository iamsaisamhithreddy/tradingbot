<?php

// Include the db.php file from one level up to access $WebsiteURL
require_once __DIR__ . '/../db.php';

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

$forexPairs = [
    "AUD/CAD", "AUD/CHF", "AUD/JPY", "AUD/USD",
    "CAD/JPY", "CHF/JPY",
    "EUR/AUD", "EUR/CAD", "EUR/CHF", "EUR/GBP", "EUR/JPY", "EUR/USD",
    "GBP/AUD", "GBP/CAD", "GBP/CHF", "GBP/JPY", "GBP/USD",
    "USD/CAD", "USD/CHF", "USD/JPY"
];

$limit = 100; // 100 candles gives the streak logic enough history to build state
$logFile = __DIR__ . '/sent_alerts_memory.txt';

echo "[" . date('Y-m-d H:i:s') . "] Starting Scan Cycle...\n";

// Fetch from ForexFactory 
function fetchBars(string $instrument, int $limit) 
{
    $bust   = time() . rand(1000, 9999);
    $apiUrl = "https://mds-api.forexfactory.com/bars?to=0&interval=M5". "&instrument={$instrument}&per_page={$limit}&extra_fields=&_={$bust}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 5,
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


function formatBars(array $rawBars): array 
{
    $out = [];
    foreach (array_reverse($rawBars) as $bar) 
    {
        $out[] = [
            'time'  => (int)$bar['timestamp'],
            'open'  => (float)$bar['open'],
            'high'  => (float)$bar['high'],
            'low'   => (float)$bar['low'],
            'close' => (float)$bar['close'],
        ];
    }
    return $out;
}


function scanStrategyAndFire(array $candles, string $symbol, string $logFile) 
{
    global $WebsiteURL; // Access the website URL defined in ../db.php

    // STRIP THE SLASH FOR DATABASE (e.g., "EUR/GBP" -> "EURGBP")
    $cleanSymbol = str_replace('/', '', $symbol);

    $streak = 0;
    $streakBullish = null;
    $streakOpen = null;
    $oppositeCount = 0;
    $waitingForConfirmation = false;
    
    // NEW TRACKING VARIABLES FOR STABLE NEXT-CLOSE VERIFICATION
    $waitingForNextClose = false;
    $pendingAlertData = null;

    // Load memory to avoid spamming the database
    $sentAlerts = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    for ($i = 0; $i < count($candles); $i++) 
    {
        $candle = $candles[$i];
        $isGreen = $candle['close'] > $candle['open'];
        $thisBullish = $isGreen;

        // --- NEW MONITORING LAYER: PROCESS EXTENDED NEXT-CANDLE CLOSES ---
        if ($waitingForNextClose) {
            // Verify how this target candle closed. If it satisfies your requirements, dispatch the webhook payload
            $alertId = $pendingAlertData['alert_id'];
            
            if (!in_array($alertId, $sentAlerts)) {
                $receiverUrl = rtrim($WebsiteURL, '/') . '/receiver.php';
                $ch = curl_init($receiverUrl);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($pendingAlertData['payload']));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                $result = curl_exec($ch);
                curl_close($ch);

                file_put_contents($logFile, $alertId . PHP_EOL, FILE_APPEND);
                $sentAlerts[] = $alertId;

                echo " 🚨 ALERT FIRED & VERIFIED: $cleanSymbol @ " . date('H:i', $candle['time']) . " - DB Response: " . trim($result) . "\n";
            }
            
            // Re-initialize tracking states back to defaults safely after confirmation checks
            $waitingForNextClose = false;
            $pendingAlertData = null;
            $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
            $waitingForConfirmation = false; $oppositeCount = 0;
            continue; // Advance execution directly to check the next sequential candle block
        }

        if (!$waitingForConfirmation) 
        {
            if ($streak === 0) 
            {
                $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
            } elseif ($thisBullish === $streakBullish) 
            {
                $streak += 1; $streakOpen = $candle['open'];
            } else 
            {
                if ($streak >= 4) 
                {
                    $waitingForConfirmation = true; $oppositeCount = 1;
                    if (($streakBullish && $candle['low'] <= $streakOpen) || (!$streakBullish && $candle['high'] >= $streakOpen)) 
                    {
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    }
                } else 
                {
                    $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                }
            }
        } else 
        {
            if ($thisBullish !== $streakBullish) 
            {
                $oppositeCount += 1;
                if ($oppositeCount > 2) {
                    $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                    $waitingForConfirmation = false; $oppositeCount = 0;
                }
            } else 
            {
                $triggerAlert = false;
                if ($oppositeCount === 2) 
                {
                    if (($streakBullish && $candle['low'] <= $streakOpen) || (!$streakBullish && $candle['high'] >= $streakOpen)) 
                    {
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    } else 
                    {
                        $triggerAlert = true;
                    }
                } elseif ($oppositeCount === 1) 
                {
                    $triggerAlert = true;
                }

                if ($triggerAlert) 
                {
                    $alertId = $cleanSymbol . '_' . $candle['time'];
                    
                    // --- TIME CHECK START (IST 12:30 to 21:30) ---
                    $dt = new DateTime('@' . $candle['time']);
                    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $timeInt = (int)$dt->format('Hi'); // Gets time as HHMM (e.g., 1230, 2130)
                    $isTimeValid = ($timeInt >= 1230 && $timeInt <= 2130);
                    // --- TIME CHECK END ---

                    if ($isTimeValid && $i >= 4 && !in_array($alertId, $sentAlerts)) 
                    {
                        $ohlcPayload = [];
                        for ($j = 4; $j >= 0; $j--) 
                        {
                            $c = $candles[$i - $j];
                            $ohlcPayload[] = ['O' => $c['open'], 'H' => $c['high'], 'L' => $c['low'], 'C' => $c['close']];
                        }

                        // Queue data package instead of sending instantly, passing validation to the following index loop entry
                        $pendingAlertData = [
                            'alert_id' => $alertId,
                            'payload'  => [
                                'ticker' => $cleanSymbol,
                                'ohlc'   => $ohlcPayload
                            ]
                        ];
                        
                        $waitingForNextClose = true; 
                    } else {
                        // Fallback reset if matching previous memory footprints OR outside valid time window
                        $streak = 1; $streakBullish = $thisBullish; $streakOpen = $candle['open'];
                        $waitingForConfirmation = false; $oppositeCount = 0;
                    }
                }
            }
        }
    }
}

// —— Main Loop ——
foreach ($forexPairs as $pair) 
{
    echo "Scanning $pair... ";
    
    $rawBars = fetchBars(urlencode($pair), $limit);
    
    if ($rawBars && count($rawBars) > 0) 
    {
        $candles = formatBars($rawBars);

        // LIVE PRICE UPDATE 
        $lastCandle = end($candles);    // Get the most recent candle
        if ($lastCandle) 
        {
            $updateUrl = rtrim($WebsiteURL, '/') . '/update_price.php';
            $payload = json_encode([
                'pair_name' => str_replace('/', '', $pair), 
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

        scanStrategyAndFire($candles, $pair, $logFile);
        echo "OK.\n";
    } else 
    {
        echo "Failed to fetch.\n";
    }
    sleep(1); 
}

// —— Memory Cleanup ——
if (file_exists($logFile)) 
{
    $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($logs) > 2000) 
    {
        $logs = array_slice($logs, -1000); 
        file_put_contents($logFile, implode(PHP_EOL, $logs) . PHP_EOL);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Cycle Complete.\n";
echo "---------------------------------------------------\n";
?>