<?php
/**
 * AJAX endpoint — pair × event detail
 * GET: pair=EURUSD&event=Nonfarm+Payrolls
 */
// Buffer ALL output so no PHP warning/notice leaks into JSON
ob_start();

ini_set('display_errors', 0);   // never let errors bleed into JSON
error_reporting(E_ALL);
set_time_limit(120);
ini_set('memory_limit', '128M');

define('DS_OLD',    '/home/sairedd1/public_html/dataset/JUN-2025 TO FEB-2026/');
define('DS_NEW',    '/home/sairedd1/public_html/dataset/dataset/');
define('WIN_BEFORE', -5);
define('WIN_AFTER',   30);
define('MIN_TS',  1700000000);
define('CUTOFF',  1779408000);
define('TOLERANCE',      360);

function sendJson($data): void {
    ob_end_clean(); // discard any stray output
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Catch any fatal that fires after ob_start
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Fatal: ' . $e['message'] . ' (line ' . $e['line'] . ')']);
    }
});

try {
    require_once __DIR__ . '/db.php';
} catch (Throwable $e) {
    sendJson(['error' => 'DB load failed: ' . $e->getMessage()]);
}

$pair  = preg_replace('/[^A-Z]/', '', strtoupper($_GET['pair']  ?? ''));
$event = trim($_GET['event'] ?? '');

if (!$pair || !$event) {
    sendJson(['error' => 'Missing pair or event parameter']);
}

// ── Load event occurrences ────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT event_time, impact FROM economic_events
     WHERE event_name = ?
     ORDER BY event_time ASC"
);
if (!$stmt) sendJson(['error' => 'Prepare failed: ' . $conn->error]);

$stmt->bind_param('s', $event);
$stmt->execute();
$stmt->bind_result($db_event_time, $db_impact);

$occurrences = [];
$seen = [];
while ($stmt->fetch()) {
    $ts = strtotime($db_event_time);
    if (!$ts || $ts < MIN_TS) continue;
    $bucket = (int)($ts / 60);
    if (isset($seen[$bucket])) continue;
    $seen[$bucket] = true;
    $occurrences[] = [
        'ts'       => $ts,
        'datetime' => $db_event_time,
        'impact'   => (int)$db_impact,
    ];
}
$stmt->close();

if (empty($occurrences)) {
    sendJson(['error' => "No occurrences found for event: \"$event\""]);
}

// ── CSV loader ────────────────────────────────────────────────────────────────
function loadCompact(string $path): array {
    $out = [];
    if (!file_exists($path)) return $out;
    $fh = fopen($path, 'r');
    if (!$fh) return $out;
    fgets($fh); // header
    while (!feof($fh)) {
        $line = fgets($fh);
        if (!$line) continue;
        $p1 = strpos($line, ','); if ($p1 === false) continue;
        $ts  = (int)substr($line, 0, $p1);
        if ($ts < MIN_TS) continue;
        $p2 = strpos($line, ',', $p1+1);
        $p3 = strpos($line, ',', $p2+1);
        $p4 = strpos($line, ',', $p3+1);
        $p5 = strpos($line, ',', $p4+1);
        if ($p5 === false) continue;
        $cl = (float)substr($line, $p4+1, $p5-$p4-1);
        if ($cl > 0) $out[$ts] = $cl;
    }
    fclose($fh);
    return $out;
}

function findPrice(array &$data, int $target): ?float {
    if (empty($data)) return null;
    $keys = array_keys($data);
    $lo = 0; $hi = count($keys) - 1;
    $best = null; $bestD = PHP_INT_MAX;
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        $d   = abs($keys[$mid] - $target);
        if ($d < $bestD) { $bestD = $d; $best = $mid; }
        if ($keys[$mid] < $target) $lo = $mid + 1; else $hi = $mid - 1;
    }
    return ($bestD <= TOLERANCE) ? $data[$keys[$best]] : null;
}

// ── Load CSVs ─────────────────────────────────────────────────────────────────
$oldData = loadCompact(DS_OLD . 'FX_' . $pair . '.csv');
$newData = loadCompact(DS_NEW . 'FX_' . $pair . '.csv');

if (empty($oldData) && empty($newData)) {
    sendJson(['error' => "CSV files not found for pair: $pair"]);
}

// ── Compute returns per occurrence ────────────────────────────────────────────
$rows = [];
$rets = [];

foreach ($occurrences as $oc) {
    $ts   = $oc['ts'];
    $data = ($ts < CUTOFF) ? $oldData : $newData;
    $tsB  = $ts + WIN_BEFORE * 60;
    $tsA  = $ts + WIN_AFTER  * 60;
    $pB   = findPrice($data, $tsB);
    $pA   = findPrice($data, $tsA);

    $ret = null;
    if ($pB !== null && $pA !== null && $pB != 0) {
        $ret    = ($pA - $pB) / $pB * 100.0;
        $rets[] = $ret;
    }

    $rows[] = [
        'date'    => substr($oc['datetime'], 0, 10),
        'time'    => substr($oc['datetime'], 11, 5),
        'pBefore' => $pB !== null ? round($pB, 5) : null,
        'pAfter'  => $pA !== null ? round($pA, 5) : null,
        'chg'     => ($pB !== null && $pA !== null) ? round($pA - $pB, 5) : null,
        'ret'     => $ret !== null ? round($ret, 5) : null,
        'dir'     => $ret === null ? null : ($ret >= 0 ? 'UP' : 'DOWN'),
        'impact'  => $oc['impact'],
    ];
}

unset($oldData, $newData);

// ── Stats ─────────────────────────────────────────────────────────────────────
$n    = count($rets);
$mean = $n > 0 ? array_sum($rets) / $n : 0;
$ups  = count(array_filter($rets, fn($r) => $r > 0));
$dns  = count(array_filter($rets, fn($r) => $r < 0));

$tstat = 0;
if ($n >= 2) {
    $var = 0;
    foreach ($rets as $r) $var += ($r - $mean) ** 2;
    $se    = sqrt($var / ($n - 1) / $n);
    $tstat = $se > 0 ? $mean / $se : 0;
}
$stars = abs($tstat) >= 2.576 ? '**' : (abs($tstat) >= 1.96 ? '*' : '');

sendJson([
    'pair'    => $pair,
    'event'   => $event,
    'rows'    => $rows,
    'summary' => [
        'n'      => $n,
        'total'  => count($rows),
        'mean'   => round($mean, 5),
        'tstat'  => round($tstat, 3),
        'stars'  => $stars,
        'ups'    => $ups,
        'dns'    => $dns,
        'pct_up' => $n > 0 ? round($ups / $n * 100, 1) : 0,
    ],
]);