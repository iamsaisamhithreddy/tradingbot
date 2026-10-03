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

    echo json_encode([
        'success' => true,
        'pair' => $tradeRes['pair_name'],
        'direction' => $tradeRes['trade_direction'],
        'alertTimeIST' => $alertTimeIST,
        'tradeResult' => $tradeRes['trade_result'] ?? 'pending',
        'winLossTime' => $winLossTimeIST,
        'winLossPrice' => $tradeRes['win_loss_price'] !== null ? (float)$tradeRes['win_loss_price'] : null,
        'targetPrice' => (float)$tradeRes['price_target'],
        'nearbyNews' => $nearbyNews
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