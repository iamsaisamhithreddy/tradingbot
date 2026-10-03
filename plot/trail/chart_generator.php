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
function getCsvPath(string $pairName, $alertTimeValue): string {
    $cleanPair = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($pairName)));
    $fileName = 'FX_' . $cleanPair . '.csv';

    $timestamp = is_numeric($alertTimeValue) ? (int)$alertTimeValue : strtotime((string)$alertTimeValue);
    if ($timestamp > 100000000000000) $timestamp = (int)($timestamp / 1000000);
    elseif ($timestamp > 100000000000) $timestamp = (int)($timestamp / 1000);

    $dateIST = $timestamp > 0
        ? (new DateTime('@'.$timestamp))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d')
        : null;

    $roots = [
        __DIR__, __DIR__.'/dataset', __DIR__.'/dataset/dataset',
        dirname(__DIR__), dirname(__DIR__).'/dataset', dirname(__DIR__).'/dataset/dataset',
        __DIR__.'/trail', __DIR__.'/trail/dataset', __DIR__.'/trail/dataset/dataset',
        dirname(__DIR__).'/trail', dirname(__DIR__).'/trail/dataset',
        ($_SERVER['DOCUMENT_ROOT'] ?? '')
    ];
    $roots = array_values(array_unique(array_filter($roots, 'is_dir')));

    $candidates = [];
    foreach ($roots as $root) {
        $candidates[] = $root.'/'.$fileName;
        $candidates[] = $root.'/dataset/'.$fileName;
        $candidates[] = $root.'/JUN-2025 TO FEB-2026/'.$fileName;
        $candidates[] = $root.'/historical/'.$fileName;
        $candidates[] = $root.'/archive/'.$fileName;
        if ($dateIST) {
            // Date-partitioned dataset layouts used by the live chart system.
            $candidates[] = $root.'/'.$cleanPair.'/'.$dateIST.'/'.$fileName;
            $candidates[] = $root.'/'.$cleanPair.'/'.$dateIST.'.csv';
            $candidates[] = $root.'/'.$cleanPair.'/FX_'.$cleanPair.'-'.$dateIST.'.csv';
            $candidates[] = $root.'/'.$cleanPair.'/FX_'.$cleanPair.'_'.$dateIST.'.csv';
            $candidates[] = $root.'/'.$dateIST.'/'.$fileName;
            $candidates[] = $root.'/FX_'.$cleanPair.'-'.$dateIST.'.csv';
            $candidates[] = $root.'/FX_'.$cleanPair.'_'.$dateIST.'.csv';
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            error_log('[CHART CSV FOUND] '.$candidate);
            return $candidate;
        }
    }

    foreach ($roots as $root) {
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $name = $f->getFilename();
                // Prefer the exact dated file for this alert date.
                if ($dateIST && strcasecmp($name, 'FX_'.$cleanPair.'-'.$dateIST.'.csv') === 0) {
                    error_log('[CHART DATED CSV FOUND RECURSIVELY] '.$f->getPathname());
                    return $f->getPathname();
                }
                if ($dateIST && strcasecmp($name, 'FX_'.$cleanPair.'_'.$dateIST.'.csv') === 0) {
                    error_log('[CHART DATED CSV FOUND RECURSIVELY] '.$f->getPathname());
                    return $f->getPathname();
                }
            }
        } catch (Throwable $e) {
            error_log('[CHART SEARCH ERROR] '.$e->getMessage());
        }
    }

    foreach ($roots as $root) {
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && strcasecmp($f->getFilename(), $fileName) === 0) {
                    error_log('[CHART CSV FOUND RECURSIVELY] '.$f->getPathname());
                    return $f->getPathname();
                }
            }
        } catch (Throwable $e) {
            error_log('[CHART SEARCH ERROR] '.$e->getMessage());
        }
    }

    error_log('[CHART CSV NOT FOUND] pair='.$cleanPair.' file='.$fileName.' timestamp='.$timestamp.' dateIST='.($dateIST ?: 'N/A').' __DIR__='.__DIR__);
    return '';
}

// ==========================================
// GD CHART RENDERER ENGINE
// ==========================================
function generateTradeChartGD($tradeId, $savePath = null) {
    global $conn, $istTimezone, $utcTimezone;

    // 1. Fetch trade info
    $query = "SELECT p.*, o.trade_result, o.win_loss_time, o.win_loss_price,
                     UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
              FROM prediction_trade_data p
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.raw_trade_id = " . (int)$tradeId . " LIMIT 1";
    $res = $conn->query($query);
    $tradeRes = $res ? $res->fetch_assoc() : null;
    if (!$tradeRes) {
        error_log("CHART TRADE NOT FOUND: tradeId={$tradeId}");
        return false;
    }

    $alertTimeUTC = $tradeRes['last_alert_time'] ?? '';
    if (empty($alertTimeUTC) || $alertTimeUTC === '0000-00-00 00:00:00') {
        error_log("CHART ALERT TIME MISSING: tradeId={$tradeId}");
        return false;
    }

    $alertTimestamp = isset($tradeRes['trigger_unixtime']) ? (int)$tradeRes['trigger_unixtime'] : strtotime($alertTimeUTC);
    if (!$alertTimestamp) {
        error_log("CHART ALERT TIMESTAMP INVALID: tradeId={$tradeId}, alertTime=" . $alertTimeUTC);
        return false;
    }

    $csvPath = getCsvPath($tradeRes['pair_name'], $alertTimestamp);
    if (empty($csvPath) || !file_exists($csvPath)) {
        error_log("CHART CSV NOT FOUND: tradeId={$tradeId}, pair=" . ($tradeRes['pair_name'] ?? '') . ", alertTimestamp={$alertTimestamp}, resolvedPath=" . ($csvPath ?: '[empty]'));
        return false;
    }

    $candlestickData = [];
    $volumeData = [];
    $patternAlerts = [];

    $windowStart = $alertTimestamp - 14400; // 4 hours before
    $windowEnd   = $alertTimestamp + 28800; // 8 hours after

    $last_day = null;
    $is_header = true;

    if (($handle = fopen($csvPath, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ',');
        if ($header !== false) {
            $header = array_map(function($v) { return strtolower(trim(str_replace('\"', '', (string)$v))); }, $header);
            $timeIdx = array_search('time', $header);
            if ($timeIdx === false) $timeIdx = array_search('timestamp', $header);
            $openIdx = array_search('open', $header);
            $highIdx = array_search('high', $header);
            $lowIdx = array_search('low', $header);
            $closeIdx = array_search('close', $header);
            $patternIdx = array_search('pattern_alert', $header);
            if ($patternIdx === false) $patternIdx = array_search('pattern', $header);
            $volumeIdx = array_search('volume', $header);
            $headerLooksValid = ($timeIdx !== false && $openIdx !== false && $closeIdx !== false);
            if (!$headerLooksValid) {
                rewind($handle);
                $timeIdx=0; $openIdx=1; $highIdx=2; $lowIdx=3; $closeIdx=4; $patternIdx=5; $volumeIdx=6;
            }
            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                if (count($row) < 5) continue;
                $rawTime = $row[$timeIdx] ?? '';
                if (is_numeric(trim((string)$rawTime))) {
                    $time = (int)floatval($rawTime);
                } else {
                    $parsed = strtotime(trim((string)$rawTime));
                    $time = $parsed ? $parsed : 0;
                }
            if ($time > 100000000000000) $time = (int)($time / 1000000);
            elseif ($time > 100000000000) $time = (int)($time / 1000);
            if ($time < $windowStart) continue;
            if ($time > $windowEnd) break;

            if ($time <= 0) continue;
            $open = (float)($row[$openIdx] ?? 0);
            $high = (float)($row[$highIdx] ?? 0);
            $low = (float)($row[$lowIdx] ?? 0);
            $close = (float)($row[$closeIdx] ?? 0);
            $pattern_alert = $patternIdx !== false ? trim((string)($row[$patternIdx] ?? '')) : '';
            $volume = $volumeIdx !== false ? (float)($row[$volumeIdx] ?? 0) : 0;

            $dayDt = new DateTime('@' . $time);
            $dayDt->setTimezone($istTimezone);
            $current_day = $dayDt->format('Y-m-d');
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
        }
        fclose($handle);
    }

    // Alert entry marker
    $patternAlerts[] = [
        'time' => $alertTimestamp,
        'type' => 'alert_entry',
        'text' => 'ALERT ENTRY'
    ];

    // Win/Loss Outcome marker
    $winLossTimestamp = null;
    if (!empty($tradeRes['win_loss_time'])) {
        try {
            $winLossDT = new DateTime($tradeRes['win_loss_time'], $istTimezone);
            $winLossTimestamp = $winLossDT->getTimestamp();
        } catch (Exception $e) {}
    }

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

    // Calculate the X coordinate for 3 candles before the Alert Entry candle
    $alertStartX = $plotX1; // Fallback to the left edge just in case
    for ($i = 0; $i < $numCandles; $i++) {
        if ($alertTimestamp >= $candlestickData[$i]['time'] && $alertTimestamp < ($candlestickData[$i]['time'] + 300)) {
            // Subtract 3 candles, clamping at 0 so it doesn't go out of bounds
            $startIndex = max(0, $i - 3);
            $alertStartX = $plotX1 + $startIndex * $candleWidth + $candleWidth / 2;
            break;
        }
    }

    // Draw target horizontal dashed line in red starting 3 candles before the alert
    $targetPriceY = $priceToY((float)$tradeRes['price_target']);
    for ($x = $alertStartX; $x < $plotX2; $x += 10) {
        imageline($im, $x, $targetPriceY, min($x + 5, $plotX2), $targetPriceY, $redColor);
    }
    imagestring($im, 2, $plotX2 - 180, $targetPriceY - 14, "TARGET: " . number_format($tradeRes['price_target'], 5), $redColor);

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
