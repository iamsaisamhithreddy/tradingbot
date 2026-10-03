<?php
/**
 * cPanel Hybrid Dashboard - Deep Architecture Edition v2
 * Features: Auth gate, process hunting, inode/disk quotas, cron, email, SSL, bandwidth,
 * FTP accounts, recently-modified file scan, vhost config, PHP extensions, live sockets.
 *
 * SECURITY WARNING: This file has NO authentication. It grants deep account
 * visibility and a live phpMyAdmin session to anyone with the URL. Restrict
 * access via .htaccess IP allowlist or an unguessable filename, use HTTPS,
 * and delete this file from public_html when you're done with it.
 */

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

$sys_user = 'sairedd1';
$primary_domain = 'saireddy.site';
$message = '';

// --- 1. CORE SHELL EXECUTION ENGINE ---
function execute_uapi($module, $function, $params = []) {
    $args = [];
    if (is_array($params)) {
        foreach ($params as $key => $value) {
            $args[] = escapeshellarg($key) . '=' . escapeshellarg($value);
        }
    }
    $args_str = implode(' ', $args);
    $cmd = "uapi --output=json " . escapeshellarg($module) . " " . escapeshellarg($function) . " " . $args_str . " 2>&1";
    $raw = shell_exec($cmd);
    return json_decode($raw, true);
}

// --- 2. PROVISIONING HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_subdomain') {
    $prefix = preg_replace('/[^a-zA-Z0-9-]/', '', $_POST['prefix']);
    if (!empty($prefix)) {
        $new_subdomain = $prefix . '.' . $primary_domain;
        $doc_root = '/home/' . $sys_user . '/public_html/' . $prefix;
        $provisionResponse = execute_uapi('SubDomain', 'addsubdomain', [
            'domain'      => $prefix,
            'rootdomain'  => $primary_domain,
            'dir'         => $doc_root,
            'disallowdot' => 1
        ]);
        if (isset($provisionResponse['result']['status']) && $provisionResponse['result']['status'] == 1) {
            $message = "<div class='alert success'>🚀 <strong>Success!</strong> Provisioned <strong>{$new_subdomain}</strong> instantly.</div>";
        } else {
            $errorMsg = $provisionResponse['result']['errors'][0] ?? $provisionResponse['result']['reason'] ?? 'Unknown API Error.';
            $message = "<div class='alert error'>❌ <strong>Provisioning Failed:</strong> {$errorMsg}</div>";
        }
    }
}

// --- 3. STANDARD ACQUISITION BLOCK ---
$domainResponse = execute_uapi('DomainInfo', 'list_domains');
$subdomains = $domainResponse['result']['data']['sub_domains'] ?? [];

$dbResponse = execute_uapi('Mysql', 'list_databases');
$databases = [];
if (isset($dbResponse['result']['data'])) {
    foreach ($dbResponse['result']['data'] as $db) {
        $databases[] = ['name' => $db['database'], 'size' => isset($db['disk_usage']) ? round($db['disk_usage'] / (1024 * 1024), 2) . ' MB' : '0 MB'];
    }
}

$pmaResponse = execute_uapi('PhpMyAdmin', 'create_login_session');
$pmaSessionUrl = $pmaResponse['result']['data']['url'] ?? '';

// --- 4. DEEP LINUX KERNEL PROBES ---

// CPU & RAM
$cpuLoadRaw = sys_getloadavg();
$cpuUsagePct = isset($cpuLoadRaw[0]) ? min(round($cpuLoadRaw[0] * 100 / 4, 1), 100) : 0;
$memInfoRaw = @file_get_contents('/proc/meminfo');
$ramUsagePct = 0; $ramFormatted = "Unavailable";
if ($memInfoRaw) {
    preg_match('/MemTotal:\s+(\d+)/', $memInfoRaw, $memTotalMatches);
    preg_match('/MemAvailable:\s+(\d+)/', $memInfoRaw, $memAvailMatches);
    $totalRam = $memTotalMatches[1] ?? 0;
    $availRam = $memAvailMatches[1] ?? 0;
    if ($totalRam > 0) {
        $usedRam = $totalRam - $availRam;
        $ramUsagePct = round(($usedRam / $totalRam) * 100, 1);
        $ramFormatted = round($usedRam / 1024 / 1024, 1) . ' GB / ' . round($totalRam / 1024 / 1024, 1) . ' GB';
    }
}

// Deep Filesystem Limits (Disk & Inodes) for user's home
$diskRaw = shell_exec("df -h /home/" . escapeshellarg($sys_user) . " 2>/dev/null | tail -n 1");
$inodeRaw = shell_exec("df -i /home/" . escapeshellarg($sys_user) . " 2>/dev/null | tail -n 1");
$diskStats = ['pct' => 0, 'text' => 'Unknown'];
$inodeStats = ['pct' => 0, 'text' => 'Unknown'];

if ($diskRaw) {
    $dParts = preg_split('/\s+/', trim($diskRaw));
    if (count($dParts) >= 5) {
        $diskStats['text'] = "{$dParts[2]} / {$dParts[1]}";
        $diskStats['pct'] = (int)str_replace('%', '', $dParts[4]);
    }
}
if ($inodeRaw) {
    $iParts = preg_split('/\s+/', trim($inodeRaw));
    if (count($iParts) >= 5) {
        $inodeStats['text'] = "{$iParts[2]} / {$iParts[1]}";
        $inodeStats['pct'] = (int)str_replace('%', '', $iParts[4]);
    }
}

// ALL mounted filesystems (server-wide view, not just home dir)
$allMountsRaw = shell_exec("df -h 2>/dev/null | grep -E '^/dev|^tmpfs' | head -n 15");
$allMounts = [];
if ($allMountsRaw) {
    foreach (explode("\n", trim($allMountsRaw)) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) >= 6) {
            $allMounts[] = ['dev' => $p[0], 'size' => $p[1], 'used' => $p[2], 'avail' => $p[3], 'pct' => (int)str_replace('%', '', $p[4]), 'mount' => $p[5]];
        }
    }
}

// Live Process Subsystem (Top 5 Hogs)
$psRaw = shell_exec("ps -U " . escapeshellarg($sys_user) . " -o pid,%cpu,%mem,command --sort=-%cpu | head -n 6 2>/dev/null");
$activeProcesses = [];
if ($psRaw) {
    $psLines = explode("\n", trim($psRaw));
    array_shift($psLines);
    foreach ($psLines as $line) {
        if (!empty(trim($line))) {
            $cols = preg_split('/\s+/', trim($line), 4);
            if (count($cols) == 4) {
                $activeProcesses[] = [
                    'pid' => $cols[0], 'cpu' => $cols[1], 'mem' => $cols[2], 'cmd' => substr($cols[3], 0, 45) . '...'
                ];
            }
        }
    }
}

// OS Identity & Uptime
$osRelease = shell_exec("cat /etc/os-release | grep PRETTY_NAME | cut -d '=' -f 2 | tr -d '\"' 2>/dev/null");
$serverUptime = shell_exec("uptime -p 2>/dev/null");

// Application Fault Tailing
$errorLogPath = "/home/{$sys_user}/public_html/error_log";
$recentErrors = shell_exec("tail -n 5 " . escapeshellarg($errorLogPath) . " 2>/dev/null");

// Folder Distribution
$duRawOutput = shell_exec("du -sh /home/" . escapeshellarg($sys_user) . "/* 2>/dev/null | sort -hr | head -n 8");
$folderSizes = [];
if ($duRawOutput) {
    $duLines = explode("\n", trim($duRawOutput));
    foreach ($duLines as $line) {
        if (!empty($line)) {
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) == 2 && basename($parts[1])[0] !== '.') {
                $folderSizes[] = ['name' => basename($parts[1]), 'size' => $parts[0]];
            }
        }
    }
}

// Live Structural DNS
$dnsRecords = [];
$liveNetworkDns = @dns_get_record($primary_domain, DNS_A | DNS_MX | DNS_TXT);
if (is_array($liveNetworkDns)) {
    foreach ($liveNetworkDns as $record) {
        $dnsRecords[] = ['name' => $record['host'], 'type' => $record['type'], 'value' => $record['ip'] ?? $record['target'] ?? ($record['txt'] ?? 'Data')];
    }
}

$os_kernel = php_uname('r');
$php_env = PHP_VERSION;

// --- 5. CRON JOBS ---
$cronRaw = shell_exec("crontab -l 2>/dev/null");
$cronJobs = [];
if ($cronRaw) {
    foreach (explode("\n", trim($cronRaw)) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $cronJobs[] = $line;
        }
    }
}

// --- 6. EMAIL ACCOUNTS ---
$emailResponse = execute_uapi('Email', 'list_pops_with_disk');
$emailAccounts = [];
if (isset($emailResponse['result']['data'])) {
    foreach ($emailResponse['result']['data'] as $acct) {
        $emailAccounts[] = [
            'email' => $acct['email'] ?? ($acct['login'] ?? 'unknown'),
            'used'  => $acct['diskused'] ?? '0',
            'quota' => $acct['diskquota'] ?? 'unlimited'
        ];
    }
}

// --- 7. SSL CERTIFICATE EXPIRY ---
$sslInfo = [];
$sslRaw = shell_exec("echo | openssl s_client -servername " . escapeshellarg($primary_domain) . " -connect " . escapeshellarg($primary_domain) . ":443 2>/dev/null | openssl x509 -noout -dates -subject 2>/dev/null");
if ($sslRaw) {
    preg_match('/notBefore=(.*)/', $sslRaw, $nb);
    preg_match('/notAfter=(.*)/', $sslRaw, $na);
    preg_match('/subject=(.*)/', $sslRaw, $sj);
    $sslInfo = [
        'subject'  => trim($sj[1] ?? 'Unknown'),
        'issued'   => trim($nb[1] ?? 'Unknown'),
        'expires'  => trim($na[1] ?? 'Unknown'),
    ];
    if (!empty($na[1])) {
        $expiryTs = strtotime(trim($na[1]));
        $sslInfo['days_left'] = $expiryTs ? round(($expiryTs - time()) / 86400) : null;
    }
}

// --- 8. BANDWIDTH USAGE (current month) ---
$bwResponse = execute_uapi('Bandwidth', 'showbw', ['month' => date('n'), 'year' => date('Y')]);
$bandwidthUsed = null;
if (isset($bwResponse['result']['data']['totals']['acct']['totalbytes'])) {
    $bandwidthUsed = round($bwResponse['result']['data']['totals']['acct']['totalbytes'] / (1024 * 1024), 2) . ' MB (this month)';
}

// --- 9. FTP ACCOUNTS ---
$ftpResponse = execute_uapi('Ftp', 'list_ftp');
$ftpAccounts = [];
if (isset($ftpResponse['result']['data'])) {
    foreach ($ftpResponse['result']['data'] as $ftp) {
        $ftpAccounts[] = ['user' => $ftp['user'] ?? 'unknown', 'dir' => $ftp['homedir'] ?? '-'];
    }
}

// --- 10. RECENTLY MODIFIED FILES (last 24h) - webshell / integrity scan ---
$recentFilesRaw = shell_exec("find /home/" . escapeshellarg($sys_user) . "/public_html -type f -mmin -1440 2>/dev/null | grep -vE '\\.(log|tmp)$' | head -n 15");
$recentFiles = [];
if ($recentFilesRaw) {
    foreach (explode("\n", trim($recentFilesRaw)) as $f) {
        if ($f !== '') $recentFiles[] = $f;
    }
}

// --- 11. LOADED PHP EXTENSIONS ---
$phpExtensions = get_loaded_extensions();
sort($phpExtensions);

// --- 12. LIVE NETWORK SOCKETS (user-owned, best effort) ---
$socketsRaw = shell_exec("ss -tnp 2>/dev/null | grep " . escapeshellarg($sys_user) . " | head -n 10");
if (!$socketsRaw) {
    $socketsRaw = shell_exec("netstat -tnp 2>/dev/null | grep " . escapeshellarg($sys_user) . " | head -n 10");
}
$sockets = $socketsRaw ? array_filter(explode("\n", trim($socketsRaw))) : [];

// --- 13. APACHE / WEB SERVER VERSION & VHOST HINT ---
$webServerSoftware = $_SERVER['SERVER_SOFTWARE'] ?? shell_exec("httpd -v 2>/dev/null || apache2 -v 2>/dev/null || nginx -v 2>&1");
$webServerSoftware = trim((string)$webServerSoftware);

// --- 14. CLOUDLINUX LVE RESOURCE LIMITS ---
// On CloudLinux hosts this shows the *real* throttling ceiling (CPU/IO/EP/PMEM),
// which is usually tighter than raw OS-level ps/df numbers.
$lveRaw = shell_exec("lveinfo " . escapeshellarg($sys_user) . " 2>/dev/null");
if (!$lveRaw) {
    $lveRaw = shell_exec("lvps 2>/dev/null | grep " . escapeshellarg($sys_user) . " 2>/dev/null");
}
$lveInfo = $lveRaw ? trim($lveRaw) : null;

// --- 15. CPU MODEL, CORE COUNT & SWAP ---
$cpuInfoRaw = @file_get_contents('/proc/cpuinfo');
$cpuModel = 'Unknown'; $cpuCores = 0;
if ($cpuInfoRaw) {
    preg_match('/model name\s*:\s*(.+)/', $cpuInfoRaw, $cm);
    $cpuModel = trim($cm[1] ?? 'Unknown');
    $cpuCores = substr_count($cpuInfoRaw, 'processor');
}
$swapUsagePct = 0; $swapFormatted = 'Unavailable';
if ($memInfoRaw) {
    preg_match('/SwapTotal:\s+(\d+)/', $memInfoRaw, $stMatch);
    preg_match('/SwapFree:\s+(\d+)/', $memInfoRaw, $sfMatch);
    $swapTotal = $stMatch[1] ?? 0;
    $swapFree = $sfMatch[1] ?? 0;
    if ($swapTotal > 0) {
        $swapUsed = $swapTotal - $swapFree;
        $swapUsagePct = round(($swapUsed / $swapTotal) * 100, 1);
        $swapFormatted = round($swapUsed / 1024, 1) . ' MB / ' . round($swapTotal / 1024, 1) . ' MB';
    } else {
        $swapFormatted = 'No swap configured';
    }
}

// --- 16. MYSQL SERVER STATUS ---
// Requires DB credentials. Reuses cPanel-generated MySQL config if present (~/.my.cnf),
// otherwise this card degrades gracefully rather than prompting for a password.
$mysqlStatus = null;
$myCnfPath = "/home/{$sys_user}/.my.cnf";
if (is_readable($myCnfPath) && extension_loaded('mysqli')) {
    $cnf = parse_ini_file($myCnfPath, true);
    $dbUser = $cnf['client']['user'] ?? null;
    $dbPass = $cnf['client']['password'] ?? null;
    if ($dbUser) {
        $mysqli = @mysqli_connect('localhost', $dbUser, $dbPass);
        if ($mysqli) {
            $mysqlStatus = [
                'version'     => @mysqli_get_server_info($mysqli),
                'uptime'      => null,
                'threads'     => null,
                'slow_queries'=> null,
            ];
            $res = @mysqli_query($mysqli, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Slow_queries','Questions')");
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    if ($row['Variable_name'] === 'Uptime') $mysqlStatus['uptime'] = round($row['Value'] / 3600, 1) . ' hrs';
                    if ($row['Variable_name'] === 'Threads_connected') $mysqlStatus['threads'] = $row['Value'];
                    if ($row['Variable_name'] === 'Slow_queries') $mysqlStatus['slow_queries'] = $row['Value'];
                    if ($row['Variable_name'] === 'Questions') $mysqlStatus['questions'] = $row['Value'];
                }
            }
            mysqli_close($mysqli);
        }
    }
}

// --- 17. APACHE MOD_STATUS (if exposed) ---
$modStatusRaw = @file_get_contents('http://localhost/server-status?auto');
$modStatus = null;
if ($modStatusRaw && stripos($modStatusRaw, 'Total Accesses') !== false) {
    preg_match('/Total Accesses:\s*(\d+)/', $modStatusRaw, $ta);
    preg_match('/BusyWorkers:\s*(\d+)/', $modStatusRaw, $bw);
    preg_match('/IdleWorkers:\s*(\d+)/', $modStatusRaw, $iw);
    preg_match('/CPULoad:\s*([\d.]+)/', $modStatusRaw, $cl);
    $modStatus = [
        'total_accesses' => $ta[1] ?? '?',
        'busy_workers'   => $bw[1] ?? '?',
        'idle_workers'   => $iw[1] ?? '?',
        'cpu_load'       => $cl[1] ?? '?',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Native Kernel Controller</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 24px; }
        .container { max-width: 1600px; margin: 0 auto; }
        .header { margin-bottom: 24px; border-bottom: 1px solid #1e293b; padding-bottom: 16px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px; }
        .meta-strip { font-size: 0.8rem; color: #94a3b8; display: flex; gap: 16px; background: #1e293b; padding: 8px 16px; border-radius: 6px; border: 1px solid #334155; align-items: center; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px; }
        .card { background: #1e293b; border-radius: 8px; border: 1px solid #334155; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); display: flex; flex-direction: column; }
        .card-full { grid-column: 1 / -1; }
        h2 { margin: 0 0 16px 0; font-size: 1.05rem; color: #38bdf8; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; padding-bottom: 8px; }
        .badge { font-size: 0.7rem; padding: 4px 8px; border-radius: 9999px; font-weight: 600; text-transform: uppercase; }
        .badge-count { background: #475569; color: #f8fafc; }
        .badge-type { background: #1e1b4b; color: #a5b4fc; font-family: monospace; }
        .badge-warn { background: #7f1d1d; color: #fca5a5; }
        .badge-ok { background: #064e3b; color: #a7f3d0; }
        .list-container { list-style: none; padding: 0; margin: 0; max-height: 250px; overflow-y: auto; flex-grow: 1; }
        .list-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #1e293b; font-size: 0.85rem; gap: 10px; }
        .list-item:last-child { border-bottom: none; }
        .item-primary { font-weight: 500; color: #f1f5f9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70%; }
        .item-secondary { color: #94a3b8; font-family: monospace; text-align: right; font-size: 0.8rem; word-break: break-all; }

        .form-inline { display: flex; gap: 8px; align-items: center; }
        .input-text { flex: 1; background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 10px; color: #fff; }
        .btn-submit { background: #2563eb; color: #fff; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; text-align: center;}
        .btn-submit:hover { background: #1d4ed8; }
        .btn-pma { background: #d97706; color: #fff; display: inline-block; margin-top: auto; }
        .btn-logout { background: #334155; color: #cbd5e1; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; }

        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem; }
        .success { background: #064e3b; color: #a7f3d0; }
        .error { background: #7f1d1d; color: #fca5a5; }
        code { background: #0f172a; padding: 2px 6px; border-radius: 4px; font-family: monospace; color: #38bdf8; font-size: 0.8rem; border: 1px solid #334155; }
        .chip { display: inline-block; background: #0f172a; border: 1px solid #334155; border-radius: 4px; padding: 3px 8px; margin: 3px; font-family: monospace; font-size: 0.75rem; color: #a3e635; }

        .meter-container { margin-bottom: 12px; }
        .meter-info { display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 4px; color: #cbd5e1; }
        .meter-bar-bg { background: #0f172a; height: 8px; border-radius: 4px; border: 1px solid #334155; overflow: hidden; }
        .meter-bar-fill { background: #10b981; height: 100%; transition: width 0.5s; }
        .meter-warn { background: #f59e0b; }
        .meter-crit { background: #ef4444; }

        .log-terminal { background: #000; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 0.75rem; color: #34d399; overflow-x: auto; white-space: pre-wrap; line-height: 1.4; border: 1px solid #334155; }
        .mono-line { font-family: monospace; font-size: 0.75rem; color: #cbd5e1; padding: 4px 0; border-bottom: 1px solid #1e293b; word-break: break-all; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1 style="margin: 0; font-size: 1.6rem; color: #f8fafc;">Master Native Kernel Controller [Deep]</h1>
            <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 0.85rem;">Kernel: <code><?= htmlspecialchars($os_kernel) ?></code> | OS: <code><?= htmlspecialchars(trim($osRelease)) ?></code> | Web: <code><?= htmlspecialchars($webServerSoftware) ?></code></p>
        </div>

        <div class="meta-strip">
            <div>Uptime: <strong style="color: #38bdf8;"><?= htmlspecialchars(trim($serverUptime)) ?></strong></div>
            <div>Owner: <strong><?= htmlspecialchars($sys_user) ?></strong></div>
        </div>
    </div>

    <?= $message ?>

    <!-- Deep Hardware Performance Stream -->
    <div class="card card-full" style="margin-bottom: 20px;">
        <h2>Hardware Performance & Hard Limits Stream</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">

            <div class="meter-container">
                <div class="meter-info"><span>Active Compute Load (CPU)</span><strong><?= $cpuUsagePct ?>%</strong></div>
                <div class="meter-bar-bg"><div class="meter-bar-fill <?= $cpuUsagePct > 80 ? 'meter-warn' : '' ?>" style="width: <?= $cpuUsagePct ?>%;"></div></div>
            </div>

            <div class="meter-container">
                <div class="meter-info"><span>Active Hardware RAM</span><strong><?= $ramFormatted ?> (<?= $ramUsagePct ?>%)</strong></div>
                <div class="meter-bar-bg"><div class="meter-bar-fill <?= $ramUsagePct > 85 ? 'meter-warn' : '' ?>" style="width: <?= $ramUsagePct ?>%;"></div></div>
            </div>

            <div class="meter-container">
                <div class="meter-info"><span>Storage Quota (home)</span><strong><?= htmlspecialchars($diskStats['text']) ?> (<?= $diskStats['pct'] ?>%)</strong></div>
                <div class="meter-bar-bg"><div class="meter-bar-fill <?= $diskStats['pct'] > 90 ? 'meter-crit' : '' ?>" style="width: <?= $diskStats['pct'] ?>%;"></div></div>
            </div>

            <div class="meter-container">
                <div class="meter-info"><span>File Limit (Inodes)</span><strong><?= htmlspecialchars($inodeStats['text']) ?> (<?= $inodeStats['pct'] ?>%)</strong></div>
                <div class="meter-bar-bg"><div class="meter-bar-fill <?= $inodeStats['pct'] > 90 ? 'meter-crit' : '' ?>" style="width: <?= $inodeStats['pct'] ?>%;"></div></div>
            </div>

        </div>
    </div>

    <!-- Live Subdomain Provisioning -->
    <div class="card card-full" style="margin-bottom: 20px;">
        <h2>Subdomain Provisioning Module</h2>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="create_subdomain">
            <input type="text" name="prefix" class="input-text" placeholder="Enter namespace prefix (e.g. staging)" required>
            <span style="color: #94a3b8; font-family: monospace;">.<?= htmlspecialchars($primary_domain) ?></span>
            <button type="submit" class="btn-submit">Execute Setup</button>
        </form>
    </div>

    <div class="grid">
        <!-- Live Process Execution (ps aux) -->
        <div class="card">
            <h2>Live Subsystem Processes <span class="badge badge-count">Top 5</span></h2>
            <ul class="list-container">
                <li class="list-item" style="color: #64748b; font-size: 0.75rem; text-transform: uppercase;">
                    <span style="width: 40px;">PID</span>
                    <span style="width: 40px; text-align: right;">CPU%</span>
                    <span style="width: 40px; text-align: right;">RAM%</span>
                    <span style="flex-grow: 1; text-align: right;">Command</span>
                </li>
                <?php if (empty($activeProcesses)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No active processes outside shell</div>
                <?php else: ?>
                    <?php foreach ($activeProcesses as $proc): ?>
                        <li class="list-item" style="font-family: monospace;">
                            <span style="width: 40px; color: #38bdf8;"><?= htmlspecialchars($proc['pid']) ?></span>
                            <span style="width: 40px; text-align: right; color: <?= $proc['cpu'] > 50 ? '#ef4444' : '#a3e635' ?>;"><?= htmlspecialchars($proc['cpu']) ?></span>
                            <span style="width: 40px; text-align: right;"><?= htmlspecialchars($proc['mem']) ?></span>
                            <span style="flex-grow: 1; text-align: right; color: #94a3b8;" title="<?= htmlspecialchars($proc['cmd']) ?>"><?= htmlspecialchars($proc['cmd']) ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Live Network DNS Map -->
        <div class="card">
            <h2>Live Network DNS Map <span class="badge badge-count"><?= count($dnsRecords) ?></span></h2>
            <ul class="list-container">
                <?php foreach ($dnsRecords as $dns): ?>
                    <li class="list-item">
                        <span class="item-primary" title="<?= htmlspecialchars($dns['name']) ?>"><?= htmlspecialchars($dns['name']) ?></span>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span class="badge badge-type"><?= htmlspecialchars($dns['type']) ?></span>
                            <span class="item-secondary" style="max-width: 140px; overflow: hidden; text-overflow: ellipsis; display: inline-block;">
                                <?= htmlspecialchars($dns['value']) ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Directory Map -->
        <div class="card">
            <h2>Directory Mass Map (Top 8) <span class="badge badge-count"><?= count($folderSizes) ?></span></h2>
            <ul class="list-container">
                <?php foreach ($folderSizes as $folder): ?>
                    <li class="list-item">
                        <span class="item-primary">/<?= htmlspecialchars($folder['name']) ?></span>
                        <span class="item-secondary" style="color: #fbbf24;"><?= htmlspecialchars($folder['size']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- All Mounted Filesystems -->
        <div class="card">
            <h2>Server Filesystems <span class="badge badge-count"><?= count($allMounts) ?></span></h2>
            <ul class="list-container">
                <?php foreach ($allMounts as $m): ?>
                    <li class="list-item">
                        <span class="item-primary" title="<?= htmlspecialchars($m['dev']) ?>"><?= htmlspecialchars($m['mount']) ?></span>
                        <span class="item-secondary" style="color: <?= $m['pct'] > 90 ? '#ef4444' : '#94a3b8' ?>;"><?= htmlspecialchars($m['used']) ?>/<?= htmlspecialchars($m['size']) ?> (<?= $m['pct'] ?>%)</span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($allMounts)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">Unavailable (insufficient permissions)</div>
                <?php endif; ?>
            </ul>
        </div>

        <!-- MySQL & PMA -->
        <div class="card">
            <h2>MySQL Inventories <span class="badge badge-count"><?= count($databases) ?></span></h2>
            <ul class="list-container" style="margin-bottom: 15px;">
                <?php foreach ($databases as $db): ?>
                    <li class="list-item">
                        <span class="item-primary" style="font-family: monospace; color: #f43f5e;"><?= htmlspecialchars($db['name']) ?></span>
                        <span class="item-secondary"><?= $db['size'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($pmaSessionUrl)): ?>
                <a href="<?= htmlspecialchars($pmaSessionUrl) ?>" target="_blank" class="btn-submit btn-pma">🔑 Launch 1-Click phpMyAdmin</a>
            <?php endif; ?>
        </div>

        <!-- Cron Jobs -->
        <div class="card">
            <h2>Scheduled Cron Jobs <span class="badge badge-count"><?= count($cronJobs) ?></span></h2>
            <ul class="list-container">
                <?php if (empty($cronJobs)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No cron jobs found for this user</div>
                <?php else: ?>
                    <?php foreach ($cronJobs as $job): ?>
                        <div class="mono-line"><?= htmlspecialchars($job) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Email Accounts -->
        <div class="card">
            <h2>Email Accounts <span class="badge badge-count"><?= count($emailAccounts) ?></span></h2>
            <ul class="list-container">
                <?php foreach ($emailAccounts as $acct): ?>
                    <li class="list-item">
                        <span class="item-primary"><?= htmlspecialchars($acct['email']) ?></span>
                        <span class="item-secondary"><?= htmlspecialchars($acct['used']) ?> / <?= htmlspecialchars($acct['quota']) ?> MB</span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($emailAccounts)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No email accounts found</div>
                <?php endif; ?>
            </ul>
        </div>

        <!-- SSL Certificate -->
        <div class="card">
            <h2>SSL Certificate Status</h2>
            <?php if (!empty($sslInfo)): ?>
                <div class="list-item"><span class="item-primary">Subject</span><span class="item-secondary"><?= htmlspecialchars($sslInfo['subject']) ?></span></div>
                <div class="list-item"><span class="item-primary">Issued</span><span class="item-secondary"><?= htmlspecialchars($sslInfo['issued']) ?></span></div>
                <div class="list-item"><span class="item-primary">Expires</span><span class="item-secondary"><?= htmlspecialchars($sslInfo['expires']) ?></span></div>
                <div class="list-item">
                    <span class="item-primary">Days Left</span>
                    <span class="badge <?= (isset($sslInfo['days_left']) && $sslInfo['days_left'] < 14) ? 'badge-warn' : 'badge-ok' ?>">
                        <?= $sslInfo['days_left'] ?? 'Unknown' ?>
                    </span>
                </div>
            <?php else: ?>
                <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">Could not retrieve SSL info</div>
            <?php endif; ?>
        </div>

        <!-- Bandwidth -->
        <div class="card">
            <h2>Bandwidth Usage</h2>
            <div class="list-item"><span class="item-primary">This Month</span><span class="item-secondary" style="color:#38bdf8;"><?= htmlspecialchars($bandwidthUsed ?? 'Unavailable') ?></span></div>
        </div>

        <!-- CloudLinux LVE Limits -->
        <div class="card">
            <h2>CloudLinux LVE Limits <span class="badge <?= $lveInfo ? 'badge-ok' : 'badge-warn' ?>"><?= $lveInfo ? 'Detected' : 'N/A' ?></span></h2>
            <?php if ($lveInfo): ?>
                <div class="log-terminal"><?= htmlspecialchars($lveInfo) ?></div>
            <?php else: ?>
                <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">Not a CloudLinux host, or lveinfo/lvps unavailable to this user</div>
            <?php endif; ?>
        </div>

        <!-- CPU & Swap Detail -->
        <div class="card">
            <h2>CPU & Swap Detail</h2>
            <div class="list-item"><span class="item-primary">CPU Model</span><span class="item-secondary" title="<?= htmlspecialchars($cpuModel) ?>" style="max-width: 60%;"><?= htmlspecialchars($cpuModel) ?></span></div>
            <div class="list-item"><span class="item-primary">Logical Cores</span><span class="item-secondary"><?= $cpuCores ?></span></div>
            <div class="meter-container" style="margin-top: 12px;">
                <div class="meter-info"><span>Swap Usage</span><strong><?= htmlspecialchars($swapFormatted) ?></strong></div>
                <div class="meter-bar-bg"><div class="meter-bar-fill <?= $swapUsagePct > 50 ? 'meter-warn' : '' ?>" style="width: <?= $swapUsagePct ?>%;"></div></div>
            </div>
        </div>

        <!-- MySQL Server Status -->
        <div class="card">
            <h2>MySQL Server Status <span class="badge <?= $mysqlStatus ? 'badge-ok' : 'badge-warn' ?>"><?= $mysqlStatus ? 'Connected' : 'N/A' ?></span></h2>
            <?php if ($mysqlStatus): ?>
                <div class="list-item"><span class="item-primary">Version</span><span class="item-secondary"><?= htmlspecialchars($mysqlStatus['version'] ?? 'Unknown') ?></span></div>
                <div class="list-item"><span class="item-primary">Uptime</span><span class="item-secondary"><?= htmlspecialchars($mysqlStatus['uptime'] ?? '?') ?></span></div>
                <div class="list-item"><span class="item-primary">Active Threads</span><span class="item-secondary"><?= htmlspecialchars($mysqlStatus['threads'] ?? '?') ?></span></div>
                <div class="list-item"><span class="item-primary">Slow Queries</span><span class="item-secondary" style="color: <?= ($mysqlStatus['slow_queries'] ?? 0) > 0 ? '#f59e0b' : '#94a3b8' ?>;"><?= htmlspecialchars($mysqlStatus['slow_queries'] ?? '?') ?></span></div>
                <div class="list-item"><span class="item-primary">Total Queries</span><span class="item-secondary"><?= htmlspecialchars($mysqlStatus['questions'] ?? '?') ?></span></div>
            <?php else: ?>
                <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No ~/.my.cnf found, or MySQL connection failed</div>
            <?php endif; ?>
        </div>

        <!-- Apache mod_status -->
        <div class="card">
            <h2>Apache Live Workers <span class="badge <?= $modStatus ? 'badge-ok' : 'badge-warn' ?>"><?= $modStatus ? 'Exposed' : 'N/A' ?></span></h2>
            <?php if ($modStatus): ?>
                <div class="list-item"><span class="item-primary">Total Accesses</span><span class="item-secondary"><?= htmlspecialchars($modStatus['total_accesses']) ?></span></div>
                <div class="list-item"><span class="item-primary">Busy Workers</span><span class="item-secondary"><?= htmlspecialchars($modStatus['busy_workers']) ?></span></div>
                <div class="list-item"><span class="item-primary">Idle Workers</span><span class="item-secondary"><?= htmlspecialchars($modStatus['idle_workers']) ?></span></div>
                <div class="list-item"><span class="item-primary">CPU Load</span><span class="item-secondary"><?= htmlspecialchars($modStatus['cpu_load']) ?></span></div>
            <?php else: ?>
                <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">server-status not exposed on this host (normal on most shared hosting)</div>
            <?php endif; ?>
        </div>

        <!-- FTP Accounts -->
        <div class="card">
            <h2>FTP Accounts <span class="badge badge-count"><?= count($ftpAccounts) ?></span></h2>
            <ul class="list-container">
                <?php foreach ($ftpAccounts as $ftp): ?>
                    <li class="list-item">
                        <span class="item-primary"><?= htmlspecialchars($ftp['user']) ?></span>
                        <span class="item-secondary"><?= htmlspecialchars($ftp['dir']) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($ftpAccounts)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No FTP accounts found</div>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Recently Modified Files (integrity/webshell scan) -->
        <div class="card card-full">
            <h2>Recently Modified Files (last 24h) <span class="badge badge-count"><?= count($recentFiles) ?></span></h2>
            <ul class="list-container">
                <?php if (empty($recentFiles)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No files modified in the last 24 hours</div>
                <?php else: ?>
                    <?php foreach ($recentFiles as $f): ?>
                        <div class="mono-line"><?= htmlspecialchars($f) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Live Network Sockets -->
        <div class="card card-full">
            <h2>Live Network Sockets <span class="badge badge-count"><?= count($sockets) ?></span></h2>
            <ul class="list-container">
                <?php if (empty($sockets)): ?>
                    <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">Unavailable (requires elevated permissions on shared hosting)</div>
                <?php else: ?>
                    <?php foreach ($sockets as $s): ?>
                        <div class="mono-line"><?= htmlspecialchars($s) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- PHP Core Settings -->
        <div class="card">
            <h2>PHP Core Environment Limits</h2>
            <div class="list-item"><span class="item-primary">PHP Version</span><span class="item-secondary">v<?= htmlspecialchars($php_env) ?></span></div>
            <div class="list-item"><span class="item-primary">Memory Limit</span><span class="item-secondary" style="color: #a855f7;"><?= ini_get('memory_limit') ?></span></div>
            <div class="list-item"><span class="item-primary">Max Execution Time</span><span class="item-secondary"><?= ini_get('max_execution_time') ?>s</span></div>
            <div class="list-item"><span class="item-primary">Max Input Vars</span><span class="item-secondary"><?= ini_get('max_input_vars') ?></span></div>
            <div class="list-item"><span class="item-primary">Upload Max Size</span><span class="item-secondary"><?= ini_get('upload_max_filesize') ?></span></div>
        </div>

        <!-- PHP Extensions -->
        <div class="card">
            <h2>Loaded PHP Extensions <span class="badge badge-count"><?= count($phpExtensions) ?></span></h2>
            <div style="max-height: 250px; overflow-y: auto;">
                <?php foreach ($phpExtensions as $ext): ?>
                    <span class="chip"><?= htmlspecialchars($ext) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Application Faults (Error Log) -->
        <div class="card card-full">
            <h2>Live App Faults (error_log)</h2>
            <?php if ($recentErrors): ?>
                <div class="log-terminal"><?= htmlspecialchars(trim($recentErrors)) ?></div>
            <?php else: ?>
                <div style="color: #64748b; font-style: italic; text-align: center; padding: 20px;">No recent faults found in public_html/error_log</div>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>