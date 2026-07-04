<?php
// Where we will temporarily store the ESP's latest vitals
$statusFile = __DIR__ . '/esp_status.json';

// =========================================================================
// BACKEND: Catch the heartbeat from the ESP8266
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data) {
        // Add server-side data to what the ESP sent
        $data['last_seen'] = time();
        $data['public_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Save it!
        file_put_contents($statusFile, json_encode($data));
        echo "Heartbeat logged successfully.";
    }
    exit;
}

// =========================================================================
// FRONTEND: Display the Dashboard to you
// =========================================================================
$status = [
    'last_seen' => 0, 'ip' => 'Waiting for data...', 'public_ip' => 'Waiting...', 
    'rssi' => 0, 'free_heap' => 0, 'uptime' => 0
];

if (file_exists($statusFile)) {
    $status = json_decode(file_get_contents($statusFile), true);
}

// Calculate how long ago the board checked in
$seconds_ago = time() - $status['last_seen'];

// CHANGED: If it fails to check in within 45 seconds, it's officially OFFLINE
$is_online = ($status['last_seen'] > 0 && $seconds_ago < 45);
$statusColor = $is_online ? '#10b981' : '#ef4444';
$statusText = $is_online ? 'ONLINE & SCANNING' : 'OFFLINE (Disconnected)';

// Convert uptime seconds into Hours/Minutes
$uptimeMins = floor($status['uptime'] / 60);
$uptimeHours = floor($uptimeMins / 60);
$uptimeMins = $uptimeMins % 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESP8266 NodeMCU Status</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: white; padding: 2rem; display: flex; justify-content: center; }
        .dashboard { background: #1e293b; padding: 30px; border-radius: 12px; width: 100%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h2 { margin-top: 0; color: #f8fafc; border-bottom: 1px solid #334155; padding-bottom: 15px; }
        .status-badge { background: <?php echo $statusColor; ?>; color: white; padding: 8px 12px; border-radius: 6px; font-weight: bold; display: inline-block; margin-bottom: 20px; font-size: 14px; letter-spacing: 1px; }
        .metric { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #334155; }
        .metric span.label { color: #94a3b8; font-weight: 500; }
        .metric span.value { font-weight: bold; color: #e2e8f0; }
        .footer { margin-top: 20px; text-align: center; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
    <div class="dashboard">
        <h2>📡 NodeMCU Monitor</h2>
        <div class="status-badge"><?php echo $statusText; ?></div>
        
        <div class="metric">
            <span class="label">Last Check-in:</span>
            <span class="value"><?php echo $status['last_seen'] > 0 ? $seconds_ago . " seconds ago" : "Never"; ?></span>
        </div>
        <div class="metric">
            <span class="label">Local Network IP:</span>
            <span class="value"><?php echo htmlspecialchars($status['ip']); ?></span>
        </div>
        <div class="metric">
            <span class="label">Public IP:</span>
            <span class="value"><?php echo htmlspecialchars($status['public_ip']); ?></span>
        </div>
        <div class="metric">
            <span class="label">Wi-Fi Signal (RSSI):</span>
            <span class="value"><?php echo $status['rssi']; ?> dBm</span>
        </div>
        <div class="metric">
            <span class="label">Available RAM (Heap):</span>
            <span class="value"><?php echo number_format($status['free_heap']); ?> bytes</span>
        </div>
        <div class="metric">
            <span class="label">Continuous Uptime:</span>
            <span class="value"><?php echo "{$uptimeHours}h {$uptimeMins}m"; ?></span>
        </div>
        
        <div class="footer">Auto-refreshes every 30 seconds...</div>
    </div>
    <script>
        // Reload the page automatically to keep the dashboard live
        setTimeout(() => { window.location.reload(); }, 30000);
    </script>
</body>
</html>