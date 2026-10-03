<?php
/**
 * cPanel Hybrid Dashboard - Hotfix Edition
 * Resolves: DomainInfo UAPI function mapping & CSS inline text clumping
 */

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

// Unified execution framework wrapper
function execute_uapi($module, $function, $arguments = '') {
    $cmd = "uapi --output=json " . escapeshellarg($module) . " " . escapeshellarg($function) . " " . $arguments . " 2>&1";
    $raw = shell_exec($cmd);
    return json_decode($raw, true);
}

// 1. Fetch Subdomains via correct UAPI module function
$domainResponse = execute_uapi('DomainInfo', 'list_domains');
$subdomains = [];
if (isset($domainResponse['result']['data']['sub_domains'])) {
    $subdomains = $domainResponse['result']['data']['sub_domains'];
}

// 2. Fetch Email pipelines and sizes
$emailResponse = execute_uapi('Email', 'list_pops');
$emails = [];
if (isset($emailResponse['result']['data'])) {
    foreach ($emailResponse['result']['data'] as $account) {
        $emails[] = [
            'address' => $account['email'],
            'usage'   => round(($account['diskused'] ?? 0) / (1024 * 1024), 2) . ' MB'
        ];
    }
}

// 3. Fetch MySQL Inventories
$dbResponse = execute_uapi('Mysql', 'list_databases');
$databases = [];
if (isset($dbResponse['result']['data'])) {
    foreach ($dbResponse['result']['data'] as $db) {
        $databases[] = [
            'name' => $db['database'],
            'size' => isset($db['disk_usage']) ? round($db['disk_usage'] / (1024 * 1024), 2) . ' MB' : '0 MB'
        ];
    }
}

// 4. Fetch Live SSL Validation Profiles
$sslResponse = execute_uapi('SSL', 'installed_hosts');
$sslProfiles = [];
if (isset($sslResponse['result']['data'])) {
    foreach ($sslResponse['result']['data'] as $host) {
        $domainName = $host['domain'] ?? 'Unknown Host';
        $notAfter = $host['certificate']['not_after'] ?? 0;
        if ($notAfter) {
            $days = ceil(($notAfter - time()) / 86400);
            $sslProfiles[] = [
                'domain' => $domainName,
                'days'   => $days > 0 ? $days : 0
            ];
        }
    }
}

// System Diagnostic calculations
$os_kernel = php_uname('r');
$php_env = PHP_VERSION;
$disk_free = disk_free_space(".");
$disk_total = disk_total_space(".");
$disk_pct = round((($disk_total - $disk_free) / $disk_total) * 100);
$disk_formatted_total = round($disk_total / (1024*1024*1024*1024), 1) . 'T';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>cPanel Hybrid Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 24px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid #1e293b; padding-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; }
        .card { background: #1e293b; border-radius: 8px; border: 1px solid #334155; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .card-full { grid-column: 1 / -1; }
        h2 { margin: 0 0 16px 0; font-size: 1.15rem; color: #38bdf8; display: flex; justify-content: space-between; align-items: center; }
        .badge { font-size: 0.75rem; padding: 4px 10px; border-radius: 9999px; font-weight: 600; text-transform: uppercase; }
        .badge-status { background: #065f46; color: #34d399; }
        .badge-count { background: #334155; color: #94a3b8; }
        .badge-expiry { background: #1e1b4b; color: #818cf8; }
        .badge-alert { background: #7c2d12; color: #fdba74; }
        .stat-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px; }
        .stat-box { background: #0f172a; padding: 12px; border-radius: 6px; border: 1px solid #334155; }
        .stat-label { font-size: 0.75rem; color: #94a3b8; margin-bottom: 4px; }
        .stat-val { font-size: 1rem; font-weight: 700; color: #f8fafc; }
        .list-container { list-style: none; padding: 0; margin: 0; max-height: 280px; overflow-y: auto; }
        .list-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #334155; font-size: 0.9rem; }
        .list-item:last-child { border-bottom: none; }
        .list-item .item-primary { font-weight: 500; color: #f1f5f9; }
        .list-item .item-secondary { color: #94a3b8; font-family: monospace; }
        .empty-placeholder { color: #64748b; text-align: center; padding: 20px 0; font-style: italic; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1 style="margin: 0; font-size: 1.6rem; color: #f8fafc;">cPanel Hybrid Dashboard</h1>
            <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 0.9rem;">
                Pipeline Status: <span class="badge badge-status">💻 EXEC Shell (Unjailed)</span>
            </p>
        </div>
    </div>

    <div class="grid">
        <!-- Server Resources Card -->
        <div class="card">
            <h2>Server Info</h2>
            <div class="stat-row">
                <div class="stat-box">
                    <div class="stat-label">OS Kernel</div>
                    <div class="stat-val" style="font-size: 0.85rem; word-break: break-all;"><?= htmlspecialchars($os_kernel) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">PHP Environment</div>
                    <div class="stat-val"><?= htmlspecialchars($php_env) ?></div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Disk Usage</div>
                <div class="stat-val"><?= $disk_pct ?>% <span style="font-weight: normal; font-size: 0.8rem; color: #64748b;">(of <?= $disk_formatted_total ?>)</span></div>
            </div>
        </div>

        <!-- Subdomain Engine Card -->
        <div class="card">
            <h2>Subdomain Management <span class="badge badge-count"><?= count($subsubdomains) ?: count($subdomains) ?></span></h2>
            <ul class="list-container">
                <?php if (empty($subdomains)): ?>
                    <div class="empty-placeholder">No active subdomains provisioned</div>
                <?php else: ?>
                    <?php foreach ($subdomains as $sub): ?>
                        <li class="list-item">
                            <span class="item-primary"><?= htmlspecialchars($sub) ?></span>
                            <span class="badge badge-status" style="font-size: 0.65rem;">Active</span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Email Pipelines Card -->
        <div class="card">
            <h2>Email Pipeline <span class="badge badge-count"><?= count($emails) ?></span></h2>
            <ul class="list-container">
                <?php if (empty($emails)): ?>
                    <div class="empty-placeholder">No active email routings configured</div>
                <?php else: ?>
                    <?php foreach ($emails as $email): ?>
                        <li class="list-item">
                            <span class="item-primary"><?= htmlspecialchars($email['address']) ?></span>
                            <span class="item-secondary"><?= $email['usage'] ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Database Inventories Card -->
        <div class="card">
            <h2>MySQL Database Inventories <span class="badge badge-count"><?= count($databases) ?></span></h2>
            <ul class="list-container">
                <?php if (empty($databases)): ?>
                    <div class="empty-placeholder">No active SQL instances mapping storage</div>
                <?php else: ?>
                    <?php foreach ($databases as $db): ?>
                        <li class="list-item">
                            <span class="item-primary"><?= htmlspecialchars($db['name']) ?></span>
                            <span class="item-secondary"><?= $db['size'] ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- SSL Profiles Unified Card -->
        <div class="card card-full">
            <h2>Live SSL Validation Profiles <span class="badge badge-count"><?= count($sslProfiles) ?></span></h2>
            <ul class="list-container" style="max-height: 400px;">
                <?php if (empty($sslProfiles)): ?>
                    <div class="empty-placeholder">No active TLS certificates mapped to virtual hosts</div>
                <?php else: ?>
                    <?php foreach ($sslProfiles as $ssl): ?>
                        <li class="list-item">
                            <span class="item-primary" style="font-family: monospace; font-size: 0.95rem;"><?= htmlspecialchars($ssl['domain']) ?></span>
                            <span class="badge <?= $ssl['days'] < 20 ? 'badge-alert' : 'badge-expiry' ?>">
                                Expires in <?= $ssl['days'] ?> days
                            </span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

</body>
</html>