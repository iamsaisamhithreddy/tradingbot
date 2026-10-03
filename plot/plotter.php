<?php
/**
 * Advanced Trade Plotter
 * ---------------------------------------------------------
 * Correct dataset routing:
 *
 * BEFORE JUN 2025:
 * /dataset/BEFORE-JUN-2025/USDJPY/FX_USDJPY-2025-05-30.csv
 *
 * JUN 2025 TO FEB 2026:
 * /dataset/JUN-2025 TO FEB-2026/FX_USDJPY.csv
 *
 * MARCH 2026 ONWARD:
 * /dataset/dataset/USDJPY/FX_USDJPY-2026-09-10.csv
 *
 * All CSV timestamps are Unix UTC.
 * Database last_alert_time is UTC.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

set_time_limit(120);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$isDebug = isset($_GET['debug_trade']);

if ($isAjax) {
    ini_set('display_errors', 0);
    error_reporting(0);
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| TIMEZONES
|--------------------------------------------------------------------------
*/

$utcTimezone = new DateTimeZone('UTC');
$istTimezone = new DateTimeZone('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| DATABASE LOADER
|--------------------------------------------------------------------------
*/

$dbPath = '';

$possibleDbPaths = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
];

foreach ($possibleDbPaths as $candidate) {
    if (file_exists($candidate)) {
        $dbPath = $candidate;
        break;
    }
}

if (!$dbPath) {
    $dir = __DIR__;

    for ($i = 0; $i < 5; $i++) {
        $dir = dirname($dir);

        if (file_exists($dir . '/db.php')) {
            $dbPath = $dir . '/db.php';
            break;
        }
    }
}

if (!$dbPath) {
    if ($isAjax || $isDebug) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'db.php not found',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    die('Database configuration file db.php not found.');
}

require_once $dbPath;

if (!isset($conn) || !($conn instanceof mysqli)) {
    if ($isAjax || $isDebug) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Database connection object not available',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    die('Database connection failed.');
}

if ($conn->connect_error) {
    if ($isAjax || $isDebug) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $conn->connect_error,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    die('Database connection failed: ' . $conn->connect_error);
}

/*
|--------------------------------------------------------------------------
| HELPER: Parse database UTC datetime
|--------------------------------------------------------------------------
*/

function parseUtcTimestamp($value): int
{
    global $utcTimezone;

    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    if ($value === '0000-00-00 00:00:00') {
        return 0;
    }

    /*
     * If already Unix timestamp.
     */
    if (ctype_digit($value)) {
        $number = (int)$value;

        if (strlen($value) >= 13) {
            return (int)floor($number / 1000);
        }

        if (strlen($value) >= 9) {
            return $number;
        }
    }

    try {
        $dt = new DateTime($value, $utcTimezone);
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        $ts = strtotime($value . ' UTC');
        return $ts !== false ? (int)$ts : 0;
    }
}
/**
 * Parse last_alert_time correctly based on historical/live storage format.
 *
 * Historical records before 2025-06-01:
 *     last_alert_time is stored as IST
 *
 * Live records from 2025-06-01 onward:
 *     last_alert_time is stored as UTC
 */
function parseAlertTimestamp(string $value): int
{
    global $istTimezone, $utcTimezone;

    $value = trim($value);

    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return 0;
    }

    // Preserve support for Unix timestamps if ever encountered.
    if (ctype_digit($value)) {
        $number = (int)$value;

        if (strlen($value) >= 13) {
            return (int)floor($number / 1000);
        }

        if (strlen($value) >= 9) {
            return $number;
        }
    }

    // Historical records: stored as IST
    $datePrefix = substr($value, 0, 10);

    if ($datePrefix < '2025-06-01') {
        try {
            return (new DateTime($value, $istTimezone))->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // Live records: stored as UTC
    try {
        return (new DateTime($value, $utcTimezone))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}
/*
|--------------------------------------------------------------------------
| HELPER: Parse CSV Unix timestamp
|--------------------------------------------------------------------------
|
| CSV timestamps are Unix UTC.
|--------------------------------------------------------------------------
*/


/*
 * Parse outcome time safely. Historical outcome rows are not always
 * consistent: some were stored as IST DATETIME while older/manual rows
 * may contain UTC DATETIME. Never allow a resolution timestamp to appear
 * before the alert timestamp. Prefer the interpretation that is chronologically
 * valid relative to the authoritative alert timestamp.
 */
function parseOutcomeTimestamp($value, int $alertTimestamp = 0): ?int
{
    global $istTimezone, $utcTimezone;

    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return null;

    $candidates = [];
    try { $candidates['ist'] = (new DateTime($value, $istTimezone))->getTimestamp(); } catch (Throwable $e) {}
    try { $candidates['utc'] = (new DateTime($value, $utcTimezone))->getTimestamp(); } catch (Throwable $e) {}
    if (!$candidates) return null;

    if ($alertTimestamp > 0) {
        $valid = array_filter($candidates, static function($ts) use ($alertTimestamp) {
            return $ts >= $alertTimestamp;
        });
        if ($valid) {
            // Choose the earliest valid interpretation after the alert.
            return min($valid);
        }
    }

    // Backward-compatible default for ordinary outcome rows: IST.
    return $candidates['ist'] ?? reset($candidates);
}

function parseCsvTimestamp($value): int
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    /*
     * Unix milliseconds.
     */
    if (ctype_digit($value)) {
        $number = (int)$value;

        if (strlen($value) >= 13) {
            return (int)floor($number / 1000);
        }

        if (strlen($value) >= 9) {
            return $number;
        }
    }

    /*
     * Fallback for textual timestamps.
     * Explicitly treat as UTC.
     */
    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        $ts = strtotime($value . ' UTC');
        return $ts !== false ? (int)$ts : 0;
    }
}

/*
|--------------------------------------------------------------------------
| DATASET ROOT DISCOVERY
|--------------------------------------------------------------------------
*/

function getDatasetRoot(): string
{
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

    $candidates = [
        __DIR__ . '/dataset',
        __DIR__ . '/../dataset',
        __DIR__ . '/../../dataset',
        $documentRoot . '/dataset',
        '/home/sairedd1/public_html/dataset',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate && is_dir($candidate)) {
            return rtrim($candidate, '/');
        }
    }

    /*
     * Search parent directories.
     */
    $dir = __DIR__;

    for ($i = 0; $i < 6; $i++) {
        $candidate = $dir . '/dataset';

        if (is_dir($candidate)) {
            return rtrim($candidate, '/');
        }

        $dir = dirname($dir);
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| PAIR NORMALIZATION
|--------------------------------------------------------------------------
*/

function normalizePair(string $pair): string
{
    return strtoupper(
        str_replace(
            ['/', '_', ' ', '-'],
            '',
            trim($pair)
        )
    );
}

/*
|--------------------------------------------------------------------------
| GET ALL CSV FILES REQUIRED FOR CHART WINDOW
|--------------------------------------------------------------------------
|
| Important:
| Historical data is daily:
|
| BEFORE-JUN-2025/USDJPY/FX_USDJPY-2025-05-30.csv
|
| Therefore we must load every date touched by the chart window.
|--------------------------------------------------------------------------
*/

function getCsvFilesForWindow(
    string $pairName,
    int $windowStart,
    int $windowEnd
): array {
    $datasetRoot = getDatasetRoot();

    if (!$datasetRoot) {
        error_log('[PLOTTER] Dataset root not found.');
        return [];
    }

    $pair = normalizePair($pairName);

    $flatFileName = 'FX_' . $pair . '.csv';

    $files = [];

    /*
     * Dataset date range classification based on UTC date.
     */
    $startDate = gmdate('Y-m-d', $windowStart);
    $endDate   = gmdate('Y-m-d', $windowEnd);

    $cutoffJune = '2025-06-01';
    $cutoffMarch2026 = '2026-03-01';

    /*
     * ---------------------------------------------------------
     * BEFORE JUN 2025
     * ---------------------------------------------------------
     *
     * Each pair has a directory and each day has its own CSV.
     */
    if ($startDate < $cutoffJune) {
        $cursor = new DateTime(
            max($startDate, '2000-01-01') . ' 00:00:00',
            new DateTimeZone('UTC')
        );

        $last = new DateTime(
            min($endDate, '2025-05-31') . ' 00:00:00',
            new DateTimeZone('UTC')
        );

        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');

            $historicalCandidates = [
                $datasetRoot .
                    '/BEFORE-JUN-2025/' .
                    $pair .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',

                $datasetRoot .
                    '/BEFORE-JUN-2025/' .
                    strtolower($pair) .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',

                $datasetRoot .
                    '/BEFORE-JUN-2025/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',

                __DIR__ .
                    '/dataset/BEFORE-JUN-2025/' .
                    $pair .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',
            ];

            foreach ($historicalCandidates as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    $files[] = $candidate;
                    break;
                }
            }

            $cursor->modify('+1 day');
        }
    }

    /*
     * ---------------------------------------------------------
     * JUNE 2025 TO FEBRUARY 2026
     * ---------------------------------------------------------
     *
     * One consolidated file per pair.
     */
    if ($startDate <= '2026-02-28' && $endDate >= '2025-06-01') {
        $midCandidates = [
            $datasetRoot . '/JUN-2025 TO FEB-2026/' . $flatFileName,
            __DIR__ . '/dataset/JUN-2025 TO FEB-2026/' . $flatFileName,
            __DIR__ . '/../dataset/JUN-2025 TO FEB-2026/' . $flatFileName,
            __DIR__ . '/JUN-2025 TO FEB-2026/' . $flatFileName,
        ];

        foreach ($midCandidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $files[] = $candidate;
                break;
            }
        }
    }

    /*
     * ---------------------------------------------------------
     * MARCH 2026 ONWARD
     * ---------------------------------------------------------
     *
     * Current data is date-partitioned.
     */
    if ($endDate >= $cutoffMarch2026) {
        $cursorStart = max($startDate, $cutoffMarch2026);

        $cursor = new DateTime(
            $cursorStart . ' 00:00:00',
            new DateTimeZone('UTC')
        );

        $last = new DateTime(
            $endDate . ' 00:00:00',
            new DateTimeZone('UTC')
        );

        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');

            $currentCandidates = [
                $datasetRoot .
                    '/dataset/' .
                    $pair .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',

                $datasetRoot .
                    '/' .
                    $pair .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',

                __DIR__ .
                    '/dataset/dataset/' .
                    $pair .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',

                __DIR__ .
                    '/../dataset/dataset/' .
                    $pair .
                    '/FX_' .
                    $pair .
                    '-' .
                    $date .
                    '.csv',
            ];

            foreach ($currentCandidates as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    $files[] = $candidate;
                    break;
                }
            }

            $cursor->modify('+1 day');
        }

        /*
         * Flat current file fallback.
         */
        $flatCandidates = [
            $datasetRoot . '/dataset/' . $flatFileName,
            $datasetRoot . '/' . $flatFileName,
            __DIR__ . '/dataset/dataset/' . $flatFileName,
            __DIR__ . '/../dataset/dataset/' . $flatFileName,
        ];

        foreach ($flatCandidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $files[] = $candidate;
                break;
            }
        }
    }

    /*
     * Remove duplicates.
     */
    $files = array_values(array_unique($files));

    /*
     * Debug logging.
     */
    error_log(
        '[PLOTTER] CSV FILES FOR ' .
        $pair .
        ' | UTC window ' .
        gmdate('Y-m-d H:i:s', $windowStart) .
        ' -> ' .
        gmdate('Y-m-d H:i:s', $windowEnd) .
        ' | files=' .
        json_encode($files)
    );

    return $files;
}

/*
|--------------------------------------------------------------------------
| FETCH TRADE
|--------------------------------------------------------------------------
*/

function fetchTrade(int $tradeId): ?array
{
    global $conn;

    $tradeId = (int)$tradeId;

    if ($tradeId <= 0) {
        return null;
    }

    /*
     * IMPORTANT:
     * last_alert_time is authoritative.
     *
     * Do NOT use raw_trade_data.created_at because historical
     * imported trades may have current insertion timestamps.
     */
    $sql = "
        SELECT
            p.*,
            o.trade_result,
            o.win_loss_time,
            o.win_loss_price
        FROM prediction_trade_data p
        LEFT JOIN trade_outcome_details o
            ON p.raw_trade_id = o.raw_trade_id
        WHERE p.raw_trade_id = {$tradeId}
        LIMIT 1
    ";

    $result = $conn->query($sql);

    if (!$result) {
        error_log('[PLOTTER] SQL error: ' . $conn->error);
        return null;
    }

    $trade = $result->fetch_assoc();

    return $trade ?: null;
}

/*
|--------------------------------------------------------------------------
| READ CANDLE DATA FROM MULTIPLE CSV FILES
|--------------------------------------------------------------------------
*/

function readCandlesFromFiles(
    array $csvFiles,
    int $windowStart,
    int $windowEnd
): array {
    global $istTimezone;

    $candles = [];
    $patternAlerts = [];

    foreach ($csvFiles as $csvPath) {
        if (!is_readable($csvPath)) {
            continue;
        }

        $handle = fopen($csvPath, 'r');

        if (!$handle) {
            continue;
        }

        $firstRow = true;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            if ($firstRow) {
                $firstRow = false;

                /*
                 * Skip normal header row.
                 */
                if (
                    isset($row[0]) &&
                    (
                        strtolower(trim($row[0])) === 'time' ||
                        strtolower(trim($row[0])) === 'timestamp'
                    )
                ) {
                    continue;
                }
            }

            /*
             * Historical CSVs may contain 5, 6, or 7 columns.
             */
            if (count($row) < 5) {
                continue;
            }

            $time = parseCsvTimestamp($row[0]);

            if ($time <= 0) {
                continue;
            }

            if ($time < $windowStart || $time > $windowEnd) {
                continue;
            }

            $open  = (float)$row[1];
            $high  = (float)$row[2];
            $low   = (float)$row[3];
            $close = (float)$row[4];

            $pattern = isset($row[5])
                ? trim((string)$row[5])
                : '';

            $volume = isset($row[6])
                ? (float)$row[6]
                : 0.0;

            /*
             * Use timestamp as unique key to avoid duplicates
             * when flat and dated files overlap.
             */
            $candles[$time] = [
                'time'  => $time,
                'open'  => $open,
                'high'  => $high,
                'low'   => $low,
                'close' => $close,
                'volume' => $volume,
                'pattern' => $pattern,
            ];
        }

        fclose($handle);
    }

    /*
     * Sort chronologically.
     */
    ksort($candles);

    $candles = array_values($candles);

    /*
     * Generate new-day markers using IST display day.
     */
    $lastDay = null;

    foreach ($candles as $candle) {
        $dt = new DateTime('@' . $candle['time']);
        $dt->setTimezone($istTimezone);

        $currentDay = $dt->format('Y-m-d');

        if ($lastDay !== null && $currentDay !== $lastDay) {
            $patternAlerts[] = [
                'time' => $candle['time'],
                'type' => 'new_day',
                'text' => 'NEW DAY',
            ];
        }

        $lastDay = $currentDay;

        if (
            $candle['pattern'] !== '' &&
            $candle['pattern'] !== '0'
        ) {
            $patternAlerts[] = [
                'time' => $candle['time'],
                'type' => 'custom',
                'text' => $candle['pattern'],
            ];
        }
    }

    return [
        'candles' => $candles,
        'patternAlerts' => $patternAlerts,
    ];
}

/*
|--------------------------------------------------------------------------
| GENERATE GD CHART
|--------------------------------------------------------------------------
*/

function generateTradeChartGD(
    int $tradeId,
    ?string $savePath = null
): bool {
    global $istTimezone;

    $trade = fetchTrade($tradeId);

    if (!$trade) {
        error_log('[PLOTTER] Trade not found: ' . $tradeId);
        return false;
    }

    $pair = $trade['pair_name'] ?? '';
    $alertRaw = $trade['last_alert_time'] ?? '';

    if (!$pair || !$alertRaw) {
        error_log('[PLOTTER] Missing pair or last_alert_time for trade ' . $tradeId);
        return false;
    }

    /*
     * THIS IS THE IMPORTANT FIX.
     *
     * Use last_alert_time as UTC.
     * Never use raw_trade_data.created_at.
     */
/*
 * last_alert_time storage depends on historical/live record date.
 * Historical (<2025-06-01) = IST
 * Live (>=2025-06-01) = UTC
 */
$alertTimestamp = parseAlertTimestamp($alertRaw);

    if ($alertTimestamp <= 0) {
        error_log(
            '[PLOTTER] Invalid alert timestamp for trade ' .
            $tradeId .
            ': ' .
            $alertRaw
        );

        return false;
    }

    /*
     * Chart window:
     * 4 hours before alert
     * 8 hours after alert
     */
    $windowStart = $alertTimestamp - (4 * 60 * 60);
    $windowEnd   = $alertTimestamp + (8 * 60 * 60);

    /*
     * Load one or multiple CSV files.
     */
    $csvFiles = getCsvFilesForWindow(
        $pair,
        $windowStart,
        $windowEnd
    );

    if (empty($csvFiles)) {
        error_log(
            '[PLOTTER] No CSV files found for trade ' .
            $tradeId .
            ' pair=' .
            $pair .
            ' alertUTC=' .
            gmdate('Y-m-d H:i:s', $alertTimestamp)
        );

        return false;
    }

    $data = readCandlesFromFiles(
        $csvFiles,
        $windowStart,
        $windowEnd
    );

    $candles = $data['candles'];
    $patternAlerts = $data['patternAlerts'];

    /*
     * Add alert marker.
     */
    $patternAlerts[] = [
        'time' => $alertTimestamp,
        'type' => 'alert_entry',
        'text' => 'ALERT ENTRY',
    ];

    /*
     * Add outcome marker.
     *
     * Existing win_loss_time in your database is treated as IST
     * because your outcome importer stores it as IST.
     */
    $winLossTimestamp = parseOutcomeTimestamp(
        $trade['win_loss_time'] ?? '',
        $alertTimestamp
    );

    if ($winLossTimestamp !== null) {
        $outcome = strtolower(
            trim((string)($trade['trade_result'] ?? ''))
        );

        if ($outcome === 'win') {
            $patternAlerts[] = [
                'time' => $winLossTimestamp,
                'type' => 'win_outcome',
                'text' => 'WIN OUTCOME',
            ];
        } elseif ($outcome === 'loss') {
            $patternAlerts[] = [
                'time' => $winLossTimestamp,
                'type' => 'loss_outcome',
                'text' => 'LOSS OUTCOME',
            ];
        }
    }

    usort($patternAlerts, function ($a, $b) {
        return $a['time'] <=> $b['time'];
    });

    /*
     * Image setup.
     */
    $imgWidth = 1200;
    $imgHeight = 670;

    if (!function_exists('imagecreatetruecolor')) {
        error_log('[PLOTTER] GD extension is not enabled.');
        return false;
    }

    $im = imagecreatetruecolor($imgWidth, $imgHeight);

    $bgColor = imagecolorallocate($im, 11, 15, 25);
    $panelColor = imagecolorallocate($im, 11, 18, 31);
    $borderColor = imagecolorallocate($im, 51, 65, 85);
    $gridColor = imagecolorallocate($im, 30, 41, 59);
    $textColor = imagecolorallocate($im, 148, 163, 184);
    $whiteColor = imagecolorallocate($im, 248, 250, 252);
    $greenColor = imagecolorallocate($im, 16, 185, 129);
    $redColor = imagecolorallocate($im, 239, 68, 68);
    $yellowColor = imagecolorallocate($im, 251, 191, 36);
    $blueColor = imagecolorallocate($im, 59, 130, 246);

    imagefill($im, 0, 0, $bgColor);

    $chartX1 = 15;
    $chartY1 = 15;
    $chartX2 = 1185;
    $chartY2 = 655;

    imagefilledrectangle(
        $im,
        $chartX1,
        $chartY1,
        $chartX2,
        $chartY2,
        $panelColor
    );

    imagerectangle(
        $im,
        $chartX1,
        $chartY1,
        $chartX2,
        $chartY2,
        $borderColor
    );

    $plotX1 = $chartX1 + 10;
    $plotY1 = $chartY1 + 15;
    $plotX2 = $chartX2 - 70;
    $plotY2 = $chartY2 - 30;

    $plotWidth = $plotX2 - $plotX1;
    $plotHeight = $plotY2 - $plotY1;

    /*
     * No candle case.
     */
    if (empty($candles)) {
        imagestring(
            $im,
            4,
            $chartX1 + 100,
            $chartY1 + 100,
            'No candlestick data available',
            $textColor
        );

        imagestring(
            $im,
            2,
            $chartX1 + 100,
            $chartY1 + 130,
            'Pair: ' . $pair,
            $textColor
        );

        imagestring(
            $im,
            2,
            $chartX1 + 100,
            $chartY1 + 150,
            'Alert UTC: ' . gmdate('Y-m-d H:i:s', $alertTimestamp),
            $textColor
        );

        if ($savePath) {
            imagepng($im, $savePath);
        } else {
            header('Content-Type: image/png');
            imagepng($im);
        }

        imagedestroy($im);
        return true;
    }

    /*
     * Price range.
     */
    $prices = [];

    foreach ($candles as $candle) {
        $prices[] = $candle['high'];
        $prices[] = $candle['low'];
    }

    $targetPrice = (float)($trade['price_target'] ?? 0);

    if ($targetPrice > 0) {
        $prices[] = $targetPrice;
    }

    if (
        isset($trade['win_loss_price']) &&
        $trade['win_loss_price'] !== null &&
        $trade['win_loss_price'] !== ''
    ) {
        $prices[] = (float)$trade['win_loss_price'];
    }

    $maxPrice = max($prices);
    $minPrice = min($prices);

    $priceRange = $maxPrice - $minPrice;

    if ($priceRange <= 0) {
        $priceRange = 0.0001;
    }

    $maxPrice += $priceRange * 0.10;
    $minPrice -= $priceRange * 0.10;

    $priceRange = $maxPrice - $minPrice;

    $priceToY = function ($price) use (
        $plotY1,
        $plotY2,
        $plotHeight,
        $minPrice,
        $priceRange
    ) {
        return $plotY2 -
            (($price - $minPrice) / $priceRange) *
            $plotHeight;
    };

    /*
     * Horizontal grid.
     */
    $gridCount = 5;

    for ($i = 0; $i <= $gridCount; $i++) {
        $gridPrice = $minPrice +
            ($priceRange / $gridCount) * $i;

        $gridY = $priceToY($gridPrice);

        imageline(
            $im,
            $plotX1,
            $gridY,
            $plotX2,
            $gridY,
            $gridColor
        );

        imagestring(
            $im,
            2,
            $plotX2 + 5,
            $gridY - 6,
            number_format($gridPrice, 5),
            $textColor
        );
    }

    $numCandles = count($candles);
    $candleWidth = $plotWidth / max(1, $numCandles);

    /*
     * Vertical time grid and IST labels.
     */
    $labelInterval = max(1, (int)floor($numCandles / 8));

    for ($i = 0; $i < $numCandles; $i++) {
        $candle = $candles[$i];

        $x = $plotX1 +
            $i * $candleWidth +
            $candleWidth / 2;

        $dt = new DateTime('@' . $candle['time']);
        $dt->setTimezone($istTimezone);

        if (
            $dt->format('i') === '00' ||
            $i % $labelInterval === 0
        ) {
            imageline(
                $im,
                $x,
                $plotY1,
                $x,
                $plotY2,
                $gridColor
            );

            imagestring(
                $im,
                2,
                $x - 18,
                $plotY2 + 5,
                $dt->format('H:i'),
                $textColor
            );
        }
    }

    /*
     * Find alert candle and start target line 3 candles before.
     */
    $alertStartX = $plotX1;

    for ($i = 0; $i < $numCandles; $i++) {
        $candleTime = $candles[$i]['time'];

        if (
            $alertTimestamp >= $candleTime &&
            $alertTimestamp < ($candleTime + 300)
        ) {
            $startIndex = max(0, $i - 3);

            $alertStartX = $plotX1 +
                $startIndex * $candleWidth +
                $candleWidth / 2;

            break;
        }
    }

    /*
     * Target line.
     */
    if ($targetPrice > 0) {
        $targetY = $priceToY($targetPrice);

        for ($x = $alertStartX; $x < $plotX2; $x += 10) {
            imageline(
                $im,
                $x,
                $targetY,
                min($x + 5, $plotX2),
                $targetY,
                $redColor
            );
        }

        imagestring(
            $im,
            2,
            $plotX2 - 180,
            $targetY - 14,
            'TARGET: ' . number_format($targetPrice, 5),
            $redColor
        );
    }

    /*
     * Volume max.
     */
    $maxVolume = 0;

    foreach ($candles as $candle) {
        if ($candle['volume'] > $maxVolume) {
            $maxVolume = $candle['volume'];
        }
    }

    if ($maxVolume <= 0) {
        $maxVolume = 1;
    }

    /*
     * Draw candles.
     */
    for ($i = 0; $i < $numCandles; $i++) {
        $candle = $candles[$i];

        $x = $plotX1 +
            $i * $candleWidth +
            $candleWidth / 2;

        $width = max(
            1,
            (int)floor($candleWidth * 0.70)
        );

        $openY = $priceToY($candle['open']);
        $closeY = $priceToY($candle['close']);
        $highY = $priceToY($candle['high']);
        $lowY = $priceToY($candle['low']);

        $isGreen = $candle['close'] >= $candle['open'];
        $candleColor = $isGreen ? $greenColor : $redColor;

        /*
         * Wick.
         */
        imageline(
            $im,
            $x,
            $highY,
            $x,
            $lowY,
            $candleColor
        );

        /*
         * Body.
         */
        $bodyTop = min($openY, $closeY);
        $bodyBottom = max($openY, $closeY);

        if (($bodyBottom - $bodyTop) < 1) {
            $bodyBottom = $bodyTop + 1;
        }

        imagefilledrectangle(
            $im,
            $x - $width / 2,
            $bodyTop,
            $x + $width / 2,
            $bodyBottom,
            $candleColor
        );

        /*
         * Volume.
         */
        $volumeHeight = (
            $candle['volume'] / $maxVolume
        ) * 45;

        if ($volumeHeight > 0) {
            imagefilledrectangle(
                $im,
                $x - $width / 2,
                $plotY2 - $volumeHeight,
                $x + $width / 2,
                $plotY2,
                $candleColor
            );
        }
    }

    /*
     * Draw markers.
     */
    foreach ($patternAlerts as $alert) {
        $markerTime = (int)$alert['time'];

        $candleIndex = -1;

        for ($i = 0; $i < $numCandles; $i++) {
            $candleTime = $candles[$i]['time'];

            if (
                $markerTime >= $candleTime &&
                $markerTime < ($candleTime + 300)
            ) {
                $candleIndex = $i;
                break;
            }
        }

        if ($candleIndex < 0) {
            continue;
        }

        $candle = $candles[$candleIndex];

        $x = $plotX1 +
            $candleIndex * $candleWidth +
            $candleWidth / 2;

        $type = $alert['type'];

        if ($type === 'alert_entry') {
            $lowY = $priceToY($candle['low']);

            $y1 = $lowY + 28;
            $y2 = $lowY + 10;

            imageline(
                $im,
                $x,
                $y1,
                $x,
                $y2,
                $yellowColor
            );

            imagefilledpolygon(
                $im,
                [
                    $x,
                    $y2 - 3,
                    $x - 6,
                    $y2 + 4,
                    $x + 6,
                    $y2 + 4,
                ],
                3,
                $yellowColor
            );

            imagestring(
                $im,
                2,
                $x - 35,
                $y1 + 4,
                'ALERT ENTRY',
                $yellowColor
            );
        }

        elseif ($type === 'win_outcome') {
            $highY = $priceToY($candle['high']);

            $y1 = $highY - 28;
            $y2 = $highY - 10;

            imageline(
                $im,
                $x,
                $y1,
                $x,
                $y2,
                $greenColor
            );

            imagefilledpolygon(
                $im,
                [
                    $x,
                    $y2 + 3,
                    $x - 6,
                    $y2 - 4,
                    $x + 6,
                    $y2 - 4,
                ],
                3,
                $greenColor
            );

            imagestring(
                $im,
                2,
                $x - 35,
                $y1 - 15,
                'WIN OUTCOME',
                $greenColor
            );
        }

        elseif ($type === 'loss_outcome') {
            $highY = $priceToY($candle['high']);

            $y1 = $highY - 28;
            $y2 = $highY - 10;

            imageline(
                $im,
                $x,
                $y1,
                $x,
                $y2,
                $redColor
            );

            imagefilledpolygon(
                $im,
                [
                    $x,
                    $y2 + 3,
                    $x - 6,
                    $y2 - 4,
                    $x + 6,
                    $y2 - 4,
                ],
                3,
                $redColor
            );

            imagestring(
                $im,
                2,
                $x - 40,
                $y1 - 15,
                'LOSS OUTCOME',
                $redColor
            );
        }

        elseif ($type === 'custom') {
            $highY = $priceToY($candle['high']);

            $y1 = $highY - 20;
            $y2 = $highY - 5;

            imageline(
                $im,
                $x,
                $y1,
                $x,
                $y2,
                $blueColor
            );

            imagestring(
                $im,
                1,
                $x - 15,
                $y1 - 12,
                substr((string)$alert['text'], 0, 20),
                $blueColor
            );
        }

        elseif ($type === 'new_day') {
            for ($y = $plotY1; $y < $plotY2; $y += 10) {
                imageline(
                    $im,
                    $x,
                    $y,
                    $x,
                    min($y + 5, $plotY2),
                    $blueColor
                );
            }

            imagestring(
                $im,
                1,
                $x - 20,
                $plotY1 + 10,
                'NEW DAY',
                $blueColor
            );
        }
    }

    /*
     * Save/output.
     */
    if ($savePath) {
        $directory = dirname($savePath);

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        imagepng($im, $savePath);
    } else {
        header('Content-Type: image/png');
        imagepng($im);
    }

    imagedestroy($im);

    return true;
}

/*
|--------------------------------------------------------------------------
| DEBUG ENDPOINT
|--------------------------------------------------------------------------
|
| Example:
| plotter.php?debug_trade=36775
|--------------------------------------------------------------------------
*/

if ($isDebug) {
    $tradeId = (int)$_GET['debug_trade'];

    $trade = fetchTrade($tradeId);

    $debug = [
        'trade_id' => $tradeId,
        'php_timezone' => date_default_timezone_get(),
        'server_time_utc' => gmdate('Y-m-d H:i:s'),
        'server_time_ist' => date('Y-m-d H:i:s'),
        'database_trade' => $trade,
    ];

    if (!$trade) {
        header('Content-Type: application/json');
        echo json_encode($debug, JSON_PRETTY_PRINT);
        exit;
    }

$alertRaw = $trade['last_alert_time'] ?? '';
$alertTimestamp = parseAlertTimestamp($alertRaw);

    $windowStart = $alertTimestamp - 14400;
    $windowEnd = $alertTimestamp + 28800;

    $csvFiles = getCsvFilesForWindow(
        $trade['pair_name'],
        $windowStart,
        $windowEnd
    );

    $scanInfo = [];

    foreach ($csvFiles as $csvPath) {
        $fileInfo = [
            'path' => $csvPath,
            'exists' => file_exists($csvPath),
            'readable' => is_readable($csvPath),
            'size' => file_exists($csvPath) ? filesize($csvPath) : 0,
            'rows' => 0,
            'valid_rows' => 0,
            'matching_rows' => 0,
            'min_utc' => null,
            'max_utc' => null,
            'samples' => [],
        ];

        $handle = @fopen($csvPath, 'r');

        if ($handle) {
            $first = true;

            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                if ($first) {
                    $first = false;
                    continue;
                }

                $fileInfo['rows']++;

                if (count($row) < 5) {
                    continue;
                }

                $time = parseCsvTimestamp($row[0]);

                if ($time <= 0) {
                    continue;
                }

                $fileInfo['valid_rows']++;

                if (
                    $fileInfo['min_utc'] === null ||
                    $time < $fileInfo['min_utc']['unix']
                ) {
                    $fileInfo['min_utc'] = [
                        'unix' => $time,
                        'utc' => gmdate('Y-m-d H:i:s', $time),
                    ];
                }

                if (
                    $fileInfo['max_utc'] === null ||
                    $time > $fileInfo['max_utc']['unix']
                ) {
                    $fileInfo['max_utc'] = [
                        'unix' => $time,
                        'utc' => gmdate('Y-m-d H:i:s', $time),
                    ];
                }

                if (
                    $time >= $windowStart &&
                    $time <= $windowEnd
                ) {
                    $fileInfo['matching_rows']++;

                    if (count($fileInfo['samples']) < 5) {
                        $fileInfo['samples'][] = [
                            'unix' => $time,
                            'utc' => gmdate('Y-m-d H:i:s', $time),
                            'ist' => (new DateTime('@' . $time))
                                ->setTimezone(new DateTimeZone('Asia/Kolkata'))
                                ->format('Y-m-d H:i:s'),
                            'row' => $row,
                        ];
                    }
                }
            }

            fclose($handle);
        }

        $scanInfo[] = $fileInfo;
    }

    $debug['alert'] = [
        'raw' => $alertRaw,
        'unix' => $alertTimestamp,
        'utc' => gmdate('Y-m-d H:i:s', $alertTimestamp),
        'ist' => (new DateTime('@' . $alertTimestamp))
            ->setTimezone(new DateTimeZone('Asia/Kolkata'))
            ->format('Y-m-d H:i:s'),
        'window_start_utc' => gmdate('Y-m-d H:i:s', $windowStart),
        'window_end_utc' => gmdate('Y-m-d H:i:s', $windowEnd),
    ];

    $debug['dataset_root'] = getDatasetRoot();
    $debug['csv_files'] = $csvFiles;
    $debug['csv_scan'] = $scanInfo;

    $data = readCandlesFromFiles(
        $csvFiles,
        $windowStart,
        $windowEnd
    );

    $debug['final_candle_count'] = count($data['candles']);
    $debug['final_first_candle'] = $data['candles'][0] ?? null;
    $debug['final_last_candle'] = !empty($data['candles'])
        ? $data['candles'][count($data['candles']) - 1]
        : null;

    header('Content-Type: application/json');
    echo json_encode($debug, JSON_PRETTY_PRINT);
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX TRADE METADATA + SERVER CHART GENERATION
|--------------------------------------------------------------------------
*/

if ($isAjax && isset($_GET['trade_id'])) {
    header('Content-Type: application/json');

    $tradeId = (int)$_GET['trade_id'];
    $trade = fetchTrade($tradeId);

    if (!$trade) {
        echo json_encode([
            'success' => false,
            'error' => 'Trade not found.',
        ]);
        exit;
    }

    $tempDir = __DIR__ . '/temp_images';

    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }

    $chartPath = $tempDir . '/trade_' . $tradeId . '.png';

    /*
     * Delete old image first so browser cannot display stale chart.
     */
    if (file_exists($chartPath)) {
        @unlink($chartPath);
    }

    $generated = generateTradeChartGD(
        $tradeId,
        $chartPath
    );

$alertTimestamp = parseAlertTimestamp(
    $trade['last_alert_time'] ?? ''
);

    $alertTimeIST = 'N/A';

    if ($alertTimestamp > 0) {
        $alertTimeIST = (new DateTime('@' . $alertTimestamp))
            ->setTimezone($istTimezone)
            ->format('Y-m-d H:i:s');
    }

    $winLossTimeIST = null;

    $winLossTimestamp = parseOutcomeTimestamp(
        $trade['win_loss_time'] ?? '',
        $alertTimestamp
    );
    if ($winLossTimestamp !== null) {
        $winLossTimeIST = (new DateTime('@' . $winLossTimestamp))
            ->setTimezone($istTimezone)
            ->format('Y-m-d H:i:s');
    }

    echo json_encode([
        'success' => true,
        'tradeId' => $tradeId,
        'pair' => $trade['pair_name'],
        'direction' => strtoupper($trade['trade_direction'] ?? ''),
        'alertTimeIST' => $alertTimeIST,
        'alertTimeUTC' => $trade['last_alert_time'],
        'tradeResult' => strtolower($trade['trade_result'] ?? 'pending'),
        'winLossTime' => $winLossTimeIST,
        'winLossPrice' => $trade['win_loss_price'] !== null
            ? (float)$trade['win_loss_price']
            : null,
        'targetPrice' => (float)$trade['price_target'],
        'chartGenerated' => $generated,
        'chartExists' => file_exists($chartPath),
        'chartSize' => file_exists($chartPath)
            ? filesize($chartPath)
            : 0,
        'chartUrl' => basename($chartPath),
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| STREAM CHART IMAGE
|--------------------------------------------------------------------------
*/

if (isset($_GET['get_chart_image']) && isset($_GET['trade_id'])) {
    $tradeId = (int)$_GET['trade_id'];

    /*
     * Generate directly to browser.
     */
    generateTradeChartGD($tradeId);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Advanced Trade Plotter</title>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --bg: #0b0f19;
    --panel: #131b2e;
    --panel2: #1e293b;
    --border: #334155;
    --text: #f8fafc;
    --muted: #94a3b8;
    --blue: #3b82f6;
    --green: #10b981;
    --red: #ef4444;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px;
    background: var(--bg);
    color: var(--text);
    font-family: 'Outfit', sans-serif;
}

.container {
    max-width: 1500px;
    margin: auto;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border);
    padding-bottom: 20px;
    margin-bottom: 25px;
}

h1 {
    margin: 0;
    font-size: 30px;
    color: #60a5fa;
}

.back {
    color: #60a5fa;
    text-decoration: none;
    font-weight: 700;
}

.workspace {
    display: grid;
    grid-template-columns: 390px 1fr;
    gap: 25px;
}

.sidebar,
.chart-panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px;
}

.sidebar {
    max-height: 850px;
    display: flex;
    flex-direction: column;
}

.sidebar-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}

.sidebar-title h2 {
    margin: 0;
    font-size: 20px;
}

input[type="text"],
input[type="number"] {
    width: 100%;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    outline: none;
}

.controls {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
}

button {
    border: 1px solid var(--border);
    background: var(--panel2);
    color: var(--text);
    padding: 11px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-family: inherit;
    font-weight: 700;
}

button:hover {
    border-color: var(--blue);
}

.primary {
    background: var(--blue);
    border-color: var(--blue);
}

.trade-list {
    overflow-y: auto;
    flex: 1;
    max-height: 690px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.trade-item {
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    background: rgba(255,255,255,0.02);
}

.trade-item:hover,
.trade-item.active {
    border-color: var(--blue);
    background: rgba(59,130,246,0.12);
}

.trade-head {
    display: flex;
    align-items: center;
    gap: 10px;
}

.trade-pair {
    flex: 1;
    font-size: 16px;
    font-weight: 800;
}

.buy {
    color: var(--green);
}

.sell {
    color: var(--red);
}

.trade-sub {
    margin-top: 7px;
    color: var(--muted);
    font-size: 13px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    flex-wrap: wrap;
}

.active-pair {
    font-size: 25px;
    font-weight: 800;
}

.active-meta {
    margin-top: 10px;
    color: var(--muted);
    line-height: 1.8;
}

#chart-container {
    margin-top: 20px;
    height: 650px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #0b121f;
    overflow: hidden;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

#chart-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: none;
}

#chart-placeholder {
    color: var(--muted);
    font-size: 17px;
    text-align: center;
    padding: 30px;
}

#loading {
    display: none;
    position: absolute;
    inset: 0;
    background: rgba(11,15,25,0.88);
    align-items: center;
    justify-content: center;
    z-index: 5;
    color: white;
    font-size: 18px;
    font-weight: 700;
}

.badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 800;
}

.badge-win {
    color: var(--green);
    background: rgba(16,185,129,0.15);
}

.badge-loss {
    color: var(--red);
    background: rgba(239,68,68,0.15);
}

.badge-pending {
    color: var(--muted);
    background: rgba(148,163,184,0.15);
}

.badge-setup_not_formed {
    color: #fbbf24;
    background: rgba(245,158,11,0.15);
}

/* ── Outcome filter pills ── */
.filter-pills {
    display: flex; gap: 5px; margin-bottom: 10px; flex-wrap: wrap;
}
.filter-pill {
    flex: 1; min-width: 56px;
    padding: 5px 6px; border-radius: 20px;
    border: 1px solid #334155; background: transparent;
    color: #94a3b8; font-size: 11px; font-weight: 700;
    cursor: pointer; text-align: center; transition: all .15s;
    white-space: nowrap;
}
.filter-pill:hover { border-color: #60a5fa; color: #e2e8f0; }
.filter-pill.active          { background: #1e3a5f; border-color: #3b82f6; color: #93c5fd; }
.filter-pill.fp-win.active   { background: rgba(16,185,129,.15); border-color: #10b981; color: #34d399; }
.filter-pill.fp-loss.active  { background: rgba(239,68,68,.15);  border-color: #ef4444; color: #f87171; }
.filter-pill.fp-snf.active   { background: rgba(245,158,11,.15); border-color: #f59e0b; color: #fbbf24; }

@media(max-width: 1000px) {
    .workspace {
        grid-template-columns: 1fr;
    }

    .sidebar {
        max-height: 500px;
    }
}
</style>
</head>

<body>

<div class="container">

<header>
    <h1>▣ Advanced Trade Plotter</h1>
    <a class="back" href="../admin_dashboard.php">« Back to Dashboard</a>
</header>

<div class="workspace">

<div class="sidebar">

    <div class="sidebar-title">
        <h2>Select Trade</h2>
        <span id="selected-count">0</span>
    </div>

    <div style="margin-bottom:8px;">
        <input
            type="text"
            id="pair-search"
            placeholder="Search pair e.g. EURUSD"
            oninput="filterTrades()"
            style="width:100%;"
        >
    </div>

    <div class="filter-pills">
        <button class="filter-pill active"        data-outcome="all"              onclick="setOutcomeFilter(this)">All</button>
        <button class="filter-pill fp-win"        data-outcome="win"              onclick="setOutcomeFilter(this)">&#9650; Wins</button>
        <button class="filter-pill fp-loss"       data-outcome="loss"             onclick="setOutcomeFilter(this)">&#9660; Losses</button>
        <button class="filter-pill fp-snf"        data-outcome="setup_not_formed" onclick="setOutcomeFilter(this)">&#8212; Not Formed</button>
    </div>

    <div class="controls">
        <input
            type="number"
            id="trade-id-input"
            placeholder="Trade ID"
            onkeydown="if(event.key === 'Enter') loadById()"
        >

        <button class="primary" onclick="loadById()">
            Load ID
        </button>
    </div>

    <div class="trade-list" id="trade-list">

<?php
/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.raw_trade_id,
        p.pair_name,
        p.trade_direction,
        p.last_alert_time,
        o.trade_result
    FROM prediction_trade_data p
    INNER JOIN trade_outcome_details o
        ON p.raw_trade_id = o.raw_trade_id
    WHERE o.trade_result IN ('win','loss','setup_not_formed')
    ORDER BY p.raw_trade_id DESC
    LIMIT 300
";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['raw_trade_id'];
        $pair = htmlspecialchars(
            $row['pair_name'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        );

        $direction = strtoupper(
            $row['trade_direction'] ?? ''
        );

        $directionLabel = $direction === 'UP'
            ? 'BUY'
            : 'SELL';

        $directionClass = $direction === 'UP'
            ? 'buy'
            : 'sell';

        // Historical trades (<2025-06-01) stored last_alert_time as IST
        $alertRawSidebar = $row['last_alert_time'] ?? '';
        $isHistSidebar   = (substr($alertRawSidebar, 0, 10) < '2025-06-01');
        $alertTs = $isHistSidebar
            ? (new DateTime($alertRawSidebar, $istTimezone))->getTimestamp()
            : parseUtcTimestamp($alertRawSidebar);

        $alertIST = $alertTs > 0
            ? (new DateTime('@' . $alertTs))
                ->setTimezone($istTimezone)
                ->format('d M H:i')
            : 'N/A';

        $outcome = strtolower(
            $row['trade_result'] ?? 'pending'
        );

        $badgeClass = 'badge-pending';

        if ($outcome === 'win') {
            $badgeClass = 'badge-win';
        } elseif ($outcome === 'loss') {
            $badgeClass = 'badge-loss';
        } elseif ($outcome === 'setup_not_formed') {
            $badgeClass = 'badge-setup_not_formed';
        }

        echo '
        <div
            class="trade-item"
            id="trade-' . $id . '"
            data-pair="' . strtoupper($pair) . '"
            data-result="' . $outcome . '"
            onclick="loadTrade(' . $id . ')"
        >
            <div class="trade-head">
                <span class="trade-pair">' . $pair . '</span>
                <strong class="' . $directionClass . '">' .
                    $directionLabel .
                '</strong>
            </div>

            <div class="trade-sub">
                ID: #' . $id .
                ' | ' . $alertIST . ' IST
                <span class="badge ' . $badgeClass . '">' .
                    strtoupper(str_replace('_', ' ', $outcome)) .
                '</span>
            </div>
        </div>';
    }
} else {
    echo '<div style="color:#94a3b8;">No trades found.</div>';
}
?>

    </div>
</div>

<div class="chart-panel">

    <div class="chart-header">
        <div>
            <div class="active-pair" id="active-pair">
                Select a Trade
            </div>

            <div class="active-meta" id="active-meta">
                Select a trade from the sidebar or enter a Trade ID.
            </div>
        </div>

        <button
            id="export-button"
            style="display:none;"
            onclick="exportChart()"
        >
            Export Image
        </button>
    </div>

    <div id="chart-container">
        <div id="chart-placeholder">
            Select a trade to load its candlestick chart.
        </div>

        <img id="chart-image" alt="Trade Chart">

        <div id="loading">
            Loading chart...
        </div>
    </div>

</div>

</div>
</div>

<script>
let activeTradeId = null;

let _outcomeFilter = 'all';

function setOutcomeFilter(btn) {
    _outcomeFilter = btn.dataset.outcome;
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterTrades();
}

function filterTrades() {
    const query = (document.getElementById('pair-search')?.value || '').toUpperCase().trim();
    const outcome = _outcomeFilter;

    document.querySelectorAll('.trade-item').forEach(item => {
        const pair       = (item.dataset.pair   || '').toUpperCase();
        const result     = (item.dataset.result || '').toLowerCase();
        const matchPair  = pair.includes(query);
        const matchOutcome = (outcome === 'all') || (result === outcome);
        item.style.display = (matchPair && matchOutcome) ? 'block' : 'none';
    });
}

function loadById() {
    const input = document.getElementById('trade-id-input');
    const id = parseInt(input.value, 10);

    if (!id || id <= 0) {
        alert('Enter a valid Trade ID.');
        return;
    }

    loadTrade(id);
}

function setLoading(status, text = 'Loading chart...') {
    const loading = document.getElementById('loading');

    if (!loading) {
        return;
    }

    loading.innerText = text;
    loading.style.display = status ? 'flex' : 'none';
}

function loadTrade(id) {
    activeTradeId = id;

    /*
     * Highlight sidebar item if available.
     */
    document.querySelectorAll('.trade-item').forEach(item => {
        item.classList.remove('active');
    });

    const selected = document.getElementById('trade-' + id);

    if (selected) {
        selected.classList.add('active');
    }

    const placeholder = document.getElementById('chart-placeholder');
    const chartImage = document.getElementById('chart-image');
    const exportButton = document.getElementById('export-button');

    if (!placeholder || !chartImage) {
        console.error('Chart DOM elements are missing.');
        return;
    }

    setLoading(true, 'Loading database trade and CSV data...');

    placeholder.style.display = 'none';
    chartImage.style.display = 'none';

    /*
     * Do not replace chart-container.innerHTML.
     * Replacing it was causing stale/null DOM references.
     */
    const currentUrl = window.location.pathname;

    fetch(
        currentUrl +
        '?ajax=1&trade_id=' +
        encodeURIComponent(id) +
        '&_=' +
        Date.now(),
        {
            credentials: 'same-origin',
            cache: 'no-store'
        }
    )
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return response.json();
    })
    .then(data => {
        console.log('[PLOTTER RESPONSE]', data);

        if (!data.success) {
            throw new Error(data.error || 'Unknown server error');
        }

        document.getElementById('active-pair').innerText =
            data.pair || 'Unknown Pair';

        const directionLabel =
            data.direction === 'UP'
                ? 'BUY'
                : 'SELL';

        let meta =
            '<strong>' +
            directionLabel +
            '</strong>' +
            ' &nbsp; Alert Time: <strong>' +
            (data.alertTimeIST || 'N/A') +
            ' IST</strong>' +
            ' &nbsp; Target: <strong>' +
            Number(data.targetPrice || 0).toFixed(5) +
            '</strong>';

        const result =
            (data.tradeResult || 'pending').toLowerCase();

        let badgeClass = 'badge-pending';

        if (result === 'win') {
            badgeClass = 'badge-win';
        } else if (result === 'loss') {
            badgeClass = 'badge-loss';
        }

        meta +=
            ' &nbsp; Outcome: <span class="badge ' +
            badgeClass +
            '">' +
            result.toUpperCase().replace(/_/g, ' ') +
            '</span>';

        if (
            data.winLossPrice !== null &&
            data.winLossPrice !== undefined
        ) {
            meta +=
                ' &nbsp; Res. Price: <strong>' +
                Number(data.winLossPrice).toFixed(5) +
                '</strong>';
        }

        if (data.winLossTime) {
            meta +=
                ' &nbsp; Res. Time: <strong>' +
                data.winLossTime +
                ' IST</strong>';
        }

        document.getElementById('active-meta').innerHTML = meta;

        if (!data.chartGenerated || !data.chartExists) {
            throw new Error(
                'Chart generation failed on server. ' +
                'Open plotter.php?debug_trade=' + id +
                ' for diagnostics.'
            );
        }

        /*
         * Use the chart streaming endpoint.
         */
        chartImage.onload = function() {
            setLoading(false);
            chartImage.style.display = 'block';

            if (exportButton) {
                exportButton.style.display = 'block';
            }
        };

        chartImage.onerror = function() {
            setLoading(false);
            alert(
                'Chart image could not be loaded. ' +
                'Check the debug endpoint.'
            );
        };

        chartImage.src =
            currentUrl +
            '?get_chart_image=1&trade_id=' +
            encodeURIComponent(id) +
            '&t=' +
            Date.now();
    })
    .catch(error => {
        console.error('[PLOTTER ERROR]', error);

        setLoading(false);
        placeholder.style.display = 'flex';
        placeholder.innerText = error.message;

        if (exportButton) {
            exportButton.style.display = 'none';
        }

        alert(error.message);
    });
}

function exportChart() {
    if (!activeTradeId) {
        alert('Load a trade first.');
        return;
    }

    const currentUrl = window.location.pathname;

    const link = document.createElement('a');

    link.href =
        currentUrl +
        '?get_chart_image=1&trade_id=' +
        encodeURIComponent(activeTradeId) +
        '&download=1&t=' +
        Date.now();

    link.download = 'Trade_' + activeTradeId + '.png';

    document.body.appendChild(link);
    link.click();
    link.remove();
}

/*
 * Auto-load trade from URL:
 * plotter.php?trade_id=36775
 */
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);

    const id =
        parseInt(params.get('trade_id') || params.get('search-id'), 10);

    if (id > 0) {
        document.getElementById('trade-id-input').value = id;
        loadTrade(id);
    }
});
</script>

</body>
</html>