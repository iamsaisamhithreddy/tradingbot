<?php
/**
 * kill_zombies.php
 * Upload to public_html, open in browser ONCE, then DELETE immediately.
 * URL: https://saireddy.site/kill_zombies.php?key=admin
 */

$PASSWORD = 'admin';
if (!isset($_GET['key']) || $_GET['key'] !== $PASSWORD) {
    http_response_code(403);
    die('<h2>403</h2>Add ?key=admin to URL');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Zombie Killer</title>
<style>
  body { font-family: monospace; background: #0d1117; color: #c9d1d9; padding: 20px; }
  h2 { color: #f85149; }
  h3 { color: #f0883e; }
  .ok   { color: #3fb950; }
  .warn { color: #d29922; }
  .crit { color: #f85149; }
  pre { background: #161b22; padding: 12px; border-radius: 6px; white-space: pre-wrap; }
</style>
</head>
<body>
<h2>🔫 Zombie Process Killer</h2>
<p>Time: <?= date('Y-m-d H:i:s') ?></p>

<?php

$targets = [
    'price_track_send_alerts.php',
    'delete_messages.php',
    'process_delete_queue.php',
];

// ─── STEP 1: Count before ────────────────────────────────────────────────────
echo '<h3>📊 Before Kill</h3><pre>';
$before = [];
foreach ($targets as $script) {
    $count = trim(shell_exec("ps aux | grep '$script' | grep -v grep | grep 'sairedd1' | wc -l"));
    $before[$script] = (int)$count;
    echo "$script → <span class='crit'>$count instances</span>\n";
}
echo '</pre>';

// ─── STEP 2: Kill them ───────────────────────────────────────────────────────
echo '<h3>🔫 Killing...</h3><pre>';
foreach ($targets as $script) {
    // Kill both jailshell wrapper and php-cgi process
    $r1 = shell_exec("pkill -9 -f '$script' 2>&1");
    sleep(1); // wait for processes to die
    echo "pkill $script → done\n";
}
echo '</pre>';

// ─── STEP 3: Count after ─────────────────────────────────────────────────────
sleep(2);
echo '<h3>📊 After Kill</h3><pre>';
$allDead = true;
foreach ($targets as $script) {
    $count = trim(shell_exec("ps aux | grep '$script' | grep -v grep | grep 'sairedd1' | wc -l"));
    $cls = (int)$count === 0 ? 'ok' : 'crit';
    $icon = (int)$count === 0 ? '✅' : '⚠️';
    if ((int)$count > 0) $allDead = false;
    echo "$icon $script → <span class='$cls'>$count instances remaining</span>\n";
}
echo '</pre>';

if ($allDead) {
    echo '<p class="ok" style="font-size:18px">✅ All zombies killed! Server should recover in ~30 seconds.</p>';
} else {
    echo '<p class="warn">⚠️ Some processes survived pkill. They will die on their own shortly as they finish.</p>';
}

// ─── STEP 4: Clean stale lock files ─────────────────────────────────────────
echo '<h3>🧹 Cleaning Stale Lock Files</h3><pre>';
$locks = [
    '/tmp/price_track.lock',
    '/tmp/delete_messages.lock',
    '/tmp/process_delete_queue.lock',
];
foreach ($locks as $lock) {
    if (file_exists($lock)) {
        unlink($lock);
        echo "Deleted: $lock\n";
    } else {
        echo "Not found (ok): $lock\n";
    }
}
echo '</pre>';

// ─── STEP 5: Patch the 3 scripts with lock file protection ──────────────────
echo '<h3>🔒 Patching Scripts with Lock File Protection</h3>';

$scripts = [
    '/home/sairedd1/public_html/price_track_send_alerts.php' => '/tmp/price_track.lock',
    '/home/sairedd1/public_html/delete_messages.php'         => '/tmp/delete_messages.lock',
    '/home/sairedd1/public_html/process_delete_queue.php'    => '/tmp/process_delete_queue.lock',
];

$lockHeader = function(string $lockPath): string {
    return <<<PHP
<?php
// ── LOCK FILE GUARD (auto-added) ──────────────────────────────────────────
\$__lock = '$lockPath';
if (file_exists(\$__lock) && (time() - filemtime(\$__lock) < 58)) {
    exit(0); // already running, skip this minute
}
touch(\$__lock);
register_shutdown_function(function() use (\$__lock) { @unlink(\$__lock); });
// ─────────────────────────────────────────────────────────────────────────

PHP;
};

echo '<pre>';
foreach ($scripts as $file => $lockPath) {
    $shortName = basename($file);

    if (!file_exists($file)) {
        echo "❌ NOT FOUND: $shortName\n";
        continue;
    }

    $content = file_get_contents($file);

    // Check if already patched
    if (str_contains($content, 'LOCK FILE GUARD')) {
        echo "⏭️  Already patched: $shortName\n";
        continue;
    }

    // Backup first
    $backup = $file . '.bak_' . date('Ymd_His');
    file_put_contents($backup, $content);

    // Remove opening <?php tag and prepend new header
    $stripped = preg_replace('/^<\?php\s*/i', '', $content, 1);
    $newContent = $lockHeader($lockPath) . $stripped;

    $written = file_put_contents($file, $newContent);
    if ($written !== false) {
        echo "✅ Patched: $shortName (backup: " . basename($backup) . ")\n";
    } else {
        echo "❌ FAILED to write: $shortName (check permissions)\n";
    }
}
echo '</pre>';

// ─── STEP 6: Final process count ────────────────────────────────────────────
echo '<h3>📊 Final System State</h3><pre>';
$total = trim(shell_exec("ps aux | grep 'php' | grep 'sairedd1' | grep -v grep | wc -l"));
$load  = sys_getloadavg();
echo "Total PHP processes: $total\n";
echo "Load average: {$load[0]} (1m) | {$load[1]} (5m) | {$load[2]} (15m)\n";
echo '</pre>';

?>

<hr style="border-color:#30363d; margin:30px 0">
<h3 style="color:#f85149">⚠️ DELETE THIS FILE NOW</h3>
<pre style="background:#3a1a1a">
rm /home/sairedd1/public_html/kill_zombies.php
</pre>
<p>Or via File Manager → delete kill_zombies.php</p>

<?php
// ─── Auto-delete self (uncomment if you trust it) ───────────────────────────
// @unlink(__FILE__);
// echo '<p class="ok">✅ Script self-deleted.</p>';
?>

</body>
</html>
