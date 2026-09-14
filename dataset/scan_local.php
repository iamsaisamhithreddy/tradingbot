<?php

// =====================================================
// ERROR REPORTING
// =====================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// =====================================================
// SHARED ROOT CONFIGURATION
// =====================================================
require_once dirname(__DIR__) . '/db.php';

// Required shared configuration validation
if (!isset($WebsiteURL) || trim($WebsiteURL) === '') {
    die("ERROR: WebsiteURL is missing from root db.php");
}

$WebsiteURL = rtrim(trim($WebsiteURL), '/');

// =====================================================
// SCRIPT CONFIGURATION
// Prefer values from db.php when available
// =====================================================
$forexPairs = $forexPairs
    ?? $FOREX_PAIRS
    ?? [
        "AUDCAD", "AUDCHF", "AUDJPY", "AUDUSD",
        "CADJPY", "CHFJPY",
        "EURAUD", "EURCAD", "EURCHF", "EURGBP", "EURJPY", "EURUSD",
        "GBPAUD", "GBPCAD", "GBPCHF", "GBPJPY", "GBPUSD",
        "USDCAD", "USDCHF", "USDJPY"
    ];

$limit = $limit
    ?? $CSV_LIMIT
    ?? 20;

$timezoneName = $timezoneName
    ?? $TIMEZONE
    ?? 'Asia/Kolkata';

$scanStartTime = $scanStartTime
    ?? $SCAN_START_TIME
    ?? 1230;

$scanEndTime = $scanEndTime
    ?? $SCAN_END_TIME
    ?? 2130;

$receiverEndpoint = $receiverEndpoint
    ?? $RECEIVER_ENDPOINT
    ?? '/receiver.php';

$priceUpdateEndpoint = $priceUpdateEndpoint
    ?? $PRICE_UPDATE_ENDPOINT
    ?? '/update_price.php';

$memoryFilename = $memoryFilename
    ?? $MEMORY_FILENAME
    ?? 'sent_alerts_memory.txt';

// =====================================================
// MEMORY FILE
// =====================================================
$logFile = __DIR__ . '/' . $memoryFilename;

// =====================================================
// DATASET LOCATIONS
// =====================================================
$csvFolders = [
    __DIR__ . '/../dataset',
    __DIR__ . '/../dataset/dataset',
    __DIR__ . '/../dataset/JUN-2025 TO FEB-2026',

    __DIR__ . '/dataset',
    __DIR__ . '/dataset/dataset',
    __DIR__ . '/dataset/JUN-2025 TO FEB-2026',
];

// =====================================================
// FIND CSV FILE
// =====================================================
function findCSVFile(string $symbol, array $folders): ?string
{
    $symbol = str_replace('/', '', trim($symbol));

    $possibleNames = [
        "FX_{$symbol}.csv",
        "{$symbol}.csv",
    ];

    foreach ($folders as $folder) {
        foreach ($possibleNames as $filename) {
            $filePath = rtrim($folder, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . $filename;

            if (is_file($filePath) && is_readable($filePath)) {
                return $filePath;
            }
        }
    }

    return null;
}

// =====================================================
// READ BARS FROM CSV
//
// Expected CSV format:
// timestamp,open,high,low,close,alert
// =====================================================
function readBarsFromCSV(
    string $symbol,
    array $folders,
    int $limit
): array {

    $filePath = findCSVFile($symbol, $folders);

    if ($filePath === null) {
        echo "CSV missing for {$symbol}\n";
        return [];
    }

    $lines = file(
        $filePath,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if (!$lines || count($lines) <= 1) {
        echo "CSV empty or invalid for {$symbol}\n";
        return [];
    }

    // Remove header
    array_shift($lines);

    // Read only latest candles
    if ($limit > 0 && count($lines) > $limit) {
        $lines = array_slice($lines, -$limit);
    }

    $candles = [];

    foreach ($lines as $line) {
        $data = str_getcsv($line);

        if (count($data) < 6) {
            continue;
        }

        $timestamp = trim($data[0]);

        if (!is_numeric($timestamp)) {
            continue;
        }

        $candles[] = [
            'time'  => (int)$timestamp,
            'open'  => (float)$data[1],
            'high'  => (float)$data[2],
            'low'   => (float)$data[3],
            'close' => (float)$data[4],
            'alert' => (int)$data[5],
        ];
    }

    return $candles;
}

// =====================================================
// SEND ALERTS TO RECEIVER
// =====================================================
function scanAlertsAndFire(
    array $candles,
    string $symbol,
    string $logFile
): void {

    global
        $WebsiteURL,
        $timezoneName,
        $scanStartTime,
        $scanEndTime,
        $receiverEndpoint;

    $cleanSymbol = str_replace('/', '', trim($symbol));

    $sentAlerts = file_exists($logFile)
        ? file(
            $logFile,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        )
        : [];

    // Need at least 5 candles
    for ($i = 4; $i < count($candles); $i++) {

        $candle = $candles[$i];

        // Only tagged alerts
        if (
            $candle['alert'] !== 1 &&
            $candle['alert'] !== -1
        ) {
            continue;
        }

        $alertId = $cleanSymbol . '_' . $candle['time'];

        // =================================================
        // CONVERT UNIX TIMESTAMP TO CONFIGURED TIMEZONE
        // =================================================
        $dt = new DateTime('@' . $candle['time']);
        $dt->setTimezone(new DateTimeZone($timezoneName));

        $timeInt = (int)$dt->format('Hi');

        // Allowed configured time range
        $isTimeValid = (
            $timeInt >= $scanStartTime &&
            $timeInt <= $scanEndTime
        );

        if (!$isTimeValid) {
            continue;
        }

        // Avoid duplicate alerts
        if (in_array($alertId, $sentAlerts, true)) {
            continue;
        }

        // =================================================
        // BUILD 5-CANDLE OHLC PAYLOAD
        // =================================================
        $ohlcPayload = [];

        for ($j = 4; $j >= 0; $j--) {
            $c = $candles[$i - $j];

            $ohlcPayload[] = [
                'O' => $c['open'],
                'H' => $c['high'],
                'L' => $c['low'],
                'C' => $c['close'],
            ];
        }

        $payloadData = [
            'ticker' => $cleanSymbol,
            'ohlc'   => $ohlcPayload,
        ];

        // =================================================
        // FIRE RECEIVER WEBHOOK
        // =================================================
        $receiverUrl = $WebsiteURL . '/' . ltrim(
            $receiverEndpoint,
            '/'
        );

        $jsonPayload = json_encode(
            $payloadData,
            JSON_UNESCAPED_SLASHES
        );

        if ($jsonPayload === false) {
            echo "\nJSON encoding failed for {$cleanSymbol}\n";
            continue;
        }

        $ch = curl_init($receiverUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        // =================================================
        // LOG RESULT
        // =================================================
        echo "\nALERT: {$cleanSymbol}";
        echo " | Time: " . $dt->format('Y-m-d H:i:s');
        echo " {$timezoneName}";
        echo " | Direction: " . $candle['alert'];
        echo " | HTTP: {$httpCode}";

        if ($curlError) {
            echo " | CURL ERROR: {$curlError}";
        } else {
            echo " | Response: " . trim((string)$result);
        }

        echo "\n";

        // Save memory to prevent duplicate sending
        file_put_contents(
            $logFile,
            $alertId . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        $sentAlerts[] = $alertId;
    }
}

// =====================================================
// MAIN LOOP
// =====================================================
echo "["
    . date('Y-m-d H:i:s')
    . "] Starting Local CSV Scan Cycle...\n";

foreach ($forexPairs as $pair) {

    echo "Reading {$pair}... ";

    $candles = readBarsFromCSV(
        $pair,
        $csvFolders,
        $limit
    );

    if (count($candles) === 0) {
        echo "No data.\n";
        continue;
    }

    // =================================================
    // UPDATE LIVE PRICE
    // =================================================
    $lastCandle = end($candles);

    if ($lastCandle) {

        $updateUrl = $WebsiteURL . '/' . ltrim(
            $priceUpdateEndpoint,
            '/'
        );

        $updatePayload = json_encode([
            'pair_name' => $pair,
            'current_price' => $lastCandle['close'],
        ]);

        $chUpdate = curl_init($updateUrl);

        curl_setopt_array($chUpdate, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $updatePayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($updatePayload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $updateResult = curl_exec($chUpdate);
        $updateError = curl_error($chUpdate);
        $updateHttpCode = curl_getinfo(
            $chUpdate,
            CURLINFO_HTTP_CODE
        );

        curl_close($chUpdate);

        if ($updateError) {
            echo "Price update error: {$updateError} ";
        }
    }

    scanAlertsAndFire(
        $candles,
        $pair,
        $logFile
    );

    echo "OK.\n";
}

// =====================================================
// CLEAN MEMORY LOG
// =====================================================
if (file_exists($logFile)) {

    $logs = file(
        $logFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if (count($logs) > 2000) {

        $logs = array_slice($logs, -1000);

        file_put_contents(
            $logFile,
            implode(PHP_EOL, $logs) . PHP_EOL,
            LOCK_EX
        );
    }
}

echo "["
    . date('Y-m-d H:i:s')
    . "] Cycle Complete.\n";

echo "---------------------------------------------------\n";

?>