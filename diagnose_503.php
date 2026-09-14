<?php
/**
 * diagnose_503.php
 * Drop in public_html, open in browser: https://saireddy.site/diagnose_503.php
 * DELETE after use — don't leave this publicly accessible!
 */

// ─── Basic Auth (change password!) ───────────────────────────────────────────
$PASSWORD = 'sai123diagnose';
if (!isset($_GET['key']) || $_GET['key'] !== $PASSWORD) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2><p>Add ?key=sai123diagnose to URL</p>');
}

// ─── Config ──────────────────────────────────────────────────────────────────
$HOME        = '/home/sairedd1';
$PUBLIC_HTML = $HOME . '/public_html';
$LOG_FILE    = $HOME . '/diagnose_503_log.json';
$DB_CONFIGS  = [
    ['host' => 'localhost', 'user' => 'sairedd1_trading', 'pass' => '', 'db' => 'sairedd1_trading'],
    // Add more DB credentials if needed
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>503 Diagnostics</title>
<meta http-equiv="refresh" content="30"> <!-- auto-refresh every 30s -->
<style>
  body { font-family: monospace; background: #0d1117; color: #c9d1d9; padding: 20px; margin: 0; }
  h2 { color: #58a6ff; border-bottom: 1px solid #30363d; padding-bottom: 6px; }
  h3 { color: #f0883e; margin-top: 24px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; }
  th { background: #161b22; color: #58a6ff; padding: 8px; text-align: left; }
  td { padding: 6px 8px; border-bottom: 1px solid #21262d; }
  tr:hover td { background: #161b22; }
  .ok    { color: #3fb950; }
  .warn  { color: #d29922; }
  .crit  { color: #f85149; font-weight: bold; }
  .box   { background: #161b22; border: 1px solid #30363d; border-radius: 6px; padding: 14px; margin-bottom: 20px; }
  .badge { display:inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
  .badge-ok   { background:#1a3a20; color:#3fb950; }
  .badge-warn { background:#3a2a00; color:#d29922; }
  .badge-crit { background:#3a1a1a; color:#f85149; }
  pre { background: #161b22; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
  .ts { color: #6e7681; font-size: 11px; }
</style>
</head>
<body>
<h2>🔍 503 Diagnostic Report — <?= date('Y-m-d H:i:s') ?> (Server Time)</h2>
<p class="ts">Auto-refreshes every 30s | <a href="?key=<?= $PASSWORD ?>&snapshot=1" style="color:#58a6ff">📸 Save Snapshot</a></p>

<?php

// ═══════════════════════════════════════════════════════════════════════════
// 1. PHP PROCESS COUNT
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>⚙️ 1. PHP Processes Right Now</h3><div class="box">';
$procs = shell_exec("ps aux | grep -E 'php' | grep -v grep | grep 'sairedd1'");
$lines = array_filter(explode("\n", trim($procs)));
$count = count($lines);
$badge = $count < 5 ? 'ok' : ($count < 10 ? 'warn' : 'crit');
echo "<p>Total PHP processes: <span class='badge badge-$badge'>$count</span></p>";

echo '<table><tr><th>PID</th><th>CPU%</th><th>MEM%</th><th>Time</th><th>Command</th></tr>';
foreach ($lines as $line) {
    $parts = preg_split('/\s+/', trim($line), 11);
    if (count($parts) < 11) continue;
    $cpu = (float)$parts[2];
    $mem = (float)$parts[3];
    $cls = $cpu > 5 ? 'crit' : ($cpu > 2 ? 'warn' : '');
    $cmd = basename(strtok($parts[10], ' '));
    echo "<tr><td>{$parts[1]}</td><td class='$cls'>{$cpu}%</td><td>{$mem}%</td><td>{$parts[9]}</td><td>{$parts[10]}</td></tr>";
}
echo '</table></div>';

// ═══════════════════════════════════════════════════════════════════════════
// 2. OPEN FILE HANDLES (lsof)
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>📂 2. Most Opened Files Right Now</h3><div class="box">';
$lsof = shell_exec("lsof -u sairedd1 2>/dev/null | grep -v 'mem\|txt\|cwd\|rtd\|DEL' | awk '{print $9}' | sort | uniq -c | sort -rn | head -20");
if ($lsof) {
    echo '<table><tr><th>Open Count</th><th>File Path</th></tr>';
    foreach (array_filter(explode("\n", trim($lsof))) as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        $cnt = (int)($parts[0] ?? 0);
        $path = $parts[1] ?? '';
        $cls = $cnt > 10 ? 'crit' : ($cnt > 5 ? 'warn' : '');
        echo "<tr><td class='$cls'>$cnt</td><td>$path</td></tr>";
    }
    echo '</table>';
} else {
    echo '<p class="warn">lsof not available or no open files found (normal on some shared hosts)</p>';
}
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// 3. RECENTLY MODIFIED FILES (last 10 min — what's actively being written)
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>✏️ 3. Files Written in Last 10 Minutes</h3><div class="box">';
$recent = shell_exec("find $PUBLIC_HTML -mmin -10 -type f ! -path '*/dataset/dataset/*' 2>/dev/null | head -30");
$rlines = array_filter(explode("\n", trim($recent)));
if ($rlines) {
    echo '<table><tr><th>File</th><th>Size</th><th>Modified</th></tr>';
    foreach ($rlines as $f) {
        $f = trim($f);
        if (!file_exists($f)) continue;
        $size = round(filesize($f) / 1024, 1) . ' KB';
        $mtime = date('H:i:s', filemtime($f));
        $short = str_replace($PUBLIC_HTML . '/', '', $f);
        echo "<tr><td>$short</td><td>$size</td><td>$mtime</td></tr>";
    }
    echo '</table>';
} else {
    echo '<p class="ok">No files written in last 10 min.</p>';
}
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// 4. ERROR LOG TAIL
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>🪵 4. Error Log (last 30 lines)</h3><div class="box">';
$errlog = $PUBLIC_HTML . '/error_log';
if (file_exists($errlog)) {
    $lines = array_slice(file($errlog), -30);
    echo '<pre>';
    foreach ($lines as $line) {
        $line = htmlspecialchars(trim($line));
        if (str_contains($line, 'Fatal') || str_contains($line, '503'))
            echo "<span class='crit'>$line</span>\n";
        elseif (str_contains($line, 'Warning') || str_contains($line, 'Deprecated'))
            echo "<span class='warn'>$line</span>\n";
        else
            echo "$line\n";
    }
    echo '</pre>';
} else {
    echo '<p class="ok">No error_log found in public_html (good sign)</p>';
}

// Also check dataset/error_log
$errlog2 = $PUBLIC_HTML . '/dataset/error_log';
if (file_exists($errlog2)) {
    echo '<p><b>dataset/error_log (last 15 lines):</b></p><pre>';
    $lines2 = array_slice(file($errlog2), -15);
    foreach ($lines2 as $l) echo htmlspecialchars(trim($l)) . "\n";
    echo '</pre>';
}
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// 5. CRON OVERLAP DETECTION (lock files)
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>🔒 5. Cron Script Lock Files (overlap detection)</h3><div class="box">';
$lockFiles = glob('/tmp/*.lock');
$phpLocks  = glob('/tmp/php*.lock');
$allLocks  = array_merge($lockFiles ?: [], $phpLocks ?: []);

// Also check if any cron scripts are running longer than 55s (overlap risk)
$cronScripts = [
    'tg.php', 'validate.php', 'valid_pairs.php', 'view_alerts.php',
    'price_track_send_alerts.php', 'scan_local.php', 'delete_messages.php',
    'process_delete_queue.php', 'broadcast.php'
];

echo '<table><tr><th>Script</th><th>Currently Running?</th><th>PID</th><th>CPU%</th><th>Status</th></tr>';
foreach ($cronScripts as $script) {
    $psOut = shell_exec("ps aux | grep '$script' | grep -v grep | grep 'sairedd1'");
    $psLines = array_filter(explode("\n", trim($psOut)));
    $running = count($psLines) > 0;
    $pid = '-';
    $cpu = '-';
    $status = $running ? "<span class='warn badge badge-warn'>RUNNING</span>" : "<span class='ok badge badge-ok'>idle</span>";

    if ($running) {
        $parts = preg_split('/\s+/', trim($psLines[0]), 11);
        $pid = $parts[1] ?? '-';
        $cpu = ($parts[2] ?? '0') . '%';
        if (count($psLines) > 1)
            $status = "<span class='crit badge badge-crit'>⚠️ OVERLAPPING (" . count($psLines) . " instances)</span>";
    }
    echo "<tr><td>$script</td><td>$status</td><td>$pid</td><td>$cpu</td></tr>";
}
echo '</table>';

if ($allLocks) {
    echo '<p><b>/tmp lock files:</b></p><pre>' . implode("\n", $allLocks) . '</pre>';
}
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// 6. MYSQL CONNECTION TEST + PROCESS LIST
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>🗄️ 6. MySQL Status</h3><div class="box">';

// Try to read from wp-config or any config file for credentials
$configFiles = glob($PUBLIC_HTML . '/*/db*.php') ?: [];
array_push($configFiles, $PUBLIC_HTML . '/db.php', $PUBLIC_HTML . '/db.php');

// Try connecting with credentials from crontab hint
$dbUser = 'sairedd1_trading';
$dbPass = ''; // Fill in if needed
$dbHost = 'localhost';

$mysqli = @new mysqli($dbHost, $dbUser, $dbPass, 'sairedd1_trading');
if ($mysqli->connect_error) {
    echo '<p class="warn">⚠️ Could not connect to DB with default credentials. Add your DB password to this script to test.</p>';
    echo '<p class="ts">Tip: check cPanel → MySQL Databases → your user\'s password</p>';
} else {
    // Show processlist
    $result = $mysqli->query("SHOW FULL PROCESSLIST");
    $procs = [];
    while ($row = $result->fetch_assoc()) $procs[] = $row;
    
    $sleeping = array_filter($procs, fn($r) => $r['Command'] === 'Sleep');
    $active   = array_filter($procs, fn($r) => $r['Command'] !== 'Sleep');
    
    echo '<p>Active queries: <b class="' . (count($active) > 5 ? 'crit' : 'ok') . '">' . count($active) . '</b> &nbsp;|&nbsp; ';
    echo 'Sleeping connections: <b class="' . (count($sleeping) > 20 ? 'warn' : 'ok') . '">' . count($sleeping) . '</b></p>';
    
    if ($active) {
        echo '<table><tr><th>ID</th><th>User</th><th>DB</th><th>Command</th><th>Time(s)</th><th>State</th><th>Query</th></tr>';
        foreach ($active as $p) {
            $cls = ($p['Time'] ?? 0) > 10 ? 'crit' : (($p['Time'] ?? 0) > 3 ? 'warn' : '');
            echo "<tr><td>{$p['Id']}</td><td>{$p['User']}</td><td>{$p['db']}</td><td>{$p['Command']}</td>
                  <td class='$cls'>{$p['Time']}</td><td>{$p['State']}</td>
                  <td>" . htmlspecialchars(substr($p['Info'] ?? '', 0, 80)) . "</td></tr>";
        }
        echo '</table>';
    }
    $mysqli->close();
}
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// 7. CPU + MEM SNAPSHOT
// ═══════════════════════════════════════════════════════════════════════════
echo '<h3>📊 7. System Snapshot</h3><div class="box">';
$load  = sys_getloadavg();
$mem   = shell_exec("free -m 2>/dev/null | grep Mem");
$memParts = preg_split('/\s+/', trim($mem));

$load1  = $load[0];
$load5  = $load[1];
$load15 = $load[2];
$cls1   = $load1 > 8 ? 'crit' : ($load1 > 4 ? 'warn' : 'ok');

echo "<p>Load Average: <span class='$cls1'>$load1</span> (1m) | $load5 (5m) | $load15 (15m)</p>";

if (count($memParts) >= 3) {
    $total = $memParts[1];
    $used  = $memParts[2];
    $pct   = round($used / $total * 100);
    $cls   = $pct > 85 ? 'crit' : ($pct > 70 ? 'warn' : 'ok');
    echo "<p>RAM: <span class='$cls'>{$used}MB / {$total}MB ({$pct}%)</span></p>";
}

$disk = disk_free_space($HOME);
$diskTotal = disk_total_space($HOME);
$diskPct = round((1 - $disk / $diskTotal) * 100);
$dCls = $diskPct > 90 ? 'crit' : ($diskPct > 75 ? 'warn' : 'ok');
echo "<p>Disk: <span class='$dCls'>" . round(($diskTotal - $disk) / 1073741824, 1) . "GB used / " . round($diskTotal / 1073741824, 1) . "GB total ({$diskPct}%)</span></p>";
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// 8. SNAPSHOT SAVE
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['snapshot'])) {
    $snap = [
        'time'       => date('Y-m-d H:i:s'),
        'load'       => $load,
        'php_procs'  => $count,
        'recent_files' => $rlines,
    ];
    $existing = file_exists($LOG_FILE) ? json_decode(file_get_contents($LOG_FILE), true) : [];
    $existing[] = $snap;
    file_put_contents($LOG_FILE, json_encode($existing, JSON_PRETTY_PRINT));
    echo '<p class="ok">✅ Snapshot saved to diagnose_503_log.json</p>';
}
?>

<p class="ts" style="margin-top:40px">⚠️ <b>Delete this file after debugging!</b> — rm /home/sairedd1/public_html/diagnose_503.php</p>
</body>
</html>
