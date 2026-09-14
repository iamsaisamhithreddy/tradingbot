<?php
/**
 * trade_simulator.php
 */

require_once __DIR__ . '/bootstrap.php';

// ─── AJAX: get all signals ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['get_signals'])) {
    header('Content-Type: application/json');

    $sql = "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.price_target,
                   p.last_alert_time,
                   o.trade_result, o.win_loss_time, o.win_loss_price
            FROM prediction_trade_data p
            INNER JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
            WHERE o.trade_result IN ('win', 'loss')
            ORDER BY p.raw_trade_id DESC
            LIMIT 200";

    $res = $conn->query($sql);
    $signals = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            try {
                $dt = new DateTime($row['last_alert_time'], $utcTimezone);
                $alertUnix = $dt->getTimestamp();
            } catch (Exception $e) {
                $alertUnix = 0;
            }

            $signals[] = [
                'id'          => (int)$row['raw_trade_id'],
                'pair'        => $row['pair_name'],
                'direction'   => $row['trade_direction'],
                'target'      => (float)$row['price_target'],
                'alertTime'   => $row['last_alert_time'],
                'alertUnix'   => $alertUnix,
                'result'      => $row['trade_result'] ?? 'pending',
                'winLossTime' => $row['win_loss_time'],
                'winLossPrice'=> $row['win_loss_price'] !== null ? (float)$row['win_loss_price'] : null,
            ];
        }
    }

    echo json_encode(['success' => true, 'signals' => $signals, 'count' => count($signals)]);
    exit;
}

// ─── AJAX: get CSV data for a pair ───────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['get_csv'])) {
    header('Content-Type: application/json');

    $pair = preg_replace('/[^A-Z0-9_]/', '', strtoupper($_GET['pair'] ?? ''));
    if (empty($pair)) {
        echo json_encode(['error' => 'No pair specified']);
        exit;
    }

    $csvDirs = [
        $_SERVER['DOCUMENT_ROOT'] . '/dataset/dataset/',
        __DIR__ . '/../../dataset/dataset/',
        __DIR__ . '/../dataset/dataset/',
        __DIR__ . '/dataset/dataset/',
        __DIR__ . '/dataset/',
        __DIR__ . '/csv_data/',
        __DIR__ . '/',
    ];

    $csvPath = null;
    foreach ($csvDirs as $dir) {
        $patterns = [
            $dir . 'FX_' . $pair . '.csv',
            $dir . $pair . '.csv',
        ];
        foreach ($patterns as $p) {
            if (file_exists($p)) { $csvPath = $p; break 2; }
        }
        $globs = glob($dir . '*' . $pair . '*.csv');
        if (!empty($globs)) { $csvPath = $globs[0]; break; }
    }

    if (!$csvPath) {
        echo json_encode(['error' => "CSV not found for pair: $pair", 'tried' => array_map(fn($d)=>$d.'FX_'.$pair.'.csv', $csvDirs)]);
        exit;
    }

    $candles = [];
    $handle = fopen($csvPath, 'r');
    $isHeader = true;
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if ($isHeader) { $isHeader = false; continue; }
        if (count($row) < 5) continue;
        $t = (int)$row[0];
        if ($t < 100000) continue;
        $candles[] = [
            'time'   => $t,
            'open'   => (float)$row[1],
            'high'   => (float)$row[2],
            'low'    => (float)$row[3],
            'close'  => (float)$row[4],
            'volume' => isset($row[6]) ? (float)$row[6] : 0,
        ];
    }
    fclose($handle);

    echo json_encode(['success' => true, 'pair' => $pair, 'candles' => $candles, 'count' => count($candles)]);
    exit;
}

// ─── AJAX: get news events for a date range ───────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['get_news'])) {
    header('Content-Type: application/json');

    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d');

    // sanitize
    $dateFrom = preg_replace('/[^0-9\-]/', '', $dateFrom);
    $dateTo   = preg_replace('/[^0-9\-]/', '', $dateTo);

    $sql = "SELECT id, event_name, impact, event_time, event_date
            FROM economic_events
            WHERE event_date BETWEEN '$dateFrom' AND '$dateTo'
            ORDER BY event_time ASC";

    $res = $conn->query($sql);
    $events = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            try {
                $dt = new DateTime($row['event_time'], $utcTimezone);
                $unixTime = $dt->getTimestamp();
            } catch (Exception $e) {
                $unixTime = 0;
            }
            $events[] = [
                'id'        => (int)$row['id'],
                'name'      => $row['event_name'],
                'impact'    => (int)$row['impact'],
                'time'      => $row['event_time'],
                'date'      => $row['event_date'],
                'unix'      => $unixTime,
            ];
        }
    }

    echo json_encode(['success' => true, 'events' => $events, 'count' => count($events)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>5-Candle Simulator</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
<style>
:root {
  --bg: #080c12;
  --surface: #0d1117;
  --surface2: #131920;
  --surface3: #1a2230;
  --border: #1e2d3d;
  --border2: #243447;
  --accent: #3b82f6;
  --accent-dim: rgba(59,130,246,.12);
  --green: #22c55e;
  --green-dim: rgba(34,197,94,.12);
  --red: #ef4444;
  --red-dim: rgba(239,68,68,.12);
  --yellow: #f59e0b;
  --yellow-dim: rgba(245,158,11,.12);
  --purple: #a855f7;
  --text: #e2e8f0;
  --text2: #64748b;
  --text3: #94a3b8;
  --radius: 8px;
  --radius-sm: 5px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Inter', sans-serif;
  font-size: 13px;
  line-height: 1.5;
  display: flex;
  flex-direction: column;
  height: 100vh;
}

/* ── HEADER ─────────────────────────────── */
.hdr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  height: 52px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
  gap: 12px;
}
.logo {
  font-size: 15px;
  font-weight: 700;
  letter-spacing: -.3px;
  white-space: nowrap;
}
.logo em { color: var(--accent); font-style: normal; }
.hdr-stats {
  display: flex;
  align-items: center;
  gap: 4px;
}
.hdr-stat {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}
.hdr-stat .val {
  font-family: 'JetBrains Mono', monospace;
  font-size: 14px;
  font-weight: 600;
}
.hdr-stat .lbl {
  font-size: 10px;
  color: var(--text2);
  text-transform: uppercase;
  letter-spacing: .4px;
}
.acc-pill {
  padding: 5px 12px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 99px;
  font-size: 12px;
  color: var(--text3);
  white-space: nowrap;
}
.acc-pill b { font-family: 'JetBrains Mono', monospace; color: var(--yellow); }

/* ── LAYOUT ─────────────────────────────── */
.workspace {
  display: flex;
  flex: 1;
  overflow: hidden;
  min-height: 0;
}

/* ── SIDEBAR ─────────────────────────────── */
.sidebar {
  width: 260px;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  flex-shrink: 0;
}
.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
.sidebar::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }

.s-sec {
  padding: 14px;
  border-bottom: 1px solid var(--border);
}
.s-sec:last-child { flex: 1; border-bottom: none; }
.s-title {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--text2);
  margin-bottom: 10px;
}

/* ── BUTTONS ─────────────────────────────── */
.btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  border: 1px solid transparent;
  font-family: 'Inter', sans-serif;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all .15s;
  margin-bottom: 5px;
}
.btn:last-child { margin-bottom: 0; }
.btn:disabled { opacity: .35; cursor: not-allowed !important; transform: none !important; box-shadow: none !important; }
.btn-primary { background: var(--accent); color: #fff; border-color: var(--accent); }
.btn-primary:hover:not(:disabled) { background: #2563eb; }
.btn-ghost { background: var(--surface2); color: var(--text3); border-color: var(--border); }
.btn-ghost:hover:not(:disabled) { border-color: var(--border2); color: var(--text); }

/* ── TRADE BUTTONS ─────────────────────────── */
.trade-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
.tb {
  padding: 16px 8px;
  border-radius: var(--radius);
  border: 1.5px solid;
  font-family: 'Inter', sans-serif;
  font-weight: 700;
  font-size: 15px;
  cursor: pointer;
  transition: all .2s;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.tb:disabled { opacity: .2; cursor: not-allowed; }
.tb-buy {
  background: var(--green-dim);
  border-color: var(--green);
  color: var(--green);
}
.tb-buy:hover:not(:disabled) {
  background: rgba(34,197,94,.22);
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(34,197,94,.3);
}
.tb-sell {
  background: var(--red-dim);
  border-color: var(--red);
  color: var(--red);
}
.tb-sell:hover:not(:disabled) {
  background: rgba(239,68,68,.22);
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(239,68,68,.3);
}
.tb .arr { font-size: 20px; line-height: 1; }
.tb-label { font-size: 13px; }

@keyframes pulse-g { 0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,.4); } 50% { box-shadow: 0 0 0 6px rgba(34,197,94,0); } }
@keyframes pulse-r { 0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,.4); } 50% { box-shadow: 0 0 0 6px rgba(239,68,68,0); } }
.glow-g { animation: pulse-g 1.2s ease-in-out infinite; }
.glow-r { animation: pulse-r 1.2s ease-in-out infinite; }

/* ── ACTION BTNS ─────────────────────────── */
.btn-skip, .btn-peek {
  width: 100%;
  padding: 7px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  background: var(--surface2);
  color: var(--text3);
  font-family: 'Inter', sans-serif;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all .15s;
  margin-bottom: 5px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
.btn-skip:hover:not(:disabled) { border-color: var(--yellow); color: var(--yellow); }
.btn-peek:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
.btn-skip:disabled, .btn-peek:disabled { opacity: .2; cursor: not-allowed; }

/* ── TIMER ─────────────────────────────── */
.timer-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px 0 4px;
}
#tw { position: relative; width: 68px; height: 68px; }
#tw svg { transform: rotate(-90deg); }
#tw circle.bg { fill: none; stroke: var(--surface3); stroke-width: 5; }
#tw circle.pg { fill: none; stroke: var(--accent); stroke-width: 5; stroke-linecap: round; transition: stroke-dashoffset 1s linear, stroke .3s; stroke-dasharray: 201; stroke-dashoffset: 0; }
#tn { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-family: 'JetBrains Mono', monospace; font-size: 17px; font-weight: 600; }

/* ── PHASE DOTS ─────────────────────────── */
.phase-row { display: flex; align-items: center; gap: 4px; }
.pd { width: 7px; height: 7px; border-radius: 50%; background: var(--border); transition: all .3s; flex-shrink: 0; }
.pd.done { background: var(--green); }
.pd.active { background: var(--accent); box-shadow: 0 0 6px var(--accent); }
.pl { flex: 1; height: 1px; background: var(--border); }
.phase-label { font-size: 10px; color: var(--text2); margin-top: 5px; text-align: center; }

/* ── RESULT BOX ─────────────────────────── */
#sbox {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px;
  min-height: 64px;
  text-align: center;
  font-size: 12px;
  color: var(--text2);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  line-height: 1.7;
}
.rl { font-size: 24px; font-weight: 800; letter-spacing: 1px; }
.rl.win { color: var(--green); }
.rl.loss { color: var(--red); }

/* ── DECISION PROMPT ─────────────────────── */
#dprompt {
  display: none;
  background: var(--accent-dim);
  border: 1px solid rgba(59,130,246,.3);
  border-radius: var(--radius-sm);
  padding: 7px 10px;
  text-align: center;
  font-size: 11px;
  font-weight: 600;
  color: var(--accent);
  margin-bottom: 8px;
  animation: blink-border 1.4s ease-in-out infinite;
}
@keyframes blink-border { 0%,100% { border-color: rgba(59,130,246,.3); } 50% { border-color: var(--accent); } }

/* ── HOW IT WORKS ─────────────────────────── */
.how-row {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  margin-bottom: 6px;
  font-size: 11px;
  color: var(--text3);
  line-height: 1.5;
}
.how-row:last-child { margin-bottom: 0; }
.badge {
  display: inline-flex;
  align-items: center;
  padding: 1px 5px;
  border-radius: 3px;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: .3px;
  flex-shrink: 0;
  margin-top: 1px;
}
.b-blue { background: var(--accent-dim); color: var(--accent); }
.b-yellow { background: var(--yellow-dim); color: var(--yellow); }
.b-green { background: var(--green-dim); color: var(--green); }
.b-red { background: var(--red-dim); color: var(--red); }
.b-purple { background: rgba(168,85,247,.12); color: var(--purple); }

/* ── TRADE LOG ─────────────────────────── */
#hist { max-height: 110px; overflow-y: auto; }
#hist::-webkit-scrollbar { width: 3px; }
#hist::-webkit-scrollbar-thumb { background: var(--border); }
.hi {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 0;
  border-bottom: 1px solid var(--border);
  font-size: 11px;
}
.hi:last-child { border: none; }
.hd { font-weight: 700; font-size: 9px; padding: 2px 5px; border-radius: 3px; }
.hd.buy { background: var(--green-dim); color: var(--green); }
.hd.sell { background: var(--red-dim); color: var(--red); }
.hd.skip { background: var(--yellow-dim); color: var(--yellow); }
.hr2 { font-weight: 700; font-size: 11px; margin-left: auto; }
.hr2.win { color: var(--green); }
.hr2.loss { color: var(--red); }

/* ── CHART AREA ─────────────────────────── */
.chart-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
  position: relative;
}
.chart-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
  flex-shrink: 0;
}
.pair-name { font-size: 17px; font-weight: 700; letter-spacing: -.3px; }
.tf-badge {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 2px 7px;
  font-size: 11px;
  color: var(--text3);
}
.ohlc-row {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--text3);
  display: flex;
  gap: 10px;
}

/* ── CHART CONTAINER ─────────────────────── */
.chart-wrap { position: relative; flex: 1; min-height: 0; }
#cm { width: 100%; height: 100%; }

/* ── PROGRESS BAR ─────────────────────────── */
#pbw { height: 2px; background: var(--surface2); flex-shrink: 0; }
#pb { height: 100%; background: var(--accent); transition: width .2s; }

/* ── INFO BAR ─────────────────────────────── */
#ibar {
  padding: 6px 16px;
  background: var(--surface);
  border-top: 1px solid var(--border);
  font-size: 11px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  flex-shrink: 0;
}
.ibar-lbl { color: var(--text2); }
.chip {
  padding: 2px 8px;
  border-radius: 4px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 500;
}
.chip-g { background: var(--green-dim); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
.chip-r { background: var(--red-dim); color: var(--red); border: 1px solid rgba(239,68,68,.25); }
.chip-y { background: var(--yellow-dim); color: var(--yellow); border: 1px solid rgba(245,158,11,.3); }
.chip-n { background: var(--surface2); color: var(--text3); border: 1px solid var(--border); }
#ib-pnl { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 600; margin-left: auto; }

/* ── OVERLAY ─────────────────────────────── */
#cov {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(8,12,18,.9);
  border: 1px solid var(--border2);
  border-radius: 12px;
  padding: 20px 32px;
  text-align: center;
  display: none;
  backdrop-filter: blur(8px);
  pointer-events: none;
  z-index: 10;
}
#cov .big { font-size: 22px; font-weight: 800; margin-bottom: 4px; }
#cov .sub { font-size: 12px; color: var(--text3); }
.c-g { color: var(--green); }
.c-y { color: var(--yellow); }
.c-b { color: var(--accent); }

/* ── FLASH ─────────────────────────────── */
#flash {
  position: fixed;
  inset: 0;
  z-index: 999;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  opacity: 0;
  transition: opacity .3s;
}
#flash .ft { font-size: 72px; font-weight: 800; letter-spacing: 4px; }
#flash.win { background: rgba(34,197,94,.06); }
#flash.loss { background: rgba(239,68,68,.06); }
#flash.win .ft { color: var(--green); text-shadow: 0 0 40px rgba(34,197,94,.6); }
#flash.loss .ft { color: var(--red); text-shadow: 0 0 40px rgba(239,68,68,.6); }

/* ── NEWS PANEL ─────────────────────────── */
.news-panel {
  position: absolute;
  top: 0;
  right: 0;
  width: 280px;
  height: 100%;
  background: var(--surface);
  border-left: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  z-index: 20;
  transform: translateX(100%);
  transition: transform .25s ease;
}
.news-panel.open { transform: translateX(0); }
.news-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  border-bottom: 1px solid var(--border);
}
.news-panel-header h2 { font-size: 13px; font-weight: 600; }
.news-close {
  background: none;
  border: none;
  color: var(--text2);
  cursor: pointer;
  font-size: 16px;
  padding: 2px 6px;
  border-radius: 4px;
  transition: color .15s;
}
.news-close:hover { color: var(--text); }
.news-date-filter {
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  display: flex;
  gap: 6px;
  align-items: center;
}
.news-date-filter input {
  flex: 1;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 4px;
  color: var(--text);
  font-size: 11px;
  padding: 5px 8px;
  font-family: 'JetBrains Mono', monospace;
}
.news-date-filter input:focus { outline: none; border-color: var(--accent); }
.news-fetch-btn {
  padding: 5px 10px;
  background: var(--accent);
  border: none;
  border-radius: 4px;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
}
.news-fetch-btn:hover { background: #2563eb; }
.news-list { flex: 1; overflow-y: auto; padding: 8px; }
.news-list::-webkit-scrollbar { width: 3px; }
.news-list::-webkit-scrollbar-thumb { background: var(--border); }
.news-item {
  padding: 9px 10px;
  border-radius: 6px;
  border: 1px solid var(--border);
  margin-bottom: 5px;
  background: var(--surface2);
  cursor: pointer;
  transition: all .15s;
}
.news-item:hover { border-color: var(--border2); background: var(--surface3); }
.news-item.active { border-color: var(--accent); background: var(--accent-dim); }
.news-item-top {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
}
.news-impact {
  display: flex;
  gap: 2px;
  flex-shrink: 0;
}
.imp-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--border2);
}
.imp-1 .imp-dot:nth-child(1) { background: var(--yellow); }
.imp-2 .imp-dot:nth-child(1), .imp-2 .imp-dot:nth-child(2) { background: #f97316; }
.imp-3 .imp-dot:nth-child(1), .imp-3 .imp-dot:nth-child(2), .imp-3 .imp-dot:nth-child(3) { background: var(--red); }
.news-name { font-size: 11px; font-weight: 500; color: var(--text); flex: 1; line-height: 1.3; }
.news-time-badge {
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  color: var(--text2);
  flex-shrink: 0;
}
.news-on-chart {
  display: inline-block;
  margin-top: 4px;
  font-size: 9px;
  font-weight: 600;
  color: var(--green);
  background: var(--green-dim);
  border-radius: 3px;
  padding: 1px 5px;
}

/* ── NEWS TOGGLE BTN ─────────────────────── */
#news-toggle {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 21;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 5px 10px;
  font-size: 11px;
  font-weight: 600;
  color: var(--text3);
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  gap: 5px;
}
#news-toggle:hover { border-color: var(--accent); color: var(--accent); }
#news-toggle.has-news { border-color: rgba(245,158,11,.4); color: var(--yellow); }

/* ── SETTINGS SLIDERS ─────────────────────── */
.slider-row { margin-bottom: 10px; }
.slider-label {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: var(--text3);
  margin-bottom: 4px;
}
.slider-label span { font-family: 'JetBrains Mono', monospace; color: var(--accent); }
input[type=range] { width: 100%; accent-color: var(--accent); }

/* ── SIG COUNT ─────────────────────────── */
#sig-count {
  background: var(--accent-dim);
  border: 1px solid rgba(59,130,246,.2);
  border-radius: 5px;
  padding: 6px 9px;
  font-size: 11px;
  color: var(--text3);
  margin-bottom: 8px;
  display: none;
}
#sig-count b { color: var(--accent); }

/* ── MARTINGALE NOTE ─────────────────────── */
.mart-note {
  border-radius: 6px;
  padding: 6px 9px;
  font-size: 11px;
  margin-top: 6px;
  line-height: 1.5;
}
.mart-win { background: var(--green-dim); border: 1px solid rgba(34,197,94,.25); color: var(--green); }
.mart-warn { background: var(--yellow-dim); border: 1px solid rgba(245,158,11,.3); color: var(--yellow); }
.mart-loss { background: var(--red-dim); border: 1px solid rgba(239,68,68,.25); color: var(--red); }

/* ── NEWS SECTION HEADERS ─────────────── */
.news-section-hdr {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .6px;
  padding: 5px 8px;
  border-radius: 5px;
  margin-bottom: 5px;
  margin-top: 6px;
}
.news-section-hdr:first-child { margin-top: 0; }
.high-impact-item {
  border-color: rgba(239,68,68,.35) !important;
  background: rgba(239,68,68,.06) !important;
}
.high-impact-item:hover {
  border-color: var(--red) !important;
  background: rgba(239,68,68,.12) !important;
}

/* ── POST-RESULT CANDLE SECTION ─────────── */
.post-candles-section {
  margin-top: 8px;
  border-top: 1px solid var(--border);
  padding-top: 8px;
}
.pcs-title {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .8px;
  color: var(--text2);
  margin-bottom: 6px;
}
.impact-info {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 8px;
  border-radius: 5px;
  font-size: 11px;
  margin-bottom: 5px;
}
.impact-info.imp1 { background: var(--yellow-dim); border: 1px solid rgba(245,158,11,.25); }
.impact-info.imp2 { background: rgba(249,115,22,.1); border: 1px solid rgba(249,115,22,.25); }
.impact-info.imp3 { background: var(--red-dim); border: 1px solid rgba(239,68,68,.25); }
</style>
</head>
<body>

<div id="flash"><div class="ft" id="ftxt"></div></div>

<!-- HEADER -->
<header class="hdr">
  <div class="logo">5-Candle<em>Sim</em></div>
  <div class="hdr-stats">
    <div class="hdr-stat">
      <div class="val" style="color:var(--green)" id="sw">0</div>
      <div class="lbl">Wins</div>
    </div>
    <div class="hdr-stat">
      <div class="val" style="color:var(--red)" id="slo">0</div>
      <div class="lbl">Losses</div>
    </div>
    <div class="hdr-stat">
      <div class="val" style="color:var(--yellow)" id="ss">0</div>
      <div class="lbl">Streak</div>
    </div>
    <div class="hdr-stat">
      <div class="val" style="color:var(--text3)" id="stot">0</div>
      <div class="lbl">Total</div>
    </div>
    <div class="acc-pill">Accuracy: <b id="sa">—</b></div>
  </div>
</header>

<div class="workspace">

<!-- SIDEBAR -->
<div class="sidebar">

  <div class="s-sec">
    <div class="s-title">Database</div>
    <div id="sig-count"></div>
    <button class="btn btn-primary" id="btn-load" onclick="loadSignals()">⟳ Load Signals</button>
    <div style="font-size:10px;color:var(--text2);margin-top:4px;">From prediction_trade_data</div>
  </div>

  <div class="s-sec">
    <div class="s-title">Settings</div>
    <div class="slider-row">
      <div class="slider-label">Context candles <span id="rv-lb">35</span></div>
      <input type="range" id="r-lb" min="10" max="80" value="35" oninput="document.getElementById('rv-lb').textContent=this.value">
    </div>
    <div class="slider-row">
      <div class="slider-label">Speed ms/candle <span id="rv-sp">200</span></div>
      <input type="range" id="r-sp" min="60" max="1500" value="200" step="40" oninput="document.getElementById('rv-sp').textContent=this.value">
    </div>
    <div class="slider-row">
      <div class="slider-label">Decision timer sec <span id="rv-tm">20</span></div>
      <input type="range" id="r-tm" min="5" max="60" value="20" oninput="document.getElementById('rv-tm').textContent=this.value">
    </div>
  </div>

  <div class="s-sec">
    <div class="s-title">Session</div>
    <button class="btn btn-primary" id="btn-start" onclick="startRound()" disabled>▶ Next Signal</button>
    <button class="btn btn-ghost" onclick="resetSession()">↺ Reset Score</button>
  </div>

  <div class="s-sec">
    <div class="s-title">Phase</div>
    <div class="phase-row">
      <div class="pd" id="ph1"></div><div class="pl"></div>
      <div class="pd" id="ph2"></div><div class="pl"></div>
      <div class="pd" id="ph3"></div><div class="pl"></div>
      <div class="pd" id="ph4"></div>
    </div>
    <div class="phase-label" id="plbl">—</div>
  </div>

  <div class="s-sec">
    <div id="dprompt">⚡ Level hit! BUY or SELL?</div>
    <div class="s-title">Your Trade</div>
    <div class="trade-grid">
      <button class="tb tb-buy" id="btn-buy" onclick="placeTrade('BUY')" disabled>
        <span class="arr">▲</span>
        <span class="tb-label">BUY</span>
      </button>
      <button class="tb tb-sell" id="btn-sell" onclick="placeTrade('SELL')" disabled>
        <span class="arr">▼</span>
        <span class="tb-label">SELL</span>
      </button>
    </div>
    <button class="btn-skip" id="btn-skip" onclick="placeTrade('SKIP')" disabled>🚫 Skip / No Trade</button>
    <button class="btn-peek" id="btn-peek" onclick="peekNextCandle()" disabled>👁 See Next Candle</button>
    <div class="timer-wrap">
      <div id="tw">
        <svg viewBox="0 0 68 68" width="68" height="68">
          <circle class="bg" cx="34" cy="34" r="32"/>
          <circle class="pg" id="tprog" cx="34" cy="34" r="32"/>
        </svg>
        <div id="tn">—</div>
      </div>
    </div>
    <div style="font-size:10px;text-align:center;color:var(--text2)">seconds left</div>
  </div>

  <div class="s-sec">
    <div class="s-title">Result</div>
    <div id="sbox">Click "Load Signals" to begin</div>
  </div>

  <div class="s-sec">
    <div class="s-title">How It Works</div>
    <div class="how-row"><span class="badge b-blue">DB</span>Pulls real trades from prediction_trade_data</div>
    <div class="how-row"><span class="badge b-yellow">LEVEL</span>Uses price_target as the key level</div>
    <div class="how-row"><span class="badge b-green">WAIT</span>Candles play until price hits the level</div>
    <div class="how-row"><span class="badge b-red">CALL</span>You decide BUY or SELL at the moment</div>
    <div class="how-row"><span class="badge b-purple">NEWS</span>Check nearby news before trading!</div>
    <div style="margin-top:6px;font-size:10px;color:var(--red);font-weight:600;">🚫 No Monday &nbsp;|&nbsp; ✅ After 12:30 IST</div>
  </div>

  <div class="s-sec" style="flex:1;">
    <div class="s-title">Trade Log</div>
    <div id="hist"><div style="color:var(--text2);font-size:11px;">No trades yet.</div></div>
  </div>

</div>
<!-- /SIDEBAR -->

<!-- CHART AREA -->
<div class="chart-area">
  <div id="pbw"><div id="pb" style="width:0%"></div></div>

  <div class="chart-header">
    <div class="pair-name" id="pdsp">—</div>
    <div class="tf-badge" id="tfb">5M</div>
    <div class="ohlc-row">
      <span style="color:var(--text3)">O:<b id="cio"> —</b></span>
      <span style="color:var(--green)">H:<b id="cih"> —</b></span>
      <span style="color:var(--red)">L:<b id="cil"> —</b></span>
      <span style="color:var(--accent)">C:<b id="cic"> —</b></span>
    </div>
  </div>

  <div class="chart-wrap">
    <div id="cm"></div>

    <!-- Chart overlay message -->
    <div id="cov">
      <div class="big" id="cov-t"></div>
      <div class="sub" id="cov-s"></div>
    </div>

    <!-- News toggle button -->
    <button id="news-toggle" onclick="toggleNewsPanel()">
      📰 News
      <span id="news-count-badge" style="display:none;background:var(--yellow-dim);color:var(--yellow);border-radius:3px;padding:0 4px;font-size:9px;"></span>
    </button>

    <!-- News Panel (slides in from right) -->
    <div class="news-panel" id="news-panel">
      <div class="news-panel-header">
        <h2>📰 Economic News</h2>
        <button class="news-close" onclick="toggleNewsPanel()">✕</button>
      </div>
      <div class="news-date-filter">
        <input type="date" id="news-date-from" placeholder="From">
        <input type="date" id="news-date-to" placeholder="To">
        <button class="news-fetch-btn" onclick="fetchNews()">Go</button>
      </div>
      <div class="news-list" id="news-list">
        <div style="color:var(--text2);font-size:11px;padding:10px;text-align:center;">Pick a date range and tap Go</div>
      </div>
    </div>
  </div>

  <div id="ibar">
    <span class="ibar-lbl">Signal</span><span id="ib-dir" class="chip chip-n">—</span>
    <span class="ibar-lbl">Target</span><span id="ib-tgt" class="chip chip-n">—</span>
    <span class="ibar-lbl">Pair</span><span id="ib-pair" class="chip chip-n">—</span>
    <span class="ibar-lbl">#</span><span id="ib-id" class="chip chip-n">—</span>
    <span id="ib-pnl"></span>
  </div>
</div>
<!-- /CHART AREA -->

</div><!-- /workspace -->

<script>
const BASE = window.location.pathname;

// ══ STATE ════════════════════════════════════════════════════════
let allSignals = [], usedIds = new Set();
let pairCandleCache = {};
let chart = null, cSeries = null, vSeries = null;
let wins = 0, losses = 0, streak = 0, skips = 0;
let timerIv = null, playHandle = null;
let pendingResult = false, roundActive = false;
let activeDir = null, SI = null, entryPrice = null;
let newsEvents = [];       // all fetched news
let newsMarkers = [];      // markers currently on chart

// ══ CHART ════════════════════════════════════════════════════════
function initChart() {
  const el = document.getElementById('cm');
  el.innerHTML = '';
  chart = LightweightCharts.createChart(el, {
    width: el.clientWidth,
    height: el.parentElement.clientHeight || 500,
    layout: { backgroundColor: '#080c12', textColor: '#94a3b8' },
    grid: { vertLines: { color: '#1e2d3d' }, horzLines: { color: '#1e2d3d' } },
    crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
    rightPriceScale: { borderColor: '#1e2d3d' },
    timeScale: { borderColor: '#1e2d3d', timeVisible: true, secondsVisible: false },
  });
  cSeries = chart.addCandlestickSeries({
    upColor: '#22c55e', downColor: '#ef4444',
    borderUpColor: '#22c55e', borderDownColor: '#ef4444',
    wickUpColor: '#22c55e', wickDownColor: '#ef4444',
  });
  vSeries = chart.addHistogramSeries({
    priceFormat: { type: 'volume' }, priceScaleId: 'vol',
    scaleMargins: { top: 0.85, bottom: 0 },
  });
  chart.subscribeCrosshairMove(p => {
    if (!p || !p.seriesPrices) return;
    const d = p.seriesPrices.get(cSeries);
    if (!d) return;
    f5('cio', d.open); f5('cih', d.high); f5('cil', d.low); f5('cic', d.close);
  });
  new ResizeObserver(() => {
    if (chart) chart.resize(el.clientWidth, el.parentElement.clientHeight || 500);
  }).observe(el.parentElement);
}
function f5(id, v) { document.getElementById(id).textContent = ' ' + v.toFixed(5); }
function uci(c) { f5('cio', c.open); f5('cih', c.high); f5('cil', c.low); f5('cic', c.close); }

// ══ LOAD SIGNALS ════════════════════════════════════════════════
async function loadSignals() {
  setStatus('⏳ Fetching signals...');
  document.getElementById('btn-load').disabled = true;
  try {
    const res = await fetch(`${BASE}?ajax=1&get_signals=1`);
    const data = await res.json();
    if (!data.success) { setStatus('❌ DB error: ' + (data.error || 'unknown')); return; }
    allSignals = data.signals;
    usedIds.clear();
    const sc = document.getElementById('sig-count');
    sc.style.display = 'block';
    sc.innerHTML = `✅ <b>${data.count}</b> signals loaded`;
    setStatus(`✅ <b>${data.count}</b> signals ready.<br>Click ▶ Next Signal to train!`);
    document.getElementById('btn-start').disabled = false;
    document.getElementById('btn-start').textContent = `▶ Next Signal (${data.count})`;
  } catch (e) {
    setStatus('❌ Failed: ' + e.message);
  }
  document.getElementById('btn-load').disabled = false;
}

// ══ CANDLES ════════════════════════════════════════════════════
async function getCandlesForPair(pair) {
  if (pairCandleCache[pair]) return pairCandleCache[pair];
  const res = await fetch(`${BASE}?ajax=1&get_csv=1&pair=${encodeURIComponent(pair)}`);
  const data = await res.json();
  if (!data.success || !data.candles) return null;
  pairCandleCache[pair] = data.candles;
  return data.candles;
}

function findSignalCandleIdx(candles, alertUnix) {
  let bestIdx = 0, bestDiff = Infinity;
  for (let i = 0; i < candles.length; i++) {
    const diff = Math.abs(candles[i].time - alertUnix);
    if (diff < bestDiff) { bestDiff = diff; bestIdx = i; }
  }
  return bestIdx;
}

function findLevelBreak(candles, signalIdx, dir, target) {
  const signalTime = candles[signalIdx].time;
  const signalDate = new Date(signalTime * 1000);
  const signalDayStart = new Date(Date.UTC(signalDate.getUTCFullYear(), signalDate.getUTCMonth(), signalDate.getUTCDate())).getTime() / 1000;
  const signalDayEnd = signalDayStart + 86400;
  const hardLimit = Math.min(signalIdx + 500, candles.length - 2);

  // UP signal: target is BELOW current price → wait for close ≤ target (price drops to support)
  // DOWN signal: target is ABOVE current price → wait for close ≥ target (price rises to resistance)
  for (let i = signalIdx + 1; i < hardLimit; i++) {
    const c = candles[i];
    if (c.time >= signalDayEnd) return null;
    if (dir === 'UP')   { if (c.close <= target) return i; }
    else                { if (c.close >= target) return i; }
  }
  return null;
}

// ══ NEWS ════════════════════════════════════════════════════════
function toggleNewsPanel() {
  const p = document.getElementById('news-panel');
  p.classList.toggle('open');
}

async function fetchNews() {
  const from = document.getElementById('news-date-from').value;
  const to = document.getElementById('news-date-to').value;
  if (!from) { alert('Pick a date!'); return; }
  const toDate = to || from;
  const list = document.getElementById('news-list');
  list.innerHTML = '<div style="color:var(--text2);font-size:11px;padding:10px;text-align:center;">Loading...</div>';

  try {
    const res = await fetch(`${BASE}?ajax=1&get_news=1&date_from=${from}&date_to=${toDate}`);
    const data = await res.json();
    newsEvents = data.events || [];
    renderNewsList();
    overlayNewsOnChart();
    updateNewsToggleBadge();
  } catch (e) {
    list.innerHTML = `<div style="color:var(--red);font-size:11px;padding:10px;">Error: ${e.message}</div>`;
  }
}

function renderNewsList() {
  const list = document.getElementById('news-list');
  if (!newsEvents.length) {
    list.innerHTML = '<div style="color:var(--text2);font-size:11px;padding:10px;text-align:center;">No events found</div>';
    return;
  }

  // Split into HIGH / MEDIUM+LOW sections
  const high   = newsEvents.map((e,i)=>({...e,_i:i})).filter(e=>e.impact===3);
  const medium = newsEvents.map((e,i)=>({...e,_i:i})).filter(e=>e.impact===2);
  const low    = newsEvents.map((e,i)=>({...e,_i:i})).filter(e=>e.impact===1);

  function renderItem(e) {
    const impColor = ['','var(--yellow)','#f97316','var(--red)'][e.impact] || 'var(--text2)';
    const impLabel = ['','Low','Medium','HIGH'][e.impact] || '?';
    const timeStr  = e.time ? e.time.split(' ')[1]?.substring(0,5) : '—';
    return `
      <div class="news-item ${e.impact===3?'high-impact-item':''}" id="ni-${e._i}" onclick="scrollChartToNews(${e._i})">
        <div class="news-item-top">
          <div class="news-impact imp-${e.impact}">
            <div class="imp-dot"></div><div class="imp-dot"></div><div class="imp-dot"></div>
          </div>
          <div class="news-name">${e.name}</div>
          <div class="news-time-badge">${timeStr}</div>
        </div>
        <div style="font-size:10px;color:${impColor};font-weight:600;">${impLabel} Impact · ${e.date}</div>
      </div>`;
  }

  let html = '';
  if (high.length) {
    html += `<div class="news-section-hdr" style="color:var(--red);background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);">
      ⚠️ HIGH IMPACT — Shown on chart (${high.length})
    </div>`;
    html += high.map(renderItem).join('');
  }
  if (medium.length) {
    html += `<div class="news-section-hdr" style="color:#f97316;background:rgba(249,115,22,.07);border:1px solid rgba(249,115,22,.2);">
      MEDIUM IMPACT (${medium.length})
    </div>`;
    html += medium.map(renderItem).join('');
  }
  if (low.length) {
    html += `<div class="news-section-hdr" style="color:var(--text2);background:var(--surface3);border:1px solid var(--border);">
      LOW IMPACT (${low.length})
    </div>`;
    html += low.map(renderItem).join('');
  }

  list.innerHTML = html;
}

function overlayNewsOnChart() {
  if (!cSeries || !SI) return;
  const markers = [];

  // Signal arrow — always shown
  const sc = SI.candles[SI.signalIdx];
  markers.push({
    time: sc.time,
    position: SI.sig.direction === 'UP' ? 'belowBar' : 'aboveBar',
    color: SI.sig.direction === 'UP' ? '#22c55e' : '#ef4444',
    shape: SI.sig.direction === 'UP' ? 'arrowUp' : 'arrowDown',
    text: SI.sig.direction === 'UP' ? 'BUY Signal' : 'SELL Signal',
  });

  // ONLY show HIGH impact (3) news on chart — deduplicated by candle time
  const seenTimes = new Set();
  newsEvents
    .filter(ev => ev.impact === 3 && ev.unix)
    .forEach(ev => {
      let best = null, bestDiff = Infinity;
      for (const c of SI.candles) {
        const d = Math.abs(c.time - ev.unix);
        if (d < bestDiff) { bestDiff = d; best = c; }
      }
      if (!best || bestDiff > 7200) return; // within 2 hours
      if (seenTimes.has(best.time)) return;  // one marker per candle
      seenTimes.add(best.time);
      markers.push({
        time: best.time,
        position: 'aboveBar',
        color: '#ef4444',
        shape: 'square',
        text: `⚠️ ${ev.name}`,
      });
    });

  markers.sort((a, b) => a.time - b.time);
  cSeries.setMarkers(markers);
}

function scrollChartToNews(idx) {
  const ev = newsEvents[idx];
  if (!ev || !ev.unix || !chart) return;
  chart.timeScale().scrollToPosition(0, false);
  // highlight
  document.querySelectorAll('.news-item').forEach(el => el.classList.remove('active'));
  const ni = document.getElementById(`ni-${idx}`);
  if (ni) ni.classList.add('active');
}

function updateNewsToggleBadge() {
  const badge = document.getElementById('news-count-badge');
  const toggle = document.getElementById('news-toggle');
  if (newsEvents.length > 0) {
    badge.style.display = 'inline';
    badge.textContent = newsEvents.length;
    toggle.classList.add('has-news');
  } else {
    badge.style.display = 'none';
    toggle.classList.remove('has-news');
  }
}

// Auto-load news when a signal is loaded (same date)
function autoLoadNewsForSignal(sig) {
  if (!sig.alertTime) return;
  const dateStr = sig.alertTime.substring(0, 10);
  document.getElementById('news-date-from').value = dateStr;
  document.getElementById('news-date-to').value = dateStr;
  fetchNews(); // auto-fetch silently
}

// ══ ROUND START ════════════════════════════════════════════════
async function startRound() {
  if (allSignals.length === 0) { alert('Load signals from DB first!'); return; }
  clearPlay(); stopTimer();
  pendingResult = false; roundActive = false; activeDir = null; SI = null; entryPrice = null;
  enableBtns(false); setDP(false); setPhase(0, '');
  document.getElementById('ib-pnl').textContent = '';
  hideOv();
  newsEvents = []; updateNewsToggleBadge();
  if (!chart) initChart();

  const unused = allSignals.filter(s => !usedIds.has(s.id));
  if (unused.length === 0) {
    usedIds.clear();
    setStatus('🔄 All signals used! Reshuffling...');
    setTimeout(startRound, 300); return;
  }

  let sig = null, candles = null, signalIdx = null, breakIdx = null;
  const shuffled = [...unused].sort(() => Math.random() - .5);

  for (const s of shuffled) {
    usedIds.add(s.id);
    setStatus(`⏳ Loading candles for <b>${s.pair}</b>...`);
    candles = await getCandlesForPair(s.pair);
    if (!candles) { setStatus(`⚠️ CSV not found for ${s.pair}, skipping...`); continue; }
    signalIdx = findSignalCandleIdx(candles, s.alertUnix);
    if (signalIdx < 10 || signalIdx >= candles.length - 5) continue;
    breakIdx = findLevelBreak(candles, signalIdx, s.direction, s.target);
    if (breakIdx === null || breakIdx + 1 >= candles.length - 1) continue;
    sig = s; break;
  }

  if (!sig) {
    setStatus('⚠️ No valid signal found. Try reloading signals.');
    return;
  }

  SI = { sig, candles, signalIdx, breakIdx };

  // Update info bar
  document.getElementById('pdsp').textContent = sig.pair;
  const de = document.getElementById('ib-dir');
  de.textContent = sig.direction === 'UP' ? '▲ BUY' : '▼ SELL';
  de.className = 'chip ' + (sig.direction === 'UP' ? 'chip-g' : 'chip-r');
  document.getElementById('ib-tgt').textContent = sig.target.toFixed(5);
  document.getElementById('ib-pair').textContent = sig.pair;
  document.getElementById('ib-id').textContent = '#' + sig.id;

  const lb = parseInt(document.getElementById('r-lb').value);
  const showFrom = Math.max(0, signalIdx - lb);
  const setupCandles = candles.slice(showFrom, signalIdx + 1);

  if (setupCandles.length > 2) {
    const gap = (setupCandles[setupCandles.length - 1].time - setupCandles[0].time) / (setupCandles.length - 1);
    document.getElementById('tfb').textContent = formatTF(gap);
  }

  cSeries.setData(setupCandles.map(c => ({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close })));
  vSeries.setData(setupCandles.map(c => ({ time: c.time, value: c.volume || 0, color: c.close >= c.open ? 'rgba(34,197,94,.35)' : 'rgba(239,68,68,.35)' })));

  // Target level — solid, thick, bright — always visible
  cSeries.createPriceLine({
    price: sig.target,
    color: '#f59e0b',
    lineWidth: 2,
    lineStyle: 0, // solid
    axisLabelVisible: true,
    title: `◆ TARGET ${sig.target.toFixed(5)}`,
  });

  const sc = candles[signalIdx];
  cSeries.setMarkers([{
    time: sc.time,
    position: sig.direction === 'UP' ? 'belowBar' : 'aboveBar',
    color: sig.direction === 'UP' ? '#22c55e' : '#ef4444',
    shape: sig.direction === 'UP' ? 'arrowUp' : 'arrowDown',
    text: sig.direction === 'UP' ? 'BUY Signal' : 'SELL Signal',
  }]);

  chart.timeScale().fitContent();

  // Price scale: for UP signal target is below candles, for DOWN it's above.
  // autoScale includes price lines by default — just re-trigger it cleanly.
  chart.priceScale('right').applyOptions({ autoScale: true });
  setPhase(1, 'Signal formed');

  // Auto-load news for this signal's date
  autoLoadNewsForSignal(sig);

  const dist = breakIdx - signalIdx;
  setStatus(`${sig.direction === 'UP' ? '🟢' : '🔴'} <b>${sig.direction === 'UP' ? 'BUY' : 'SELL'} Signal</b> — #${sig.id}<br>Pair: <b>${sig.pair}</b> &nbsp;|&nbsp; Level: <b>${sig.target.toFixed(5)}</b><br>~<b>${dist}</b> candles until level hit`);

  showOv(
    sig.direction === 'UP' ? '🟢 BUY Signal — Watch Support' : '🔴 SELL Signal — Watch Resistance',
    sig.direction === 'UP'
      ? `Price drops to ${sig.target.toFixed(5)} → decide BUY`
      : `Price rises to ${sig.target.toFixed(5)} → decide SELL`,
    sig.direction === 'UP' ? 'c-g' : ''
  );

  setTimeout(() => playToBreak(), 1500);
}

// ══ PLAY CANDLES ════════════════════════════════════════════════
function playToBreak() {
  hideOv();
  setPhase(2, 'Price playing...');
  const speed = parseInt(document.getElementById('r-sp').value);
  const { candles, signalIdx, breakIdx } = SI;
  const between = candles.slice(signalIdx + 1, breakIdx);
  let idx = 0;
  setStatus(`${SI.sig.direction === 'UP' ? '📉' : '📈'} Price moving to level <b>${SI.sig.target.toFixed(5)}</b><br>${SI.sig.direction === 'UP' ? 'Waiting for price to DROP to support' : 'Waiting for price to RISE to resistance'}`);

  function step() {
    if (idx >= between.length) { clearPlay(); setTimeout(() => playBreakCandle(), 300); return; }
    const c = between[idx];
    cSeries.update({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close });
    vSeries.update({ time: c.time, value: c.volume || 0, color: c.close >= c.open ? 'rgba(34,197,94,.35)' : 'rgba(239,68,68,.35)' });
    uci(c);
    chart.timeScale().scrollToRealTime();
    document.getElementById('pb').style.width = ((idx + 1) / (between.length + 1) * 95) + '%';
    if (between.length - idx === 4) showOv('👀 Getting close...', 'Price approaching level', 'c-y');
    if (between.length - idx === 1) showOv('⚡ Almost there!', 'Level about to hit!', 'c-b');
    idx++;
    playHandle = setTimeout(step, speed);
  }
  step();
}

function playBreakCandle() {
  setPhase(3, 'Level hit!');
  const speed = parseInt(document.getElementById('r-sp').value);
  const bc = SI.candles[SI.breakIdx];
  const { open, high, low, close } = bc;
  const steps = 16; let step = 0, announcedHit = false;
  const tol = Math.abs(SI.sig.target) * 0.0005;
  setStatus(`🎯 <b>Level candle forming!</b><br>Get ready to decide...`);
  showOv('🎯 Level candle forming...', 'Get ready!', 'c-b');
  cSeries.update({ time: bc.time, open, high: open, low: open, close: open });
  vSeries.update({ time: bc.time, value: bc.volume || 0, color: 'rgba(148,163,184,.2)' });

  playHandle = setInterval(() => {
    step++;
    const p = step / steps;
    const cH = open + (high - open) * Math.min(1, p * 1.4);
    const cL = open + (low - open) * Math.min(1, p * 1.4);
    const cC = open + (close - open) * p;
    cSeries.update({ time: bc.time, open, high: cH, low: cL, close: cC });
    uci({ open, high: cH, low: cL, close: cC });
    if (!announcedHit) {
      // UP signal: target below, price dropped to it → detect via low
      // DOWN signal: target above, price rose to it → detect via high
      const hit = SI.sig.direction === 'UP'
        ? (cL <= SI.sig.target + tol)
        : (cH >= SI.sig.target - tol);
      if (hit) { announcedHit = true; showOv('⚡ LEVEL HIT!', `${SI.sig.target.toFixed(5)} touched!`, 'c-g'); }
    }
    if (step >= steps) {
      clearPlay();
      cSeries.update({ time: bc.time, open, high, low, close });
      vSeries.update({ time: bc.time, value: bc.volume || 0, color: close >= open ? 'rgba(34,197,94,.5)' : 'rgba(239,68,68,.5)' });
      uci(bc);
      setTimeout(() => askTrade(), 500);
    }
  }, speed * 0.6);
}

// ══ ASK TRADE ════════════════════════════════════════════════
function askTrade() {
  hideOv();
  setPhase(4, 'YOUR CALL!');
  roundActive = true; enableBtns(true); setDP(true);
  document.getElementById('btn-buy').classList.add('glow-g');
  document.getElementById('btn-sell').classList.add('glow-r');

  // Check nearby news and show warning
  const breakTime = SI.candles[SI.breakIdx].time;
  const nearNews = newsEvents.filter(ev => ev.unix && Math.abs(ev.unix - breakTime) < 3600);
  let newsWarning = '';
  if (nearNews.length > 0) {
    const highImpact = nearNews.filter(n => n.impact >= 2);
    if (highImpact.length > 0) {
      newsWarning = `<div style="margin-top:5px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:5px;padding:5px 7px;font-size:10px;color:var(--red);">⚠️ ${highImpact.length} high-impact news nearby! Trade carefully.</div>`;
    } else {
      newsWarning = `<div style="margin-top:5px;font-size:10px;color:var(--yellow);">📰 ${nearNews.length} news events near this candle</div>`;
    }
  }

  const dir = SI.sig.direction;
  setStatus(`⚡ <b>Level Hit!</b><br>Signal: <b>${dir === 'UP' ? '🟢 BUY' : '🔴 SELL'}</b><br>What will next candle do?${newsWarning}`);
  startTimer(parseInt(document.getElementById('r-tm').value));
}

// ══ PLACE TRADE ════════════════════════════════════════════════
let peekOffset = 0;

function placeTrade(dir) {
  if (pendingResult || !roundActive) return;
  pendingResult = true; activeDir = dir;
  enableBtns(false); setDP(false); stopTimer();
  document.getElementById('btn-buy').classList.remove('glow-g');
  document.getElementById('btn-sell').classList.remove('glow-r');

  if (dir === 'SKIP') {
    const nextIdx = SI.breakIdx + peekOffset + 1;
    const nc = SI.candles[nextIdx];
    if (nc) {
      cSeries.update({ time: nc.time, open: nc.open, high: nc.high, low: nc.low, close: nc.close });
      vSeries.update({ time: nc.time, value: nc.volume || 0, color: nc.close >= nc.open ? 'rgba(34,197,94,.35)' : 'rgba(239,68,68,.35)' });
      uci(nc);
    }
    const wouldHaveBeen = nc ? (nc.close > nc.open ? 'bullish 🟢' : 'bearish 🔴') : '—';
    skips++;
    setStatus(`🚫 <b>Skipped</b> — discipline!<br>Next candle: <b>${wouldHaveBeen}</b><br>DB result: <b>${SI.sig.result}</b>`);
    updateStats(); addHist('SKIP', null, SI.sig.pair);
    roundActive = false; pendingResult = false; setPhase(0, 'Skipped');
    const rem = allSignals.filter(s => !usedIds.has(s.id)).length;
    document.getElementById('btn-start').textContent = `▶ Next Signal (${rem} left)`;
    setTimeout(() => startRound(), 2500);
    return;
  }

  const nextIdx = SI.breakIdx + peekOffset + 1;
  const nc = SI.candles[nextIdx];
  if (!nc) { setStatus('⚠️ No candle after break.'); pendingResult = false; roundActive = false; return; }

  entryPrice = nc.open;
  cSeries.createPriceLine({
    price: entryPrice,
    color: dir === 'BUY' ? '#22c55e' : '#ef4444',
    lineWidth: 1.5, lineStyle: 0, axisLabelVisible: true,
    title: `${dir} @ ${entryPrice.toFixed(5)}`
  });

  setStatus(`🎯 <b style="color:${dir === 'BUY' ? 'var(--green)' : 'var(--red)'};">${dir}</b> @ ${entryPrice.toFixed(5)}<br>Watching outcome...`);

  const speed = parseInt(document.getElementById('r-sp').value);
  const isJpy = SI.sig.pair.toUpperCase().includes('JPY');
  const pipSize = isJpy ? 0.01 : 0.0001;
  const dbResult = SI.sig.result;
  const isWinFromDB = dbResult === 'win';

  // Never cross into the next day — clamp to same day as break candle
  const breakDayEnd = getDayEnd(SI.candles[SI.breakIdx].time);
  const fwdCandles = SI.candles.slice(nextIdx, nextIdx + 40).filter(c => c.time < breakDayEnd);
  let pidx = 0;

  playHandle = setInterval(() => {
    if (pidx >= fwdCandles.length) { clearPlay(); finishRound(isWinFromDB); return; }
    const c = fwdCandles[pidx];
    cSeries.update({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close });
    vSeries.update({ time: c.time, value: c.volume || 0, color: c.close >= c.open ? 'rgba(34,197,94,.35)' : 'rgba(239,68,68,.35)' });
    uci(c);
    // Keep chart scrolled to show the latest candle
    chart.timeScale().scrollToRealTime();
    const pnl = dir === 'BUY' ? c.close - entryPrice : entryPrice - c.close;
    const pips = Math.round(pnl / pipSize);
    const pe = document.getElementById('ib-pnl');
    pe.textContent = (pips >= 0 ? '+' : '') + pips + ' pips';
    pe.style.color = pips >= 0 ? 'var(--green)' : 'var(--red)';
    document.getElementById('pb').style.width = ((pidx + 1) / fwdCandles.length * 100) + '%';
    pidx++;
    if (pidx >= 5 && dbResult !== 'pending') {
      clearPlay();
      // Play extra candles — but NEVER cross into next day
      const martStart = nextIdx + pidx;
      let mIdx = 0;
      doFlash(isWinFromDB ? 'WIN!' : 'LOSS', isWinFromDB ? 'win' : 'loss');

      // ── NEWS POST-RESULT EXTRA CANDLES ────────────────────
      // Find if any nearby news has impact — if so, show 10 candles
      const breakTime = SI.candles[SI.breakIdx].time;
      const nearNews = newsEvents.filter(ev => ev.unix && Math.abs(ev.unix - breakTime) < 7200);
      const maxImpact = nearNews.reduce((m, n) => Math.max(m, n.impact), 0);
      const extraCount = maxImpact >= 1 ? 10 : 3;
      // Clamp to same day — no next-day candles
      const extraCandles = SI.candles
        .slice(martStart, martStart + extraCount)
        .filter(c => c.time < breakDayEnd);

      let newsImpactNote = '';
      if (maxImpact >= 1) {
        const impName = ['', 'Low', 'Medium', 'High'][maxImpact];
        const impCls = ['', 'mart-win', 'mart-warn', 'mart-loss'][maxImpact] || 'mart-win';
        newsImpactNote = `<div class="mart-note ${impCls}">📰 ${impName}-impact news nearby — showing 10 post-candles</div>`;
      }

      setStatus(`${isWinFromDB
        ? '<div class="rl win">✅ WIN!</div>'
        : '<div class="rl loss">❌ LOSS</div>'}
        <div style="font-size:11px;color:var(--text3);">DB: <b>${dbResult}</b></div>
        ${newsImpactNote}
        <div style="font-size:10px;color:var(--text2);margin-top:3px;">Showing ${extraCount} more candles...</div>`);

      const mPlay = setInterval(() => {
        if (mIdx >= extraCandles.length) {
          clearInterval(mPlay);
          finishRound(isWinFromDB);
          return;
        }
        const mc = extraCandles[mIdx];
        if (mc) {
          cSeries.update({ time: mc.time, open: mc.open, high: mc.high, low: mc.low, close: mc.close });
          vSeries.update({ time: mc.time, value: mc.volume || 0, color: mc.close >= mc.open ? 'rgba(34,197,94,.35)' : 'rgba(239,68,68,.35)' });
          uci(mc);
          chart.timeScale().scrollToRealTime();
        }
        mIdx++;
      }, speed * 1.2);
    }
  }, speed);
}

// ══ FINISH ROUND ════════════════════════════════════════════════
function finishRound(isWin) {
  const actualResult = SI.sig.result;
  const dir = activeDir;

  const martStart = SI.breakIdx + peekOffset + 1;
  const breakDayEndFR = getDayEnd(SI.candles[SI.breakIdx].time);

  // Only check candles within the same trading day for martingale
  function sameDayCandle(idx) {
    const c = SI.candles[idx];
    if (!c || c.time >= breakDayEndFR) return null;
    return c;
  }
  const c1 = sameDayCandle(martStart + 1);
  const c2 = sameDayCandle(martStart + 2);

  function dirMatch(c) {
    if (!c) return false;
    return dir === 'BUY' ? c.close > c.open : c.close < c.open;
  }
  const m1 = dirMatch(c1);
  const m2 = dirMatch(c2);

  let martNote = '';
  if (!isWin) {
    if (m1) {
      martNote = `<div class="mart-note mart-win">✅ Martingale: Next candle (C+1) would recover!</div>`;
    } else if (m2) {
      martNote = `<div class="mart-note mart-warn">⚠️ C+1 against, C+2 matches — 2nd martingale recovers</div>`;
    } else {
      martNote = `<div class="mart-note mart-loss">❌ No martingale recovery — both C+1 & C+2 against</div>`;
    }
  } else {
    if (m1 && m2) {
      martNote = `<div class="mart-note mart-win">🔥 Next 2 candles also ${dir === 'BUY' ? 'bullish' : 'bearish'} — strong momentum!</div>`;
    }
  }

  if (isWin) {
    wins++; streak = streak > 0 ? streak + 1 : 1;
    setStatus(`<div class="rl win">✅ WIN!</div><div style="font-size:11px;color:var(--text3);">DB: <b>${actualResult}</b></div>${martNote}<div style="font-size:10px;color:var(--text2);margin-top:3px;">Next in 4s...</div>`);
    doFlash('WIN!', 'win');
  } else {
    losses++; streak = streak < 0 ? streak - 1 : -1;
    setStatus(`<div class="rl loss">❌ LOSS</div><div style="font-size:11px;color:var(--text3);">DB: <b>${actualResult}</b></div>${martNote}<div style="font-size:10px;color:var(--text2);margin-top:3px;">Next in 4s...</div>`);
    doFlash('LOSS', 'loss');
  }

  updateStats();
  addHist(activeDir, isWin, SI.sig.pair);
  roundActive = false; pendingResult = false; setPhase(0, 'Done');
  const rem = allSignals.filter(s => !usedIds.has(s.id)).length;
  document.getElementById('btn-start').textContent = `▶ Next Signal (${rem} left)`;
  setTimeout(() => startRound(), 4000);
}

// ══ TIMER ════════════════════════════════════════════════════
function startTimer(sec) {
  const pg = document.getElementById('tprog');
  document.getElementById('tn').textContent = sec;
  pg.style.stroke = 'var(--accent)'; pg.style.strokeDashoffset = 0;
  let rem = sec;
  timerIv = setInterval(() => {
    rem--;
    document.getElementById('tn').textContent = rem;
    pg.style.strokeDashoffset = ((sec - rem) / sec) * 201;
    if (rem <= 8) pg.style.stroke = 'var(--yellow)';
    if (rem <= 4) pg.style.stroke = 'var(--red)';
    if (rem <= 0) {
      stopTimer();
      if (!pendingResult && roundActive) {
        enableBtns(false); setDP(false);
        document.getElementById('btn-buy').classList.remove('glow-g');
        document.getElementById('btn-sell').classList.remove('glow-r');
        pendingResult = true; roundActive = false;
        losses++; streak = streak < 0 ? streak - 1 : -1;
        updateStats();
        addHist('MISS', false, SI?.sig?.pair || '');
        setStatus(`⏰ <b>Time's up!</b> — LOSS<br><div style="font-size:11px;color:var(--text2);margin-top:3px;">Next signal in 3s...</div>`);
        pendingResult = false;
        const rem2 = allSignals.filter(s => !usedIds.has(s.id)).length;
        document.getElementById('btn-start').textContent = `▶ Next Signal (${rem2} left)`;
        setTimeout(() => startRound(), 3000);
      }
    }
  }, 1000);
}
function stopTimer() { clearInterval(timerIv); document.getElementById('tn').textContent = '—'; }

// ══ PEEK ════════════════════════════════════════════════════════
function peekNextCandle() {
  if (!roundActive || !SI) return;
  peekOffset++;
  const revealIdx = SI.breakIdx + peekOffset;
  const nc = SI.candles[revealIdx];
  if (!nc) { setStatus('⚠️ No more candles to peek.'); document.getElementById('btn-peek').disabled = true; return; }
  cSeries.update({ time: nc.time, open: nc.open, high: nc.high, low: nc.low, close: nc.close });
  vSeries.update({ time: nc.time, value: nc.volume || 0, color: nc.close >= nc.open ? 'rgba(34,197,94,.25)' : 'rgba(239,68,68,.25)' });
  uci(nc);
  chart.timeScale().scrollToRealTime();
  const dir = nc.close > nc.open ? '🟢 Bullish' : '🔴 Bearish';
  document.getElementById('btn-peek').textContent = `👁 See +${peekOffset + 1} Candle`;
  setStatus(`👁 <b>Peek ×${peekOffset}:</b> Candle #${peekOffset} is <b>${dir}</b><br>Trade now or peek more?`);
}

// ══ UTILS ════════════════════════════════════════════════════════
function clearPlay() { clearInterval(playHandle); clearTimeout(playHandle); playHandle = null; }

// Returns the UTC midnight timestamp of the day AFTER the given unix time
function getDayEnd(unixTime) {
  const d = new Date(unixTime * 1000);
  return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + 1) / 1000;
}
function enableBtns(on) {
  document.getElementById('btn-buy').disabled = !on;
  document.getElementById('btn-sell').disabled = !on;
  document.getElementById('btn-skip').disabled = !on;
  document.getElementById('btn-peek').disabled = !on;
  if (on) { peekOffset = 0; document.getElementById('btn-peek').textContent = '👁 See Next Candle'; }
}
function setStatus(h) { document.getElementById('sbox').innerHTML = h; }
function setDP(s) { document.getElementById('dprompt').style.display = s ? 'block' : 'none'; }
function showOv(t, s, cls) {
  document.getElementById('cov-t').className = 'big ' + (cls || 'c-b');
  document.getElementById('cov-t').textContent = t;
  document.getElementById('cov-s').textContent = s;
  document.getElementById('cov').style.display = 'block';
}
function hideOv() { document.getElementById('cov').style.display = 'none'; }
function setPhase(n, l) {
  for (let i = 1; i <= 4; i++) {
    const e = document.getElementById('ph' + i);
    e.className = 'pd';
    if (i < n) e.classList.add('done');
    else if (i === n) e.classList.add('active');
  }
  document.getElementById('plbl').textContent = l;
}
function doFlash(t, type) {
  const e = document.getElementById('flash');
  document.getElementById('ftxt').textContent = t;
  e.className = type;
  e.style.opacity = 1;
  setTimeout(() => e.style.opacity = 0, 1400);
}
function updateStats() {
  document.getElementById('sw').textContent = wins;
  document.getElementById('slo').textContent = losses;
  document.getElementById('ss').textContent = streak;
  document.getElementById('stot').textContent = wins + losses + skips;
  const t = wins + losses;
  document.getElementById('sa').textContent = t > 0 ? Math.round(wins / t * 100) + '% (' + skips + ' skip)' : '—';
}
function addHist(dir, win, pair) {
  const list = document.getElementById('hist');
  if (list.querySelector('div[style]')) list.innerHTML = '';
  const item = document.createElement('div');
  item.className = 'hi';
  const dc = dir === 'BUY' ? 'buy' : dir === 'SELL' ? 'sell' : 'skip';
  const resHtml = win === null
    ? `<span class="hr2" style="color:var(--yellow);">SKIP</span>`
    : `<span class="hr2 ${win ? 'win' : 'loss'}">${win ? '✅ W' : '❌ L'}</span>`;
  item.innerHTML = `<span class="hd ${dc}">${dir}</span><span style="color:var(--text2);font-size:10px;">${pair}</span>${resHtml}`;
  list.prepend(item);
}
function resetSession() {
  wins = 0; losses = 0; streak = 0; skips = 0;
  usedIds.clear(); updateStats();
  document.getElementById('hist').innerHTML = '<div style="color:var(--text2);font-size:11px;">No trades yet.</div>';
  setStatus('Score reset.');
  setPhase(0, '');
}
function formatTF(s) {
  if (s < 120) return s + 's';
  if (s < 3600) return Math.round(s / 60) + 'M';
  if (s < 86400) return Math.round(s / 3600) + 'H';
  return Math.round(s / 86400) + 'D';
}

window.addEventListener('DOMContentLoaded', () => { initChart(); });
</script>
</body>
</html>