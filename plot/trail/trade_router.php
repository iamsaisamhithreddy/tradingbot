<?php
/**
 * trade_router.php
 * ---------------------------------------------------
 * Main entry point. This is the file the web server / cron job actually
 * calls. It wires together the 3 supporting modules and handles all
 * request dispatch:
 *   - CLI cron autonomous broadcast runner
 *   - AJAX: save_image / set_pdf_ids
 *   - PDF report generation + Telegram broadcast trigger
 *   - GD chart image streaming endpoints
 *   - Falls through to the browser UI view for normal page loads
 */

require_once __DIR__ . '/bootstrap.php';        // $conn, $isCli, timezones, JSON db helpers
require_once __DIR__ . '/chart_generator.php';  // getCsvPath(), generateTradeChartGD(), news lookup
require_once __DIR__ . '/pdf_broadcast.php';    // TradeReportPDF, generatePdfReport()

// ==========================================
// WIN/LOSS OUTCOME FILTER HELPERS (new)
// ==========================================
/**
 * Normalizes a raw 'outcome' request value to 'win', 'loss', or null.
 * null means "no filter / show everything", i.e. the original,
 * unchanged behavior.
 */
function normalizeOutcomeFilter($raw): ?string {
    $val = strtolower(trim((string)$raw));
    if ($val === 'win' || $val === 'loss') {
        return $val;
    }
    return null; // covers '', 'all', anything else
}

/**
 * Given a list of trade IDs, returns only those whose trade_result in
 * trade_outcome_details matches the given outcome ('win' or 'loss').
 * Trades with no matching row (pending / not yet resolved) are excluded,
 * same as how wins/losses are already isolated elsewhere in this app
 * (e.g. the SQL filter in htf.php).
 */
function filterTradeIdsByOutcome($conn, array $tradeIds, string $outcome): array {
    if (empty($tradeIds)) return [];
    $idsString = implode(',', array_map('intval', $tradeIds));
    $safeOutcome = $conn->real_escape_string($outcome); // already whitelisted to win/loss, escaped defensively anyway

    $query = "SELECT raw_trade_id FROM trade_outcome_details
              WHERE raw_trade_id IN ($idsString) AND LOWER(trade_result) = '$safeOutcome'";
    $res = $conn->query($query);

    $filtered = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $filtered[] = (int)$row['raw_trade_id'];
        }
    }
    return $filtered;
}

// ==========================================
// CLI CRON BROADCAST RUNNER (FULLY AUTONOMOUS)
// ==========================================
if ($isCli && isset($_GET['run_broadcast']) && $_GET['run_broadcast'] == '1') {
    echo "[" . date('Y-m-d H:i:s') . "] Starting autonomous CLI broadcast...\n";

    // Verify GD Library presence to prevent fatal crash
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        echo "ERROR: GD library is not enabled in this PHP environment.\n";
        exit(1);
    }

    // 1. Get IDs
    $tradeIdsRaw = isset($_GET['trade_ids']) ? explode(',', $_GET['trade_ids']) : [];
    $tradeIds = array_filter(array_map('intval', $tradeIdsRaw));

    if (empty($tradeIds)) {
        echo "ERROR: No trade IDs provided.\n";
        exit(1);
    }

    echo "Found " . count($tradeIds) . " trade IDs to process: " . implode(', ', $tradeIds) . "\n";

    $tempDir = __DIR__ . '/temp_images';
    if (!is_dir($tempDir)) {
        if (mkdir($tempDir, 0755, true)) {
            echo "Created temp directory: $tempDir\n";
        } else {
            echo "ERROR: Failed to create temp directory: $tempDir\n";
            exit(1);
        }
    }

    // 2. Generate all images (Server-side)
    foreach ($tradeIds as $id) {
        $filePath = $tempDir . '/trade_' . $id . '.png';
        echo "Generating chart image for trade #{$id}... ";
        $res = generateTradeChartGD($id, $filePath);
        if ($res && file_exists($filePath)) {
            echo "SUCCESS: Saved to " . basename($filePath) . "\n";
        } else {
            echo "FAILED to generate chart image.\n";
        }
    }

    // 3. Set Session-like state for the PDF generator
    $GLOBALS['pdf_trade_ids'] = $tradeIds;

    $_GET['generate_pdf'] = '1';
    $_GET['broadcast'] = '1';
    echo "Dispatched parameters to PDF generator and Telegram broadcast.\n";
}

// ==========================================
// SAVE CHART SCREENSHOT IMAGE (AJAX POST)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['save_image'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// SAVE PDF TRADE IDS TO SESSION (AJAX POST)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['set_pdf_ids']) && isset($_POST['trade_ids'])) {
    header('Content-Type: application/json');
    $tradeIdsRaw = explode(',', $_POST['trade_ids']);
    $tradeIds = array_map('intval', $tradeIdsRaw);
    $tradeIds = array_filter($tradeIds);

    $_SESSION['pdf_trade_ids'] = $tradeIds;
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// LIST/VIEW TRADES FILTERED BY OUTCOME (AJAX, new)
// Usage: ?ajax=1&list_trades=1&outcome=win   (or outcome=loss)
// Purely additive - does not touch the existing "all trades" views.
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['list_trades'])) {
    header('Content-Type: application/json');

    $outcome = normalizeOutcomeFilter($_GET['outcome'] ?? '');
    if ($outcome === null) {
        echo json_encode(['error' => "Missing or invalid 'outcome' parameter. Use 'win' or 'loss'."]);
        exit;
    }

    $query = "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.price_target,
                     o.trade_result, o.win_loss_time, o.win_loss_price
              FROM trade_outcome_details o
              INNER JOIN prediction_trade_data p ON p.raw_trade_id = o.raw_trade_id
              WHERE LOWER(o.trade_result) = '" . $conn->real_escape_string($outcome) . "'
              ORDER BY o.raw_trade_id DESC";
    $res = $conn->query($query);

    $trades = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $trades[] = [
                'id'            => (int)$row['raw_trade_id'],
                'pair'          => $row['pair_name'],
                'direction'     => $row['trade_direction'],
                'targetPrice'   => (float)$row['price_target'],
                'tradeResult'   => $row['trade_result'],
                'winLossTime'   => $row['win_loss_time'],
                'winLossPrice'  => $row['win_loss_price'] !== null ? (float)$row['win_loss_price'] : null,
            ];
        }
    }

    echo json_encode(['success' => true, 'outcome' => $outcome, 'count' => count($trades), 'trades' => $trades]);
    exit;
}

// ==========================================
// GENERATE OUTCOME REPORT PDF (FPDF MULTIPAGE)
// Optional: &outcome=win  or  &outcome=loss narrows the already-selected
// session trade IDs down to just that outcome before compiling/sending.
// Omitting &outcome (or any value other than win/loss) leaves this
// 100% unchanged - all selected trades (wins + losses) go in, as before.
// The CLI cron path ($GLOBALS['pdf_trade_ids']) is untouched either way.
// ==========================================
if (isset($_GET['generate_pdf']) && $_GET['generate_pdf'] == '1') {
    if (!$isCli) {
        $outcomeFilter = normalizeOutcomeFilter($_GET['outcome'] ?? ($_POST['outcome'] ?? ''));
        if ($outcomeFilter !== null && !empty($_SESSION['pdf_trade_ids'])) {
            $_SESSION['pdf_trade_ids'] = filterTradeIdsByOutcome($conn, $_SESSION['pdf_trade_ids'], $outcomeFilter);
        }
    }
    generatePdfReport(); // always exits internally
}

// ==========================================
// STREAK CHECK ENDPOINT (AJAX BATCH via POST)
// Usage: POST ?ajax=1&check_streak=1
//   Body (x-www-form-urlencoded): trade_ids=1,2,3&min_streak=5
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['check_streak'])) {
    header('Content-Type: application/json');

    $minStreak  = max(1, (int)($_POST['min_streak'] ?? $_GET['min_streak'] ?? 1));
    $rawIdsStr  = $_POST['trade_ids'] ?? $_GET['trade_ids'] ?? '';
    $rawIds     = explode(',', $rawIdsStr);
    $tradeIds   = array_values(array_filter(array_map('intval', $rawIds)));

    if (empty($tradeIds)) {
        echo json_encode(['error' => 'No trade_ids provided.']);
        exit;
    }

    $idsStr    = implode(',', $tradeIds);
    $tradeRows = [];

    // Use same JOIN + trigger_unixtime as chart_generator.php so candle lookup aligns
    $res = $conn->query(
        "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.last_alert_time,
                UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
         FROM prediction_trade_data p
         LEFT JOIN raw_trade_data r ON p.raw_trade_id = r.id
         WHERE p.raw_trade_id IN ($idsStr)"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $tradeRows[(int)$row['raw_trade_id']] = $row;
        }
    }

    $results = [];

    foreach ($tradeIds as $tid) {
        if (!isset($tradeRows[$tid])) {
            $results[(string)$tid] = ['meets_streak' => false, 'streak' => 0, 'error' => 'trade not found'];
            continue;
        }

        $trade          = $tradeRows[$tid];
        $alertTimeUTC   = $trade['last_alert_time'] ?? '';
        // Exactly mirrors chart_generator.php line 152
        $alertTimestamp = isset($trade['trigger_unixtime']) && $trade['trigger_unixtime'] > 0
                          ? (int)$trade['trigger_unixtime']
                          : (int)strtotime($alertTimeUTC);

        if ($alertTimestamp <= 0) {
            $results[(string)$tid] = ['meets_streak' => false, 'streak' => 0, 'error' => 'bad alert_time'];
            continue;
        }

        $isUp    = strtoupper($trade['trade_direction']) === 'UP';
        $csvPath = getCsvPath($trade['pair_name'], $alertTimeUTC);

        if (!file_exists($csvPath)) {
            $results[(string)$tid] = ['meets_streak' => false, 'streak' => 0, 'error' => 'csv not found'];
            continue;
        }

        // Large lookback: 50+ candles before signal guaranteed
        $lookback    = 300 * max($minStreak + 30, 50);
        $windowStart = $alertTimestamp - $lookback;
        $windowEnd   = $alertTimestamp + 600;

        $candles  = [];
        $isHeader = true;
        if (($handle = fopen($csvPath, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if ($isHeader) { $isHeader = false; continue; }
                if (count($row) < 5) continue;
                $t = (int)floatval($row[0]);
                if ($t < $windowStart) continue;
                if ($t > $windowEnd)   break;
                $candles[] = [
                    'time'  => $t,
                    'open'  => (float)$row[1],
                    'high'  => (float)$row[2],
                    'low'   => (float)$row[3],
                    'close' => (float)$row[4],
                ];
            }
            fclose($handle);
        }

        if (empty($candles)) {
            $results[(string)$tid] = [
                'meets_streak' => false, 'streak' => 0,
                'error'        => 'no candles in window',
                'alert_ts'     => $alertTimestamp,
                'window_start' => $windowStart,
                'window_end'   => $windowEnd,
            ];
            continue;
        }

        // Find signal candle: bar whose [time, time+300) contains alertTimestamp
        $signalIdx = -1;
        foreach ($candles as $i => $c) {
            if ($alertTimestamp >= $c['time'] && $alertTimestamp < ($c['time'] + 300)) {
                $signalIdx = $i; break;
            }
        }
        // Fallback: last candle with time <= alertTimestamp
        if ($signalIdx === -1) {
            for ($i = count($candles) - 1; $i >= 0; $i--) {
                if ($candles[$i]['time'] <= $alertTimestamp) { $signalIdx = $i; break; }
            }
        }

        if ($signalIdx === -1) {
            $results[(string)$tid] = [
                'meets_streak' => false, 'streak' => 0,
                'error'        => 'signal candle not found',
                'alert_ts'     => $alertTimestamp,
                'first_ts'     => $candles[0]['time'],
                'last_ts'      => $candles[count($candles)-1]['time'],
            ];
            continue;
        }

        // ---------------------------------------------------------------
        // PULLBACK PATTERN DETECTION
        //
        // The pattern (searched in the window ending at the signal candle,
        // with ±1 candle tolerance for late-recorded signals):
        //
        //  [A] At least $minStreak consecutive SAME-COLOR candles  ("trend run")
        //  [B] Then 1 or 2 OPPOSITE-color candles                  ("pullback")
        //       → none of those candles may reach/breach the OPEN
        //         of the LAST trend candle (candle at end of [A])
        //  [C] Then at least 1 candle resuming the ORIGINAL color   ("confirm")
        //       → the signal candle (or candle just before/after it)
        //         is expected to be this confirmation candle
        //
        // We search for the pattern in a window of candles that includes
        // signalIdx and up to 2 candles beyond it (to handle late recording).
        // ---------------------------------------------------------------

        // Helper: is a candle "same direction" as the trade?
        //   UP/BUY  -> needs green  (close > open)
        //   DOWN/SELL -> needs red  (close <= open)
        $isSameTrend = function(array $c) use ($isUp): bool {
            $isGreen = $c['close'] > $c['open'];
            return $isUp ? $isGreen : !$isGreen;
        };
        $isOppTrend = function(array $c) use ($isSameTrend): bool {
            return !$isSameTrend($c);
        };

        // Search window: allow signal candle + 1 more candle as the confirmation candle
        // (because signals can be recorded 1-2 candles late)
        $searchEnd = min(count($candles) - 1, $signalIdx + 1);

        $patternFound   = false;
        $debugInfo      = [];

        // Walk backwards from $searchEnd looking for a [C] confirm candle
        for ($ci = $searchEnd; $ci >= $minStreak + 1 && !$patternFound; $ci--) {
            // [C] candle at $ci must be same-trend
            if (!$isSameTrend($candles[$ci])) continue;

            // [B] scan backwards for 1 or 2 opposite candles immediately before $ci
            $bEnd   = $ci - 1;
            $bStart = $bEnd;
            if ($bEnd < 0) continue;

            if (!$isOppTrend($candles[$bEnd])) continue; // must have at least 1 opp candle

            // Allow up to 2 opposite candles
            if ($bEnd - 1 >= 0 && $isOppTrend($candles[$bEnd - 1])) {
                $bStart = $bEnd - 1;
            }

            // More than 2 opp candles? skip (check candle before bStart)
            if ($bStart - 1 >= 0 && $isOppTrend($candles[$bStart - 1])) {
                continue; // 3+ opposite candles → not the pattern
            }

            // [A] must end at $bStart - 1
            $aEnd = $bStart - 1;
            if ($aEnd < 0) continue;

            // The last trend candle of [A]
            $lastTrendCandle = $candles[$aEnd];
            if (!$isSameTrend($lastTrendCandle)) continue;

            // Count consecutive same-trend candles ending at $aEnd
            $streak = 0;
            for ($i = $aEnd; $i >= 0; $i--) {
                if ($isSameTrend($candles[$i])) { $streak++; } else { break; }
            }

            if ($streak < $minStreak) {
                $debugInfo[] = "ci=$ci streak=$streak < min=$minStreak";
                continue;
            }

            // [B] opposite candles must NOT reach the open of the last trend candle
            $trendOpen    = $lastTrendCandle['open'];
            $pullbackOK   = true;

            for ($i = $bStart; $i <= $bEnd; $i++) {
                $pc = $candles[$i];
                // For UP trade: pullback candles are bearish → check if their
                //   low (wick) reaches below the open of the last green trend candle
                // For DOWN trade: pullback candles are bullish → check if their
                //   high (wick) exceeds the open of the last red trend candle
                if ($isUp) {
                    // Low wick must stay AT OR ABOVE the trend candle's open
                    $breach = $pc['low'] < $trendOpen;
                } else {
                    // High wick must stay AT OR BELOW the trend candle's open
                    $breach = $pc['high'] > $trendOpen;
                }
                if ($breach) {
                    $pullbackOK = false;
                    $debugInfo[] = "ci=$ci pullback candle $i breached trend open $trendOpen";
                    break;
                }
            }

            if (!$pullbackOK) continue;

            // Pattern found!
            $patternFound = true;
            $debugInfo[]  = "MATCH: ci=$ci aEnd=$aEnd streak=$streak bStart=$bStart bEnd=$bEnd";
        }

        $results[(string)$tid] = [
            'meets_streak'  => $patternFound,
            'streak'        => $patternFound ? ($streak ?? 0) : 0,
            'signal_idx'    => $signalIdx,
            'total_candles' => count($candles),
            'debug'         => $debugInfo,
        ];
    }

    echo json_encode(['success' => true, 'min_streak' => $minStreak, 'total' => count($tradeIds), 'results' => $results]);
    exit;
}

// ==========================================
// BATCH CANDLE COUNT ENDPOINT
// Usage: POST ?ajax=1&get_candle_counts=1
//   Body: trade_ids=1,2,3,...
// Returns: per-trade candle count (signal → resolution, from CSV timestamps)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['get_candle_counts'])) {
    header('Content-Type: application/json');

    $rawIdsStr = $_POST['trade_ids'] ?? $_GET['trade_ids'] ?? '';
    $rawIds    = explode(',', $rawIdsStr);
    $tradeIds  = array_values(array_filter(array_map('intval', $rawIds)));

    if (empty($tradeIds)) {
        echo json_encode(['error' => 'No trade_ids provided.']);
        exit;
    }

    $idsStr = implode(',', $tradeIds);

    // Fetch all required fields in one query
    $res = $conn->query(
        "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.last_alert_time,
                o.trade_result, o.win_loss_time,
                UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
         FROM prediction_trade_data p
         LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
         LEFT JOIN raw_trade_data r        ON p.raw_trade_id = r.id
         WHERE p.raw_trade_id IN ($idsStr)"
    );

    $tradeRows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $tradeRows[(int)$row['raw_trade_id']] = $row;
        }
    }

    $results = [];

    foreach ($tradeIds as $tid) {
        if (!isset($tradeRows[$tid])) {
            $results[(string)$tid] = ['candle_count' => null, 'error' => 'not found'];
            continue;
        }

        $trade        = $tradeRows[$tid];
        $alertTimeUTC = $trade['last_alert_time'] ?? '';

        // Alert timestamp: prefer trigger_unixtime (exact cron fire), fall back to alert_time
        $alertTimestamp = isset($trade['trigger_unixtime']) && $trade['trigger_unixtime'] > 0
                          ? (int)$trade['trigger_unixtime']
                          : (int)strtotime($alertTimeUTC);

        // Alert time in IST for display
        $alertTimeIST = 'N/A';
        try {
            $alertTimeIST = (new DateTime($alertTimeUTC, $utcTimezone))->setTimezone($istTimezone)->format('d M H:i');
        } catch (Exception $e) {}

        // Resolution time
        $winLossTimestampVal = null;
        $winLossTimeIST      = null;
        if (!empty($trade['win_loss_time'])) {
            try {
                $dt = new DateTime($trade['win_loss_time'], $istTimezone);
                $winLossTimestampVal = $dt->getTimestamp();
                $winLossTimeIST      = $dt->format('d M H:i');
            } catch (Exception $e) {}
        }

        // No resolution yet → candle count is null
        if ($winLossTimestampVal === null || $alertTimestamp <= 0) {
            $results[(string)$tid] = [
                'candle_count'   => null,
                'alert_time_ist' => $alertTimeIST,
                'res_time_ist'   => $winLossTimeIST,
                'trade_result'   => $trade['trade_result'] ?? 'pending',
                'pair'           => $trade['pair_name'],
                'direction'      => $trade['trade_direction'],
            ];
            continue;
        }

        // Read candle timestamps from CSV
        $csvPath     = getCsvPath($trade['pair_name'], $alertTimeUTC);
        $candleCount = null;

        if (file_exists($csvPath)) {
            $windowStart = $alertTimestamp - 600;
            $windowEnd   = $winLossTimestampVal + 600;

            $csvCandles = [];
            $isHeader   = true;
            if (($handle = fopen($csvPath, 'r')) !== false) {
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    if ($isHeader) { $isHeader = false; continue; }
                    if (count($row) < 5) continue;
                    $t = (int)floatval($row[0]);
                    if ($t < $windowStart) continue;
                    if ($t > $windowEnd)   break;
                    $csvCandles[] = $t;
                }
                fclose($handle);
            }

            if (!empty($csvCandles)) {
                // Signal candle
                $signalIdx = -1;
                foreach ($csvCandles as $i => $ct) {
                    if ($alertTimestamp >= $ct && $alertTimestamp < ($ct + 300)) { $signalIdx = $i; break; }
                }
                if ($signalIdx === -1) {
                    for ($i = count($csvCandles) - 1; $i >= 0; $i--) {
                        if ($csvCandles[$i] <= $alertTimestamp) { $signalIdx = $i; break; }
                    }
                }

                // Resolution candle
                $resIdx = -1;
                foreach ($csvCandles as $i => $ct) {
                    if ($winLossTimestampVal >= $ct && $winLossTimestampVal < ($ct + 300)) { $resIdx = $i; break; }
                }
                if ($resIdx === -1) {
                    for ($i = count($csvCandles) - 1; $i >= 0; $i--) {
                        if ($csvCandles[$i] <= $winLossTimestampVal) { $resIdx = $i; break; }
                    }
                }

                if ($signalIdx !== -1 && $resIdx !== -1 && $resIdx >= $signalIdx) {
                    $candleCount = $resIdx - $signalIdx + 1;
                }
            }
        }

        $results[(string)$tid] = [
            'candle_count'   => $candleCount,
            'alert_time_ist' => $alertTimeIST,
            'res_time_ist'   => $winLossTimeIST,
            'trade_result'   => $trade['trade_result'] ?? 'pending',
            'pair'           => $trade['pair_name'],
            'direction'      => $trade['trade_direction'],
        ];
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// ==========================================
// GD CHART GENERATION & STREAMING ENDPOINTS
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['trade_id'])) {
    header('Content-Type: application/json');
    $tradeId = (int)$_GET['trade_id'];

    // Join outcome details to supply user with computed win/loss markers
    $query = "SELECT p.*, o.trade_result, o.win_loss_time, o.win_loss_price,
                     UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
              FROM prediction_trade_data p
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.raw_trade_id = " . (int)$tradeId . " LIMIT 1";
    $res = $conn->query($query);
    $tradeRes = $res ? $res->fetch_assoc() : null;

    if (!$tradeRes) {
        echo json_encode(['error' => 'Trade not found in database.']);
        exit;
    }

    // Generate and save GD image to temp_images/trade_XX.png immediately on server
    $tempDir = __DIR__ . '/temp_images';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $filePath = $tempDir . '/trade_' . $tradeId . '.png';
    generateTradeChartGD($tradeId, $filePath);

    $alertTimeUTC = $tradeRes['last_alert_time'] ?? '';
    try {
        $alertTimeIST = (new DateTime($alertTimeUTC, $utcTimezone))->setTimezone($istTimezone)->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $alertTimeIST = 'N/A';
    }

    $winLossTimeIST = null;
    $winLossTimestampVal = null;
    if (!empty($tradeRes['win_loss_time'])) {
        try {
            $winLossDT = new DateTime($tradeRes['win_loss_time'], $istTimezone);
            $winLossTimeIST = $winLossDT->format('Y-m-d H:i:s');
            $winLossTimestampVal = $winLossDT->getTimestamp();
        } catch (Exception $e) {
            $winLossTimeIST = $tradeRes['win_loss_time'];
        }
    }

    // Economic news within +/-15 minutes of the win/loss resolution (highest impact only)
    $nearbyNews = null;
    if ($winLossTimestampVal !== null) {
        $rawNearbyNews = getNearbyEconomicEvents($conn, $istTimezone, $winLossTimestampVal, 15);
        $topEvent = pickHighestImpactEvent($rawNearbyNews);
        if ($topEvent !== null) {
            $nearbyNews = [
                'event_name'     => $topEvent['event_name'],
                'impact'         => $topEvent['impact'],
                'event_time_ist' => $topEvent['event_time_ist'],
                'offset_minutes' => $topEvent['offset_minutes'],
            ];
        }
    }

    // -------------------------------------------------------
    // CANDLE COUNT: signal candle → resolution candle (inclusive)
    // Counted from CSV timestamps — NOT from DB timestamps,
    // so delayed DB entries don't affect the count.
    // -------------------------------------------------------
    $candleCount = null;
    $alertTimestamp = isset($tradeRes['trigger_unixtime']) && $tradeRes['trigger_unixtime'] > 0
                      ? (int)$tradeRes['trigger_unixtime']
                      : (int)strtotime($alertTimeUTC);

    if ($winLossTimestampVal !== null && $alertTimestamp > 0) {
        $csvPath = getCsvPath($tradeRes['pair_name'], $alertTimeUTC);
        if (file_exists($csvPath)) {
            // Read candles from signal time to resolution time + 2 extra bars buffer
            $windowStart = $alertTimestamp - 600;   // 2 bars before signal
            $windowEnd   = $winLossTimestampVal + 600; // 2 bars after resolution

            $csvCandles = [];
            $isHeader = true;
            if (($handle = fopen($csvPath, 'r')) !== false) {
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    if ($isHeader) { $isHeader = false; continue; }
                    if (count($row) < 5) continue;
                    $t = (int)floatval($row[0]);
                    if ($t < $windowStart) continue;
                    if ($t > $windowEnd)   break;
                    $csvCandles[] = $t;
                }
                fclose($handle);
            }

            if (!empty($csvCandles)) {
                // Find signal candle index: bar whose [time, time+300) contains alertTimestamp
                $signalCandleIdx = -1;
                foreach ($csvCandles as $i => $ct) {
                    if ($alertTimestamp >= $ct && $alertTimestamp < ($ct + 300)) {
                        $signalCandleIdx = $i; break;
                    }
                }
                // Fallback: last candle with time <= alertTimestamp
                if ($signalCandleIdx === -1) {
                    for ($i = count($csvCandles) - 1; $i >= 0; $i--) {
                        if ($csvCandles[$i] <= $alertTimestamp) { $signalCandleIdx = $i; break; }
                    }
                }

                // Find resolution candle index: bar whose [time, time+300) contains winLossTimestamp
                $resCandleIdx = -1;
                foreach ($csvCandles as $i => $ct) {
                    if ($winLossTimestampVal >= $ct && $winLossTimestampVal < ($ct + 300)) {
                        $resCandleIdx = $i; break;
                    }
                }
                // Fallback: last candle with time <= winLossTimestamp
                if ($resCandleIdx === -1) {
                    for ($i = count($csvCandles) - 1; $i >= 0; $i--) {
                        if ($csvCandles[$i] <= $winLossTimestampVal) { $resCandleIdx = $i; break; }
                    }
                }

                // Count = inclusive span from signal candle to resolution candle
                if ($signalCandleIdx !== -1 && $resCandleIdx !== -1 && $resCandleIdx >= $signalCandleIdx) {
                    $candleCount = $resCandleIdx - $signalCandleIdx + 1;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'pair' => $tradeRes['pair_name'],
        'direction' => $tradeRes['trade_direction'],
        'alertTimeIST' => $alertTimeIST,
        'tradeResult' => $tradeRes['trade_result'] ?? 'pending',
        'winLossTime' => $winLossTimeIST,
        'winLossPrice' => $tradeRes['win_loss_price'] !== null ? (float)$tradeRes['win_loss_price'] : null,
        'targetPrice' => (float)$tradeRes['price_target'],
        'nearbyNews' => $nearbyNews,
        'candleCount' => $candleCount,   // null = pending/no resolution yet
    ]);
    exit;
}

// GET dynamic chart image streaming endpoint
if (isset($_GET['get_chart_image']) && isset($_GET['trade_id'])) {
    $tradeId = (int)$_GET['trade_id'];
    generateTradeChartGD($tradeId);
    exit;
}

// ==========================================
// RENDER UI (only for real browser requests, not CLI/AJAX/PDF/image endpoints)
// ==========================================
if (!$isCli) {
    require __DIR__ . '/trade_plotter_view.php';
}