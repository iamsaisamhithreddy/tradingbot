<?php
// Check if the clear cache button was clicked
if (isset($_GET['clear_cache']) && $_GET['clear_cache'] == '1') {
    if (function_exists('opcache_reset')) opcache_reset();
    if (function_exists('apcu_clear_cache')) apcu_clear_cache();
    $cleanUrl = strtok($_SERVER["REQUEST_URI"], '?');
    echo "<script>alert('Cache cleared successfully!');window.location.href='{$cleanUrl}';</script>";
    exit;
}
?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/maintenance_check.php';

date_default_timezone_set("Asia/Kolkata");
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$loggedInUser = $_SESSION['admin_username'];
$permissions  = $_SESSION['admin_permissions'] ?? [];

function generateSessionBanner() {
    $now = new DateTime("now");
    $current_time = $now->format("H:i");
    $dayOfWeek = $now->format("l");
    $currentDateDisplay = $now->format("d/m/Y");

    $sessions = [
        "Sydney"   => ["02:30", "11:30"],
        "Tokyo"    => ["05:30", "14:30"],
        "London"   => ["12:30", "21:30"],
        "New York" => ["17:30", "02:30"],
    ];

    $active = [];
    foreach ($sessions as $name => [$start, $end]) {
        if ($start < $end) {
            if ($current_time >= $start && $current_time <= $end) $active[] = $name;
        } else {
            if ($current_time >= $start || $current_time <= $end) $active[] = $name;
        }
    }

    if (count($active) > 1) {
        $sessionLabel = "Overlap: " . implode(" + ", $active);
        $state = "overlap";
    } elseif (count($active) == 1) {
        $sessionLabel = $active[0];
        $state = strtolower(str_replace(' ', '_', $active[0]));
    } else {
        $sessionLabel = "No Active Session";
        $state = "none";
    }

    $confidence = "Normal";
    if (strpos($sessionLabel, "London") !== false && strpos($sessionLabel, "New York") !== false) {
        $confidence = "Very High (London + NY Overlap)";
    } elseif (strpos($sessionLabel, "London") !== false && $current_time >= "13:30") {
        $confidence = "Boosted";
    } elseif (strpos($sessionLabel, "London") !== false || strpos($sessionLabel, "New York") !== false) {
        $confidence = "High";
    }
    if ($current_time >= "21:30" && strpos($sessionLabel, "New York") !== false) {
        $confidence = "Low (Late NY)";
    }

    if ($dayOfWeek === "Saturday" || $dayOfWeek === "Sunday") {
        $note = "🚫 Weekend — Forex markets are CLOSED. No trading today.";
    } elseif ($dayOfWeek === "Monday") {
        $note = "⚠️ Markets can be volatile on Monday.";
    } elseif ($dayOfWeek === "Friday" && $current_time >= "20:00") {
        $note = "⚠️ Friday late session: liquidity often drops.";
    } else {
        $note = "ℹ️ Moderate volatility expected.";
    }

    $gradientClass = "session-bar--neutral";
    if ($dayOfWeek === "Saturday" || $dayOfWeek === "Sunday") {
        $gradientClass = "session-bar--weekend";
    } elseif ($state === "overlap") {
        $gradientClass = "session-bar--overlap";
    } elseif ($state === "london" || $state === "new_york") {
        $gradientClass = "session-bar--highlight";
    } elseif ($state === "tokyo" || $state === "sydney") {
        $gradientClass = "session-bar--calm";
    }

    $html  = '<div class="session-bar ' . $gradientClass . '">';
    $html .= '  <div class="session-bar__left">';
    $html .= '    <div class="session-bar__title">🌍 ACTIVE SESSION</div>';
    $html .= '    <div class="session-bar__session"><strong>' . htmlspecialchars($sessionLabel) . '</strong></div>';
    $html .= '  </div>';
    $html .= '  <div class="session-bar__right">';
    $html .= '    <div class="session-bar__time">⏰ ' . htmlspecialchars($current_time) . ' IST</div>';
    $html .= '    <div class="session-bar__date">📅 ' . htmlspecialchars($currentDateDisplay) . '</div>';
    $html .= '    <div class="session-bar__confidence">📊 ' . htmlspecialchars($confidence) . '</div>';
    $html .= '  </div>';
    $html .= '  <div class="session-bar__note">📝 ' . htmlspecialchars($note) . '</div>';
    $html .= '</div>';
    return $html;
}

if (isset($_GET['action']) && $_GET['action'] === 'session_status') {
    header('Content-Type: text/html; charset=utf-8');
    echo generateSessionBanner();
    exit;
}

function getCount($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) return (int)$row['c'];
    return 0;
}

$nowIST = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
$currentIST = $nowIST->format("Y-m-d H:i:s");
$todayIST   = $nowIST->format("Y-m-d");

$usersCount    = getCount($conn, "SELECT COUNT(*) AS c FROM telegram_users");
$pendingEvents = getCount($conn, "SELECT COUNT(*) AS c FROM economic_events WHERE sent_status = 0 AND event_time > '$currentIST' AND DATE(event_time) = '$todayIST'");
$validPairs    = 20;

$istZone  = new DateTimeZone('Asia/Kolkata');
$utcZone  = new DateTimeZone('UTC');
$istStart = new DateTime($todayIST . ' 00:00:00', $istZone);
$istEnd   = clone $istStart;
$istEnd->modify('+1 day');
$istStartUTC = $istStart->setTimezone($utcZone)->format('Y-m-d H:i:s');
$istEndUTC   = $istEnd->setTimezone($utcZone)->format('Y-m-d H:i:s');

$totalAlertsToday = getCount($conn, "SELECT COUNT(*) AS c FROM prediction_trade_data WHERE last_alert_time >= '$istStartUTC' AND last_alert_time < '$istEndUTC'");

$winStatsQuery = "SELECT SUM(CASE WHEN trade_result LIKE 'win%' THEN 1 ELSE 0 END) AS wins, SUM(CASE WHEN trade_result LIKE 'loss%' THEN 1 ELSE 0 END) AS losses FROM prediction_trade_data";
$winResult   = $conn->query($winStatsQuery);
$winData     = $winResult->fetch_assoc();
$totalWins   = (int)$winData['wins'];
$totalLosses = (int)$winData['losses'];
$totalClosed = $totalWins + $totalLosses;
$winPercentage = ($totalClosed > 0) ? round(($totalWins / $totalClosed) * 100, 2) : 0;

// Server Health
$load       = function_exists('sys_getloadavg') ? sys_getloadavg() : [0,0,0];
$cpuLoad    = round($load[0], 2);
$cpuPercent = min(($cpuLoad / 4) * 100, 100);
$diskTotal  = disk_total_space('/');
$diskFree   = disk_free_space('/');
$diskUsed   = $diskTotal - $diskFree;
$diskUsagePercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
$dbSizeRes = $conn->query("SELECT SUM(data_length + index_length) / 1024 / 1024 AS mb FROM information_schema.tables WHERE table_schema = DATABASE()");
$dbSizeMb  = ($dbSizeRes && $row = $dbSizeRes->fetch_assoc()) ? round($row['mb'], 2) : 0;

// Recent Trades
$recentTrades = [];
$res = $conn->query("SELECT pair, trade_result, last_alert_time FROM prediction_trade_data ORDER BY id DESC LIMIT 5");
if ($res) while ($r = $res->fetch_assoc()) $recentTrades[] = $r;

// Upcoming News (event_time stored as IST)
$upcomingNews = [];
$newsRes = $conn->query("SELECT * FROM economic_events WHERE event_time >= '$currentIST' AND sent_status = 0 ORDER BY event_time ASC LIMIT 5");
if ($newsRes) while ($r = $newsRes->fetch_assoc()) $upcomingNews[] = $r;

// Live Prices
$livePrices = [];
$livePriceRes = $conn->query("SELECT * FROM live_price_data ORDER BY pair_name ASC");
if ($livePriceRes) while ($r = $livePriceRes->fetch_assoc()) $livePrices[$r['pair_name']] = $r;

// ── ESP32 Heartbeat Status ──
$esp32Status   = null;
$esp32Online   = false;
$esp32LastSeen = 'Never';
$esp32Ip       = '—';
$esp32Rssi     = '—';
$esp32Heap     = '—';
$esp32Uptime   = '—';

$espJsonFile = __DIR__ . '/esp_status.json';
if (file_exists($espJsonFile)) {
    $espRow = json_decode(file_get_contents($espJsonFile), true);
    if ($espRow) {
        $esp32Status  = $espRow;
        $esp32Ip      = $espRow['ip'] ?? '—';
        $esp32Rssi    = $espRow['rssi'] ?? '—';
        $esp32Heap    = isset($espRow['free_heap']) ? round($espRow['free_heap'] / 1024, 1) . ' KB' : '—';
        $uptimeSec    = (int)($espRow['uptime'] ?? 0);
        $uptimeMins   = floor($uptimeSec / 60);
        $uptimeHrs    = floor($uptimeMins / 60);
        $uptimeMins   = $uptimeMins % 60;
        $esp32Uptime  = $uptimeHrs . 'h ' . $uptimeMins . 'm';
        $secondsAgo   = time() - (int)($espRow['last_seen'] ?? 0);
        $esp32Online  = ($espRow['last_seen'] > 0 && $secondsAgo < 45);
        $esp32LastSeen = $espRow['last_seen'] > 0 ? $secondsAgo . ' seconds ago' : 'Never';
    }
}

// ── Proximity Alert Monitor ──
$proximityData = [];
$proxRes = $conn->query("
    SELECT p.raw_trade_id, p.pair_name, p.price_target, p.trade_direction,
           l.current_price, l.updated_at
    FROM prediction_trade_data p
    LEFT JOIN live_price_data l ON l.pair_name = p.pair_name
    WHERE DATE(CONVERT_TZ(p.last_alert_time, @@session.time_zone, '+05:30'))
          = DATE(CONVERT_TZ(NOW(), @@session.time_zone, '+05:30'))
    ORDER BY p.pair_name ASC
");
if ($proxRes) {
    while ($r = $proxRes->fetch_assoc()) {
        $target  = (float)$r['price_target'];
        $current = (float)$r['current_price'];
        $diff    = abs($current - $target);
        $pipSize = (stripos($r['pair_name'], 'JPY') !== false) ? 0.01 : 0.0001;
        $pips    = round($diff / $pipSize, 1);
        $tol     = $target * 0.00005;
        if ($current >= $target - $tol && $current <= $target + $tol) {
            $zone = 'in';
        } elseif ($pips <= 10) {
            $zone = 'close';
        } else {
            $zone = 'far';
        }
        $r['pips'] = $pips;
        $r['zone'] = $zone;
        $proximityData[] = $r;
    }
}

// ── Dataset Health ──
$datasetHealth = [];
$healthRes = $conn->query("
    SELECT pair_name, updated_at,
           TIMESTAMPDIFF(MINUTE, updated_at, UTC_TIMESTAMP()) AS mins_ago
    FROM live_price_data
    ORDER BY pair_name ASC
");
if ($healthRes) {
    while ($r = $healthRes->fetch_assoc()) {
        $minsAgo = (int)$r['mins_ago'];
        $r['status']   = $minsAgo <= 2 ? 'fresh' : ($minsAgo <= 10 ? 'stale' : 'dead');
        $r['mins_ago'] = $minsAgo;
        $datasetHealth[] = $r;
    }
}
$freshCount = count(array_filter($datasetHealth, fn($r) => $r['status'] === 'fresh'));
$staleCount = count(array_filter($datasetHealth, fn($r) => $r['status'] === 'stale'));
$deadCount  = count(array_filter($datasetHealth, fn($r) => $r['status'] === 'dead'));

// ── Next High-Impact News Event (for countdown) ──
// event_time is stored as IST in the DB
$nextHighNews = null;
$nextHighRes = $conn->query("
    SELECT event_name, impact,
           event_time AS event_ist,
           UNIX_TIMESTAMP(CONVERT_TZ(event_time, '+05:30', '+00:00')) AS event_unix
    FROM economic_events
    WHERE impact IN (2,3)
      AND sent_status = 0
      AND event_time >= '$currentIST'
    ORDER BY event_time ASC
    LIMIT 1
");
if ($nextHighRes && $row = $nextHighRes->fetch_assoc()) {
    $nextHighNews = $row;
}

// ── Telegram Bot Health ──
$botToken = defined('BOT_TOKEN') ? BOT_TOKEN : (isset($botToken) ? $botToken : '');
if (empty($botToken)) {
    $botToken = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
}
$telegramBotOk     = false;
$telegramBotName   = '—';
$telegramBotStatus = 'Unknown';
$telegramLastSent  = '—';

if (!empty($botToken)) {
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/getMe");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $tgResp = curl_exec($ch);
    $tgErr  = curl_errno($ch);
    curl_close($ch);
    if (!$tgErr && $tgResp) {
        $tgJson = json_decode($tgResp, true);
        if (!empty($tgJson['ok'])) {
            $telegramBotOk     = true;
            $telegramBotName   = '@' . ($tgJson['result']['username'] ?? 'unknown');
            $telegramBotStatus = 'Online';
        } else {
            $telegramBotStatus = 'API Error';
        }
    } else {
        $telegramBotStatus = 'Unreachable';
    }
} else {
    $telegramBotStatus = 'No Token';
}

$lastSentRes = $conn->query("SELECT MAX(last_alert_time) AS last_sent FROM prediction_trade_data WHERE last_alert_time IS NOT NULL");
if ($lastSentRes) {
    $row = $lastSentRes->fetch_assoc();
    if (!empty($row['last_sent'])) {
        $telegramLastSent = date('M d, g:i A', strtotime($row['last_sent']));
    }
}

// ── Trades Today vs Historical Daily Average ──
$tradesToday = getCount($conn, "
    SELECT COUNT(*) AS c FROM prediction_trade_data
    WHERE last_alert_time >= '$istStartUTC' AND last_alert_time < '$istEndUTC'
");

$historicalAvgRes = $conn->query("
    SELECT ROUND(AVG(daily_count), 1) AS avg_daily
    FROM (
        SELECT DATE(CONVERT_TZ(last_alert_time, '+00:00', '+05:30')) AS trade_date,
               COUNT(*) AS daily_count
        FROM prediction_trade_data
        WHERE last_alert_time IS NOT NULL
          AND DATE(CONVERT_TZ(last_alert_time, '+00:00', '+05:30')) < CURDATE()
        GROUP BY trade_date
    ) daily_counts
");
$historicalAvg = 0;
if ($historicalAvgRes && $row = $historicalAvgRes->fetch_assoc()) {
    $historicalAvg = (float)($row['avg_daily'] ?? 0);
}
$tradeVsAvgPercent = ($historicalAvg > 0) ? round(($tradesToday / $historicalAvg) * 100) : 0;
$tradeVsAvgDiff    = $tradesToday - $historicalAvg;
$tradeVsAvgLabel   = $tradeVsAvgDiff >= 0 ? '+' . round($tradeVsAvgDiff, 1) : round($tradeVsAvgDiff, 1);
$tradeVsAvgColor   = $tradeVsAvgDiff >= 0 ? '#10b981' : '#ef4444';


// ── Read maintenance state for kill switch widget ──
$_maintFile = __DIR__ . '/maintenance.json';
$_maintData = file_exists($_maintFile) ? (json_decode(file_get_contents($_maintFile), true) ?? []) : [];
$_maintOn   = !empty($_maintData['enabled']);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Admin Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  /* ── THEME VARIABLES ── */
  :root {
    --bg-body:       #f0f4f8;
    --bg-panel:      #ffffff;
    --bg-sub:        #f8fafc;
    --bg-row:        #f1f5f9;
    --text-main:     #1e293b;
    --text-sub:      #64748b;
    --text-meta:     #94a3b8;
    --border:        #e2e8f0;
    --border-row:    #f1f5f9;
    --header-bg:     #ffffff;
    --progress-bg:   #e2e8f0;
    --shadow:        0 4px 15px rgba(0,0,0,.05);
    --shadow-hdr:    0 4px 20px rgba(0,0,0,.03);
    --clock-bg:      #ffffff;
    --clock-overlay: rgba(255,255,255,.96);
    --input-bg:      #f1f5f9;
  }
  [data-theme="dark"] {
    --bg-body:       #0f172a;
    --bg-panel:      #1e293b;
    --bg-sub:        #0f172a;
    --bg-row:        #1e293b;
    --text-main:     #f1f5f9;
    --text-sub:      #94a3b8;
    --text-meta:     #64748b;
    --border:        #334155;
    --border-row:    #334155;
    --header-bg:     #1e293b;
    --progress-bg:   #334155;
    --shadow:        0 4px 15px rgba(0,0,0,.3);
    --shadow-hdr:    0 4px 20px rgba(0,0,0,.3);
    --clock-bg:      #1e293b;
    --clock-overlay: rgba(15,23,42,.96);
    --input-bg:      #334155;
  }

  *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
  body{display:flex;min-height:100vh;background:var(--bg-body);color:var(--text-main);transition:background .3s,color .3s;}

  /* Sidebar */
  .sidebar{width:280px;background:linear-gradient(180deg,#0f172a,#020617);color:#fff;display:flex;flex-direction:column;padding:25px 20px;flex-shrink:0;}
  .sidebar h2{margin-bottom:30px;font-weight:800;font-size:1.5rem;text-align:center;letter-spacing:1.5px;color:#38bdf8;text-shadow:0 2px 10px rgba(56,189,248,0.2);}
  .sidebar-section{margin-bottom:25px;}
  .sidebar-section-title{font-size:.75rem;text-transform:uppercase;letter-spacing:1.2px;color:#64748b;margin-bottom:10px;padding-left:15px;font-weight:700;}
  .sidebar a{color:#cbd5e1;text-decoration:none;margin:4px 0;padding:10px 15px;border-radius:8px;display:flex;align-items:center;gap:10px;font-weight:500;font-size:.95rem;transition:all .2s ease;}
  .sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,.1);color:#fff;transform:translateX(4px);}
  .sidebar a.logout{margin-top:auto;background:rgba(239,68,68,.1);color:#fca5a5;}
  .sidebar a.logout:hover{background:#ef4444;color:white;}

  /* Main */
  .main-content{flex-grow:1;padding:30px 40px;overflow-y:auto;}
  header{background:var(--header-bg);padding:20px 30px;border-radius:16px;box-shadow:var(--shadow-hdr);margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;border:1px solid var(--border);}
  header h1{font-weight:700;font-size:1.6rem;color:var(--text-main);}
  header .date-badge{background:var(--input-bg);color:var(--text-sub);padding:8px 16px;border-radius:20px;font-size:.9rem;font-weight:600;}

  .theme-toggle{background:var(--input-bg);border:1px solid var(--border);color:var(--text-main);padding:8px 14px;border-radius:20px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:all .2s;}
  .theme-toggle:hover{background:var(--border);}

  /* Clocks */
  .world-clocks{display:flex;gap:20px;margin-bottom:24px;overflow-x:auto;padding-bottom:5px;}
  .clock-widget{background:var(--clock-bg);flex:1;min-width:220px;padding:20px;border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;align-items:center;justify-content:center;border-top:5px solid #cbd5e1;position:relative;overflow:hidden;border:1px solid var(--border);border-top-width:5px;}
  .clock-widget.ny{border-top-color:#3b82f6;}.clock-widget.lon{border-top-color:#ef4444;}.clock-widget.tok{border-top-color:#f59e0b;}.clock-widget.ist{border-top-color:#10b981;}
  .clock-city{display:flex;align-items:center;justify-content:center;gap:10px;font-size:.85rem;text-transform:uppercase;font-weight:700;color:var(--text-sub);letter-spacing:1px;margin-bottom:12px;}
  .flag-icon{width:24px;height:auto;border-radius:3px;}
  .clock-time{font-size:1.6rem;font-weight:800;color:var(--text-main);font-variant-numeric:tabular-nums;}
  .clock-overlay{position:absolute;top:0;left:0;width:100%;height:100%;background:var(--clock-overlay);backdrop-filter:blur(3px);display:flex;flex-direction:column;align-items:center;justify-content:center;opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);transform:translateY(15px);z-index:10;}
  .clock-widget:hover .clock-overlay{opacity:1;transform:translateY(0);}
  .overlay-title{font-size:.75rem;text-transform:uppercase;font-weight:800;color:var(--text-sub);margin-bottom:10px;letter-spacing:1px;}
  .overlay-pairs{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;padding:0 10px;}
  .pair-badge{padding:5px 10px;border-radius:6px;font-size:.8rem;font-weight:700;border:1px solid transparent;}
  .clock-widget.ny .pair-badge{border-color:#bfdbfe;color:#1d4ed8;background:#eff6ff;}
  .clock-widget.lon .pair-badge{border-color:#fecaca;color:#b91c1c;background:#fef2f2;}
  .clock-widget.tok .pair-badge{border-color:#fde68a;color:#b45309;background:#fffbeb;}
  .clock-widget.ist .pair-badge{border-color:#a7f3d0;color:#047857;background:#ecfdf5;}

  /* Session bar */
  .session-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 25px;border-radius:16px;color:#fff;margin-bottom:25px;box-shadow:0 10px 25px rgba(0,0,0,.08);}
  .session-bar__left{display:flex;flex-direction:column;gap:6px;}
  .session-bar__right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;}
  .session-bar__title{font-weight:700;font-size:.85rem;opacity:.9;letter-spacing:1px;}
  .session-bar__session{font-weight:800;font-size:1.2rem;text-shadow:0 2px 4px rgba(0,0,0,.2);}
  .session-bar__time,.session-bar__date,.session-bar__confidence{font-size:.95rem;font-weight:500;}
  .session-bar__note{margin-left:auto;margin-right:20px;font-size:.95rem;font-weight:500;background:rgba(0,0,0,.15);padding:8px 15px;border-radius:8px;}
  .session-bar--overlap{background:linear-gradient(135deg,#8b5cf6,#ec4899);}
  .session-bar--highlight{background:linear-gradient(135deg,#06b6d4,#3b82f6);}
  .session-bar--calm{background:linear-gradient(135deg,#10b981,#3b82f6);}
  .session-bar--neutral{background:linear-gradient(135deg,#64748b,#475569);}
  .session-bar--weekend{background:linear-gradient(135deg,#374151,#111827);}

  /* News Countdown bar */
  .news-countdown-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 22px;border-radius:14px;margin-bottom:22px;border:2px solid #f59e0b;background:linear-gradient(135deg,#fffbeb,#fef3c7);color:#92400e;}
  [data-theme="dark"] .news-countdown-bar{background:linear-gradient(135deg,#292524,#1c1917);border-color:#b45309;color:#fbbf24;}
  .news-countdown-bar.high-impact{border-color:#ef4444;background:linear-gradient(135deg,#fff1f2,#fee2e2);color:#991b1b;}
  [data-theme="dark"] .news-countdown-bar.high-impact{background:linear-gradient(135deg,#1f0a0a,#1e0000);border-color:#dc2626;color:#f87171;}
  .news-cd-label{font-weight:700;font-size:.95rem;}
  .news-cd-event{font-size:.85rem;font-weight:600;opacity:.85;margin-top:2px;}
  .news-cd-time{font-size:.8rem;margin-top:4px;opacity:.75;}
  .news-cd-timer{font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:2px;}
  .news-cd-impact{font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;background:rgba(0,0,0,.1);}

  /* Stat Cards */
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:25px;}
  .card{padding:25px 20px;border-radius:16px;color:white;box-shadow:0 10px 20px rgba(0,0,0,.06);transition:all .3s;position:relative;overflow:hidden;z-index:1;}
  .card:hover{transform:translateY(-5px);box-shadow:0 15px 30px rgba(0,0,0,.12);}
  .card::after{content:attr(data-icon);position:absolute;right:-5px;bottom:-15px;font-size:5rem;opacity:.15;z-index:-1;line-height:1;filter:grayscale(100%) brightness(200%);}
  .card h2{font-size:2.2rem;font-weight:800;margin-bottom:5px;line-height:1;}
  .card p{font-size:.95rem;font-weight:500;opacity:.9;}
  .card.users{background:linear-gradient(135deg,#4facfe,#00f2fe);}
  .card.events{background:linear-gradient(135deg,#f6d365,#fda085);}
  .card.pairs{background:linear-gradient(135deg,#43e97b,#38f9d7);}
  .card.alerts{background:linear-gradient(135deg,#ff0844,#ffb199);}
  .card.winrate{background:linear-gradient(135deg,#667eea,#764ba2);}

  /* Layout */
  .bottom-panels{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:25px;align-items:start;}
  .panel{background:var(--bg-panel);border-radius:16px;padding:25px;box-shadow:var(--shadow);display:flex;flex-direction:column;border:1px solid var(--border);}
  .panel-title{font-size:1.1rem;font-weight:700;color:var(--text-main);margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;}

  /* Health bars */
  .health-item{margin-bottom:15px;}
  .health-label{display:flex;justify-content:space-between;font-size:.9rem;font-weight:600;color:var(--text-sub);margin-bottom:5px;}
  .progress-bg{background:var(--progress-bg);height:10px;border-radius:10px;overflow:hidden;}
  .progress-fill{height:100%;border-radius:10px;transition:width .5s ease;}

  /* Buttons */
  .btn-action{display:block;width:100%;padding:12px;margin-bottom:10px;border:none;border-radius:8px;font-weight:600;font-size:.95rem;cursor:pointer;transition:all .2s;color:white;text-align:center;text-decoration:none;}
  .btn-primary{background:#3b82f6;}.btn-primary:hover{background:#2563eb;}
  .btn-warning{background:#f59e0b;}.btn-warning:hover{background:#d97706;}
  .btn-danger{background:#ef4444;}.btn-danger:hover{background:#dc2626;}

  /* Lists */
  .feed-list{list-style:none;padding:0;margin:0;flex-grow:1;overflow-y:auto;max-height:250px;}
  .feed-item{padding:12px 0;border-bottom:1px solid var(--border-row);display:flex;justify-content:space-between;align-items:center;gap:10px;}
  .feed-item:last-child{border-bottom:none;}
  .feed-title{font-size:.95rem;font-weight:600;color:var(--text-main);line-height:1.3;}
  .feed-meta{font-size:.8rem;color:var(--text-meta);margin-top:2px;}

  /* Badges */
  .badge{padding:4px 8px;border-radius:12px;font-size:.75rem;font-weight:700;text-transform:uppercase;white-space:nowrap;}
  .badge-win{background:#dcfce7;color:#166534;}
  .badge-loss{background:#fee2e2;color:#991b1b;}
  .badge-pending{background:#fef9c3;color:#854d0e;}
  .badge-high{background:#fee2e2;color:#b91c1c;}
  .badge-medium{background:#ffedd5;color:#c2410c;}
  .badge-low{background:#f1f5f9;color:#475569;}
  [data-theme="dark"] .badge-low{background:#334155;color:#94a3b8;}

  /* ESP32 Widget */
  .esp32-status-widget{display:flex;flex-direction:column;gap:14px;}
  .esp32-top{display:flex;align-items:center;gap:14px;padding:14px;border-radius:12px;background:var(--bg-sub);border:2px solid var(--border);}
  .esp32-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;}
  .esp32-dot.online{background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.2);animation:pulse-green 2s infinite;}
  .esp32-dot.offline{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.2);}
  @keyframes pulse-green{0%,100%{box-shadow:0 0 0 4px rgba(16,185,129,.2);}50%{box-shadow:0 0 0 8px rgba(16,185,129,.05);}}
  .esp32-label{font-size:1rem;font-weight:700;color:var(--text-main);}
  .esp32-sublabel{font-size:.8rem;color:var(--text-sub);margin-top:2px;}
  .esp32-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .esp32-stat{background:var(--bg-sub);border-radius:10px;padding:10px 14px;border:1px solid var(--border);}
  .esp32-stat-label{font-size:.75rem;color:var(--text-meta);text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
  .esp32-stat-val{font-size:1rem;font-weight:700;color:var(--text-main);margin-top:3px;}

  /* Proximity Monitor */
  .prox-table{width:100%;border-collapse:collapse;font-size:.85rem;}
  .prox-table th{text-align:left;padding:8px 10px;background:var(--bg-sub);color:var(--text-sub);font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}
  .prox-table td{padding:9px 10px;border-bottom:1px solid var(--border-row);font-weight:600;color:var(--text-main);vertical-align:middle;}
  .prox-table tr:last-child td{border-bottom:none;}
  .zone-in{display:inline-block;padding:3px 8px;border-radius:20px;background:#dcfce7;color:#166534;font-size:.75rem;font-weight:700;}
  .zone-close{display:inline-block;padding:3px 8px;border-radius:20px;background:#fef9c3;color:#854d0e;font-size:.75rem;font-weight:700;}
  .zone-far{display:inline-block;padding:3px 8px;border-radius:20px;background:var(--bg-row);color:var(--text-sub);font-size:.75rem;font-weight:700;}
  .prox-wrap{overflow-y:auto;max-height:280px;}

  /* Dataset Health */
  .dataset-summary{display:flex;gap:10px;margin-bottom:14px;}
  .ds-badge{flex:1;text-align:center;padding:8px;border-radius:10px;font-weight:700;font-size:.85rem;}
  .ds-badge.fresh{background:#dcfce7;color:#166534;}
  .ds-badge.stale{background:#fef9c3;color:#854d0e;}
  .ds-badge.dead{background:#fee2e2;color:#991b1b;}
  .ds-list{overflow-y:auto;max-height:220px;}
  .ds-item{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-row);font-size:.85rem;}
  .ds-item:last-child{border-bottom:none;}
  .ds-pair{font-weight:700;color:var(--text-main);}
  .ds-time{font-size:.75rem;color:var(--text-meta);}
  .ds-status{padding:3px 8px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;}
  .ds-status.fresh{background:#dcfce7;color:#166534;}
  .ds-status.stale{background:#fef9c3;color:#854d0e;}
  .ds-status.dead{background:#fee2e2;color:#991b1b;}

  /* Telegram Bot Health */
  .tg-status-widget{display:flex;flex-direction:column;gap:12px;}
  .tg-top{display:flex;align-items:center;gap:14px;padding:14px;border-radius:12px;background:var(--bg-sub);border:2px solid var(--border);}
  .tg-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;}
  .tg-dot.online{background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.2);animation:pulse-green 2s infinite;}
  .tg-dot.offline{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.2);}
  .tg-label{font-size:1rem;font-weight:700;color:var(--text-main);}
  .tg-sublabel{font-size:.8rem;color:var(--text-sub);margin-top:2px;}
  .tg-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .tg-stat{background:var(--bg-sub);border-radius:10px;padding:10px 14px;border:1px solid var(--border);}
  .tg-stat-label{font-size:.75rem;color:var(--text-meta);text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
  .tg-stat-val{font-size:1rem;font-weight:700;color:var(--text-main);margin-top:3px;}

  /* Trades vs Historical */
  .trades-vs-avg{display:flex;flex-direction:column;gap:14px;}
  .tva-numbers{display:flex;align-items:flex-end;gap:8px;}
  .tva-today{font-size:2.8rem;font-weight:800;color:var(--text-main);line-height:1;}
  .tva-label{font-size:.85rem;color:var(--text-meta);font-weight:600;margin-bottom:6px;}
  .tva-vs{font-size:.9rem;font-weight:700;padding:4px 10px;border-radius:20px;margin-bottom:6px;}
  .tva-bar-wrap{margin-top:4px;}
  .tva-bar-label{display:flex;justify-content:space-between;font-size:.8rem;color:var(--text-meta);font-weight:600;margin-bottom:5px;}
  .tva-progress{background:var(--progress-bg);height:12px;border-radius:10px;overflow:hidden;}
  .tva-progress-fill{height:100%;border-radius:10px;transition:width .6s ease;}
  .tva-avg-line{font-size:.8rem;color:var(--text-meta);margin-top:8px;font-weight:500;}
</style>
</head>
<body>

<div class="sidebar">
  <h2>⚡ ADMIN PANEL</h2>
  <div class="sidebar-section">
    <div class="sidebar-section-title">Trading & Data</div>
    <a href="dataset/">📰 Dataset Viewer</a>
    <a href="stats.php">📈 Pair-wise Stats</a>
    <a href="plot/">📊 Plot Data (Replay)</a>
    <a href="trail/">🔗 Trades Plot Linking</a>
    <a href="/missing_candles_data.php">📰 Missing candles verification</a>
    <a href="/intrestRates_WinRate_relation.php">📊 Interest Rates Vs WinRate</a>
    <a href="candles_news.php">🕯️ Win/Loss Filter</a>
    <a href="/analysis/">ADVANCED ANALYITCS PAGE</a>
    <a href="/livechart/trades_status_verify.php">🤖 Trade Status (Auto)</a>
    <a href="/co-relation.php">📊 Co-relation data</a>
    <?php if (in_array('valid_pairs', $permissions)): ?>
      <a href="valid_pairs.php">💱 Valid Pairs</a>
    <?php endif; ?>
  </div>
  <div class="sidebar-section">
    <div class="sidebar-section-title">Management</div>
    <?php if (in_array('manage_users', $permissions)): ?><a href="manage_users.php">👤 Manage Users</a><?php endif; ?>
    <a href="create_email.php">✉️ Create Mails</a>
    <a href="fwd.php">✉️ Setup Email forwarders</a>
    <?php if (in_array('add_news', $permissions)): ?><a href="news_admin.php">📰 Add News Event</a><?php endif; ?>
    <?php if (in_array('broadcast', $permissions)): ?><a href="broadcast.php">📢 Broadcast</a><?php endif; ?>
    <?php if (in_array('view_events', $permissions)): ?><a href="events.php">📅 Upcoming Events</a><?php endif; ?>
    <?php if (in_array('trade_reports', $permissions)): ?><a href="Trade_report.php">📑 Trade Reports</a><?php endif; ?>
    <?php if (in_array('trade_enquiry', $permissions)): ?><a href="fetch_trade.php">🔍 Trade Enquiry</a><?php endif; ?>
  </div>
  <div class="sidebar-section">
    <div class="sidebar-section-title">System & Tools</div>
    <a href="api_keys_add.php">🌐 AI API Keys</a>
    <a href="server_details.php">💾 SERVER INFO..</a>
    <a href="cron_manager.php">⏱️ Cron Manager</a>
    <a href="kill_zombies.php?key=admin">🧹 KILL ZOMBIE PROCESSES</a>
    <a href="/ota/upload.html">🌐 OTA (ESP32) Update</a>
    <?php if (in_array('add_admin', $permissions)): ?><a href="add_admin.php">🛡️ Add Admins</a><?php endif; ?>
    <?php if (in_array('backup', $permissions)): ?><a href="tables_backup.php">💾 Tables Backup</a><?php endif; ?>
    <?php if (in_array('validate', $permissions)): ?><a href="verify.php">✅ Validate Data</a><?php endif; ?>
    <a href="ip_blocker.php">🌐 IP Blocker</a>
    <a href="2fa.php">🔐 2-Factor authentication</a>
    <?php if (in_array('upload_files', $permissions)): ?><a href="upload.php">📁 Upload Files</a><?php endif; ?>
    <?php if (in_array('send_mail', $permissions)): ?><a href="send_mail.php">✉️ Send Mail</a><?php endif; ?>
  </div>
  <a href="logout.php" class="logout">🚪 Logout</a>
</div>

<div class="main-content">
  <header>
    <h1>Welcome back, <?php echo htmlspecialchars($loggedInUser); ?> 👋</h1>
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">🌙 Dark Mode</button>
      <div class="date-badge">📅 <?php echo date("l, F j, Y"); ?></div>
    </div>
  </header>

  <!-- World Clocks -->
  <div class="world-clocks">
    <div class="clock-widget ny">
      <div class="clock-city"><img src="flags/united-states-flag-icon.png" alt="US" class="flag-icon"> NEW YORK</div>
      <div class="clock-time" id="time-ny">--:--:--</div>
      <div class="clock-overlay">
        <div class="overlay-title">Best Pairs</div>
        <div class="overlay-pairs">
          <span class="pair-badge">EUR/USD</span>
          <span class="pair-badge">USD/JPY</span>
          <span class="pair-badge">GBP/USD</span>
        </div>
      </div>
    </div>
    <div class="clock-widget lon">
      <div class="clock-city"><img src="flags/united-kingdom-flag-icon.png" alt="UK" class="flag-icon"> LONDON</div>
      <div class="clock-time" id="time-lon">--:--:--</div>
      <div class="clock-overlay">
        <div class="overlay-title">Best Pairs</div>
        <div class="overlay-pairs">
          <span class="pair-badge">EUR/USD</span>
          <span class="pair-badge">GBP/USD</span>
          <span class="pair-badge">EUR/GBP</span>
        </div>
      </div>
    </div>
    <div class="clock-widget tok">
      <div class="clock-city"><img src="flags/japan-flag-icon.png" alt="JP" class="flag-icon"> TOKYO</div>
      <div class="clock-time" id="time-tok">--:--:--</div>
      <div class="clock-overlay">
        <div class="overlay-title">Best Pairs</div>
        <div class="overlay-pairs">
          <span class="pair-badge">USD/JPY</span>
          <span class="pair-badge">AUD/JPY</span>
          <span class="pair-badge">AUD/USD</span>
        </div>
      </div>
    </div>
    <div class="clock-widget ist">
      <div class="clock-city"><img src="flags/india-flag-icon.png" alt="IN" class="flag-icon"> INDIA (IST)</div>
      <div class="clock-time" id="time-ist">--:--:--</div>
      <div class="clock-overlay">
        <div class="overlay-title">Best Pairs</div>
        <div class="overlay-pairs">
          <span class="pair-badge">USD/INR</span>
          <span class="pair-badge">GBP/INR</span>
          <span class="pair-badge">EUR/INR</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Session Banner -->
  <div id="session-banner-container">
    <?php echo generateSessionBanner(); ?>
  </div>

  <!-- News Impact Countdown -->
  <?php if ($nextHighNews): ?>
  <?php
    $impactLevel   = (int)($nextHighNews['impact'] ?? 2);
    $highClass     = $impactLevel === 3 ? 'high-impact' : '';
    $impactLabel   = $impactLevel === 3 ? '🔴 HIGH IMPACT' : '🟡 MEDIUM IMPACT';
    $rawName       = $nextHighNews['event_name'] ?? '';
    $evCurrency    = strtoupper(substr($rawName, 0, 3));
    $evName        = trim(substr($rawName, 3));
    $eventUnix     = (int)$nextHighNews['event_unix'];
    $evTimeDisplay = date('h:i A', strtotime($nextHighNews['event_ist'])) . ' IST';
  ?>
  <div class="news-countdown-bar <?= $highClass ?>" id="newsCountdownBar">
    <div>
      <div class="news-cd-label">⏳ Next <?= $impactLabel ?> Event</div>
      <div class="news-cd-event">[<?= htmlspecialchars($evCurrency) ?>] <?= htmlspecialchars($evName) ?></div>
      <div class="news-cd-time">🕐 Scheduled: <strong><?= $evTimeDisplay ?></strong></div>
    </div>
    <div class="news-cd-timer" id="newsCountdownTimer">--:--:--</div>
    <div class="news-cd-impact"><?= $impactLabel ?></div>
  </div>
  <script>
  (function(){
    const target = <?= $eventUnix ?> * 1000;
    function tick(){
      const now  = Date.now();
      const diff = target - now;
      const el   = document.getElementById('newsCountdownTimer');
      const bar  = document.getElementById('newsCountdownBar');
      if (!el) return;
      if (diff <= 0) {
        el.textContent = '🔔 NOW';
        return;
      }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      el.textContent = (h > 0 ? String(h).padStart(2,'0') + ':' : '') +
                       String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
      if (diff < 600000) bar.style.borderColor = '#ef4444';
    }
    tick();
    setInterval(tick, 1000);
  })();
  </script>
  <?php else: ?>
  <div class="news-countdown-bar" style="opacity:.5;">
    <div>
      <div class="news-cd-label">📭 No High/Medium Impact Events Pending Today</div>
    </div>
    <div class="news-cd-timer">—</div>
  </div>
  <?php endif; ?>

  <!-- Stat Cards -->
  <div class="cards">
    <div class="card users"   data-icon="👥"><h2><?php echo $usersCount; ?></h2><p>Users Registered</p></div>
    <div class="card events"  data-icon="📰"><h2><?php echo $pendingEvents; ?></h2><p>Pending News Events</p></div>
    <div class="card pairs"   data-icon="💱"><h2><?php echo $validPairs; ?></h2><p>Valid Trading Pairs</p></div>
    <div class="card alerts"  data-icon="🔔"><h2><?php echo $totalAlertsToday; ?></h2><p>Total Alerts Today</p></div>
    <div class="card winrate" data-icon="🏆"><h2><?php echo $winPercentage; ?>%</h2><p>Win Rate (<?php echo $totalClosed; ?> Trades)</p></div>
  </div>

  <div class="bottom-panels">

    <!-- Win/Loss Chart -->
    <div class="panel">
      <div class="panel-title">🎯 Win/Loss Ratio (All-time)</div>
      <div style="position:relative;height:250px;width:100%;display:flex;align-items:center;justify-content:center;">
        <canvas id="winLossChart"></canvas>
      </div>
    </div>

    <!-- Server Health -->
    <div class="panel">
      <div class="panel-title">🖥️ Server Health</div>
      <div class="health-item">
        <div class="health-label"><span>CPU Load (1 Min Avg)</span><span><?= $cpuLoad ?></span></div>
        <div class="progress-bg"><div class="progress-fill" style="width:<?= $cpuPercent ?>%;background:<?= $cpuPercent > 80 ? '#ef4444' : '#3b82f6' ?>;"></div></div>
      </div>
      <div class="health-item">
        <div class="health-label"><span>Disk Space</span><span><?= formatBytes($diskFree) ?> Free</span></div>
        <div class="progress-bg"><div class="progress-fill" style="width:<?= $diskUsagePercent ?>%;background:<?= $diskUsagePercent > 90 ? '#ef4444' : '#10b981' ?>;"></div></div>
      </div>
      <div class="health-item" style="margin-top:auto;padding-top:15px;border-top:1px solid var(--border-row);">
        <div class="health-label"><span>Database Size</span><span style="color:var(--text-main);font-weight:800;"><?= $dbSizeMb ?> MB</span></div>
      </div>
    </div>

    <!-- Recent Trades Feed -->
    <div class="panel">
      <div class="panel-title">⚡ Recent Trade Results</div>
      <ul class="feed-list">
        <?php if (empty($recentTrades)): ?>
          <li class="feed-item"><span class="feed-meta">No recent trades found.</span></li>
        <?php else: ?>
          <?php foreach ($recentTrades as $trade):
            $res = strtolower($trade['trade_result']);
            if (strpos($res,'win')  !== false) { $bClass = 'badge-win';     $text = 'WIN'; }
            elseif (strpos($res,'loss') !== false) { $bClass = 'badge-loss'; $text = 'LOSS'; }
            else { $bClass = 'badge-pending'; $text = 'PENDING'; }
          ?>
          <li class="feed-item">
            <div>
              <div class="feed-title"><?= htmlspecialchars($trade['pair']) ?></div>
              <div class="feed-meta"><?= date('M d, H:i', strtotime($trade['last_alert_time'])) ?></div>
            </div>
            <span class="badge <?= $bClass ?>"><?= $text ?></span>
          </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Upcoming Economic News -->
    <div class="panel">
      <div class="panel-title">📅 Upcoming Economic Events</div>
      <ul class="feed-list">
        <?php if (empty($upcomingNews)): ?>
          <li class="feed-item"><span class="feed-meta">No pending news events scheduled.</span></li>
        <?php else: ?>
          <?php foreach ($upcomingNews as $news):
            $impactLevel = (int)($news['impact'] ?? 1);
            if ($impactLevel === 3)      { $impClass = 'badge-high';   $impLabel = 'HIGH'; }
            elseif ($impactLevel === 2)  { $impClass = 'badge-medium'; $impLabel = 'MED'; }
            else                         { $impClass = 'badge-low';    $impLabel = 'LOW'; }
            $rawEventName = $news['event_name'] ?? 'USD Economic Event';
            $currency     = strtoupper(substr($rawEventName, 0, 3));
            $eventName    = trim(substr($rawEventName, 3));
          ?>
          <li class="feed-item">
            <div>
              <div class="feed-title"><strong>[<?= htmlspecialchars($currency) ?>]</strong> <?= htmlspecialchars($eventName) ?></div>
              <div class="feed-meta">⏰ <?= date('M d, g:ia', strtotime($news['event_time'])) ?> IST</div>
            </div>
            <span class="badge <?= $impClass ?>"><?= $impLabel ?></span>
          </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Quick Actions -->
    <div class="panel">
      <div class="panel-title">🚀 Quick Actions</div>
      <div style="flex-grow:1;display:flex;flex-direction:column;justify-content:center;">
        <a href="fetch_news.php" class="btn-action btn-primary" target="_blank">📰 Force Fetch News Now</a>
        <a href="broadcast.php" class="btn-action btn-warning">📢 Draft Broadcast</a>
        <a href="livechart/trades_status_verify.php" class="btn-action btn-primary" target="_blank">✅ Trigger Trade Verification</a>
        <button class="btn-action btn-danger" onclick="if(confirm('Are you sure you want to clear the cache?')) window.location.href='?clear_cache=1';">🧹 Clear System Cache</button>
      </div>
    </div>


<?php
// ── Read current maintenance state for widget ──
$_maintFile = __DIR__ . '/maintenance.json';
$_maintData = file_exists($_maintFile) ? (json_decode(file_get_contents($_maintFile), true) ?? []) : [];
$_maintOn   = !empty($_maintData['enabled']);
?>

<!-- ═══════════════════════════════════════════
     PASTE THIS ENTIRE BLOCK inside .bottom-panels
     in your index.php, e.g. right after the
     "Quick Actions" panel.
     ═══════════════════════════════════════════ -->

<!-- ── Maintenance Kill Switch ── -->
<div class="panel" style="grid-column: span 2;" id="maintenancePanel">
  <div class="panel-title">
    🔴 Maintenance Kill Switch
    <span id="maintBadge" style="
      font-size:.75rem;font-weight:700;padding:4px 12px;border-radius:20px;
      background:<?= $_maintOn ? '#fee2e2' : '#dcfce7' ?>;
      color:<?= $_maintOn ? '#991b1b' : '#166534' ?>;">
      <?= $_maintOn ? '🔴 MAINTENANCE ON' : '🟢 SITE LIVE' ?>
    </span>
  </div>

  <!-- Status banner -->
  <div id="maintStatusBanner" style="
    padding:16px 20px;border-radius:12px;margin-bottom:20px;
    background:<?= $_maintOn ? 'linear-gradient(135deg,#fee2e2,#fecaca)' : 'linear-gradient(135deg,#dcfce7,#bbf7d0)' ?>;
    border:2px solid <?= $_maintOn ? '#fca5a5' : '#86efac' ?>;
    display:flex;align-items:center;gap:14px;">
    <div style="font-size:2rem;"><?= $_maintOn ? '⚠️' : '✅' ?></div>
    <div>
      <div style="font-weight:700;font-size:1rem;color:<?= $_maintOn ? '#991b1b' : '#166534' ?>;">
        <?= $_maintOn ? 'MAINTENANCE MODE IS ACTIVE' : 'SITE IS LIVE AND OPERATIONAL' ?>
      </div>
      <div style="font-size:.8rem;color:<?= $_maintOn ? '#b91c1c' : '#15803d' ?>;margin-top:3px;">
        <?php if ($_maintOn): ?>
          Started: <strong><?= htmlspecialchars($_maintData['started_at'] ?? '—') ?> IST</strong>
          &nbsp;|&nbsp; By: <strong><?= htmlspecialchars($_maintData['started_by'] ?? '—') ?></strong>
        <?php else: ?>
          All users can access the site normally.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

    <!-- Left: controls -->
    <div style="display:flex;flex-direction:column;gap:14px;">

      <div>
        <label style="font-size:.8rem;font-weight:700;color:var(--text-sub);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">
          📝 Maintenance Message
        </label>
        <textarea id="maintMessage" rows="3" style="
          width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--border);
          background:var(--bg-sub);color:var(--text-main);font-size:.9rem;
          font-family:'Inter',sans-serif;resize:vertical;line-height:1.5;"
          placeholder="Message shown to users..."><?= htmlspecialchars($_maintData['message'] ?? '') ?></textarea>
      </div>

      <div>
        <label style="font-size:.8rem;font-weight:700;color:var(--text-sub);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">
          ⏰ ETA (Back Online By)
        </label>
        <input type="datetime-local" id="maintEta" value="<?= !empty($_maintData['eta']) ? date('Y-m-d\TH:i', strtotime($_maintData['eta'])) : '' ?>"
          style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-sub);color:var(--text-main);font-size:.9rem;">
        <div style="font-size:.75rem;color:var(--text-meta);margin-top:4px;">Leave blank to show no ETA to users.</div>
      </div>

      <button onclick="saveMaintSettings()" style="
        padding:10px;border:1px solid var(--border);border-radius:8px;
        background:var(--bg-sub);color:var(--text-sub);font-weight:600;
        font-size:.85rem;cursor:pointer;transition:all .2s;">
        💾 Save Message & ETA
      </button>
    </div>

    <!-- Right: big toggle -->
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;
      background:var(--bg-sub);border-radius:14px;padding:24px;border:1px solid var(--border);">

      <!-- Toggle switch -->
      <label style="position:relative;display:inline-block;width:80px;height:40px;cursor:pointer;">
        <input type="checkbox" id="maintToggle" <?= $_maintOn ? 'checked' : '' ?>
          onchange="handleMaintToggle(this.checked)"
          style="opacity:0;width:0;height:0;">
        <span id="maintSlider" style="
          position:absolute;inset:0;border-radius:40px;
          background:<?= $_maintOn ? '#ef4444' : '#e2e8f0' ?>;
          transition:background .3s;"></span>
        <span id="maintThumb" style="
          position:absolute;top:4px;
          left:<?= $_maintOn ? '44px' : '4px' ?>;
          width:32px;height:32px;border-radius:50%;background:white;
          box-shadow:0 2px 6px rgba(0,0,0,.2);transition:left .3s;"></span>
      </label>

      <div style="text-align:center;">
        <div id="maintToggleLabel" style="font-size:1.1rem;font-weight:800;color:<?= $_maintOn ? '#ef4444' : '#10b981' ?>;">
          <?= $_maintOn ? '🔴 MAINTENANCE ON' : '🟢 SITE LIVE' ?>
        </div>
        <div style="font-size:.8rem;color:var(--text-meta);margin-top:4px;">
          <?= $_maintOn ? 'Users see maintenance page' : 'Toggle to enable maintenance mode' ?>
        </div>
      </div>

      <!-- Danger zone warning -->
      <div id="maintWarning" style="
        display:<?= $_maintOn ? 'none' : 'block' ?>;
        font-size:.78rem;color:#f59e0b;text-align:center;
        background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);
        border-radius:8px;padding:8px 12px;line-height:1.5;">
        ⚠️ Enabling this will block all non-admin users from accessing the site.
      </div>

      <a href="/maintenance.php" target="_blank" style="
        font-size:.8rem;color:var(--text-meta);text-decoration:none;
        padding:6px 14px;border-radius:8px;border:1px solid var(--border);
        transition:all .2s;" onmouseover="this.style.background='var(--bg-row)'" onmouseout="this.style.background='transparent'">
        👁️ Preview Maintenance Page
      </a>
    </div>
  </div>

  <!-- Toast notification -->
  <div id="maintToast" style="
    display:none;margin-top:14px;padding:12px 16px;border-radius:10px;
    font-size:.9rem;font-weight:600;text-align:center;"></div>
</div>

<style>
  #maintenancePanel .panel-title { border-bottom: 2px solid var(--border); padding-bottom: 14px; }
</style>

<script>
function showMaintToast(msg, ok) {
  const t = document.getElementById('maintToast');
  t.style.display  = 'block';
  t.style.background = ok ? '#dcfce7' : '#fee2e2';
  t.style.color      = ok ? '#166534' : '#991b1b';
  t.textContent = msg;
  setTimeout(() => t.style.display = 'none', 4000);
}

function handleMaintToggle(isOn) {
  if (isOn) {
    if (!confirm('⚠️ Are you sure you want to ENABLE maintenance mode?\n\nAll non-admin users will be redirected to the maintenance page immediately.')) {
      document.getElementById('maintToggle').checked = false;
      return;
    }
  }
  const message = document.getElementById('maintMessage').value.trim();
  const etaInput = document.getElementById('maintEta').value;
  let eta = '';
  if (etaInput) {
    // Convert local datetime to readable format
    const d = new Date(etaInput);
    eta = d.getFullYear() + '-' +
          String(d.getMonth()+1).padStart(2,'0') + '-' +
          String(d.getDate()).padStart(2,'0') + ' ' +
          String(d.getHours()).padStart(2,'0') + ':' +
          String(d.getMinutes()).padStart(2,'0') + ':00';
  }

  fetch('/toggle_maintenance.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: isOn ? 'enable' : 'disable', message, eta })
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) { showMaintToast('❌ Error: ' + data.error, false); return; }
    updateMaintUI(isOn);
    showMaintToast(isOn ? '🔴 Maintenance mode ENABLED — site is now blocked.' : '✅ Maintenance mode DISABLED — site is live!', true);
  })
  .catch(() => showMaintToast('❌ Network error. Could not toggle maintenance.', false));
}

function saveMaintSettings() {
  const message  = document.getElementById('maintMessage').value.trim();
  const etaInput = document.getElementById('maintEta').value;
  let eta = '';
  if (etaInput) {
    const d = new Date(etaInput);
    eta = d.getFullYear() + '-' +
          String(d.getMonth()+1).padStart(2,'0') + '-' +
          String(d.getDate()).padStart(2,'0') + ' ' +
          String(d.getHours()).padStart(2,'0') + ':' +
          String(d.getMinutes()).padStart(2,'0') + ':00';
  }
  fetch('/toggle_maintenance.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'update', message, eta })
  })
  .then(r => r.json())
  .then(data => {
    showMaintToast(data.success ? '💾 Settings saved!' : '❌ ' + data.error, data.success);
  })
  .catch(() => showMaintToast('❌ Network error.', false));
}

function updateMaintUI(isOn) {
  // Slider & thumb
  document.getElementById('maintSlider').style.background = isOn ? '#ef4444' : '#e2e8f0';
  document.getElementById('maintThumb').style.left        = isOn ? '44px' : '4px';
  // Badge
  const badge = document.getElementById('maintBadge');
  badge.textContent = isOn ? '🔴 MAINTENANCE ON' : '🟢 SITE LIVE';
  badge.style.background = isOn ? '#fee2e2' : '#dcfce7';
  badge.style.color      = isOn ? '#991b1b' : '#166534';
  // Label
  const lbl = document.getElementById('maintToggleLabel');
  lbl.textContent = isOn ? '🔴 MAINTENANCE ON' : '🟢 SITE LIVE';
  lbl.style.color = isOn ? '#ef4444' : '#10b981';
  // Warning
  document.getElementById('maintWarning').style.display = isOn ? 'none' : 'block';
  // Banner
  const banner = document.getElementById('maintStatusBanner');
  if (isOn) {
    banner.style.background = 'linear-gradient(135deg,#fee2e2,#fecaca)';
    banner.style.borderColor = '#fca5a5';
    banner.innerHTML = `<div style="font-size:2rem;">⚠️</div>
      <div><div style="font-weight:700;font-size:1rem;color:#991b1b;">MAINTENANCE MODE IS ACTIVE</div>
      <div style="font-size:.8rem;color:#b91c1c;margin-top:3px;">Users are now being redirected to the maintenance page.</div></div>`;
  } else {
    banner.style.background = 'linear-gradient(135deg,#dcfce7,#bbf7d0)';
    banner.style.borderColor = '#86efac';
    banner.innerHTML = `<div style="font-size:2rem;">✅</div>
      <div><div style="font-weight:700;font-size:1rem;color:#166534;">SITE IS LIVE AND OPERATIONAL</div>
      <div style="font-size:.8rem;color:#15803d;margin-top:3px;">All users can access the site normally.</div></div>`;
  }
}
</script>

    <!-- Live Price Feed -->
    <div class="panel">
      <div class="panel-title">📈 Live Pair Prices</div>
      <ul class="feed-list">
        <?php if (empty($livePrices)): ?>
          <li class="feed-item"><span class="feed-meta">No live price data found.</span></li>
        <?php else: ?>
          <?php foreach ($livePrices as $pairName => $priceRow):
            $updatedAtIST = '';
            if (!empty($priceRow['updated_at'])) {
              $updatedAtUTC = DateTime::createFromFormat('Y-m-d H:i:s', $priceRow['updated_at'], new DateTimeZone('UTC'));
              if ($updatedAtUTC !== false) {
                $updatedAtUTC->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $updatedAtIST = $updatedAtUTC->format('M d, g:i:s A');
              }
            }
          ?>
          <li class="feed-item">
            <div>
              <div class="feed-title"><?= htmlspecialchars($pairName) ?></div>
              <div class="feed-meta"><?= htmlspecialchars($updatedAtIST) ?> IST</div>
            </div>
            <span class="badge badge-pending"><?= htmlspecialchars($priceRow['current_price']) ?></span>
          </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>

    <!-- ESP32 Live Status -->
    <div class="panel">
      <div class="panel-title">📡 ESP32 Live Status</div>
      <div class="esp32-status-widget">
        <div class="esp32-top">
          <div class="esp32-dot <?= $esp32Online ? 'online' : 'offline' ?>"></div>
          <div>
            <div class="esp32-label"><?= $esp32Online ? '🟢 ONLINE' : '🔴 OFFLINE' ?></div>
            <div class="esp32-sublabel">Last seen: <?= htmlspecialchars($esp32LastSeen) ?></div>
          </div>
        </div>
        <?php if ($esp32Status): ?>
        <div class="esp32-grid">
          <div class="esp32-stat">
            <div class="esp32-stat-label">📶 IP Address</div>
            <div class="esp32-stat-val"><?= htmlspecialchars($esp32Ip) ?></div>
          </div>
          <div class="esp32-stat">
            <div class="esp32-stat-label">📡 WiFi RSSI</div>
            <div class="esp32-stat-val"><?= htmlspecialchars($esp32Rssi) ?> dBm</div>
          </div>
          <div class="esp32-stat">
            <div class="esp32-stat-label">🧠 Free Heap</div>
            <div class="esp32-stat-val"><?= htmlspecialchars($esp32Heap) ?></div>
          </div>
          <div class="esp32-stat">
            <div class="esp32-stat-label">⏱️ Uptime</div>
            <div class="esp32-stat-val"><?= htmlspecialchars($esp32Uptime) ?></div>
          </div>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--text-meta);font-size:.9rem;">No heartbeat data found.<br>Check if ESP32 is running.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Telegram Bot Health -->
    <div class="panel">
      <div class="panel-title">🤖 Telegram Bot Health</div>
      <div class="tg-status-widget">
        <div class="tg-top">
          <div class="tg-dot <?= $telegramBotOk ? 'online' : 'offline' ?>"></div>
          <div>
            <div class="tg-label"><?= $telegramBotOk ? '🟢 BOT ONLINE' : '🔴 BOT OFFLINE' ?></div>
            <div class="tg-sublabel"><?= htmlspecialchars($telegramBotName) ?> · <?= htmlspecialchars($telegramBotStatus) ?></div>
          </div>
        </div>
        <div class="tg-grid">
          <div class="tg-stat">
            <div class="tg-stat-label">📡 API Status</div>
            <div class="tg-stat-val" style="color:<?= $telegramBotOk ? '#10b981' : '#ef4444' ?>;"><?= htmlspecialchars($telegramBotStatus) ?></div>
          </div>
          <div class="tg-stat">
            <div class="tg-stat-label">👥 Bot Users</div>
            <div class="tg-stat-val"><?= $usersCount ?></div>
          </div>
          <div class="tg-stat" style="grid-column:span 2;">
            <div class="tg-stat-label">📤 Last Alert Sent</div>
            <div class="tg-stat-val" style="font-size:.9rem;"><?= htmlspecialchars($telegramLastSent) ?></div>
          </div>
        </div>
        <?php if (!$telegramBotOk && $telegramBotStatus === 'No Token'): ?>
          <div style="font-size:.8rem;color:#f59e0b;padding:8px;background:var(--bg-sub);border-radius:8px;border:1px solid var(--border);">
            ⚠️ Bot token not found. Define <code>BOT_TOKEN</code> or <code>TELEGRAM_BOT_TOKEN</code> in db.php.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Trades Today vs Historical Average -->
    <div class="panel">
      <div class="panel-title">📊 Trades Today vs Avg</div>
      <div class="trades-vs-avg">
        <div class="tva-numbers">
          <div>
            <div class="tva-today"><?= $tradesToday ?></div>
            <div class="tva-label">trades today</div>
          </div>
          <div class="tva-vs" style="background:<?= $tradeVsAvgDiff >= 0 ? '#dcfce7' : '#fee2e2' ?>;color:<?= $tradeVsAvgColor ?>;">
            <?= $tradeVsAvgLabel ?> vs avg
          </div>
        </div>
        <div class="tva-bar-wrap">
          <div class="tva-bar-label">
            <span>0</span>
            <span style="color:<?= $tradeVsAvgColor ?>;font-weight:800;"><?= $tradeVsAvgPercent ?>% of avg</span>
            <span><?= round($historicalAvg * 1.5) ?>+</span>
          </div>
          <?php
            $barMax    = max($historicalAvg * 1.5, $tradesToday, 1);
            $barFill   = min(round(($tradesToday / $barMax) * 100), 100);
            $avgMarker = min(round(($historicalAvg / $barMax) * 100), 100);
          ?>
          <div class="tva-progress" style="position:relative;">
            <div class="tva-progress-fill" style="width:<?= $barFill ?>%;background:<?= $tradeVsAvgColor ?>;"></div>
            <div style="position:absolute;top:0;left:<?= $avgMarker ?>%;height:100%;width:2px;background:#64748b;opacity:.7;"></div>
          </div>
          <div class="tva-avg-line">📉 Historical daily avg: <strong><?= $historicalAvg ?></strong> trades/day</div>
        </div>
      </div>
    </div>

    <!-- Proximity Alert Monitor -->
    <div class="panel" style="grid-column: span 2;">
      <div class="panel-title">
        🎯 Proximity Alert Monitor (Today)
        <span style="font-size:.8rem;font-weight:500;color:var(--text-meta);"><?= count($proximityData) ?> trades</span>
      </div>
      <?php if (empty($proximityData)): ?>
        <div style="text-align:center;padding:20px;color:var(--text-meta);font-size:.9rem;">No trades found for today.</div>
      <?php else: ?>
      <div class="prox-wrap">
        <table class="prox-table">
          <thead>
            <tr>
              <th>Pair</th>
              <th>Direction</th>
              <th>Target</th>
              <th>Current Price</th>
              <th>Distance (pips)</th>
              <th>Zone</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($proximityData as $p): ?>
            <tr>
              <td><strong><?= htmlspecialchars($p['pair_name']) ?></strong></td>
              <td>
                <?php if (strtoupper($p['trade_direction']) === 'BUY' || strtoupper($p['trade_direction']) === 'UP'): ?>
                  <span style="color:#10b981;font-weight:700;">▲ BUY</span>
                <?php else: ?>
                  <span style="color:#ef4444;font-weight:700;">▼ SELL</span>
                <?php endif; ?>
              </td>
              <td><?= number_format((float)$p['price_target'], 5) ?></td>
              <td><?= number_format((float)$p['current_price'], 5) ?></td>
              <td><?= number_format($p['pips'], 1) ?> pips</td>
              <td>
                <?php if ($p['zone'] === 'in'): ?>
                  <span class="zone-in">🟢 IN ZONE</span>
                <?php elseif ($p['zone'] === 'close'): ?>
                  <span class="zone-close">🟡 CLOSE</span>
                <?php else: ?>
                  <span class="zone-far">⚪ FAR</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Dataset Health -->
    <div class="panel">
      <div class="panel-title">🗄️ Dataset Health</div>
      <div class="dataset-summary">
        <div class="ds-badge fresh">✅ <?= $freshCount ?> Fresh</div>
        <div class="ds-badge stale">⚠️ <?= $staleCount ?> Stale</div>
        <div class="ds-badge dead">❌ <?= $deadCount ?> Dead</div>
      </div>
      <div class="ds-list">
        <?php if (empty($datasetHealth)): ?>
          <div style="text-align:center;padding:20px;color:var(--text-meta);font-size:.9rem;">No data found.</div>
        <?php else: ?>
          <?php foreach ($datasetHealth as $dh):
            $updIST = '';
            if (!empty($dh['updated_at'])) {
              $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dh['updated_at'], new DateTimeZone('UTC'));
              if ($dt) { $dt->setTimezone(new DateTimeZone('Asia/Kolkata')); $updIST = $dt->format('h:i:s A'); }
            }
          ?>
          <div class="ds-item">
            <div>
              <div class="ds-pair"><?= htmlspecialchars($dh['pair_name']) ?></div>
              <div class="ds-time"><?= $updIST ?> IST · <?= $dh['mins_ago'] ?>m ago</div>
            </div>
            <span class="ds-status <?= $dh['status'] ?>">
              <?= $dh['status'] === 'fresh' ? '✅ Fresh' : ($dh['status'] === 'stale' ? '⚠️ Stale' : '❌ Dead') ?>
            </span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- end bottom-panels -->
</div><!-- end main-content -->

<script>
  // Live Clocks
  function updateClocks() {
    const now  = new Date();
    const opts = {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true};
    document.getElementById('time-ny').innerText  = now.toLocaleTimeString('en-US',{...opts,timeZone:'America/New_York'});
    document.getElementById('time-lon').innerText = now.toLocaleTimeString('en-US',{...opts,timeZone:'Europe/London'});
    document.getElementById('time-tok').innerText = now.toLocaleTimeString('en-US',{...opts,timeZone:'Asia/Tokyo'});
    document.getElementById('time-ist').innerText = now.toLocaleTimeString('en-US',{...opts,timeZone:'Asia/Kolkata'});
  }
  updateClocks();
  setInterval(updateClocks, 1000);

  // Session Banner Auto-Refresh
  (function(){
    const container = document.getElementById('session-banner-container');
    async function fetchSession(){
      try {
        const resp = await fetch(window.location.pathname + '?action=session_status', {cache:'no-store'});
        if (!resp.ok) throw new Error('Network error');
        container.innerHTML = await resp.text();
      } catch(err) { console.warn('Session fetch failed:', err); }
    }
    setInterval(fetchSession, 30000);
  })();

  // Win/Loss Chart
  const ctxWinLoss = document.getElementById('winLossChart').getContext('2d');
  new Chart(ctxWinLoss, {
    type: 'doughnut',
    data: {
      labels: ['Wins','Losses'],
      datasets: [{
        data: [<?= $totalWins ?>, <?= $totalLosses ?>],
        backgroundColor: ['#10b981','#ef4444'],
        borderWidth: 0,
        hoverOffset: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '75%',
      plugins: { legend: { position:'bottom', labels: { usePointStyle:true, padding:20 } } }
    }
  });

  // Dark / Light Mode Toggle
  (function(){
    const html  = document.documentElement;
    const btn   = document.getElementById('themeBtn');
    const saved = localStorage.getItem('adminTheme') || 'light';
    html.setAttribute('data-theme', saved);
    btn.textContent = saved === 'dark' ? '☀️ Light Mode' : '🌙 Dark Mode';
  })();

  function toggleTheme(){
    const html    = document.documentElement;
    const btn     = document.getElementById('themeBtn');
    const current = html.getAttribute('data-theme');
    const next    = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('adminTheme', next);
    btn.textContent = next === 'dark' ? '☀️ Light Mode' : '🌙 Dark Mode';
  }
</script>
</body>
</html>