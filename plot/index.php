<?php
/* =============================================================================
   index.php — Trade-ID Chart Plotter
   PHP top: ?ajax=1&trade_id=N  →  trade metadata JSON from DB
   HTML/JS: Interactive LightweightCharts v3.8 with:
     • Trade ID input → auto-load pair CSV, jump to trade window, overlay markers
     • H-Lines (draw, drag, color-pick, remove)
     • Pip Range Measure (drag on chart)
     • Bar Replay with play/pause/speed
     • Date Range Filter + Go-to-Date
     • OHLC crosshair display (IST)
     • Pattern Alert navigation buttons
     • Optional free-form pair picker (Browse pair dropdown)
     • Dual dataset: JUN-2025→FEB-2026 + dataset/ folder merged automatically
   ============================================================================= */

// ── AJAX: Trade metadata by ID ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['trade_id'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    error_reporting(0);

    $conn = null;
    foreach ([__DIR__, dirname(__DIR__), dirname(dirname(__DIR__))] as $searchDir) {
        if (file_exists($searchDir . '/db.php')) {
            require_once $searchDir . '/db.php';
            break;
        }
    }
    if (!isset($conn) || ($conn instanceof mysqli && $conn->connect_error)) {
        echo json_encode(['error' => 'Database connection failed. Check db.php path.']);
        exit;
    }

    $id    = (int)$_GET['trade_id'];
    $istTZ = new DateTimeZone('Asia/Kolkata');
    $utcTZ = new DateTimeZone('UTC');

    $res = $conn->query(
        "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.price_target, p.last_alert_time,
                o.trade_result, o.win_loss_time, o.win_loss_price,
                UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
         FROM   prediction_trade_data p
         LEFT  JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
         INNER JOIN raw_trade_data r        ON p.raw_trade_id = r.id
         WHERE  p.raw_trade_id = $id LIMIT 1"
    );
    $row = ($res && $res->num_rows) ? $res->fetch_assoc() : null;

    if (!$row) { echo json_encode(['error' => "Trade #$id not found in database."]); exit; }

    $aUTC = $row['last_alert_time'];
    $aTs  = !empty($row['trigger_unixtime']) ? (int)$row['trigger_unixtime'] : (int)strtotime($aUTC);

    try { $aIST = (new DateTime($aUTC, $utcTZ))->setTimezone($istTZ)->format('Y-m-d H:i:s'); }
    catch (Exception $e) { $aIST = 'N/A'; }

    $wIST = null; $wTs = null;
    if (!empty($row['win_loss_time'])) {
        // Outcome timestamps in historical/manual rows may be IST or UTC.
        // Choose the interpretation that cannot precede the alert.
        $candidates = [];
        try { $candidates[] = (new DateTime($row['win_loss_time'], $istTZ))->getTimestamp(); } catch (Exception $e) {}
        try { $candidates[] = (new DateTime($row['win_loss_time'], $utcTZ))->getTimestamp(); } catch (Exception $e) {}
        $valid = array_values(array_filter($candidates, static fn($ts) => $ts >= $aTs));
        $wTs = $valid ? min($valid) : ($candidates[0] ?? null);
        if ($wTs !== null) {
            $wIST = (new DateTime('@' . $wTs))->setTimezone($istTZ)->format('Y-m-d H:i:s');
        }
    }

    echo json_encode([
        'success'          => true,
        'id'               => $id,
        'pair'             => $row['pair_name'],
        'direction'        => $row['trade_direction'],
        'alertTimeIST'     => $aIST,
        'alertTimestamp'   => $aTs,
        'tradeResult'      => $row['trade_result'] ?? 'pending',
        'winLossTimeIST'   => $wIST,
        'winLossTimestamp' => $wTs,
        'winLossPrice'     => $row['win_loss_price'] !== null ? (float)$row['win_loss_price'] : null,
        'targetPrice'      => (float)$row['price_target'],
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Trade Chart Plotter</title>
<script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; font-family: 'Segoe UI', sans-serif; background: #1a1d23; color: #c9d1d9; }
body { display: flex; flex-direction: column; }

/* ── Header ─────────────────────────────────────────── */
#header {
    background: #0d1117; border-bottom: 1px solid #30363d;
    padding: 7px 16px; display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; position: relative; z-index: 200; flex-shrink: 0;
}
#header h2 { font-size: 15px; color: #58a6ff; font-weight: 700; white-space: nowrap; }

#trade-id-group {
    display: flex; align-items: center; gap: 5px;
    background: #161b22; border: 1px solid #30363d; border-radius: 6px;
    padding: 3px 8px; transition: border-color .15s;
}
#trade-id-group:focus-within { border-color: #58a6ff; }
#trade-id-group .tid-label { font-size: 11px; color: #8b949e; white-space: nowrap; }
#trade-id-input {
    width: 84px; background: transparent; border: none;
    color: #e6edf3; font-size: 13px; font-weight: 600; outline: none;
}
#trade-id-input::placeholder { color: #484f58; font-weight: 400; }
#trade-id-input::-webkit-inner-spin-button,
#trade-id-input::-webkit-outer-spin-button { -webkit-appearance: none; }
#load-trade-btn {
    background: #1f6feb; border: none; color: #fff;
    padding: 4px 11px; border-radius: 4px; cursor: pointer;
    font-size: 12px; font-weight: 700; transition: background .15s;
}
#load-trade-btn:hover:not(:disabled) { background: #388bfd; }
#load-trade-btn:disabled { background: #21262d; color: #484f58; cursor: not-allowed; }
#clear-trade-btn {
    background: transparent; border: 1px solid #30363d; color: #8b949e;
    width: 22px; height: 22px; border-radius: 4px; cursor: pointer;
    font-size: 11px; display: none; align-items: center;
    justify-content: center; line-height: 1; transition: all .15s;
}
#clear-trade-btn:hover { background: #3a1a1a; border-color: #da3633; color: #f85149; }

#pair-dropdown-btn {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
    padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 13px;
    display: flex; align-items: center; gap: 6px; transition: border-color .15s;
}
#pair-dropdown-btn:hover { border-color: #58a6ff; }
.selected-pair { color: #58a6ff; font-weight: 600; }
#pair-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0; z-index: 300;
    background: #161b22; border: 1px solid #30363d; border-radius: 6px;
    padding: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.6); min-width: 360px;
}
#pair-panel.open { display: block; }
#pair-search {
    width: 100%; background: #0d1117; border: 1px solid #30363d; border-radius: 4px;
    color: #c9d1d9; padding: 5px 10px; font-size: 12px; margin-bottom: 8px; outline: none;
}
#pair-search:focus { border-color: #58a6ff; }
#pair-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 5px; max-height: 210px; overflow-y: auto; }
.pair-btn {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
    padding: 5px 4px; border-radius: 4px; cursor: pointer; font-size: 11px;
    font-weight: 600; text-align: center; transition: all .15s;
}
.pair-btn:hover { background: #1f6feb; border-color: #58a6ff; color: #fff; }
.pair-btn.active { background: #238636; border-color: #3fb950; color: #fff; }

.hdr-sep { font-size: 13px; color: #30363d; }
#header-loading { font-size: 12px; color: #e3b341; display: none; }
#loaded-pair-info { font-size: 12px; color: #3fb950; font-weight: 600; display: none; }

/* ── Trade Info Bar ──────────────────────────────────── */
#trade-info-bar {
    background: #0a0f16; border-bottom: 1px solid #1f6feb44;
    padding: 5px 16px; display: none; align-items: center;
    gap: 8px; flex-wrap: wrap; font-size: 12px; flex-shrink: 0;
}
.tib-badge {
    background: #0c1d3e; border: 1px solid #1f6feb; color: #58a6ff;
    padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 12px;
}
.tib-pair { color: #e6edf3; font-weight: 700; font-size: 14px; letter-spacing: .3px; }
.tib-dir-up {
    background: #1a3a1a; border: 1px solid #2ea043; color: #3fb950;
    padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 11px;
}
.tib-dir-down {
    background: #3a1a1a; border: 1px solid #da3633; color: #f85149;
    padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 11px;
}
.tib-label { color: #6b7280; }
.tib-val   { color: #e6edf3; font-weight: 600; }
.tib-target { color: #e3b341; font-weight: 700; }
.tib-sep   { color: #30363d; margin: 0 1px; }
.outcome-win-badge {
    background: #0f3a2a; border: 1px solid #2ea043; color: #10b981;
    padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 11px;
}
.outcome-loss-badge {
    background: #3a1a1a; border: 1px solid #da3633; color: #ef4444;
    padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 11px;
}
.outcome-pending-badge { color: #6b7280; font-style: italic; font-size: 11px; }

/* ── Toolbars ────────────────────────────────────────── */
#toolbars {
    background: #161b22; border-bottom: 1px solid #30363d;
    padding: 6px 16px; display: none; flex-direction: column; gap: 6px; flex-shrink: 0;
}
.toolbar-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.toolbar-label {
    font-size: 11px; font-weight: 600; color: #8b949e;
    text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; min-width: 76px;
}
.toolbar-sep { width: 1px; height: 20px; background: #30363d; margin: 0 2px; }

.tbtn {
    padding: 4px 10px; border-radius: 4px; border: 1px solid #30363d;
    background: #21262d; color: #c9d1d9; font-size: 12px; cursor: pointer;
    white-space: nowrap; transition: all .15s;
}
.tbtn:hover                 { background: #30363d; border-color: #58a6ff; color: #fff; }
.tbtn.green                 { background: #1a3a1a; border-color: #2ea043; color: #3fb950; }
.tbtn.green:hover           { background: #238636; color: #fff; }
.tbtn.red                   { background: #3a1a1a; border-color: #da3633; color: #f85149; }
.tbtn.red:hover             { background: #b91c1c; color: #fff; }
.tbtn.yellow                { background: #3a2e00; border-color: #d29922; color: #e3b341; }
.tbtn.yellow:hover          { background: #9e6a03; color: #fff; }
.tbtn.blue                  { background: #0c1d3e; border-color: #1f6feb; color: #58a6ff; }
.tbtn.blue:hover            { background: #1f6feb; color: #fff; }
.tbtn.cyan                  { background: #0c2d3e; border-color: #0088cc; color: #39c3ef; }
.tbtn.cyan:hover            { background: #0088cc; color: #fff; }
.tbtn.active                { background: #1f6feb !important; color: #fff !important; border-color: #58a6ff !important; }

.dt-input {
    background: #0d1117; border: 1px solid #30363d; border-radius: 4px;
    color: #c9d1d9; padding: 4px 8px; font-size: 12px; cursor: pointer;
}
.dt-input:focus { outline: none; border-color: #58a6ff; }
.dt-input::-webkit-calendar-picker-indicator { filter: invert(.7); cursor: pointer; }

input[type="number"].speed-input {
    width: 56px; background: #0d1117; border: 1px solid #30363d;
    border-radius: 4px; color: #c9d1d9; padding: 4px 6px; font-size: 12px;
}

.color-swatches { display: flex; gap: 5px; align-items: center; }
.swatch {
    width: 18px; height: 18px; border-radius: 3px; cursor: pointer;
    border: 2px solid transparent; transition: border-color .15s; flex-shrink: 0;
}
.swatch:hover { border-color: #fff; }
.swatch.selected { border-color: #fff; box-shadow: 0 0 0 1px #58a6ff; }
#custom-color-input {
    width: 28px; height: 22px; border: 1px solid #30363d;
    border-radius: 3px; cursor: pointer; background: transparent; padding: 1px;
}

#pip-info-box {
    background: #0d1117; border: 1px solid #30363d; border-radius: 4px;
    padding: 4px 10px; font-size: 12px; font-family: 'Courier New', monospace;
    display: none; gap: 14px; align-items: center; flex-wrap: wrap;
}
#pip-info-box.visible { display: flex; }
.pip-stat { display: flex; flex-direction: column; align-items: center; }
.pip-stat .pip-label { font-size: 10px; color: #484f58; text-transform: uppercase; }
.pip-stat .pip-val   { font-size: 13px; font-weight: 700; }

#replay-row {
    display: none; background: #1a0e00; border: 1px solid #d29922;
    border-radius: 5px; padding: 5px 10px;
}
#replay-row .toolbar-label { color: #e3b341; }

/* ── Alert list ──────────────────────────────────────── */
#alert-list-container {
    padding: 5px 16px; background: #0d1117;
    border-bottom: 1px solid #30363d; display: none; flex-shrink: 0;
}
#alert-list-header { font-size: 11px; font-weight: 600; color: #8b949e; text-transform: uppercase; margin-bottom: 4px; }
#alert-list { display: flex; flex-wrap: wrap; gap: 6px; max-height: 56px; overflow-y: auto; }
.alert-btn {
    background: #3a1215; color: #f85149; border: 1px solid #da3633;
    padding: 3px 9px; border-radius: 3px; cursor: pointer; font-size: 11px; transition: all .15s;
}
.alert-btn:hover { background: #b91c1c; color: #fff; }

/* ── Chart area ──────────────────────────────────────── */
#chart-wrap { flex: 1; min-height: 0; position: relative; width: 100%; }
#chart-container { width: 100%; height: 100%; background: #0d1117; }
#chart-message {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
    color: #484f58; font-size: 15px; text-align: center;
    pointer-events: none; line-height: 1.9;
}
#loading-overlay {
    position: absolute; inset: 0; background: rgba(13,17,23,.9);
    display: none; align-items: center; justify-content: center;
    font-size: 16px; color: #58a6ff; flex-direction: column; gap: 10px; z-index: 100;
}
#loading-overlay .spinner {
    width: 32px; height: 32px; border: 3px solid #30363d;
    border-top-color: #58a6ff; border-radius: 50%;
    animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

#ohlc-display {
    position: absolute; top: 10px; left: 10px;
    background: rgba(13,17,23,.88); padding: 6px 10px; border-radius: 5px;
    font-family: 'Courier New', monospace; font-size: 12px; z-index: 10;
    display: none; border: 1px solid #30363d; pointer-events: none; line-height: 1.65;
}

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #0d1117; }
::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }
</style>
</head>
<body>

<!-- ══════════════ HEADER ══════════════ -->
<div id="header">
    <h2>📈 Trade Plotter</h2>

    <div id="trade-id-group">
        <span class="tid-label">Trade #</span>
        <input type="number" id="trade-id-input" placeholder="e.g. 12345" min="1"
               onkeydown="if(event.key==='Enter') loadTradeById()">
        <button id="load-trade-btn" onclick="loadTradeById()">⚡ Load</button>
        <button id="clear-trade-btn" onclick="clearTradeInfo()" title="Clear trade overlay">✕</button>
    </div>

    <span class="hdr-sep">│</span>

    <div style="position:relative;">
        <button id="pair-dropdown-btn" onclick="togglePairPanel()">
            <span class="selected-pair" id="selected-pair-label">Browse pair…</span>
            <span style="color:#484f58;font-size:10px;">▼</span>
        </button>
        <div id="pair-panel">
            <input type="text" id="pair-search" placeholder="🔍  Search (e.g. EUR, JPY)" oninput="filterPairs()">
            <div id="pair-grid"></div>
        </div>
    </div>

    <span id="header-loading">⏳ Loading…</span>
    <span id="loaded-pair-info"></span>
</div>

<!-- ══════════════ TRADE INFO BAR ══════════════ -->
<div id="trade-info-bar">
    <span id="tib-id"         class="tib-badge"></span>
    <span id="tib-pair"       class="tib-pair"></span>
    <span id="tib-dir"></span>
    <span class="tib-sep">|</span>
    <span class="tib-label">Alert:</span>
    <span id="tib-alert-time" class="tib-val"></span>
    <span class="tib-sep">|</span>
    <span class="tib-label">🎯</span>
    <span id="tib-target"     class="tib-target"></span>
    <span class="tib-sep" id="tib-outcome-sep">|</span>
    <span id="tib-outcome"></span>
    <span id="tib-wl-info"    style="color:#8b949e;font-size:11px;"></span>
    <button id="jump-trade-btn" class="tbtn blue"
            style="display:none;padding:3px 9px;font-size:11px;margin-left:4px;"
            onclick="jumpToTradeWindow(currentTradeData && currentTradeData.alertTimestamp, currentTradeData && currentTradeData.winLossTimestamp)">
        📍 Jump to Trade
    </button>
</div>

<!-- ══════════════ TOOLBARS ══════════════ -->
<div id="toolbars">

    <div class="toolbar-row">
        <span class="toolbar-label">Date Range</span>
        <input type="datetime-local" id="datetime-input-start" class="dt-input">
        <span style="color:#484f58;font-size:12px;">→</span>
        <input type="datetime-local" id="datetime-input-end" class="dt-input">
        <button id="filter-btn"     class="tbtn green">Apply</button>
        <button id="reset-view-btn" class="tbtn">Reset</button>
        <div class="toolbar-sep"></div>
        <span class="toolbar-label" style="min-width:58px;">Go to Date</span>
        <input type="date" id="date-input" class="dt-input">
        <button id="go-to-date-btn" class="tbtn blue">Go</button>
        <div class="toolbar-sep"></div>
        <button id="start-replay-btn" class="tbtn yellow">▶ Replay</button>
    </div>

    <div class="toolbar-row">
        <span class="toolbar-label">Pip Range</span>
        <button id="pip-range-btn" class="tbtn blue">📏 Select Range</button>
        <button id="pip-clear-btn" class="tbtn" style="display:none;">✕ Clear</button>
        <span id="pip-hint" style="font-size:11px;color:#484f58;">Click and drag on chart to measure pips</span>
        <div id="pip-info-box">
            <div class="pip-stat"><span class="pip-label">High</span><span class="pip-val" id="pip-high" style="color:#3fb950;">—</span></div>
            <div class="pip-stat"><span class="pip-label">Low</span><span class="pip-val"  id="pip-low"  style="color:#f85149;">—</span></div>
            <div class="pip-stat"><span class="pip-label">Total Pips</span><span class="pip-val" id="pip-total" style="color:#e3b341;">—</span></div>
            <div class="pip-stat"><span class="pip-label">50% Level</span><span class="pip-val"  id="pip-mid"   style="color:#39c3ef;">—</span></div>
            <div class="pip-stat"><span class="pip-label">Pips to Mid</span><span class="pip-val" id="pip-to-mid" style="color:#c9d1d9;">—</span></div>
        </div>
    </div>

    <div class="toolbar-row">
        <span class="toolbar-label">H-Lines</span>
        <button id="draw-hline-btn" class="tbtn blue">+ Draw H-Line</button>
        <div id="color-swatches" class="color-swatches">
            <span style="font-size:11px;color:#8b949e;">Color:</span>
        </div>
        <button id="remove-last-line-btn" class="tbtn">Remove Last</button>
        <button id="remove-all-lines-btn" class="tbtn red">Remove All</button>
        <span id="hline-status" style="font-size:12px;color:#484f58;margin-left:4px;"></span>
    </div>

    <div class="toolbar-row" id="replay-row">
        <span class="toolbar-label">Replay</span>
        <button id="replay-play-pause-btn" class="tbtn cyan">▶ Play</button>
        <button id="replay-next-bar-btn"   class="tbtn">Next Bar »</button>
        <button id="replay-stop-btn"       class="tbtn red">■ Stop</button>
        <label style="font-size:12px;color:#8b949e;margin-left:6px;">
            Speed (ms): <input type="number" id="replay-speed" value="800" class="speed-input">
        </label>
    </div>
</div>

<!-- ══════════════ PATTERN ALERTS ══════════════ -->
<div id="alert-list-container">
    <div id="alert-list-header">⬇ Detected Patterns — Click to Jump</div>
    <div id="alert-list"></div>
</div>

<!-- ══════════════ CHART ══════════════ -->
<div id="chart-wrap">
    <div id="chart-container"></div>
    <div id="ohlc-display"><span style="color:#484f58">Time: — | O: — H: — L: — C: —</span></div>
    <div id="chart-message">Enter a Trade ID above — or choose a pair to browse the chart.</div>
    <div id="loading-overlay">
        <div class="spinner"></div>
        <span id="loading-text">Loading…</span>
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════
//  CONFIG
// ═══════════════════════════════════════════════════════
const DATASET_BEFORE_JUN_2025 = 'https://tradeedify.in/dataset/BEFORE-JUN-2025/';
const DATASET_OLD     = 'https://tradeedify.in/dataset/JUN-2025%20TO%20FEB-2026/';
const DATASET_NEW     = 'https://tradeedify.in/dataset/dataset/';
const PROCESS_PHP     = 'process_csv.php';

const ALL_PAIRS = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'NZDCAD','NZDCHF','NZDJPY','NZDUSD',
    'USDCAD','USDCHF','USDJPY'
];

const SWATCH_COLORS = [
    { hex: '#58a6ff', name: 'Blue'   },
    { hex: '#3fb950', name: 'Green'  },
    { hex: '#f85149', name: 'Red'    },
    { hex: '#e3b341', name: 'Yellow' },
    { hex: '#bc8cff', name: 'Purple' },
    { hex: '#ff7b72', name: 'Coral'  },
    { hex: '#39c3ef', name: 'Cyan'   },
    { hex: '#ffffff', name: 'White'  },
];

// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
let chart             = null;
let candlestickSeries = null;
let volumeSeries      = null;
let fullChartData     = null;
let currentPair       = '';
let activePairBtn     = null;
let selectedHlineColor = '#58a6ff';
let lastPriceLine      = null;

let isDrawingLine  = false;
let drawnLines     = [];
let isDraggingLine = false;
let dragLineIdx    = -1;
const DRAG_TOL     = 8;

let isPipMode     = false;
let pipStartPrice = null;
let pipEndPrice   = null;
let pipIsDragging = false;
let pipHighLine   = null;
let pipLowLine    = null;
let pipMidLine    = null;

let currentTradeData  = null;
let tradeOverlayLines = [];
let tradeMarkers      = [];

let isReplaying             = false;
let replayMasterData        = {};
let replayCurrentIndex      = 0;
let replayTimer             = null;
let replayInitialAlerts     = [];
let replayAccumulatedAlerts = [];

// ═══════════════════════════════════════════════════════
//  DOM REFERENCES
// ═══════════════════════════════════════════════════════
const chartContainer     = document.getElementById('chart-container');
const chartMessage       = document.getElementById('chart-message');
const loadingOverlay     = document.getElementById('loading-overlay');
const loadingText        = document.getElementById('loading-text');
const ohlcDisplay        = document.getElementById('ohlc-display');
const toolbars           = document.getElementById('toolbars');
const dtInputStart       = document.getElementById('datetime-input-start');
const dtInputEnd         = document.getElementById('datetime-input-end');
const filterBtn          = document.getElementById('filter-btn');
const resetViewBtn       = document.getElementById('reset-view-btn');
const dateInput          = document.getElementById('date-input');
const goToDateBtn        = document.getElementById('go-to-date-btn');
const startReplayBtn     = document.getElementById('start-replay-btn');
const drawHLineBtn       = document.getElementById('draw-hline-btn');
const removeLastBtn      = document.getElementById('remove-last-line-btn');
const removeAllBtn       = document.getElementById('remove-all-lines-btn');
const hlineStatus        = document.getElementById('hline-status');
const replayRow          = document.getElementById('replay-row');
const replayPlayPauseBtn = document.getElementById('replay-play-pause-btn');
const replayNextBarBtn   = document.getElementById('replay-next-bar-btn');
const replayStopBtn      = document.getElementById('replay-stop-btn');
const replaySpeedInput   = document.getElementById('replay-speed');
const alertListContainer = document.getElementById('alert-list-container');
const alertList          = document.getElementById('alert-list');
const headerLoading      = document.getElementById('header-loading');
const loadedPairInfo     = document.getElementById('loaded-pair-info');
const selectedPairLabel  = document.getElementById('selected-pair-label');
const pairPanel          = document.getElementById('pair-panel');
const pairGrid           = document.getElementById('pair-grid');
const pipRangeBtn        = document.getElementById('pip-range-btn');
const pipClearBtn        = document.getElementById('pip-clear-btn');
const pipHint            = document.getElementById('pip-hint');
const pipInfoBox         = document.getElementById('pip-info-box');
const jumpTradeBtn       = document.getElementById('jump-trade-btn');

// ═══════════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════════
function isPairJpy(pair) { return (pair || currentPair || '').includes('JPY'); }
function calcPips(diff, pair) {
    return isPairJpy(pair)
        ? Math.round(Math.abs(diff) * 100)
        : Math.round(Math.abs(diff) * 10000);
}
function priceToFixed(price, pair) {
    return isPairJpy(pair) ? price.toFixed(3) : price.toFixed(5);
}
function toDateTimeLocalString(ts) {
    const d = new Date(ts * 1000);
    return new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}
function formatTimeForLabel(ts) {
    const d = new Date(ts * 1000);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function formatISTshort(istStr) {
    if (!istStr || istStr === 'N/A') return istStr || '—';
    const p = istStr.split(/[ :-]/);
    if (p.length < 5) return istStr;
    const M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${parseInt(p[2])} ${M[parseInt(p[1])-1]}, ${p[3]}:${p[4]}`;
}

// ═══════════════════════════════════════════════════════
//  DATASET PICKER
//  Feb 28 2026 23:59:59 UTC = 1772111999
//  Trades on/before that → old folder, after → new folder
//  alertTs = unix timestamp of the trade alert (or null for Browse mode)
// ═══════════════════════════════════════════════════════
const FEB2026_END_IST = '2026-02-28';

function formatISTDateFromTimestamp(alertTs) {
    if (
        alertTs === null ||
        alertTs === undefined ||
        alertTs === '' ||
        isNaN(Number(alertTs))
    ) {
        return null;
    }

    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kolkata',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).formatToParts(new Date(Number(alertTs) * 1000));

    const out = {};
    parts.forEach(p => {
        if (p.type !== 'literal') out[p.type] = p.value;
    });

    return `${out.year}-${out.month}-${out.day}`;
}

function pickDatasetUrl(alertTs, pair = null) {
    const normalizedPair = pair ? String(pair).trim().toUpperCase() : null;

    if (
        alertTs === null ||
        alertTs === undefined ||
        alertTs === '' ||
        isNaN(Number(alertTs))
    ) {
        return normalizedPair
            ? `${DATASET_NEW}FX_${normalizedPair}.csv`
            : DATASET_NEW;
    }

    const istDate = formatISTDateFromTimestamp(alertTs);

    if (!istDate) {
        return normalizedPair
            ? `${DATASET_NEW}FX_${normalizedPair}.csv`
            : DATASET_NEW;
    }

    // Before 01-June-2025 IST: pair/date-specific historical CSV
    if (istDate < '2025-06-01') {
        return normalizedPair
            ? `${DATASET_BEFORE_JUN_2025}${normalizedPair}/FX_${normalizedPair}-${istDate}.csv`
            : DATASET_BEFORE_JUN_2025;
    }

    // 01-June-2025 through 28-February-2026 IST: consolidated CSV
    if (istDate <= FEB2026_END_IST) {
        return normalizedPair
            ? `${DATASET_OLD}FX_${normalizedPair}.csv`
            : DATASET_OLD;
    }

    // After February 2026: pair/date-specific CSV
    return normalizedPair
        ? `${DATASET_NEW}${normalizedPair}/FX_${normalizedPair}-${istDate}.csv`
        : DATASET_NEW;
}

async function fetchCsv(pair, alertTs = null) {
    const normalizedPair = String(pair).trim().toUpperCase();
    const url = pickDatasetUrl(alertTs, normalizedPair);

    console.log('--------------------------------------------');
    console.log('CSV DATASET REQUEST');
    console.log('Pair:', normalizedPair);
    console.log('Alert Unix Timestamp:', alertTs);
    console.log(
        'Alert IST Date:',
        alertTs !== null && alertTs !== undefined
            ? formatISTDateFromTimestamp(alertTs)
            : 'Browse mode'
    );
    console.log('Dataset URL:', url);
    console.log('--------------------------------------------');

    const resp = await fetch(url, { cache: 'no-store' });

    if (!resp.ok) {
        throw new Error(
            `CSV not found for ${normalizedPair} (HTTP ${resp.status}): ${url}`
        );
    }

    return resp.blob();
}

// ═══════════════════════════════════════════════════════
//  COLOR SWATCHES
// ═══════════════════════════════════════════════════════
function buildSwatches() {
    const container = document.getElementById('color-swatches');
    SWATCH_COLORS.forEach(c => {
        const sw = document.createElement('div');
        sw.className = 'swatch' + (c.hex === selectedHlineColor ? ' selected' : '');
        sw.style.background = c.hex;
        sw.title = c.name;
        sw.onclick = () => selectColor(c.hex, sw);
        container.appendChild(sw);
    });
    const inp = document.createElement('input');
    inp.type = 'color'; inp.id = 'custom-color-input'; inp.value = '#ffffff'; inp.title = 'Custom color';
    inp.oninput = () => {
        selectedHlineColor = inp.value;
        document.querySelectorAll('.swatch').forEach(s => s.classList.remove('selected'));
    };
    container.appendChild(inp);
}
function selectColor(hex, swEl) {
    selectedHlineColor = hex;
    document.querySelectorAll('.swatch').forEach(s => s.classList.remove('selected'));
    swEl.classList.add('selected');
}

// ═══════════════════════════════════════════════════════
//  PIP RANGE TOOL
// ═══════════════════════════════════════════════════════
function updatePipInfoBox(p1, p2) {
    const high = Math.max(p1, p2), low = Math.min(p1, p2), mid = (high + low) / 2;
    const tot  = calcPips(high - low, currentPair);
    document.getElementById('pip-high').textContent   = priceToFixed(high, currentPair);
    document.getElementById('pip-low').textContent    = priceToFixed(low,  currentPair);
    document.getElementById('pip-total').textContent  = tot + ' pips';
    document.getElementById('pip-mid').textContent    = priceToFixed(mid,  currentPair);
    document.getElementById('pip-to-mid').textContent = Math.round(tot / 2) + ' pips';
    pipInfoBox.classList.add('visible');
    pipHint.style.display     = 'none';
    pipClearBtn.style.display = 'inline-block';
    return { high, low, mid };
}
function drawPipLines(high, low, mid) {
    clearPipLines();
    pipHighLine = candlestickSeries.createPriceLine({ price: high, color: '#3fb950', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, axisLabelVisible: true, title: `H: ${priceToFixed(high, currentPair)}` });
    pipLowLine  = candlestickSeries.createPriceLine({ price: low,  color: '#f85149', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, axisLabelVisible: true, title: `L: ${priceToFixed(low,  currentPair)}` });
    pipMidLine  = candlestickSeries.createPriceLine({ price: mid,  color: '#39c3ef', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dotted, axisLabelVisible: true, title: `50%: ${priceToFixed(mid, currentPair)}` });
}
function clearPipLines() {
    if (pipHighLine) { try { candlestickSeries.removePriceLine(pipHighLine); } catch(e){} pipHighLine = null; }
    if (pipLowLine)  { try { candlestickSeries.removePriceLine(pipLowLine);  } catch(e){} pipLowLine  = null; }
    if (pipMidLine)  { try { candlestickSeries.removePriceLine(pipMidLine);  } catch(e){} pipMidLine  = null; }
}
function clearPipTool() {
    clearPipLines();
    pipStartPrice = null; pipEndPrice = null;
    pipInfoBox.classList.remove('visible');
    pipHint.style.display     = 'inline';
    pipClearBtn.style.display = 'none';
}
function exitPipMode() {
    isPipMode = false; pipIsDragging = false;
    pipRangeBtn.classList.remove('active');
    pipRangeBtn.textContent     = '📏 Select Range';
    chartContainer.style.cursor = 'default';
}

// ═══════════════════════════════════════════════════════
//  PAIR PANEL
// ═══════════════════════════════════════════════════════
function buildPairGrid(filter = '') {
    pairGrid.innerHTML = '';
    const f = filter.toUpperCase().replace('/', '');
    ALL_PAIRS.filter(p => p.includes(f)).forEach(pair => {
        const btn = document.createElement('button');
        btn.className   = 'pair-btn' + (activePairBtn === pair ? ' active' : '');
        btn.textContent = pair;
        btn.onclick     = () => loadPair(pair);
        pairGrid.appendChild(btn);
    });
}
function filterPairs() { buildPairGrid(document.getElementById('pair-search').value); }
function togglePairPanel() {
    pairPanel.classList.toggle('open');
    if (pairPanel.classList.contains('open')) { buildPairGrid(); document.getElementById('pair-search').focus(); }
}
document.addEventListener('click', e => {
    if (!e.target.closest('#pair-dropdown-btn') && !e.target.closest('#pair-panel'))
        pairPanel.classList.remove('open');
});

// ═══════════════════════════════════════════════════════
//  CHART DATA
// ═══════════════════════════════════════════════════════
function updateChartData(data) {
    if (!candlestickSeries || !volumeSeries) return;
    candlestickSeries.setData(data.candlesticks);
    volumeSeries.setData(data.volume);
    const merged = [...(data.alerts || []), ...tradeMarkers].sort((a, b) => a.time - b.time);
    candlestickSeries.setMarkers(merged);
    if (!currentTradeData) {
        chart.timeScale().fitContent();
    }
}

function jumpToTimestamp(targetTime) {
    if (!chart || !fullChartData) return;
    const candles = fullChartData.candlesticks;
    let idx = candles.findIndex(c => c.time === targetTime);
    if (idx === -1) {
        let minDiff = Infinity;
        candles.forEach((c, i) => { const d = Math.abs(c.time - targetTime); if (d < minDiff) { minDiff = d; idx = i; } });
    }
    const half = 25, from = Math.max(0, idx - half), to = Math.min(candles.length - 1, idx + half);
    chart.timeScale().setVisibleRange({ from: candles[from].time, to: candles[to].time });
}

// ═══════════════════════════════════════════════════════
//  DATE RANGE + GO-TO
// ═══════════════════════════════════════════════════════
filterBtn.addEventListener('click', () => {
    if (!fullChartData) return;
    const s = new Date(dtInputStart.value).getTime() / 1000;
    const e = new Date(dtInputEnd.value).getTime()   / 1000;
    if (isNaN(s) || isNaN(e)) { alert('Invalid date/time.'); return; }
    const filtered = {
        candlesticks: fullChartData.candlesticks.filter(d => d.time >= s && d.time <= e),
        volume:       fullChartData.volume.filter(d => d.time >= s && d.time <= e),
        alerts:       (fullChartData.alerts || []).filter(d => d.time >= s && d.time <= e)
    };
    updateChartData(filtered);
    generateAlertButtons(filtered);
});
resetViewBtn.addEventListener('click', () => {
    if (!fullChartData) return;
    dtInputStart.value = toDateTimeLocalString(fullChartData.startDate);
    dtInputEnd.value   = toDateTimeLocalString(fullChartData.endDate);
    updateChartData(fullChartData);
    generateAlertButtons(fullChartData);
});
goToDateBtn.addEventListener('click', () => {
    if (!fullChartData?.candlesticks?.length || !dateInput.value) return;
    jumpToTimestamp(new Date(dateInput.value).getTime() / 1000);
});

// ═══════════════════════════════════════════════════════
//  PATTERN ALERT BUTTONS
// ═══════════════════════════════════════════════════════
function generateAlertButtons(data) {
    alertList.innerHTML = '';
    const arrows = (data.alerts || []).filter(a => a.shape === 'arrowDown');
    if (!arrows.length) { alertList.innerHTML = '<span style="color:#484f58;font-size:12px;">No patterns detected.</span>'; return; }
    arrows.forEach(alert => {
        const btn = document.createElement('button');
        btn.className = 'alert-btn';
        btn.innerHTML = `⬇ ${formatTimeForLabel(alert.time)}: ${alert.text}`;
        btn.onclick   = () => jumpToTimestamp(alert.time);
        alertList.appendChild(btn);
    });
}

// ═══════════════════════════════════════════════════════
//  H-LINE TOOLS
// ═══════════════════════════════════════════════════════
drawHLineBtn.addEventListener('click', () => {
    if (isPipMode) return;
    isDrawingLine = !isDrawingLine;
    if (isDrawingLine) {
        drawHLineBtn.classList.add('active');
        drawHLineBtn.textContent    = '✎ Click chart to place…';
        hlineStatus.textContent     = '';
        chartContainer.style.cursor = 'crosshair';
    } else { resetDrawMode(); }
});
function resetDrawMode() {
    isDrawingLine = false;
    drawHLineBtn.classList.remove('active');
    drawHLineBtn.textContent    = '+ Draw H-Line';
    hlineStatus.textContent     = drawnLines.length ? `${drawnLines.length} line(s) on chart` : '';
    chartContainer.style.cursor = 'default';
}
removeLastBtn.addEventListener('click', () => {
    if (!drawnLines.length) return;
    const last = drawnLines.pop();
    try { candlestickSeries.removePriceLine(last.priceLine); } catch(e){}
    hlineStatus.textContent = drawnLines.length ? `${drawnLines.length} line(s) on chart` : '';
});
removeAllBtn.addEventListener('click', () => {
    drawnLines.forEach(l => { try { candlestickSeries.removePriceLine(l.priceLine); } catch(e){} });
    drawnLines = []; hlineStatus.textContent = '';
});

// ═══════════════════════════════════════════════════════
//  PIP TOOL BUTTON EVENTS
// ═══════════════════════════════════════════════════════
pipRangeBtn.addEventListener('click', () => {
    if (isDrawingLine) resetDrawMode();
    isPipMode = !isPipMode;
    if (isPipMode) {
        pipRangeBtn.classList.add('active');
        pipRangeBtn.textContent     = '📏 Measuring…';
        chartContainer.style.cursor = 'ns-resize';
        pipHint.textContent         = 'Click and drag up/down on chart to select price range';
        pipHint.style.display       = 'inline';
        pipInfoBox.classList.remove('visible');
    } else { exitPipMode(); }
});
pipClearBtn.addEventListener('click', () => { clearPipTool(); exitPipMode(); });

// ═══════════════════════════════════════════════════════
//  MOUSE EVENTS
// ═══════════════════════════════════════════════════════
function getChartY(e) { return e.clientY - chartContainer.getBoundingClientRect().top; }

chartContainer.addEventListener('mousedown', e => {
    if (!chart || !candlestickSeries) return;
    const y = getChartY(e);
    if (isPipMode) {
        pipIsDragging = true;
        pipStartPrice = candlestickSeries.coordinateToPrice(y);
        pipEndPrice   = pipStartPrice;
        e.preventDefault(); return;
    }
    if (isDrawingLine) return;
    let bestDist = DRAG_TOL + 1, bestIdx = -1;
    drawnLines.forEach((l, i) => {
        const ly = candlestickSeries.priceToCoordinate(l.price);
        if (ly === null) return;
        const dist = Math.abs(y - ly);
        if (dist < bestDist) { bestDist = dist; bestIdx = i; }
    });
    if (bestIdx !== -1) {
        isDraggingLine = true; dragLineIdx = bestIdx;
        chartContainer.style.cursor = 'ns-resize';
        drawnLines[bestIdx].priceLine.applyOptions({ color: '#f85149', lineWidth: 3 });
        e.preventDefault();
    }
});

window.addEventListener('mousemove', e => {
    if (!chart || !candlestickSeries) return;
    const y = getChartY(e);
    if (isPipMode && pipIsDragging) {
        pipEndPrice = candlestickSeries.coordinateToPrice(y);
        if (pipEndPrice !== null && pipStartPrice !== null) {
            const { high, low, mid } = updatePipInfoBox(pipStartPrice, pipEndPrice);
            drawPipLines(high, low, mid);
        }
        return;
    }
    if (isDraggingLine && dragLineIdx >= 0) {
        const newPrice = candlestickSeries.coordinateToPrice(y);
        if (newPrice !== null) {
            drawnLines[dragLineIdx].price = newPrice;
            drawnLines[dragLineIdx].priceLine.applyOptions({ price: newPrice, title: priceToFixed(newPrice, currentPair) });
        }
        return;
    }
    if (!isPipMode && !isDrawingLine && drawnLines.length) {
        let nearLine = false;
        drawnLines.forEach(l => {
            const ly = candlestickSeries.priceToCoordinate(l.price);
            if (ly !== null && Math.abs(y - ly) < DRAG_TOL) nearLine = true;
        });
        chartContainer.style.cursor = nearLine ? 'ns-resize' : 'default';
    }
});

window.addEventListener('mouseup', () => {
    if (isPipMode && pipIsDragging) { pipIsDragging = false; exitPipMode(); return; }
    if (isDraggingLine && dragLineIdx >= 0) {
        isDraggingLine = false;
        const l = drawnLines[dragLineIdx];
        l.priceLine.applyOptions({ color: l.color, lineWidth: 2 });
        dragLineIdx = -1;
        chartContainer.style.cursor = 'default';
        hlineStatus.textContent = `${drawnLines.length} line(s) on chart`;
    }
});

// ═══════════════════════════════════════════════════════
//  REPLAY
// ═══════════════════════════════════════════════════════
startReplayBtn.addEventListener('click', () => {
    if (!fullChartData?.candlesticks?.length) { alert('No data loaded.'); return; }
    const startTs = new Date(dtInputStart.value).getTime() / 1000;
    if (isNaN(startTs)) { alert("Invalid 'From' date."); return; }
    const idx = fullChartData.candlesticks.findIndex(d => d.time >= startTs);
    if (idx === -1) { alert('No data after specified date.'); return; }
    const initial = {
        candlesticks: fullChartData.candlesticks.slice(0, idx),
        volume:       fullChartData.volume.slice(0, idx),
        alerts:       (fullChartData.alerts || []).filter(d => d.time < startTs)
    };
    replayMasterData = {
        candlesticks: fullChartData.candlesticks.slice(idx),
        volume:       fullChartData.volume.slice(idx),
        alerts:       (fullChartData.alerts || []).filter(d => d.time >= startTs)
    };
    replayCurrentIndex = 0; isReplaying = true;
    replayInitialAlerts = initial.alerts.slice(); replayAccumulatedAlerts = [];
    updateChartData(initial);
    chart.timeScale().scrollToPosition(0, true);
    alertListContainer.style.display = 'none';
    replayRow.style.display          = 'flex';
    replayPlayPauseBtn.textContent   = '▶ Play';
});

function applyReplayStep() {
    if (!isReplaying || replayCurrentIndex >= replayMasterData.candlesticks.length) { stopReplay(); return; }
    const c = replayMasterData.candlesticks[replayCurrentIndex];
    const v = replayMasterData.volume[replayCurrentIndex];
    candlestickSeries.update(c); volumeSeries.update(v);
    const now = replayMasterData.alerts.filter(a => a.time === c.time);
    if (now.length) {
        replayAccumulatedAlerts.push(...now);
        const combined = [...replayInitialAlerts, ...replayAccumulatedAlerts, ...tradeMarkers].sort((a, b) => a.time - b.time);
        candlestickSeries.setMarkers(combined);
    }
    chart.timeScale().scrollToPosition(0, true);
    replayCurrentIndex++;
}
replayNextBarBtn.addEventListener('click', applyReplayStep);
replayPlayPauseBtn.addEventListener('click', () => {
    if (!isReplaying) return;
    if (replayTimer) { clearInterval(replayTimer); replayTimer = null; replayPlayPauseBtn.textContent = '▶ Play'; }
    else { replayPlayPauseBtn.textContent = '⏸ Pause'; replayTimer = setInterval(applyReplayStep, Math.max(50, parseInt(replaySpeedInput.value) || 800)); }
});
function stopReplay() {
    if (replayTimer) { clearInterval(replayTimer); replayTimer = null; }
    isReplaying = false;
    if (fullChartData) updateChartData(fullChartData);
    replayRow.style.display          = 'none';
    alertListContainer.style.display = 'block';
    replayPlayPauseBtn.textContent   = '▶ Play';
}
replayStopBtn.addEventListener('click', stopReplay);

// ═══════════════════════════════════════════════════════
//  CORE CHART RENDER
// ═══════════════════════════════════════════════════════
function plotChart(data) {
    try {
        if (!chart) {
            chart = LightweightCharts.createChart(chartContainer, {
                width:     chartContainer.clientWidth,
                height:    chartContainer.clientHeight,
                layout:    { backgroundColor: '#0d1117', textColor: '#8b949e' },
                grid:      { vertLines: { color: '#1c2128' }, horzLines: { color: '#1c2128' } },
                crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
                timeScale: { borderColor: '#30363d', timeVisible: true, secondsVisible: false }
            });

            candlestickSeries = chart.addCandlestickSeries({
                upColor:       '#3fb950', downColor:       '#f85149',
                borderUpColor: '#3fb950', borderDownColor: '#f85149',
                wickUpColor:   '#3fb950', wickDownColor:   '#f85149',
                priceFormat:   { type: 'custom', formatter: p => priceToFixed(p, currentPair) }
            });

            volumeSeries = chart.addHistogramSeries({
                color: '#26a69a', priceFormat: { type: 'volume' }, priceScaleId: 'vol'
            });
            chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } });

            ohlcDisplay.style.display = 'block';
            const istOpts = { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12: false, timeZone: 'Asia/Kolkata' };

            chart.subscribeCrosshairMove(param => {
                if (param.time && param.seriesPrices?.size) {
                    const d   = param.seriesPrices.get(candlestickSeries);
                    const col = d && d.close >= d.open ? '#3fb950' : '#f85149';
                    if (d) {
                        const t = new Date(param.time * 1000).toLocaleString('en-IN', istOpts);
                        ohlcDisplay.innerHTML =
                            `<span style="color:#8b949e">${t} IST</span><br>` +
                            `O: <b>${priceToFixed(d.open,  currentPair)}</b>  ` +
                            `H: <b>${priceToFixed(d.high,  currentPair)}</b>  ` +
                            `L: <b>${priceToFixed(d.low,   currentPair)}</b>  ` +
                            `C: <b style="color:${col}">${priceToFixed(d.close, currentPair)}</b>`;
                    }
                } else {
                    ohlcDisplay.innerHTML = '<span style="color:#484f58">Time: — | O: — H: — L: — C: —</span>';
                }
            });

            chart.subscribeClick(param => {
                if (!isDrawingLine || !param.point) return;
                const price = candlestickSeries.coordinateToPrice(param.point.y);
                if (price === null) return;
                const p  = parseFloat(priceToFixed(price, currentPair));
                const pl = candlestickSeries.createPriceLine({
                    price: p, color: selectedHlineColor, lineWidth: 2,
                    axisLabelVisible: true, title: priceToFixed(p, currentPair)
                });
                drawnLines.push({ priceLine: pl, price: p, color: selectedHlineColor });
                resetDrawMode();
            });

            new ResizeObserver(() => {
                if (chart) chart.resize(chartContainer.clientWidth, chartContainer.clientHeight);
            }).observe(chartContainer);
        }

        updateChartData(data);

    } catch (err) {
        chartMessage.style.display = 'block';
        chartMessage.innerHTML     = `<span style="color:#f85149">JS Error: ${err.message}</span>`;
        console.error(err);
    }
}

// ═══════════════════════════════════════════════════════
//  LOAD PAIR  — picks correct dataset by alert date,
//  fetches CSV, sends to process_csv.php, renders chart
//  alertTs: unix timestamp of trade alert (null = Browse mode)
// ═══════════════════════════════════════════════════════
async function loadPair(pair, throwOnError = false, alertTs = null) {
    pairPanel.classList.remove('open');
    currentPair   = pair;
    activePairBtn = pair;
    selectedPairLabel.textContent = pair;
    document.querySelectorAll('.pair-btn').forEach(b => b.classList.toggle('active', b.textContent === pair));

    loadingText.textContent      = `Loading ${pair}…`;
    loadingOverlay.style.display = 'flex';
    chartMessage.style.display   = 'none';
    headerLoading.style.display  = 'inline';
    loadedPairInfo.style.display = 'none';

    clearTradeOverlays();
    if (candlestickSeries) {
        drawnLines.forEach(l => { try { candlestickSeries.removePriceLine(l.priceLine); } catch(e){} });
    }
    drawnLines = []; hlineStatus.textContent = '';
    clearPipTool();

    try {
        // Pick dataset folder based on alert date, then fetch single CSV
        const selectedISTDate = alertTs ? formatISTDateFromTimestamp(alertTs) : null;
        const selectedDatasetType = !selectedISTDate
            ? 'browse'
            : selectedISTDate < '2025-06-01'
                ? 'before-jun-2025'
                : selectedISTDate <= FEB2026_END_IST
                    ? 'jun-2025-to-feb-2026'
                    : 'new';
        loadingText.textContent = `Fetching ${pair} from ${selectedDatasetType} dataset…`;
        const csvBlob = await fetchCsv(pair, alertTs);

        loadingText.textContent = `Processing ${pair} chart data…`;
        const fd = new FormData();
        fd.append('csv_file', new File([csvBlob], `FX_${pair}.csv`, { type: 'text/csv' }));

        const phpResp = await fetch(PROCESS_PHP, { method: 'POST', body: fd });
        const data    = await phpResp.json();
        if (data.error) throw new Error(data.error);

        fullChartData = data;

        if (data.startDate && data.endDate) {
            dtInputStart.value = toDateTimeLocalString(data.startDate);
            dtInputEnd.value   = toDateTimeLocalString(data.endDate);
            dateInput.min      = new Date(data.startDate * 1000).toISOString().split('T')[0];
            dateInput.max      = new Date(data.endDate   * 1000).toISOString().split('T')[0];
            dateInput.value    = dateInput.min;
        }

        toolbars.style.display           = 'flex';
        alertListContainer.style.display = 'block';
        loadedPairInfo.style.display     = 'inline';
        loadedPairInfo.textContent       = `✓ ${pair} (${(data.candlesticks?.length || 0).toLocaleString()} bars)`;

        plotChart(fullChartData);
        generateAlertButtons(fullChartData);

    } catch (err) {
        chartMessage.style.display = 'block';
        chartMessage.innerHTML     = `<span style="color:#f85149">⚠ ${err.message}</span>`;
        console.error('loadPair error:', err);
        if (throwOnError) throw err;
    } finally {
        loadingOverlay.style.display = 'none';
        headerLoading.style.display  = 'none';
    }
}

// ═══════════════════════════════════════════════════════
//  TRADE OVERLAYS
// ═══════════════════════════════════════════════════════
function clearTradeOverlays() {
    if (candlestickSeries) {
        tradeOverlayLines.forEach(l => { try { candlestickSeries.removePriceLine(l); } catch(e){} });
    }
    tradeOverlayLines = [];
    tradeMarkers      = [];
}

function addTradeOverlays(meta) {
    clearTradeOverlays();
    if (!candlestickSeries || !fullChartData) return;
    const isUp = meta.direction === 'UP';
    tradeMarkers.push({
        time:     meta.alertTimestamp,
        position: isUp ? 'belowBar' : 'aboveBar',
        shape:    isUp ? 'arrowUp'  : 'arrowDown',
        color:    isUp ? '#3fb950'  : '#f85149',
        text:     isUp ? '▲ BUY'    : '▼ SELL',
    });
    const allMarkers = [...(fullChartData.alerts || []), ...tradeMarkers].sort((a, b) => a.time - b.time);
    candlestickSeries.setMarkers(allMarkers);
}

// ═══════════════════════════════════════════════════════
//  JUMP TO TRADE WINDOW
// ═══════════════════════════════════════════════════════
function jumpToTradeWindow(alertTs, winLossTs) {
    if (!alertTs || !chart || !fullChartData?.candlesticks?.length) return;
    const candles = fullChartData.candlesticks;

    let alertIdx = -1, minDiff = Infinity;
    candles.forEach((c, i) => {
        const d = Math.abs(c.time - alertTs);
        if (d < minDiff) { minDiff = d; alertIdx = i; }
    });
    if (alertIdx === -1) return;

    let wlIdx = -1;
    if (winLossTs) {
        let minDiff2 = Infinity;
        candles.forEach((c, i) => {
            const d = Math.abs(c.time - winLossTs);
            if (d < minDiff2) { minDiff2 = d; wlIdx = i; }
        });
    }

    const fromIdx   = Math.max(0, alertIdx - 60);
    const anchorIdx = wlIdx !== -1 ? wlIdx : alertIdx;
    const toIdx     = Math.min(candles.length - 1, anchorIdx + 80);

    chart.timeScale().setVisibleRange({
        from: candles[fromIdx].time,
        to:   candles[toIdx].time,
    });
}

// ═══════════════════════════════════════════════════════
//  TRADE INFO BAR UI
// ═══════════════════════════════════════════════════════
function updateTradeInfoBar(meta) {
    const isUp   = meta.direction === 'UP';
    const isWin  = meta.tradeResult === 'win';
    const isLoss = meta.tradeResult === 'loss';
    const pair   = meta.pair;

    document.getElementById('tib-id').textContent   = `#${meta.id}`;
    document.getElementById('tib-pair').textContent = pair;

    const dirEl = document.getElementById('tib-dir');
    dirEl.textContent = isUp ? '▲ BUY' : '▼ SELL';
    dirEl.className   = isUp ? 'tib-dir-up' : 'tib-dir-down';

    document.getElementById('tib-alert-time').textContent = formatISTshort(meta.alertTimeIST) + ' IST';
    document.getElementById('tib-target').textContent     = priceToFixed(meta.targetPrice, pair);

    const outEl  = document.getElementById('tib-outcome');
    const outSep = document.getElementById('tib-outcome-sep');
    const wlEl   = document.getElementById('tib-wl-info');

    if (isWin) {
        outEl.innerHTML  = '<span class="outcome-win-badge">✓ WIN</span>'
            + (meta.winLossPrice ? ` <span class="tib-val" style="color:#10b981"> @ ${priceToFixed(meta.winLossPrice, pair)}</span>` : '');
        wlEl.textContent = meta.winLossTimeIST ? `  ${formatISTshort(meta.winLossTimeIST)} IST` : '';
        outSep.style.display = '';
    } else if (isLoss) {
        outEl.innerHTML  = '<span class="outcome-loss-badge">✗ LOSS</span>'
            + (meta.winLossPrice ? ` <span class="tib-val" style="color:#ef4444"> @ ${priceToFixed(meta.winLossPrice, pair)}</span>` : '');
        wlEl.textContent = meta.winLossTimeIST ? `  ${formatISTshort(meta.winLossTimeIST)} IST` : '';
        outSep.style.display = '';
    } else {
        outEl.innerHTML      = '<span class="outcome-pending-badge">Pending / N/A</span>';
        wlEl.textContent     = '';
        outSep.style.display = 'none';
    }

    document.getElementById('trade-info-bar').style.display  = 'flex';
    document.getElementById('clear-trade-btn').style.display = 'inline-flex';
    jumpTradeBtn.style.display = 'inline-block';
}

function clearTradeInfo() {
    clearTradeOverlays();
    if (candlestickSeries && fullChartData) {
        candlestickSeries.setMarkers(fullChartData.alerts || []);
        chart.timeScale().fitContent();
    }
    currentTradeData = null;
    document.getElementById('trade-info-bar').style.display  = 'none';
    document.getElementById('clear-trade-btn').style.display = 'none';
    jumpTradeBtn.style.display                               = 'none';
    document.getElementById('trade-id-input').value          = '';
}

// ═══════════════════════════════════════════════════════
//  LOAD TRADE BY ID
// ═══════════════════════════════════════════════════════
async function loadTradeById() {
    const input = document.getElementById('trade-id-input');
    const btn   = document.getElementById('load-trade-btn');
    const id    = parseInt(input.value);

    if (isNaN(id) || id <= 0) { alert('Please enter a valid Trade ID.'); input.focus(); return; }

    btn.disabled = true;
    loadingText.textContent      = `Fetching Trade #${id} from database…`;
    loadingOverlay.style.display = 'flex';
    chartMessage.style.display   = 'none';

    try {
        const resp = await fetch(`?ajax=1&trade_id=${id}`, { credentials: 'same-origin' });
        if (!resp.ok) throw new Error(`Server error: HTTP ${resp.status}`);
        const meta = await resp.json();
        if (meta.error) throw new Error(meta.error);

        currentTradeData = meta;
        updateTradeInfoBar(meta);

        loadingText.textContent = `Loading ${meta.pair} chart data…`;
        try {
            await loadPair(meta.pair, true, meta.alertTimestamp);
        } catch (csvErr) {
            return;
        }

        addTradeOverlays(meta);
        jumpToTradeWindow(meta.alertTimestamp, meta.winLossTimestamp);

    } catch (err) {
        loadingOverlay.style.display = 'none';
        chartMessage.style.display   = 'block';
        chartMessage.innerHTML       = `<span style="color:#f85149">⚠ ${err.message}</span>`;
        console.error('loadTradeById error:', err);
    } finally {
        btn.disabled = false;
    }
}

// ═══════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════
buildPairGrid();
buildSwatches();
</script>
</body>
</html>