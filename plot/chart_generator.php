<?php
/**
 * chart_generator.php
 * ---------------------------------------------------
 * Everything related to producing a single trade's candlestick
 * chart image: economic-news proximity lookup, CSV dataset routing,
 * and the GD-based chart renderer itself.
 *
 * Depends on globals from bootstrap.php: $conn, $istTimezone, $utcTimezone
 */

// ==========================================
// ECONOMIC NEWS PROXIMITY LOOKUP
// ==========================================
/**
 * Finds economic_events rows within $windowMinutes of a reference UTC epoch
 * timestamp (before, at, or after it). economic_events.event_time is stored
 * as an IST DATETIME (see manual_news.php), so the reference timestamp is
 * converted to IST before building the SQL range, and each matched row's
 * event_time is converted back to a UTC epoch for chart placement.
 */
function getNearbyEconomicEvents($conn, DateTimeZone $istTimezone, $referenceTimestampUTC, $windowMinutes = 15) {
    if (!$referenceTimestampUTC) return [];

    $windowSeconds = $windowMinutes * 60;
    $startIST = (new DateTime('@' . ($referenceTimestampUTC - $windowSeconds)))->setTimezone($istTimezone)->format('Y-m-d H:i:s');
    $endIST   = (new DateTime('@' . ($referenceTimestampUTC + $windowSeconds)))->setTimezone($istTimezone)->format('Y-m-d H:i:s');

    $query = "SELECT event_name, impact, event_time FROM economic_events
              WHERE event_time BETWEEN '" . $conn->real_escape_string($startIST) . "' AND '" . $conn->real_escape_string($endIST) . "'
              ORDER BY event_time ASC";
    $res = $conn->query($query);

    $events = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $eventTimestamp = null;
            try {
                $eventTimestamp = (new DateTime($row['event_time'], $istTimezone))->getTimestamp();
            } catch (Exception $e) {}

            $events[] = [
                'event_name'     => $row['event_name'],
                'impact'         => (int)$row['impact'],
                'event_time_ist' => $row['event_time'],
                'timestamp'      => $eventTimestamp,
                'offset_minutes' => $eventTimestamp !== null ? round(($eventTimestamp - $referenceTimestampUTC) / 60) : null,
            ];
        }
    }
    return $events;
}

/**
 * Given a list of events from getNearbyEconomicEvents(), returns only the
 * single highest-impact one. Ties are broken by whichever is closest in
 * time to the reference timestamp (smallest absolute offset_minutes).
 */
function pickHighestImpactEvent(array $events) {
    if (empty($events)) return null;

    usort($events, function($a, $b) {
        if ($a['impact'] !== $b['impact']) {
            return $b['impact'] <=> $a['impact']; // higher impact first
        }
        return abs($a['offset_minutes'] ?? PHP_INT_MAX) <=> abs($b['offset_minutes'] ?? PHP_INT_MAX);
    });

    return $events[0];
}

// ==========================================
// ROUTING LOGIC FOR DATASET DIRECTORIES
// ==========================================
// ==========================================
// ROUTING LOGIC FOR ALL DATASET DIRECTORIES
// ==========================================

function getCsvPath(string $pairName, string $alertTimeStr): string
{
    global $istTimezone, $utcTimezone;

    $cleanPair = strtoupper(str_replace(['/', '_', ' '], '', trim($pairName)));
    $fileName  = "FX_" . $cleanPair . ".csv";

    /*
     * IMPORTANT:
     * Database last_alert_time is stored as UTC datetime.
     * Convert explicitly as UTC before getting Unix timestamp.
     */
    $timestamp = 0;

    try {
        $dt = new DateTime($alertTimeStr, $utcTimezone);
        $timestamp = $dt->getTimestamp();
    } catch (Exception $e) {
        $timestamp = strtotime($alertTimeStr);
    }

    if (!$timestamp) {
        error_log("CSV ROUTING ERROR: Invalid alert time: " . $alertTimeStr);
        return '';
    }

    /*
     * Locate the main dataset directory.
     */
    $possibleBaseDirs = [
        __DIR__ . '/dataset',
        __DIR__ . '/../dataset',
        __DIR__ . '/../../dataset',
        (!empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/dataset' : ''),
    ];

    $datasetDir = '';

    foreach ($possibleBaseDirs as $candidate) {
        if (is_dir($candidate)) {
            $datasetDir = rtrim($candidate, '/');
            break;
        }
    }

    /*
     * Additional parent-directory search.
     */
    if (!$datasetDir) {
        $dir = __DIR__;

        for ($i = 0; $i < 5; $i++) {
            $dir = dirname($dir);

            if (is_dir($dir . '/dataset')) {
                $datasetDir = $dir . '/dataset';
                break;
            }
        }
    }

    if (!$datasetDir) {
        error_log("CSV ROUTING ERROR: Dataset base directory not found.");
        return '';
    }

    /*
     * Exact historical routing:
     *
     * < 2025-06-01       BEFORE-JUN-2025
     * < 2026-03-01       JUN-2025 TO FEB-2026
     * >= 2026-03-01      dataset
     */
    $cutoffBeforeJune = strtotime('2025-06-01 00:00:00 UTC');
    $cutoffMarch2026   = strtotime('2026-03-01 00:00:00 UTC');

    $candidatePaths = [];

    if ($timestamp < $cutoffBeforeJune) {

        // Date-specific historical file: BEFORE-JUN-2025/PAIR/FX_PAIR-YYYY-MM-DD.csv
        // last_alert_time for these trades was stored as IST, so date string is already IST date
        $alertDate    = substr($alertTimeStr, 0, 10); // 'YYYY-MM-DD' (IST date)
        $dateFileName = 'FX_' . $cleanPair . '-' . $alertDate . '.csv';
        $candidatePaths[] = $datasetDir . '/BEFORE-JUN-2025/' . $cleanPair . '/' . $dateFileName;
        // Fallback: rolling file (in case one was placed there)
        $candidatePaths[] = $datasetDir . '/BEFORE-JUN-2025/' . $fileName;

    } elseif ($timestamp < $cutoffMarch2026) {

        // June 2025 through February 2026
        $candidatePaths[] =
            $datasetDir . '/JUN-2025 TO FEB-2026/' . $fileName;

    } else {

        // March 2026 onward
        $candidatePaths[] =
            $datasetDir . '/dataset/' . $fileName;
    }

    /*
     * Fallback paths in case a file was placed in a different folder.
     */
    // Fallback: date-specific historical (catches any routing miss above)
    $alertDate = substr($alertTimeStr, 0, 10);
    $candidatePaths[] = $datasetDir . '/BEFORE-JUN-2025/' . $cleanPair . '/FX_' . $cleanPair . '-' . $alertDate . '.csv';
    $candidatePaths[] = $datasetDir . '/BEFORE-JUN-2025/' . $fileName;
    $candidatePaths[] = $datasetDir . '/JUN-2025 TO FEB-2026/' . $fileName;
    $candidatePaths[] = $datasetDir . '/dataset/' . $fileName;
    $candidatePaths[] = $datasetDir . '/' . $fileName;

    /*
     * Remove duplicate paths while preserving order.
     */
    $candidatePaths = array_values(array_unique($candidatePaths));

    foreach ($candidatePaths as $path) {
        if (file_exists($path) && is_readable($path)) {
            error_log(
                "CSV ROUTING SUCCESS: pair={$cleanPair} " .
                "timestamp={$timestamp} " .
                "date=" . gmdate('Y-m-d H:i:s', $timestamp) .
                " path={$path}"
            );

            return $path;
        }
    }

    error_log(
        "CSV NOT FOUND: pair={$cleanPair}, " .
        "alertTime={$alertTimeStr}, " .
        "timestamp={$timestamp}, " .
        "searched=" . implode(' | ', $candidatePaths)
    );

    return '';
}
// ==========================================
// CSV TIMESTAMP PARSER
 // ==========================================

function parseCsvTimestamp($value): int
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    // Unix timestamp in milliseconds.
    if (ctype_digit($value) && strlen($value) >= 13) {
        return (int) floor(((int)$value) / 1000);
    }

    // Unix timestamp in seconds.
    if (ctype_digit($value) && strlen($value) >= 9) {
        return (int) $value;
    }

    // Normalize common ISO-8601 UTC suffix.
    $normalized = str_replace('T', ' ', $value);
    $normalized = preg_replace('/\\.\\d+Z$/', '', $normalized);
    $normalized = preg_replace('/Z$/', '', $normalized);
    $normalized = trim($normalized);

    try {
        // CSV timestamps are treated as UTC unless an explicit offset exists.
        $dt = new DateTime($normalized, new DateTimeZone('UTC'));
        return $dt->getTimestamp();
    } catch (Exception $e) {
        $fallback = strtotime($value . ' UTC');
        return $fallback !== false ? (int)$fallback : 0;
    }
}

// ==========================================
// GD CHART RENDERER ENGINE
// ==========================================
function resolveOutcomeTimestamp($value, int $alertTimestamp = 0): ?int
{
    global $istTimezone, $utcTimezone;
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return null;

    $candidates = [];
    try { $candidates['ist'] = (new DateTime($value, $istTimezone))->getTimestamp(); } catch (Exception $e) {}
    try { $candidates['utc'] = (new DateTime($value, $utcTimezone))->getTimestamp(); } catch (Exception $e) {}
    if (!$candidates) return null;

    if ($alertTimestamp > 0) {
        $valid = array_filter($candidates, static fn($ts) => $ts >= $alertTimestamp);
        if ($valid) return min($valid);
    }
    return $candidates['ist'] ?? reset($candidates);
}

function generateTradeChartGD($tradeId, $savePath = null) {
    global $conn, $istTimezone, $utcTimezone;

    // 1. Fetch trade info
    $query = "SELECT p.*, o.trade_result, o.win_loss_time, o.win_loss_price,
                     UNIX_TIMESTAMP(p.last_alert_time) AS trigger_unixtime
              FROM prediction_trade_data p
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.raw_trade_id = " . (int)$tradeId . " LIMIT 1";
    $res = $conn->query($query);
    $tradeRes = $res ? $res->fetch_assoc() : null;
    if (!$tradeRes) return false;

    $alertTimeUTC = $tradeRes['last_alert_time'] ?? '';
    if (empty($alertTimeUTC) || $alertTimeUTC === '0000-00-00 00:00:00') {
        return false;
    }

    /*
     * IMPORTANT: Timezone-aware alert timestamp resolution.
     * - Historical trades (pre-2025-06-01): last_alert_time was stored as IST
     *   by the recovery script. Parse as Asia/Kolkata.
     * - Live trades (post-2025-06-01): last_alert_time is stored as UTC.
     *   Parse as UTC (original behaviour).
     */
    $isHistoricalTrade = (substr($alertTimeUTC, 0, 10) < '2025-06-01');
    try {
        $alertTZ = $isHistoricalTrade ? $istTimezone : $utcTimezone;
        $alertDT = new DateTime($alertTimeUTC, $alertTZ);
        $alertTimestamp = $alertDT->getTimestamp();
    } catch (Exception $e) {
        $alertTimestamp = $isHistoricalTrade
            ? strtotime($alertTimeUTC . ' Asia/Kolkata')
            : strtotime($alertTimeUTC . ' UTC');
    }

    if (!$alertTimestamp) {
        error_log("CHART TIME ERROR: Invalid alert time: " . $alertTimeUTC);
        return false;
    }

    $csvPath = getCsvPath($tradeRes['pair_name'], $alertTimeUTC);

    error_log("========== TRADE CHART DEBUG ==========");
    error_log("Trade ID: " . (int)$tradeId);
    error_log("Pair: " . ($tradeRes['pair_name'] ?? ''));
    error_log("Alert UTC: " . $alertTimeUTC);
    error_log("Alert Unix: " . $alertTimestamp);
    error_log("Alert UTC Converted: " . gmdate('Y-m-d H:i:s', $alertTimestamp));
    error_log("CSV Path: " . ($csvPath ?: '[EMPTY]'));
    error_log("CSV Exists: " . (($csvPath && file_exists($csvPath)) ? 'YES' : 'NO'));
    error_log("CSV Readable: " . (($csvPath && is_readable($csvPath)) ? 'YES' : 'NO'));
    error_log("=======================================");

    if (empty($csvPath) || !file_exists($csvPath) || !is_readable($csvPath)) {
        return false;
    }

    $candlestickData = [];
    $volumeData = [];
    $patternAlerts = [];

    $windowStart = $alertTimestamp - 14400; // 4 hours before
    $windowEnd   = $alertTimestamp + 28800; // 8 hours after

    $last_day = null;
    $is_header = true;
    $totalCsvRows = 0;
    $validTimestampRows = 0;
    $skippedBeforeWindow = 0;
    $skippedAfterWindow = 0;

    if (($handle = fopen($csvPath, "r")) !== FALSE) {
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $totalCsvRows++;

            if ($is_header) {
                $is_header = false;
                continue;
            }
            if (count($row) < 6) continue; // historical CSVs have 6 cols (no volume)

            $time = parseCsvTimestamp($row[0]);

            if ($time <= 0) {
                continue;
            }

            $validTimestampRows++;

            if ($time < $windowStart) {
                $skippedBeforeWindow++;
                continue;
            }

            if ($time > $windowEnd) {
                $skippedAfterWindow++;
                /*
                 * Only break when the CSV is chronologically sorted.
                 * Historical files are expected to be sorted, but do not
                 * assume this for malformed/unsorted files.
                 */
                continue;
            }

            $open  = (float)$row[1];
            $high  = (float)$row[2];
            $low   = (float)$row[3];
            $close = (float)$row[4];
            $pattern_alert = isset($row[5]) ? trim($row[5]) : '0';
            $volume = isset($row[6]) ? (float)$row[6] : 0.0; // not present in historical CSVs

            $current_day = date('Y-m-d', $time);
            if ($last_day === null) {
                $last_day = $current_day;
            }
            if ($current_day != $last_day) {
                $patternAlerts[] = [
                    'time' => $time,
                    'type' => 'new_day'
                ];
                $last_day = $current_day;
            }

            $candlestickData[] = [
                'time' => $time,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close
            ];

            $volumeData[] = [
                'time' => $time,
                'value' => $volume,
                'is_green' => ($close > $open)
            ];

            if (!empty($pattern_alert) && $pattern_alert !== '0') {
                $patternAlerts[] = [
                    'time' => $time,
                    'type' => 'custom',
                    'text' => $pattern_alert
                ];
            }
        }
        fclose($handle);
    }

    error_log(
        "CSV READ SUMMARY: trade=" . (int)$tradeId .
        " totalRows=" . $totalCsvRows .
        " validTimestampRows=" . $validTimestampRows .
        " beforeWindow=" . $skippedBeforeWindow .
        " afterWindow=" . $skippedAfterWindow .
        " candlesInWindow=" . count($candlestickData) .
        " windowStartUTC=" . gmdate('Y-m-d H:i:s', $windowStart) .
        " windowEndUTC=" . gmdate('Y-m-d H:i:s', $windowEnd)
    );

    // Alert entry marker
    $patternAlerts[] = [
        'time' => $alertTimestamp,
        'type' => 'alert_entry',
        'text' => 'ALERT ENTRY'
    ];

    // Win/Loss Outcome marker
    $winLossTimestamp = resolveOutcomeTimestamp(
        $tradeRes['win_loss_time'] ?? '',
        $alertTimestamp
    );

    if ($winLossTimestamp !== null) {
        $outcomeVal = strtolower($tradeRes['trade_result'] ?? '');
        if ($outcomeVal === 'win') {
            $patternAlerts[] = [
                'time' => $winLossTimestamp,
                'type' => 'win_outcome',
                'text' => 'WIN OUTCOME'
            ];
        } elseif ($outcomeVal === 'loss') {
            $patternAlerts[] = [
                'time' => $winLossTimestamp,
                'type' => 'loss_outcome',
                'text' => 'LOSS OUTCOME'
            ];
        }
    }

    // Economic news within +/-15 minutes of the win/loss resolution (highest impact only)
    if ($winLossTimestamp !== null) {
        $nearbyNewsEvents = getNearbyEconomicEvents($conn, $istTimezone, $winLossTimestamp, 15);
        $topNewsEvent = pickHighestImpactEvent($nearbyNewsEvents);
        if ($topNewsEvent !== null && $topNewsEvent['timestamp'] !== null) {
            $patternAlerts[] = [
                'time' => $topNewsEvent['timestamp'],
                'type' => 'news_event',
                'text' => $topNewsEvent['event_name'],
                'impact' => $topNewsEvent['impact']
            ];
        }
    }

    // Draw target line AFTER candlesticks so it is always visible on top.
    // Computed here so $candlestickData and $candleWidth are ready.
    $alertStartX = $plotX1;
    for ($i = 0; $i < $numCandles; $i++) {
        if ($alertTimestamp >= $candlestickData[$i]['time'] && $alertTimestamp < ($candlestickData[$i]['time'] + 300)) {
            $startIndex = max(0, $i - 3);
            $alertStartX = $plotX1 + $startIndex * $candleWidth + $candleWidth / 2;
            break;
        }
    }

    $targetPriceY = $priceToY((float)$tradeRes['price_target']);
    $targetColor  = imagecolorallocate($im, 251, 191, 36); // amber — distinct from loss red
    for ($x = $alertStartX; $x < $plotX2; $x += 10) {
        imageline($im, $x, $targetPriceY, min($x + 5, $plotX2), $targetPriceY, $targetColor);
    }
    imagestring($im, 2, $plotX2 - 185, $targetPriceY - 14, "TARGET: " . number_format($tradeRes['price_target'], 5), $targetColor);

    // Sort alerts by time
    usort($patternAlerts, function($a, $b) {
        return $a['time'] <=> $b['time'];
    });

    // 2. Initialize Image (Aspect ratio 1.8 matching FPDF)
    $imgWidth = 1200;
    $imgHeight = 670;

    $im = imagecreatetruecolor($imgWidth, $imgHeight);

    // Allocate Colors
    $bgColor = imagecolorallocate($im, 11, 15, 25);         // #0b0f19 primary bg
    $panelColor = imagecolorallocate($im, 11, 18, 31);      // #0b121f chart area bg
    $cardBgColor = imagecolorallocate($im, 19, 27, 46);     // #131b2e card bg
    $cardBorderColor = imagecolorallocate($im, 51, 65, 85); // #334155 card border
    $gridColor = imagecolorallocate($im, 30, 41, 59);       // #1e293b grid lines
    $textColor = imagecolorallocate($im, 148, 163, 184);   // #94a3b8 text-secondary
    $whiteColor = imagecolorallocate($im, 248, 250, 252);   // #f8fafc white
    $greenColor = imagecolorallocate($im, 16, 185, 129);    // #10b981 emerald
    $redColor = imagecolorallocate($im, 239, 68, 68);       // #ef4444 soft red
    $yellowColor = imagecolorallocate($im, 251, 191, 36);   // #fbbf24 amber
    $blueColor = imagecolorallocate($im, 59, 130, 246);     // #3b82f6 blue
    $newsImpact2Color = imagecolorallocate($im, 249, 115, 22); // #f97316 orange (impact 2)
    $newsImpact1Color = imagecolorallocate($im, 168, 85, 247);  // #a855f7 violet (impact 1)

    // 4. Draw Chart Area
    $chartX1 = 15;
    $chartY1 = 15;
    $chartX2 = 1185;
    $chartY2 = 655;

    imagefilledrectangle($im, $chartX1, $chartY1, $chartX2, $chartY2, $panelColor);
    imagerectangle($im, $chartX1, $chartY1, $chartX2, $chartY2, $cardBorderColor);

    $plotX1 = $chartX1 + 10;
    $plotY1 = $chartY1 + 15;
    $plotX2 = $chartX2 - 70;
    $plotY2 = $chartY2 - 30;

    $plotWidth = $plotX2 - $plotX1;
    $plotHeight = $plotY2 - $plotY1;

    if (empty($candlestickData)) {
        imagestring($im, 4, $chartX1 + 100, $chartY1 + 100, "No candlestick data available", $textColor);
        if ($savePath) {
            imagepng($im, $savePath);
        } else {
            imagepng($im);
        }
        imagedestroy($im);
        return true;
    }

    $prices = [];
    foreach ($candlestickData as $c) {
        $prices[] = $c['high'];
        $prices[] = $c['low'];
    }
    $prices[] = (float)$tradeRes['price_target'];
    if ($tradeRes['win_loss_price'] !== null) {
        $prices[] = (float)$tradeRes['win_loss_price'];
    }

    $maxPrice = max($prices);
    $minPrice = min($prices);
    $priceRange = $maxPrice - $minPrice;
    if ($priceRange == 0) $priceRange = 0.0001;

    // Add 10% padding
    $maxPrice += $priceRange * 0.10;
    $minPrice -= $priceRange * 0.10;
    $priceRange = $maxPrice - $minPrice;

    $priceToY = function($price) use ($plotY1, $plotY2, $plotHeight, $minPrice, $maxPrice, $priceRange) {
        return $plotY2 - (($price - $minPrice) / $priceRange) * $plotHeight;
    };

    // Draw horizontal grid lines and price labels
    $gridCount = 5;
    for ($i = 0; $i <= $gridCount; $i++) {
        $gridPrice = $minPrice + ($priceRange / $gridCount) * $i;
        $gridY = $priceToY($gridPrice);
        imageline($im, $plotX1, $gridY, $plotX2, $gridY, $gridColor);

        $priceLabel = number_format($gridPrice, 5);
        imagestring($im, 2, $plotX2 + 5, $gridY - 6, $priceLabel, $textColor);
    }

    // X-axis coordinate mapping:
    $numCandles = count($candlestickData);
    $candleWidth = $plotWidth / $numCandles;

    // Draw vertical hourly gridlines and label time at bottom
    $labelInterval = max(1, floor($numCandles / 8));
    for ($i = 0; $i < $numCandles; $i++) {
        $c = $candlestickData[$i];
        $cX = $plotX1 + $i * $candleWidth + $candleWidth / 2;

        $dt = new DateTime("@" . $c['time']);
        $dt->setTimezone($istTimezone);
        $minute = $dt->format('i');
        if ($minute === '00' || $i % $labelInterval == 0) {
            imageline($im, $cX, $plotY1, $cX, $plotY2, $gridColor);
            $timeStr = $dt->format('H:i');
            imagestring($im, 2, $cX - 15, $plotY2 + 5, $timeStr, $textColor);
        }
    }

    // alertStartX is computed after candles are drawn (see below)

    // Draw candlesticks & Volume
    $maxVol = 0;
    foreach ($volumeData as $v) {
        if ($v['value'] > $maxVol) $maxVol = $v['value'];
    }
    if ($maxVol == 0) $maxVol = 1;

    for ($i = 0; $i < $numCandles; $i++) {
        $c = $candlestickData[$i];
        $v = $volumeData[$i];

        $cX = $plotX1 + $i * $candleWidth + $candleWidth / 2;
        $w = max(1, floor($candleWidth * 0.7));

        $cOpenY = $priceToY($c['open']);
        $cCloseY = $priceToY($c['close']);
        $cHighY = $priceToY($c['high']);
        $cLowY = $priceToY($c['low']);

        $cColor = $v['is_green'] ? $greenColor : $redColor;
        imageline($im, $cX, $cHighY, $cX, $cLowY, $cColor);

        $topY = min($cOpenY, $cCloseY);
        $bottomY = max($cOpenY, $cCloseY);
        if ($bottomY - $topY < 1) {
            $bottomY = $topY + 1;
        }
        imagefilledrectangle($im, $cX - $w/2, $topY, $cX + $w/2, $bottomY, $cColor);

        // Draw Volume bar
        $volHeight = ($v['value'] / $maxVol) * 50;
        $volY1 = $plotY2 - $volHeight;
        $volY2 = $plotY2;
        imagefilledrectangle($im, $cX - $w/2, $volY1, $cX + $w/2, $volY2, $cColor);
    }

    // Draw Markers
    foreach ($patternAlerts as $a) {
        $cIndex = -1;
        for ($i = 0; $i < $numCandles; $i++) {
            if ($a['time'] >= $candlestickData[$i]['time'] && $a['time'] < ($candlestickData[$i]['time'] + 300)) {
                $cIndex = $i;
                break;
            }
        }
        if ($cIndex === -1) continue;

        $c = $candlestickData[$cIndex];
        $cX = $plotX1 + $cIndex * $candleWidth + $candleWidth / 2;

        if ($a['type'] === 'alert_entry') {
            $cLowY = $priceToY($c['low']);
            $arrowY1 = $cLowY + 25;
            $arrowY2 = $cLowY + 10;

            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $yellowColor);
            imageline($im, $cX-1, $arrowY1, $cX-1, $arrowY2, $yellowColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 - 3,
                $cX - 6, $arrowY2 + 3,
                $cX + 6, $arrowY2 + 3
            ], 3, $yellowColor);

            imagestring($im, 2, $cX - 35, $arrowY1 + 4, "ALERT ENTRY", $yellowColor);
        }
        elseif ($a['type'] === 'win_outcome') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 25;
            $arrowY2 = $cHighY - 10;

            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $greenColor);
            imageline($im, $cX-1, $arrowY1, $cX-1, $arrowY2, $greenColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 3,
                $cX - 6, $arrowY2 - 3,
                $cX + 6, $arrowY2 - 3
            ], 3, $greenColor);

            imagestring($im, 2, $cX - 35, $arrowY1 - 15, "WIN OUTCOME", $greenColor);
        }
        elseif ($a['type'] === 'loss_outcome') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 25;
            $arrowY2 = $cHighY - 10;

            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $redColor);
            imageline($im, $cX-1, $arrowY1, $cX-1, $arrowY2, $redColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 3,
                $cX - 6, $arrowY2 - 3,
                $cX + 6, $arrowY2 - 3
            ], 3, $redColor);

            imagestring($im, 2, $cX - 40, $arrowY1 - 15, "LOSS OUTCOME", $redColor);
        }
        elseif ($a['type'] === 'custom') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 20;
            $arrowY2 = $cHighY - 5;

            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $blueColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 2,
                $cX - 4, $arrowY2 - 2,
                $cX + 4, $arrowY2 - 2
            ], 3, $blueColor);

            imagestring($im, 2, $cX - 5, $arrowY1 - 12, $a['text'], $blueColor);
        }
        elseif ($a['type'] === 'new_day') {
            for ($y = $plotY1; $y < $plotY2; $y += 10) {
                imageline($im, $cX, $y, $cX, min($y + 5, $plotY2), $blueColor);
            }
            imagestring($im, 1, $cX - 15, $plotY1 + 10, "New Day", $blueColor);
        }
        elseif ($a['type'] === 'news_event') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 40;
            $arrowY2 = $cHighY - 25;

            $impactVal = isset($a['impact']) ? (int)$a['impact'] : 1;
            if ($impactVal >= 3) {
                $newsColor = $redColor; // highest impact tier
            } elseif ($impactVal === 2) {
                $newsColor = $newsImpact2Color;
            } else {
                $newsColor = $newsImpact1Color;
            }

            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $newsColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 2,
                $cX - 4, $arrowY2 - 2,
                $cX + 4, $arrowY2 - 2
            ], 3, $newsColor);

            // GD's built-in bitmap fonts only support Latin-1, so keep labels ASCII
            $newsLabel = 'NEWS[' . $impactVal . ']: ' . substr($a['text'], 0, 18);
            imagestring($im, 1, $cX - 25, $arrowY1 - 12, $newsLabel, $newsColor);
        }
    }

    // Save or output
    if ($savePath) {
        imagepng($im, $savePath);
    } else {
        header('Content-Type: image/png');
        imagepng($im);
    }

    imagedestroy($im);
    return true;
}
