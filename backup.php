<?php
session_start();

// ==========================================
// 1. CONFIGURATION & PATHS
// ==========================================
require_once 'db.php'; // provides $email

$public_dir = $_SERVER['DOCUMENT_ROOT'];
$download_dir = $public_dir . '/temp_backups'; 

// Safely determine the cPanel home directory (usually /home/username)
preg_match('/^\/home[0-9]*\/[a-zA-Z0-9_]+/', $public_dir, $matches);
$home_dir = $matches[0] ?? dirname($public_dir);

// ==========================================
// 2. BACKEND (AJAX HANDLERS)
// ==========================================
if (isset($_GET['action'])) {
    
    // ACTION: Start the Backup
    if ($_GET['action'] == 'start') {
        // Run the cPanel UAPI command
        $command = "uapi Backup fullbackup_to_homedir email={$email} 2>&1";
        shell_exec($command);
        
        // Record the exact time so we don't accidentally grab old backups
        $_SESSION['backup_start_time'] = time();
        
        // Create the temporary download folder if it does not exist
        if (!is_dir($download_dir)) {
            mkdir($download_dir, 0755, true);
        }
        
        echo json_encode(['status' => 'started']);
        exit;
    }

    // ACTION: Check the Status
    if ($_GET['action'] == 'check') {
        $start_time = $_SESSION['backup_start_time'] ?? time();
        
        // Search the home directory for the newly generating .tar.gz file
        $files = glob($home_dir . '/backup-*.tar.gz');
        $latest_file = '';
        $latest_time = 0;

        foreach ($files as $file) {
            $file_time = filemtime($file);
            // Look for a backup created AFTER the button was clicked
            if ($file_time >= $start_time && $file_time > $latest_time) {
                $latest_file = $file;
                $latest_time = $file_time;
            }
        }

        if ($latest_file) {
            // Check if cPanel is finished writing the file.
            // If the file has not grown/changed in 60 seconds, we assume it is complete.
            if ((time() - filemtime($latest_file)) > 60) {
                
                $filename = basename($latest_file);
                $destination = $download_dir . '/' . $filename;
                
                // Move the file into the public web folder so the browser can reach it
                rename($latest_file, $destination);
                
                // Construct the download URL
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $download_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/temp_backups/" . $filename;
                
                echo json_encode(['status' => 'finished', 'url' => $download_url]);
                exit;
            } else {
                echo json_encode(['status' => 'running', 'message' => 'cPanel is generating the backup file...']);
                exit;
            }
        }
        
        echo json_encode(['status' => 'running', 'message' => 'Waiting for cPanel to start the process...']);
        exit;
    }
}
?>

<!-- ========================================== -->
<!-- 3. FRONTEND (USER INTERFACE)               -->
<!-- ========================================== -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auto Backup & Download</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 40px; background: #f4f5f7; text-align: center; }
        .card { background: white; padding: 40px 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        h2 { margin-top: 0; color: #333; }
        p { color: #555; line-height: 1.5; }
        button { background: #ff6c2c; color: white; border: none; padding: 14px 24px; font-size: 16px; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 20px; transition: background 0.3s; }
        button:hover { background: #e05a20; }
        #status-area { margin-top: 30px; display: none; padding: 20px; background: #f8f9fa; border-radius: 6px; border: 1px solid #ddd; }
        .spinner { border: 4px solid #e9ecef; border-top: 4px solid #ff6c2c; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 0 auto 15px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        #status-text { margin: 0; font-weight: 500; color: #333; }
        .warning { color: #d9534f; font-size: 14px; margin-top: 20px; text-align: left; background: #fdf0ef; padding: 10px; border-radius: 4px; border-left: 4px solid #d9534f; }
    </style>
</head>
<body>

<div class="card">
    <h2>Generate Full Backup</h2>
    <p>Click below to generate a full server backup. The page will monitor the process and automatically download the file when it is completely finished.</p>
    
    <button id="startBtn" onclick="startBackup()">Start Backup & Auto-Download</button>

    <div id="status-area">
        <div class="spinner" id="spinner"></div>
        <p id="status-text">Starting backup...</p>
    </div>

    <div class="warning">
        <strong>⚠️ Security Warning:</strong> After the download finishes, immediately delete this script (<code>autobackup.php</code>) and the <code>temp_backups</code> folder from your cPanel File Manager to prevent unauthorized access to your server data.
    </div>
</div>

<script>
    let checkInterval;

    function startBackup() {
        document.getElementById('startBtn').style.display = 'none';
        document.getElementById('status-area').style.display = 'block';

        // Ping backend to start the backup
        fetch('?action=start')
            .then(response => response.json())
            .then(data => {
                if(data.status === 'started') {
                    document.getElementById('status-text').innerText = "cPanel is building the backup. Do not close this tab...";
                    
                    // Start polling the server every 10 seconds to check if it is done
                    checkInterval = setInterval(checkStatus, 10000);
                }
            })
            .catch(error => {
                document.getElementById('status-text').innerText = "Error initiating backup. Check server logs.";
                document.getElementById('spinner').style.display = 'none';
            });
    }

    function checkStatus() {
        fetch('?action=check')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'finished') {
                    clearInterval(checkInterval);
                    document.getElementById('status-text').innerHTML = "<strong>Complete!</strong> Your download is starting...";
                    document.getElementById('spinner').style.display = 'none';
                    
                    // Trigger the automatic download
                    window.location.href = data.url;
                } else {
                    document.getElementById('status-text').innerText = data.message;
                }
            })
            .catch(error => console.error('Error checking status:', error));
    }
</script>

</body>
</html>