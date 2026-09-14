
<?php
if (file_exists(__DIR__ . '/lib_candle_algo.php')) require_once __DIR__ . '/lib_candle_algo.php';
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

set_time_limit(120);

$startTime = microtime(true);
$startMemory = memory_get_usage();

// ==========================================
// JSON DATABASE HELPER FUNCTIONS
// ==========================================
$jsonDbPath = __DIR__ . '/outcomes_db.json';

if (php_sapi_name() === 'cli' || php_sapi_name() === 'cgi-fcgi' || php_sapi_name() === 'cgi' || !isset($_SERVER['REQUEST_METHOD']) || defined('STDIN')) {
    global $argv;
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg) {
            if (strpos($arg, '=') !== false) {
                list($key, $val) = explode('=', $arg);
                $key = ltrim($key, '-');
                $key = trim($key, "\"' ");
                $val = trim($val, "\"' ");
                $_GET[$key] = $val;
                $_REQUEST[$key] = $val;
            }
        }
    }
}

function getJsonDb() {
    global $jsonDbPath;
    if (!file_exists($jsonDbPath)) return [];
    $data = file_get_contents($jsonDbPath);
    return json_decode($data, true) ?: [];
}

function saveJsonDb($data) {
    global $jsonDbPath;
    $fp = fopen($jsonDbPath, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

// Robust db.php path lookup
$dbPath = '';
if (file_exists(__DIR__ . '/db.php')) {
    $dbPath = __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../db.php')) {
    $dbPath = __DIR__ . '/../db.php';
} else {
    $dir = __DIR__;
    for ($i = 0; $i < 3; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/db.php')) { $dbPath = $dir . '/db.php'; break; }
    }
}
if ($dbPath) { require_once $dbPath; } else { die("Error: db.php not found."); }

// ==========================================
// 1. DATABASE BOOTSTRAPPING
// ==========================================
$createTableQuery = "
CREATE TABLE IF NOT EXISTS trade_outcome_details (
  raw_trade_id INT(11) NOT NULL,
  pair_name VARCHAR(20) DEFAULT NULL,
  trade_result VARCHAR(20) DEFAULT NULL,
  win_loss_time DATETIME DEFAULT NULL,
  win_loss_price DECIMAL(10,5) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (raw_trade_id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
";
$conn->query($createTableQuery);

// ==========================================
// 2. ROUTING LOGIC — returns pair FOLDER
//    New layout: /dataset/dataset/EURUSD/
//                /dataset/JUN-2025 TO FEB-2026/EURUSD/
// ==========================================
function getPairFolder(string $pairName, string $alertTimeStr): string {
    $IST = new DateTimeZone('Asia/Kolkata');
    $ts  = (new DateTime($alertTimeStr, $IST))->getTimestamp();
    $jun2025 = (new DateTime('2025-06-01 00:00:00', $IST))->getTimestamp();
    $mar2026 = (new DateTime('2026-03-01 00:00:00', $IST))->getTimestamp();
    if ($ts < $jun2025) return __DIR__ . '/dataset/BEFORE-JUN-2025';
    if ($ts < $mar2026) return __DIR__ . '/dataset/JUN-2025 TO FEB-2026';
    return __DIR__ . '/dataset/dataset';
}

// ==========================================
// 3. CPANEL OPTIMISED CSV LOADER
//    Scans prev-day + alert-day + next-day files,
//    slices to [alertTimestamp-1hr … 21:30 IST],
//    deduplicates, and returns sorted candles.
// ==========================================
function fetchBarsFromCSVSliced(string $dataBasePath, string $pairName, int $alertTimestamp): array {
    $IST = new DateTimeZone('Asia/Kolkata');
    $out = [];

    if (!is_dir($dataBasePath)) return $out;

    $cleanPair = str_replace(['/', '_', ' '], '', $pairName); // EURUSD
    $pairFolder = $dataBasePath . '/' . $cleanPair;
    $legacyFile = $dataBasePath . '/FX_' . $cleanPair . '.csv';
    $hasPairFolder = is_dir($pairFolder);
    $hasLegacyFile = is_file($legacyFile);

    if (!$hasPairFolder && !$hasLegacyFile) return $out;

    // Alert date in IST
    $alertDT = new DateTime("@$alertTimestamp");
    $alertDT->setTimezone($IST);
    $alertDate = $alertDT->format('Y-m-d');

    // Session end = 21:30 IST on alert date
    $sessionEndDT = new DateTime("{$alertDate} 21:30:00", $IST);
    $endThreshold = $sessionEndDT->getTimestamp();

    // Load prev + alert + next to safely cover 1-hr pre-alert lookback
    // and any rare cross-midnight session tail
    $prevDate = (clone $alertDT)->modify('-1 day')->format('Y-m-d');
    $nextDate = (clone $alertDT)->modify('+1 day')->format('Y-m-d');
    $datesToLoad = [$prevDate, $alertDate, $nextDate];

    $startThreshold = $alertTimestamp - 3600; // 1 hour before alert

    // Use dated files inside PAIR folder when available;
    // otherwise use the direct FX_PAIR.csv file.
    if (!$hasPairFolder && $hasLegacyFile) {
        $datesToLoad = [null];
    }

    foreach ($datesToLoad as $dateStr) {
        $filePath = $hasPairFolder
            ? $pairFolder . "/FX_{$cleanPair}-{$dateStr}.csv"
            : $legacyFile;

        if (!file_exists($filePath)) continue;

        $handle = fopen($filePath, 'r');
        if (!$handle) continue;

        $header = fgetcsv($handle, 1000, ',');
        if (!$header) { fclose($handle); continue; }
        $header = array_map(fn($c) => trim(str_replace('"', '', $c)), $header);

        $timeIdx  = array_search('time',  $header);
        $openIdx  = array_search('open',  $header);
        $highIdx  = array_search('high',  $header);
        $lowIdx   = array_search('low',   $header);
        $closeIdx = array_search('close', $header);

        if ($timeIdx === false || $openIdx === false || $closeIdx === false) {
            fclose($handle);
            continue;
        }

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (!isset($data[$timeIdx], $data[$openIdx], $data[$closeIdx])) continue;
            $timeVal = (int)floatval($data[$timeIdx]);
            if ($timeVal < $startThreshold) continue;       // before our window
            if ($timeVal > $endThreshold + 300) break;      // past session end (+1 candle buffer)

            $out[] = [
                'time'  => $timeVal,
                'open'  => (float)$data[$openIdx],
                'high'  => ($highIdx !== false && isset($data[$highIdx]))  ? (float)$data[$highIdx]  : 0.0,
                'low'   => ($lowIdx  !== false && isset($data[$lowIdx]))   ? (float)$data[$lowIdx]   : 0.0,
                'close' => (float)$data[$closeIdx],
            ];
        }
        fclose($handle);
        if (!$hasPairFolder) break;
    }

    // Sort chronologically then deduplicate timestamps
    usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
    $deduped = [];
    foreach ($out as $row) {
        $deduped[$row['time']] = $row;
    }
    return array_values($deduped);
}

// ==========================================
// 4. THE EVALUATOR (NEWS LOGIC INTEGRATED)
// ==========================================
require_once __DIR__ . '/dataset/trading_core_functions.php';

// ==========================================
// 5. ENVIRONMENT & REQUEST PARAMETERS
// ==========================================
$istTimezone = new DateTimeZone('Asia/Kolkata');
$utcTimezone = new DateTimeZone('UTC');

$isCli = (php_sapi_name() === 'cli' || php_sapi_name() === 'cgi-fcgi' || php_sapi_name() === 'cgi' || !isset($_SERVER['REQUEST_METHOD']) || defined('STDIN'));
$isCron = (isset($_GET['cron']) && $_GET['cron'] == '1');
$isBackground = ($isCli || $isCron);
$isWeb = !$isBackground;

$todayStr      = date('Y-m-d');
$yesterdayStr  = date('Y-m-d', strtotime('-1 day'));
$last7DaysStr  = date('Y-m-d', strtotime('-6 days'));
$monthStartStr = date('Y-m-01');

$duration = isset($_GET['duration']) ? $_GET['duration'] : 'today';

switch ($duration) {
    case 'today':       $startDateStr = $todayStr;      $endDateStr = $todayStr;      break;
    case 'yesterday':   $startDateStr = $yesterdayStr;  $endDateStr = $yesterdayStr;  break;
    case 'last_7_days': $startDateStr = $last7DaysStr;  $endDateStr = $todayStr;      break;
    case 'this_month':  $startDateStr = $monthStartStr; $endDateStr = $todayStr;      break;
    case 'custom':
    default:
        $startDateStr = isset($_GET['start_date']) ? $_GET['start_date'] : $todayStr;
        $endDateStr   = isset($_GET['end_date'])   ? $_GET['end_date']   : $todayStr;
        break;
}

$batchLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

$startDT_IST = new DateTime("{$startDateStr} 00:00:00", $istTimezone);
$endDT_IST   = new DateTime("{$endDateStr} 23:59:59",   $istTimezone);
$startUTC    = (clone $startDT_IST)->setTimezone($utcTimezone);
$endUTC      = (clone $endDT_IST)->setTimezone($utcTimezone);

$processedTrades = [];
$totalQueried    = 0;
$countInserted   = 0;

if ($isBackground) {
    $isTriggered = true;
} else {
    $isTriggered = isset($_GET['trigger_evaluation']) && $_GET['trigger_evaluation'] == '1';
}

if (isset($_GET['broadcast_success']) && $_GET['broadcast_success'] == '1') {
    $isTriggered = false;
}

if ($isTriggered) {
    // ==========================================
    // 5.5. FETCH ALL NEWS FOR THE PERIOD
    // ==========================================
    $newsEvents = [];
    $newsQuery  = "SELECT impact, event_time FROM economic_events WHERE event_date >= '{$startDateStr}' AND event_date <= '{$endDateStr}'";
    $newsRes    = $conn->query($newsQuery);
    if ($newsRes) {
        while ($row = $newsRes->fetch_assoc()) {
            $dt = new DateTime($row['event_time'], $istTimezone);
            $newsEvents[] = ['impact' => (int)$row['impact'], 'time' => $dt->getTimestamp()];
        }
    }

    // ==========================================
    // 6. EVALUATION AND DATABASE UPDATE LOOP
    // ==========================================
    $query = "SELECT p.raw_trade_id, p.pair_name, p.price_target, p.trade_direction, p.last_alert_time, p.trade_result,
                     UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
              FROM prediction_trade_data p
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.last_alert_time >= '{$startDateStr} 00:00:00'
              AND p.last_alert_time <= '{$endDateStr} 23:59:59'
              ORDER BY p.raw_trade_id DESC LIMIT " . (int)$batchLimit;

    $result = $conn->query($query);

    if ($result) {
        $totalQueried = $result->num_rows;

        $saveStmt = $conn->prepare("
            INSERT INTO trade_outcome_details (raw_trade_id, pair_name, trade_result, win_loss_time, win_loss_price)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                trade_result    = VALUES(trade_result),
                win_loss_time   = VALUES(win_loss_time),
                win_loss_price  = VALUES(win_loss_price)
        ");

        while ($trade = $result->fetch_assoc()) {
            $pair       = $trade['pair_name'];

            // ── NEW: get pair folder instead of a single file path ──
            $pairFolder = getPairFolder($pair, $trade['last_alert_time']);

            $dtUTC            = new DateTime($trade['last_alert_time'], $utcTimezone);
            $triggerTimestamp = isset($trade['trigger_unixtime']) ? (int)$trade['trigger_unixtime'] : $dtUTC->getTimestamp();
            $tradeDateIST     = (new DateTime("@" . $triggerTimestamp))->setTimezone($istTimezone)->format('Y-m-d');

            // Resolve either:
            // /public_html/dataset/.../FX_PAIR.csv
            // /public_html/dataset/dataset/FX_PAIR.csv
            // /public_html/dataset/dataset/PAIR/FX_PAIR-YYYY-MM-DD.csv
            $cleanPair = str_replace(['/', '_', ' '], '', $pair);
            $pairFolderPath = $pairFolder . '/' . $cleanPair;
            $legacyFilePath = $pairFolder . '/FX_' . $cleanPair . '.csv';

            if (!is_dir($pairFolderPath) && !is_file($legacyFilePath)) {
                $processedTrades[] = [
                    'id'      => $trade['raw_trade_id'],
                    'pair'    => $pair,
                    'status'  => 'error',
                    'details' => 'Missing CSV data. Checked: ' . $pairFolderPath . ' and ' . $legacyFilePath,
                    'time'    => 'N/A',
                    'price'   => 'N/A'
                ];
                continue;
            }

            $candles = fetchBarsFromCSVSliced($pairFolder, $pair, $triggerTimestamp);

            if (empty($candles)) {
                $processedTrades[] = [
                    'id'      => $trade['raw_trade_id'],
                    'pair'    => $pair,
                    'status'  => 'error',
                    'details' => 'No candle data found in ' . basename($pairFolder) . ' for this date',
                    'time'    => 'N/A',
                    'price'   => 'N/A'
                ];
                continue;
            }

            $sessionEndIST = new DateTime("{$tradeDateIST} 21:30:00", $istTimezone);
            $sessionEndUTC = (clone $sessionEndIST)->setTimezone($utcTimezone);

            $eval = evaluatePatternTrade(
                $candles,
                $trade['trade_direction'],
                (float)$trade['price_target'],
                $triggerTimestamp,
                $sessionEndUTC->getTimestamp(),
                $newsEvents
            );

            $resType      = $eval['result'];
            $winLossTimeIST = null;
            $winLossPrice   = null;

            if ($resType === 'win' || $resType === 'loss') {
                $outcomeDT = new DateTime("@" . $eval['time']);
                $outcomeDT->setTimezone($istTimezone);
                $winLossTimeIST = $outcomeDT->format('Y-m-d H:i:s');
                $winLossPrice   = (float)$eval['price'];
            }

            $saveStmt->bind_param("isssd",
                $trade['raw_trade_id'], $pair, $resType, $winLossTimeIST, $winLossPrice
            );
            $saveStmt->execute();

            $conn->query("UPDATE prediction_trade_data SET trade_result = '{$resType}', updated_at = NOW() WHERE raw_trade_id = " . (int)$trade['raw_trade_id']);

            $countInserted++;

            $processedTrades[] = [
                'id'              => $trade['raw_trade_id'],
                'pair'            => $pair,
                'status'          => $resType,
                'details'         => $eval['reason'] ?? 'Evaluated',
                'time'            => $winLossTimeIST ? $winLossTimeIST : 'N/A',
                'price'           => $winLossPrice !== null ? number_format($winLossPrice, 5) : 'N/A',
                'direction'       => $trade['trade_direction'],
                'price_target'    => $trade['price_target'],
                'last_alert_time' => $trade['last_alert_time']
            ];
            unset($candles);
        }
        $saveStmt->close();
    }
} else {
    $query = "SELECT p.raw_trade_id, p.pair_name, p.price_target, p.trade_direction, p.last_alert_time, o.trade_result, o.win_loss_time, o.win_loss_price
              FROM prediction_trade_data p
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              WHERE p.last_alert_time >= '{$startDateStr} 00:00:00'
                AND p.last_alert_time <= '{$endDateStr} 23:59:59'
              ORDER BY p.raw_trade_id DESC LIMIT " . (int)$batchLimit;

    $result = $conn->query($query);
    if ($result) {
        $totalQueried = $result->num_rows;
        while ($trade = $result->fetch_assoc()) {
            $processedTrades[] = [
                'id'              => $trade['raw_trade_id'],
                'pair'            => $trade['pair_name'],
                'status'          => $trade['trade_result'] ?? 'pending',
                'details'         => $trade['trade_result'] ? 'Loaded from DB' : 'Pending evaluation',
                'time'            => $trade['win_loss_time'] ? $trade['win_loss_time'] : 'N/A',
                'price'           => $trade['win_loss_price'] !== null ? number_format($trade['win_loss_price'], 5) : 'N/A',
                'direction'       => $trade['trade_direction'],
                'price_target'    => $trade['price_target'],
                'last_alert_time' => $trade['last_alert_time']
            ];
        }
    }
}

// ==========================================
// RENDER & FILTER LOGIC
// ==========================================
$validOutcomesForPdf = ['win', 'loss', 'setup_not_formed'];
$tradesToRender = [];

if ($isTriggered && !empty($processedTrades)) {
    $jsonDb     = getJsonDb();
    $jsonUpdated = false;

    foreach ($processedTrades as $t) {
        $idStr          = (string)$t['id'];
        $status         = $t['status'];
        $isBroadcasted  = isset($jsonDb[$idStr]['is_broadcasted']) && $jsonDb[$idStr]['is_broadcasted'] == 1;
        $forceBroadcast = (isset($_GET['force_broadcast']) && $_GET['force_broadcast'] == '1');

        if (in_array($status, $validOutcomesForPdf) && (!$isBroadcasted || $forceBroadcast)) {
            $tradesToRender[] = $t['id'];
            if (!isset($jsonDb[$idStr])) $jsonDb[$idStr] = [];
            $jsonDb[$idStr]['status']       = $status;
            $jsonDb[$idStr]['is_broadcasted'] = 0;
            $jsonUpdated = true;
        }
    }
    if ($jsonUpdated) saveJsonDb($jsonDb);
}

$conn->close();

$executionTime     = round(microtime(true) - $startTime, 4);
$peakMemory        = memory_get_peak_usage();
$peakMemoryMB      = round($peakMemory / 1024 / 1024, 2);
$memoryPercentage  = round(($peakMemoryMB / 128) * 100, 2);

// ==========================================
// TERMINATE IF CRON / CLI
// ==========================================
if ($isBackground) {
    echo "==================================================\n";
    echo "📊 Bulk Evaluation: {$countInserted} trades processed\n";
    echo "⚡ Execution Time : {$executionTime}s\n";
    echo "💾 Peak Memory    : {$peakMemoryMB} MB / 128 MB ({$memoryPercentage}%)\n";
    echo "==================================================\n";

    foreach ($processedTrades as $t) {
        echo "ID: #{$t['id']} | Pair: {$t['pair']} | Status: " . strtoupper($t['status']) . " | Time: {$t['time']} | Price: {$t['price']}\n";
    }

    if (!empty($tradesToRender)) {
        echo "\n[NOTICE] " . count($tradesToRender) . " trades ready. Triggering headless broadcast...\n";
        $tradeIds    = implode(',', $tradesToRender);
        $phpExecutable = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'cgi') === false && strpos(PHP_BINARY, 'fpm') === false) {
            $phpExecutable = PHP_BINARY;
        }
        $plotterPath = __DIR__ . '/trade_router.php';
        if (file_exists(__DIR__ . '/plot/trail/trade_router.php')) {
            $plotterPath = __DIR__ . '/plot/trail/trade_router.php';
        }
        $logPath = dirname($plotterPath) . '/plotter_debug.log';
        $cmd = escapeshellarg($phpExecutable) . " -q " . escapeshellarg($plotterPath) . " run_broadcast=1 trade_ids=" . escapeshellarg($tradeIds);

        if ($isCli && !isset($_GET['cron'])) {
            echo "Running plotter synchronously...\nCommand: $cmd\n";
            $output = []; $returnVar = 0;
            exec($cmd, $output, $returnVar);
            echo "---------------- plotter output ----------------\n";
            echo implode("\n", $output) . "\n";
            echo "------------------------------------------------\nPlotter exit code: $returnVar\n";
        } else {
            if (stripos(PHP_OS, 'WIN') === 0) {
                pclose(popen("start /B " . $cmd, "r"));
                echo "Broadcast dispatched (Windows).\n";
            } else {
                exec($cmd . " >> " . escapeshellarg($logPath) . " 2>&1 &");
                echo "Broadcast dispatched (Unix). Log: " . basename($logPath) . "\n";
            }
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sleek Trade Evaluator</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0b0f19; --bg-secondary: #131b2e; --bg-tertiary: #1e293b;
            --accent-blue: #3b82f6; --accent-green: #10b981; --accent-red: #ef4444;
            --text-main: #f8fafc; --text-secondary: #94a3b8; --border-color: #334155;
            --gradient-blue: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --glass-bg: rgba(19, 27, 46, 0.7); --glass-border: rgba(255,255,255,0.05);
        }
        body { font-family: 'Outfit', sans-serif; background-color: var(--bg-primary); color: var(--text-main); margin: 0; padding: 40px 20px; display: flex; justify-content: center; }
        .dashboard { width: 100%; max-width: 1100px; animation: fadeIn 0.8s ease-out; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(15px); } to { opacity:1; transform:translateY(0); } }
        header { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; border-bottom:1px solid var(--border-color); padding-bottom:20px; }
        h1 { font-size:32px; font-weight:800; background:linear-gradient(to right,#60a5fa,#3b82f6); -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin:0; }
        .system-stats { display:flex; gap:20px; margin-bottom:30px; }
        .stat-card { flex:1; background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:16px; padding:20px; box-shadow:0 8px 32px 0 rgba(0,0,0,0.2); backdrop-filter:blur(8px); position:relative; overflow:hidden; }
        .stat-card::before { content:''; position:absolute; top:0; left:0; width:100%; height:4px; background:var(--gradient-blue); }
        .stat-card.memory::before { background:<?php echo $memoryPercentage > 50 ? 'var(--accent-red)' : 'var(--accent-green)'; ?>; }
        .stat-card h3 { margin:0 0 8px 0; font-size:13px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1.5px; }
        .stat-card .value { font-size:28px; font-weight:700; color:var(--text-main); margin:0; }
        .stat-card .desc { font-size:12px; color:var(--text-secondary); margin-top:5px; }
        .progress-bar-container { width:100%; height:6px; background-color:var(--border-color); border-radius:3px; margin-top:10px; overflow:hidden; }
        .progress-fill { height:100%; width:<?php echo min(100,$memoryPercentage); ?>%; background-color:<?php echo $memoryPercentage > 50 ? 'var(--accent-red)' : 'var(--accent-green)'; ?>; border-radius:3px; transition:width 0.5s ease-in-out; }
        .controls-container { background-color:var(--bg-secondary); border:1px solid var(--border-color); border-radius:16px; padding:24px; margin-bottom:40px; }
        .controls-container h3 { margin-top:0; margin-bottom:20px; font-weight:600; }
        .filter-form { display:flex; flex-wrap:wrap; gap:20px; align-items:flex-end; }
        .form-group { display:flex; flex-direction:column; gap:8px; }
        .form-group label { font-size:13px; color:var(--text-secondary); font-weight:600; }
        .form-group input, .form-group select { background-color:var(--bg-primary); border:1px solid var(--border-color); border-radius:8px; color:var(--text-main); padding:10px 16px; font-size:14px; outline:none; transition:all 0.3s; }
        .form-group input:focus, .form-group select:focus { border-color:var(--accent-blue); box-shadow:0 0 0 2px rgba(59,130,246,0.25); }
        .btn-submit { background:var(--gradient-blue); color:white; border:none; border-radius:8px; padding:11px 24px; font-weight:600; cursor:pointer; transition:all 0.3s; font-size:14px; box-shadow:0 4px 12px rgba(29,78,216,0.3); }
        .btn-submit:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(29,78,216,0.4); }
        .results-card { background-color:var(--bg-secondary); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3); }
        .results-card h3 { padding:24px 24px 10px 24px; margin:0; font-weight:600; }
        table { width:100%; border-collapse:collapse; text-align:left; }
        th, td { padding:16px 24px; border-bottom:1px solid var(--border-color); }
        th { color:var(--text-secondary); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:1.5px; background-color:rgba(0,0,0,0.1); }
        tr:hover { background-color:rgba(255,255,255,0.02); }
        .badge { display:inline-block; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
        .badge-win { background-color:rgba(16,185,129,0.15); color:var(--accent-green); border:1px solid rgba(16,185,129,0.2); }
        .badge-loss { background-color:rgba(239,68,68,0.15); color:var(--accent-red); border:1px solid rgba(239,68,68,0.2); }
        .badge-pending { background-color:rgba(148,163,184,0.15); color:var(--text-secondary); border:1px solid rgba(148,163,184,0.2); }
        .badge-setup_not_formed { background-color:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.2); }
        .badge-error { background-color:rgba(239,68,68,0.1); color:#fca5a5; border:1px dashed rgba(239,68,68,0.3); }
        .text-mono { font-family:monospace; font-size:14px; color:#60a5fa; font-weight:600; }
        .text-gray { color:var(--text-secondary); }
    </style>
</head>
<body>
<div class="dashboard">
    <header>
        <h1>⚡ Standalone Trade Evaluator</h1>
        <div class="text-gray" style="font-size:14px;">cPanel Shared Hosting Edition</div>
    </header>

    <?php if (isset($_GET['broadcast_success']) && $_GET['broadcast_success'] == '1'): ?>
        <div style="background-color:rgba(16,185,129,0.15);border:1px solid var(--accent-green);color:var(--accent-green);padding:16px;border-radius:12px;margin-bottom:30px;text-align:center;font-weight:600;">
            ✅ PDF Report compiled and broadcasted to Telegram successfully!
        </div>
    <?php endif; ?>

    <div class="system-stats">
        <div class="stat-card memory">
            <h3>Memory Resource Usage</h3>
            <p class="value"><?php echo $peakMemoryMB; ?> MB</p>
            <div class="progress-bar-container"><div class="progress-fill"></div></div>
            <p class="desc">Shared Hosting limit: 128 MB (<?php echo $memoryPercentage; ?>% used)</p>
        </div>
        <div class="stat-card">
            <h3>Execution Speed</h3>
            <p class="value"><?php echo $executionTime; ?>s</p>
            <p class="desc">Execution limit: 120s buffer</p>
        </div>
        <div class="stat-card">
            <h3>Database Synchronization</h3>
            <p class="value"><?php echo $countInserted; ?> / <?php echo $totalQueried; ?></p>
            <p class="desc">outcome details stored successfully</p>
        </div>
    </div>

    <div class="controls-container">
        <h3>🔍 Query Filter Configuration</h3>
        <form class="filter-form" method="GET" action="" id="filterForm">
            <div class="form-group">
                <label>Duration Preset</label>
                <select name="duration" id="durationSelect" onchange="updateDurationPreset()">
                    <option value="today"      <?php if($duration==='today')      echo 'selected'; ?>>Today</option>
                    <option value="yesterday"  <?php if($duration==='yesterday')  echo 'selected'; ?>>Yesterday</option>
                    <option value="last_7_days"<?php if($duration==='last_7_days')echo 'selected'; ?>>Last 7 Days</option>
                    <option value="this_month" <?php if($duration==='this_month') echo 'selected'; ?>>This Month</option>
                    <option value="custom"     <?php if($duration==='custom')     echo 'selected'; ?>>Custom Range</option>
                </select>
            </div>
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="start_date" id="startDateInput" value="<?php echo htmlspecialchars($startDateStr); ?>" onchange="handleManualDateChange()">
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="end_date" id="endDateInput" value="<?php echo htmlspecialchars($endDateStr); ?>" onchange="handleManualDateChange()">
            </div>
            <div class="form-group">
                <label>Limit (Batch Size)</label>
                <select name="limit">
                    <option value="10"   <?php if($batchLimit===10)   echo 'selected'; ?>>10 Trades</option>
                    <option value="50"   <?php if($batchLimit===50)   echo 'selected'; ?>>50 Trades</option>
                    <option value="100"  <?php if($batchLimit===100)  echo 'selected'; ?>>100 Trades</option>
                    <option value="200"  <?php if($batchLimit===200)  echo 'selected'; ?>>200 Trades</option>
                    <option value="500"  <?php if($batchLimit===500)  echo 'selected'; ?>>500 Trades</option>
                    <option value="1000" <?php if($batchLimit===1000) echo 'selected'; ?>>1000 Trades</option>
                    <option value="1500" <?php if($batchLimit===1500) echo 'selected'; ?>>1500 Trades</option>
                </select>
            </div>
            <button type="submit" name="trigger_evaluation" value="1" class="btn-submit">Trigger Bulk Evaluation</button>
        </form>
    </div>

    <div class="results-card">
        <h3>📊 Evaluation Detailed Log</h3>
        <table>
            <thead>
                <tr>
                    <th>Trade ID</th><th>Pair Name</th><th>Outcome</th>
                    <th>Resolution Time (IST)</th><th>Resolution Price</th><th>Audit Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($processedTrades)): ?>
                    <?php foreach ($processedTrades as $t): ?>
                        <tr>
                            <td class="text-mono">#<?php echo $t['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($t['pair']); ?></strong></td>
                            <td><span class="badge badge-<?php echo $t['status']; ?>"><?php echo str_replace('_',' ',$t['status']); ?></span></td>
                            <td><?php echo $t['time']; ?></td>
                            <td class="text-mono"><?php echo $t['price']; ?></td>
                            <td class="text-gray" style="font-size:13px;"><?php echo htmlspecialchars($t['details']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--text-secondary);padding:40px;">
                            No trade signals found for the selected date range.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function updateDurationPreset() {
        const select = document.getElementById('durationSelect');
        const startInput = document.getElementById('startDateInput');
        const endInput   = document.getElementById('endDateInput');
        const val = select.value;
        const today = new Date();
        const fmt = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        const todayStr = fmt(today);
        if (val === 'today') {
            startInput.value = endInput.value = todayStr;
        } else if (val === 'yesterday') {
            const y = new Date(); y.setDate(today.getDate()-1);
            startInput.value = endInput.value = fmt(y);
        } else if (val === 'last_7_days') {
            const s = new Date(); s.setDate(today.getDate()-6);
            startInput.value = fmt(s); endInput.value = todayStr;
        } else if (val === 'this_month') {
            startInput.value = fmt(new Date(today.getFullYear(), today.getMonth(), 1));
            endInput.value   = todayStr;
        }
    }
    function handleManualDateChange() {
        document.getElementById('durationSelect').value = 'custom';
    }

    <?php if (isset($tradesToRender) && !empty($tradesToRender)): ?>
    <?php $webPlotterPath = is_dir(__DIR__ . '/plot') ? 'plot/trail/trade_router.php' : 'trade_router.php'; ?>
    const tradesToRender = <?php echo json_encode($tradesToRender); ?>;
    document.addEventListener('DOMContentLoaded', () => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(11,15,25,0.95);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;';
        overlay.innerHTML = `<div style="text-align:center;font-family:'Outfit',sans-serif;color:#f8fafc;">
            <h2 style="background:linear-gradient(to right,#60a5fa,#3b82f6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-size:24px;font-weight:800;">📊 Preparing PDF Report</h2>
            <div style="font-size:16px;color:#94a3b8;">Redirecting to chart renderer engine...</div>
        </div>`;
        document.body.appendChild(overlay);
        setTimeout(() => {
            window.location.href = '<?php echo $webPlotterPath; ?>?run_broadcast=1&trade_ids=' + tradesToRender.join(',');
        }, 1000);
    });
    <?php endif; ?>
</script>
</body>
</html>