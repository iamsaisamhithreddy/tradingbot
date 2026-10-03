<?php
ob_start();
error_reporting(E_ALL);
// Toggle: add ?debug=1 to the URL to see raw PHP errors on screen
$DEBUG_MODE = isset($_GET['debug']) && $_GET['debug'] === '1';
ini_set('display_errors', $DEBUG_MODE ? 1 : 0);
ini_set('log_errors', 1);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "FATAL: {$error['message']} in {$error['file']} on line {$error['line']}"]);
        } else {
            echo "<pre style='color:red;background:#1a0000;padding:20px;'>FATAL ERROR: " . htmlspecialchars($error['message']) . " in {$error['file']} on line {$error['line']}</pre>";
        }
    }
});

require 'db.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ==========================================
// DATASET ROUTING
// ==========================================
function getCsvPath(string $pairName, string $alertTimeStr): string {
    $cleanPair = str_replace(['/', '_', ' '], '', $pairName);
    $fileName = "FX_" . $cleanPair . ".csv";
    $timestamp = strtotime($alertTimeStr);
    $cutoffEnd = strtotime('2026-03-01 00:00:00');
    $baseDir = __DIR__ . '/dataset';

    $path = ($timestamp !== false && $timestamp < $cutoffEnd)
        ? $baseDir . "/JUN-2025 TO FEB-2026/" . $fileName
        : $baseDir . "/" . $fileName;

    if (file_exists($path)) return $path;
    $fallback1 = $baseDir . "/dataset/" . $fileName;
    if (file_exists($fallback1)) return $fallback1;
    $fallback2 = ($timestamp !== false && $timestamp < $cutoffEnd)
        ? $baseDir . "/" . $fileName
        : $baseDir . "/JUN-2025 TO FEB-2026/" . $fileName;
    if (file_exists($fallback2)) return $fallback2;
    return $path;
}

// ==========================================
// HTF ENGINE — returns status + candle arrays for charting
// ==========================================
function getHtfChartData($pairName, $alertTimeStr, $tradeDirection) {
    $out = ['status' => 'INVALID_DATE', 'htfTrend' => null, 'candles5m' => [], 'candles15m' => []];

    if (empty($alertTimeStr) || $alertTimeStr === '0000-00-00 00:00:00') return $out;

    $csvPath = getCsvPath($pairName, $alertTimeStr);
    if (!file_exists($csvPath)) { $out['status'] = 'MISSING_CSV'; return $out; }

    try {
        $dtUTC = new DateTime($alertTimeStr, new DateTimeZone('UTC'));
        $alertTimestamp = $dtUTC->getTimestamp();
    } catch (Exception $e) { return $out; }

    $handle = @fopen($csvPath, "r");
    if ($handle === false) { $out['status'] = 'CSV_READ_ERROR'; return $out; }

    $windowStart = $alertTimestamp - 3600; // 1hr back is enough for the last closed 15m candle
    $candlestickData = [];
    $is_header = true;
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if ($is_header) { $is_header = false; continue; }
        if (count($row) < 5) continue;
        $time = (int)floatval($row[0]);
        if ($time < $windowStart) continue;
        if ($time > $alertTimestamp) break;
        $candlestickData[] = ['time' => $time, 'open' => (float)$row[1], 'high' => (float)$row[2], 'low' => (float)$row[3], 'close' => (float)$row[4]];
    }
    fclose($handle);

    if (empty($candlestickData)) { $out['status'] = 'NO_CANDLES'; return $out; }
    $out['candles5m'] = $candlestickData;

    // Aggregate 5m -> 15m
    $htfCandles = [];
    $tempCandle = null;
    $currentBucket = null;
    foreach ($candlestickData as $c) {
        $bucketTime = floor($c['time'] / 900) * 900;
        if ($currentBucket !== $bucketTime) {
            if ($tempCandle !== null) $htfCandles[] = $tempCandle;
            $currentBucket = $bucketTime;
            $tempCandle = ['time' => $bucketTime, 'open' => $c['open'], 'high' => $c['high'], 'low' => $c['low'], 'close' => $c['close']];
        } else {
            $tempCandle['high']  = max($tempCandle['high'], $c['high']);
            $tempCandle['low']   = min($tempCandle['low'], $c['low']);
            $tempCandle['close'] = $c['close'];
        }
    }
    if ($tempCandle !== null) $htfCandles[] = $tempCandle;
    $out['candles15m'] = $htfCandles;

    $alertBucket = floor($alertTimestamp / 900) * 900;
    $preAlertCandles = array_values(array_filter($htfCandles, fn($c) => $c['time'] < $alertBucket));

    if (count($preAlertCandles) < 1) { $out['status'] = 'INSUFFICIENT_DATA'; return $out; }

    $last15m = $preAlertCandles[count($preAlertCandles) - 1];
    $htfTrend = ($last15m['close'] >= $last15m['open']) ? 'UP' : 'DOWN';
    $out['htfTrend'] = $htfTrend;
    $out['status'] = ($tradeDirection === $htfTrend) ? 'ALIGNED' : 'AGAINST';
    return $out;
}

// Lightweight version used only by the aggregate scanner (no candle arrays kept in memory)
function checkHtfAlignmentFactor($pairName, $alertTimeStr, $tradeDirection) {
    $r = getHtfChartData($pairName, $alertTimeStr, $tradeDirection);
    return $r['status'];
}

// ==========================================
// AJAX: AGGREGATE SCAN BATCH (counts only — win/loss trades only)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && !isset($_GET['mode'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    set_time_limit(120);

    try {
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

        $alignedCount = 0; $againstCount = 0; $skippedCount = 0; $skippedReasons = [];
        $alignedWins = 0; $alignedLosses = 0; $againstWins = 0; $againstLosses = 0;

        $sql = "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.last_alert_time,
                       COALESCE(o.trade_result, p.trade_result) AS final_result
                FROM prediction_trade_data p
                LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
                WHERE COALESCE(o.trade_result, p.trade_result) IN ('win', 'loss')
                ORDER BY p.raw_trade_id ASC
                LIMIT {$limit} OFFSET {$offset}";

        $result = $conn->query($sql);
        if (!$result) throw new Exception("Database query failed: " . $conn->error);

        $processedThisBatch = 0;
        while ($row = $result->fetch_assoc()) {
            $status = checkHtfAlignmentFactor($row['pair_name'], $row['last_alert_time'], strtoupper($row['trade_direction']));
            $outcome = strtolower($row['final_result'] ?? '');

            if ($status === 'ALIGNED') {
                $alignedCount++;
                if ($outcome === 'win') $alignedWins++; elseif ($outcome === 'loss') $alignedLosses++;
            } elseif ($status === 'AGAINST') {
                $againstCount++;
                if ($outcome === 'win') $againstWins++; elseif ($outcome === 'loss') $againstLosses++;
            } else {
                $skippedCount++;
                $skippedReasons[$status] = ($skippedReasons[$status] ?? 0) + 1;
            }
            $processedThisBatch++;
        }

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 'aligned' => $alignedCount, 'against' => $againstCount, 'skipped' => $skippedCount,
            'skipped_reasons' => $skippedReasons, 'aligned_wins' => $alignedWins, 'aligned_losses' => $alignedLosses,
            'against_wins' => $againstWins, 'against_losses' => $againstLosses, 'processed' => $processedThisBatch,
            'next_offset' => $offset + $processedThisBatch, 'is_done' => ($processedThisBatch < $limit)
        ]);
        exit;
    } catch (Throwable $e) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine()]);
        exit;
    }
}

// ==========================================
// AJAX: CHART REVIEW BATCH (win/loss trades + candle data for plotting)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['mode']) && $_GET['mode'] === 'charts') {
    ini_set('display_errors', 0);
    set_time_limit(120);
    try {
        $offset = intval($_GET['offset'] ?? 0);
        $limit  = min(intval($_GET['limit'] ?? 15), 30);
        $pairFilter  = $_GET['pair'] ?? '';
        $alignFilter = $_GET['align'] ?? '';   // '', 'ALIGNED', 'AGAINST'
        $resultFilter = $_GET['result'] ?? ''; // '', 'win', 'loss'

        $where = ["COALESCE(o.trade_result, p.trade_result) IN ('win', 'loss')"];
        $params = []; $types = '';
        if ($pairFilter !== '') { $where[] = "p.pair_name = ?"; $params[] = $pairFilter; $types .= 's'; }
        if ($resultFilter !== '') { $where[] = "COALESCE(o.trade_result, p.trade_result) = ?"; $params[] = $resultFilter; $types .= 's'; }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.last_alert_time,
                       COALESCE(o.trade_result, p.trade_result) AS final_result
                FROM prediction_trade_data p
                LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
                {$whereSql}
                ORDER BY p.raw_trade_id ASC
                LIMIT {$limit} OFFSET {$offset}";

        if ($types !== '') {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conn->query($sql);
        }

        if (!$result) throw new Exception("Database query failed: " . $conn->error);

        $trades = [];
        $processedThisBatch = 0;

        while ($row = $result->fetch_assoc()) {
            $processedThisBatch++;
            $dir = strtoupper($row['trade_direction']);
            $chart = getHtfChartData($row['pair_name'], $row['last_alert_time'], $dir);
            $outcome = strtolower($row['final_result'] ?? '');

            if ($alignFilter !== '' && $chart['status'] !== $alignFilter) continue;

            $trades[] = [
                'raw_trade_id' => $row['raw_trade_id'],
                'pair_name'    => $row['pair_name'],
                'direction'    => $dir,
                'alert_time'   => $row['last_alert_time'],
                'result'       => $outcome,
                'status'       => $chart['status'],
                'htf_trend'    => $chart['htfTrend'],
                'candles5m'    => $chart['candles5m'],
                'candles15m'   => $chart['candles15m'],
            ];
        }

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 'trades' => $trades, 'processed' => $processedThisBatch,
            'next_offset' => $offset + $processedThisBatch, 'is_done' => ($processedThisBatch < $limit)
        ]);
        exit;
    } catch (Throwable $e) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine()]);
        exit;
    }
}

// ==========================================
// INITIAL PAGE LOAD (win/loss totals + pair list)
// ==========================================
$totalQuery = $conn->query("
    SELECT COUNT(*) as total FROM prediction_trade_data p
    LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
    WHERE COALESCE(o.trade_result, p.trade_result) IN ('win', 'loss')
");
$totalTrades = $totalQuery->fetch_assoc()['total'];

$pairsQuery = $conn->query("SELECT DISTINCT pair_name FROM prediction_trade_data ORDER BY pair_name ASC");
$allPairs = [];
while ($p = $pairsQuery->fetch_assoc()) $allPairs[] = $p['pair_name'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HTF Alignment Master Scan</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
    :root {
        --bg-primary:#0b0f19; --bg-secondary:#131b2e; --bg-tertiary:#1e293b;
        --accent-blue:#3b82f6; --accent-green:#10b981; --accent-red:#ef4444; --accent-gray:#64748b;
        --text-main:#f8fafc; --text-secondary:#94a3b8; --border-color:#334155;
    }
    body{font-family:'Outfit',sans-serif;background:var(--bg-primary);color:var(--text-main);margin:0;padding:40px 20px;}
    .container{max-width:1100px;margin:auto;}
    header{text-align:center;margin-bottom:40px;}
    h1{margin:0;font-size:36px;background:linear-gradient(to right,#60a5fa,#3b82f6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
    .controls{display:flex;justify-content:center;gap:15px;margin-bottom:30px;flex-wrap:wrap;}
    .btn{padding:12px 25px;border-radius:8px;border:none;font-size:16px;font-weight:bold;cursor:pointer;color:#fff;transition:opacity .2s;}
    .btn:hover{opacity:.9;} .btn:disabled{opacity:.5;cursor:not-allowed;}
    .btn-start{background:var(--accent-blue);box-shadow:0 4px 15px rgba(59,130,246,.4);}
    .btn-pdf{background:var(--accent-green);}

    .progress-box{background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:25px;margin-bottom:30px;}
    .progress-bar{width:100%;height:20px;background:var(--bg-primary);border-radius:10px;overflow:hidden;margin-top:15px;border:1px solid var(--border-color);}
    .progress-fill{width:0%;height:100%;background:var(--accent-blue);transition:width .3s;}

    .stats-grid{display:flex;gap:20px;margin-bottom:40px;}
    .stat-card{flex:1;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:25px;text-align:center;position:relative;}
    .stat-card h3{margin:0 0 10px;font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:1px;}
    .stat-card .factor-value{font-size:50px;font-weight:800;color:#60a5fa;margin:0;}
    .stat-card .sub-value{font-size:15px;color:var(--text-secondary);margin-top:5px;}
    .win-loss-bar{display:flex;justify-content:space-between;margin-top:15px;background:var(--bg-primary);border-radius:6px;padding:10px;border:1px solid var(--border-color);font-size:14px;font-weight:bold;}
    .wl-win{color:var(--accent-green);} .wl-loss{color:var(--accent-red);}

    #errorLog{color:#fca5a5;font-size:14px;margin-top:15px;text-align:left;display:none;background:rgba(239,68,68,.1);padding:10px;border-radius:6px;border:1px dashed rgba(239,68,68,.4);white-space:pre-wrap;word-break:break-word;}
    #skipBreakdown{color:var(--text-secondary);font-size:13px;margin-top:15px;text-align:left;display:none;background:var(--bg-primary);padding:10px;border-radius:6px;border:1px solid var(--border-color);white-space:pre-wrap;}

    .section-title{font-size:22px;font-weight:700;margin:40px 0 15px;}
    .chart-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:center;background:var(--bg-secondary);border:1px solid var(--border-color);padding:18px;border-radius:12px;margin-bottom:20px;}
    select,input{background:var(--bg-tertiary);color:var(--text-main);border:1px solid var(--border-color);border-radius:6px;padding:8px 10px;font-family:inherit;}

    .trade-card{background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:18px;margin-bottom:16px;page-break-inside:avoid;}
    .trade-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
    .trade-id{font-weight:800;font-size:16px;}
    .trade-meta{color:var(--text-secondary);font-size:13px;}
    .badge{padding:4px 10px;border-radius:20px;font-size:12px;font-weight:bold;}
    .badge-aligned{background:rgba(16,185,129,.15);color:var(--accent-green);}
    .badge-against{background:rgba(239,68,68,.15);color:var(--accent-red);}
    .badge-neutral{background:rgba(100,116,139,.15);color:var(--accent-gray);}
    .badge-win{background:rgba(16,185,129,.15);color:var(--accent-green);}
    .badge-loss{background:rgba(239,68,68,.15);color:var(--accent-red);}
    .charts-row{display:flex;gap:16px;}
    .chart-box{flex:1;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:8px;padding:8px;}
    .chart-label{font-size:11px;color:var(--text-secondary);text-align:center;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}
</style>
</head>
<body>
<div class="container">
    <header>
        <h1>HTF 15m Direction Analyzer</h1>
        <p style="color:var(--text-secondary);">Scanning <?= number_format($totalTrades) ?> WIN/LOSS trades in the database</p>
        <?php if ($DEBUG_MODE): ?><p style="color:#fbbf24;">🐛 DEBUG MODE ON</p><?php endif; ?>
    </header>

    <div class="controls">
        <button class="btn btn-start" id="startBtn" onclick="startScan()">▶ Start Full Database Scan</button>
    </div>

    <div class="progress-box">
        <div style="display:flex;justify-content:space-between;font-weight:bold;">
            <span id="statusText">Ready to scan...</span>
            <span id="progressText">0 / <?= $totalTrades ?> Processed</span>
        </div>
        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
        <div id="errorLog"></div>
        <div id="skipBreakdown"></div>
    </div>

    <div class="stats-grid">
        <div class="stat-card" style="border-top:4px solid var(--accent-blue);">
            <h3>Live Factor Ratio</h3>
            <p class="factor-value" id="ui-factor">0.00</p>
            <p class="sub-value"><span id="ui-percentage">0</span>% 15m Trend Alignment</p>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--accent-green);">
            <h3>Aligned Rows</h3>
            <p class="factor-value" id="ui-aligned" style="color:var(--accent-green);">0</p>
            <div class="win-loss-bar"><span class="wl-win">🏆 <span id="ui-aligned-wins">0</span></span><span class="wl-loss">❌ <span id="ui-aligned-losses">0</span></span></div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--accent-red);">
            <h3>Against Rows</h3>
            <p class="factor-value" id="ui-against" style="color:var(--accent-red);">0</p>
            <div class="win-loss-bar"><span class="wl-win">🏆 <span id="ui-against-wins">0</span></span><span class="wl-loss">❌ <span id="ui-against-losses">0</span></span></div>
        </div>
    </div>

    <!-- ================= CHART REVIEW SECTION ================= -->
    <div class="section-title">📈 Chart Review — 5m &amp; 15m (Win/Loss only)</div>
    <div class="chart-filters">
        <select id="pairFilter"><option value="">All Pairs</option>
            <?php foreach ($allPairs as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
        </select>
        <select id="alignFilter"><option value="">Aligned + Against</option><option value="ALIGNED">Aligned only</option><option value="AGAINST">Against only</option></select>
        <select id="resultFilter"><option value="">Win + Loss</option><option value="win">Win only</option><option value="loss">Loss only</option></select>
        <input type="number" id="limitInput" value="50" min="1" max="9999" style="width:90px;" title="Max trades to load">
        <button class="btn btn-start" id="chartsBtn" onclick="startChartLoad()">▶ Load Charts</button>
        <button class="btn btn-pdf" id="pdfBtn" onclick="downloadPdf()" disabled>⬇ Download PDF</button>
    </div>
    <div id="chartsStatusBar" style="display:flex;justify-content:space-between;font-size:14px;color:var(--text-secondary);margin-bottom:15px;">
        <span id="chartsStatusText">Ready.</span><span id="chartsProgressText">0 loaded</span>
    </div>
    <div id="chartsErrorLog" style="color:#fca5a5;background:rgba(239,68,68,.1);border:1px dashed rgba(239,68,68,.4);padding:10px;border-radius:6px;display:none;white-space:pre-wrap;margin-bottom:15px;"></div>

    <div id="pdfRoot">
        <div id="cardsContainer"></div>
    </div>
</div>

<script>
/* ============== AGGREGATE SCANNER (counts only) ============== */
const totalTrades = <?= $totalTrades ?>;
const batchSize = 100;
let totalAligned = 0, totalAgainst = 0, totalProcessed = 0, totalSkipped = 0;
let skippedReasonsTotal = {};
let totalAlignedWins = 0, totalAlignedLosses = 0, totalAgainstWins = 0, totalAgainstLosses = 0;
let isRunning = false;

function startScan() {
    const btn = document.getElementById('startBtn');
    btn.disabled = true; btn.style.opacity = '0.5'; btn.innerText = "⏳ Scanning in Progress...";
    document.getElementById('statusText').innerText = "Fetching chunk data...";
    document.getElementById('errorLog').style.display = 'none';
    isRunning = true;
    processBatch(0);
}

async function processBatch(offset) {
    if (!isRunning) return;
    try {
        const response = await fetch(`?ajax=1&offset=${offset}&limit=${batchSize}`);
        const textData = await response.text();
        let data;
        try { data = JSON.parse(textData); } catch (e) { throw new Error("Server response corrupted:\n" + textData.substring(0,500)); }
        if (!data.success) throw new Error(data.error);

        totalAligned += data.aligned; totalAgainst += data.against; totalProcessed += data.processed; totalSkipped += data.skipped;
        if (data.skipped_reasons) for (const [r,c] of Object.entries(data.skipped_reasons)) skippedReasonsTotal[r] = (skippedReasonsTotal[r]||0)+c;
        totalAlignedWins += data.aligned_wins; totalAlignedLosses += data.aligned_losses;
        totalAgainstWins += data.against_wins; totalAgainstLosses += data.against_losses;
        updateUI();

        if (data.is_done) {
            document.getElementById('statusText').innerText = "✅ Scan Complete!";
            document.getElementById('startBtn').innerText = "Scan Finished";
            isRunning = false;
        } else {
            document.getElementById('statusText').innerText = `Processing batch... (${totalProcessed} done)`;
            processBatch(data.next_offset);
        }
    } catch (error) {
        document.getElementById('statusText').innerText = '⚠️ Error occurred. Halting scan.';
        document.getElementById('errorLog').innerText = "Details: " + error.message;
        document.getElementById('errorLog').style.display = 'block';
        document.getElementById('startBtn').innerText = "Resume Scan";
        document.getElementById('startBtn').disabled = false;
        document.getElementById('startBtn').style.opacity = '1';
        document.getElementById('startBtn').onclick = () => {
            document.getElementById('startBtn').disabled = true;
            document.getElementById('startBtn').style.opacity = '0.5';
            document.getElementById('errorLog').style.display = 'none';
            processBatch(offset);
        };
        isRunning = false;
    }
}

function updateUI() {
    const validEvaluated = totalAligned + totalAgainst;
    const factor = validEvaluated > 0 ? (totalAligned / validEvaluated) : 0;
    document.getElementById('ui-factor').innerText = factor.toFixed(2);
    document.getElementById('ui-percentage').innerText = (factor * 100).toFixed(1);
    document.getElementById('ui-aligned').innerText = totalAligned;
    document.getElementById('ui-aligned-wins').innerText = totalAlignedWins;
    document.getElementById('ui-aligned-losses').innerText = totalAlignedLosses;
    document.getElementById('ui-against').innerText = totalAgainst;
    document.getElementById('ui-against-wins').innerText = totalAgainstWins;
    document.getElementById('ui-against-losses').innerText = totalAgainstLosses;
    document.getElementById('progressText').innerText = `${totalProcessed} / ${totalTrades} Processed`;
    document.getElementById('progressFill').style.width = ((totalProcessed/totalTrades)*100) + '%';
    if (totalSkipped > 0) {
        const box = document.getElementById('skipBreakdown');
        box.style.display = 'block';
        let lines = [`Skipped: ${totalSkipped} rows`];
        for (const [r,c] of Object.entries(skippedReasonsTotal)) lines.push(`  • ${r}: ${c}`);
        box.innerText = lines.join('\n');
    }
}

/* ============== CHART REVIEW (5m + 15m + aligned badge) ============== */
let chartsRunning = false;
let chartsLoaded = 0;
let chartsMaxLimit = 50;

function startChartLoad() {
    document.getElementById('cardsContainer').innerHTML = '';
    chartsLoaded = 0;
    chartsMaxLimit = parseInt(document.getElementById('limitInput').value) || 50;

    const btn = document.getElementById('chartsBtn');
    btn.disabled = true; btn.innerText = '⏳ Loading...';
    document.getElementById('pdfBtn').disabled = true;
    document.getElementById('chartsErrorLog').style.display = 'none';

    chartsRunning = true;
    processChartBatch(0);
}

async function processChartBatch(offset) {
    if (!chartsRunning || chartsLoaded >= chartsMaxLimit) { finishChartLoad(); return; }

    const pair = document.getElementById('pairFilter').value;
    const align = document.getElementById('alignFilter').value;
    const result = document.getElementById('resultFilter').value;
    const batchSize2 = Math.min(15, chartsMaxLimit - chartsLoaded);

    try {
        const resp = await fetch(`?ajax=1&mode=charts&offset=${offset}&limit=${batchSize2}&pair=${encodeURIComponent(pair)}&align=${align}&result=${result}`);
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error("Corrupted response: " + text.substring(0,300)); }
        if (!data.success) throw new Error(data.error);

        data.trades.forEach(renderTradeCard);
        chartsLoaded += data.trades.length;
        document.getElementById('chartsProgressText').innerText = `${chartsLoaded} loaded`;
        document.getElementById('chartsStatusText').innerText = `Loading... offset ${data.next_offset}`;

        if (data.is_done || chartsLoaded >= chartsMaxLimit) finishChartLoad();
        else processChartBatch(data.next_offset);
    } catch (err) {
        document.getElementById('chartsStatusText').innerText = '⚠️ Error — halted.';
        document.getElementById('chartsErrorLog').innerText = err.message;
        document.getElementById('chartsErrorLog').style.display = 'block';
        document.getElementById('chartsBtn').disabled = false;
        document.getElementById('chartsBtn').innerText = '▶ Resume';
        chartsRunning = false;
    }
}

function finishChartLoad() {
    chartsRunning = false;
    document.getElementById('chartsStatusText').innerText = '✅ Done.';
    document.getElementById('chartsBtn').disabled = false;
    document.getElementById('chartsBtn').innerText = '▶ Load Charts';
    document.getElementById('pdfBtn').disabled = (chartsLoaded === 0);
}

function renderTradeCard(t) {
    const alignClass = t.status === 'ALIGNED' ? 'badge-aligned' : (t.status === 'AGAINST' ? 'badge-against' : 'badge-neutral');
    const resultClass = t.result === 'win' ? 'badge-win' : (t.result === 'loss' ? 'badge-loss' : 'badge-neutral');

    const card = document.createElement('div');
    card.className = 'trade-card';
    card.innerHTML = `
        <div class="trade-header">
            <div>
                <span class="trade-id">#${t.raw_trade_id} — ${t.pair_name}</span>
                <div class="trade-meta">${t.alert_time} &middot; Direction: ${t.direction}${t.htf_trend ? ' &middot; HTF: ' + t.htf_trend : ''}</div>
            </div>
            <div style="display:flex;gap:8px;">
                <span class="badge ${alignClass}">${t.status}</span>
                <span class="badge ${resultClass}">${t.result.toUpperCase()}</span>
            </div>
        </div>
        <div class="charts-row">
            <div class="chart-box"><div class="chart-label">5-Minute</div><div class="svg5m"></div></div>
            <div class="chart-box"><div class="chart-label">15-Minute (HTF)</div><div class="svg15m"></div></div>
        </div>
    `;
    document.getElementById('cardsContainer').appendChild(card);
    card.querySelector('.svg5m').appendChild(buildCandleSVG(t.candles5m));
    card.querySelector('.svg15m').appendChild(buildCandleSVG(t.candles15m));
}

function buildCandleSVG(candles) {
    const w = 460, h = 160, pad = 6;
    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', h);

    if (!candles || candles.length === 0) {
        const txt = document.createElementNS(svgNS, 'text');
        txt.setAttribute('x', w/2); txt.setAttribute('y', h/2);
        txt.setAttribute('fill', '#64748b'); txt.setAttribute('text-anchor', 'middle');
        txt.textContent = 'No candle data';
        svg.appendChild(txt);
        return svg;
    }

    const highs = candles.map(c => c.high), lows = candles.map(c => c.low);
    const maxP = Math.max(...highs), minP = Math.min(...lows);
    const range = (maxP - minP) || 1;
    const candleW = (w - pad*2) / candles.length;
    const scaleY = p => h - pad - ((p - minP) / range) * (h - pad*2);

    candles.forEach((c, i) => {
        const x = pad + i * candleW + candleW/2;
        const isUp = c.close >= c.open;
        const color = isUp ? '#10b981' : '#ef4444';

        const wick = document.createElementNS(svgNS, 'line');
        wick.setAttribute('x1', x); wick.setAttribute('x2', x);
        wick.setAttribute('y1', scaleY(c.high)); wick.setAttribute('y2', scaleY(c.low));
        wick.setAttribute('stroke', color); wick.setAttribute('stroke-width', 1);
        svg.appendChild(wick);

        const bodyTop = scaleY(Math.max(c.open, c.close));
        const bodyBottom = scaleY(Math.min(c.open, c.close));
        const bodyH = Math.max(bodyBottom - bodyTop, 1);

        const rect = document.createElementNS(svgNS, 'rect');
        rect.setAttribute('x', x - candleW*0.3);
        rect.setAttribute('y', bodyTop);
        rect.setAttribute('width', candleW*0.6);
        rect.setAttribute('height', bodyH);
        rect.setAttribute('fill', color);
        svg.appendChild(rect);
    });

    return svg;
}

function downloadPdf() {
    const el = document.getElementById('pdfRoot');
    const btn = document.getElementById('pdfBtn');
    btn.innerText = '⏳ Building PDF...'; btn.disabled = true;

    html2pdf().set({
        margin: 10,
        filename: `htf-chart-review-${Date.now()}.pdf`,
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 1.5, backgroundColor: '#0b0f19' },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'legacy'] }
    }).from(el).save().then(() => {
        btn.innerText = '⬇ Download PDF'; btn.disabled = false;
    });
}
</script>
</body>
</html>