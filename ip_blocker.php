<?php
// Enable error reporting to debug any issues instantly
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Pull cPanel credentials & base URL from db.php ($cpanel_user, $cpanel_token, $WebsiteURL)
require_once 'db.php';

date_default_timezone_set("Asia/Kolkata");

// Session login or not.
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); // redirecting to login page if not logged in
    exit;
}

define('CPANEL_PORT', '2083');
$cpanel_host = parse_url($WebsiteURL, PHP_URL_HOST);

$message = '';
$error = '';
$action = $_POST['action'] ?? '';

// Helper function to execute cPanel UAPI requests via cURL (Used for Add/Remove)
function call_cpanel_uapi($module, $function, $params, $cpanel_host, $cpanel_user, $cpanel_token) {
    $url = 'https://' . $cpanel_host . ':' . CPANEL_PORT . '/execute/' . $module . '/' . $function;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: cpanel ' . $cpanel_user . ':' . $cpanel_token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'errors' => [$error_msg]];
    }
    curl_close($ch);
    
    return json_decode($response, true);
}

// Helper function to execute older cPanel API 2 requests (Required for listing IPs)
function call_cpanel_api2($module, $function, $params, $cpanel_host, $cpanel_user, $cpanel_token) {
    // API 2 requires module, function, and auth user in the payload
    $params['cpanel_jsonapi_user'] = $cpanel_user;
    $params['cpanel_jsonapi_apiversion'] = '2';
    $params['cpanel_jsonapi_module'] = $module;
    $params['cpanel_jsonapi_func'] = $function;

    $url = 'https://' . $cpanel_host . ':' . CPANEL_PORT . '/json-api/cpanel';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: cpanel ' . $cpanel_user . ':' . $cpanel_token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['cpanelresult' => ['event' => ['result' => 0], 'error' => $error_msg]];
    }
    curl_close($ch);
    
    return json_decode($response, true);
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_block') {
        $ip = trim($_POST['ip'] ?? '');
        if (empty($ip)) {
            $error = "IP address, range, or domain cannot be empty.";
        } else {
            // UAPI BlockIP::add_ip
            $result = call_cpanel_uapi('BlockIP', 'add_ip', ['ip' => $ip], $cpanel_host, $cpanel_user, $cpanel_token);
            if (isset($result['status']) && $result['status'] == 1) {
                $message = "Successfully blocked: " . htmlspecialchars($ip);
            } else {
                $error = "Failed to add block: " . implode(', ', $result['errors'] ?? ['Unknown error']);
            }
        }
    } elseif ($action === 'delete_block') {
        $ip = trim($_POST['ip'] ?? '');
        if (empty($ip)) {
            $error = "IP address is required for removal.";
        } else {
            // UAPI BlockIP::remove_ip
            $result = call_cpanel_uapi('BlockIP', 'remove_ip', ['ip' => $ip], $cpanel_host, $cpanel_user, $cpanel_token);
            if (isset($result['status']) && $result['status'] == 1) {
                $message = "Successfully unblocked: " . htmlspecialchars($ip);
            } else {
                $error = "Failed to remove block: " . implode(', ', $result['errors'] ?? ['Unknown error']);
            }
        }
    }
}

// Fetch currently blocked IPs list
// Note: UAPI has no list function for this, so we MUST use API 2's DenyIp::listdenyips
$blocked_ips = [];
$blockers_res = call_cpanel_api2('DenyIp', 'listdenyips', [], $cpanel_host, $cpanel_user, $cpanel_token);

if (isset($blockers_res['cpanelresult']['event']['result']) && $blockers_res['cpanelresult']['event']['result'] == 1) {
    // API 2 returns the actual array inside the 'data' node of 'cpanelresult'
    $blocked_ips = $blockers_res['cpanelresult']['data'] ?? [];
} elseif (isset($blockers_res['cpanelresult']['error'])) {
    $error = "Failed to fetch blocked IPs: " . $blockers_res['cpanelresult']['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>cPanel IP Blocker Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="fa-solid fa-ban me-2"></i>IP Blocker Management</h4>
                        <span class="badge bg-light text-dark font-monospace"><?= count($blocked_ips) ?> Blocked</span>
                    </div>
                    <div class="card-body">
                        
                        <?php if (!empty($message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Add IP Form Section -->
                        <div class="card mb-4 border-primary">
                            <div class="card-header bg-light fw-bold text-primary">
                                <i class="fa-solid fa-plus-circle me-1"></i> Add an IP Address or Range to Block
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">
                                    You can block a single IP address (e.g., <code>192.168.1.1</code>), a range (e.g., <code>192.168.1.1-192.168.1.25</code>, or <code>192.168.1.0/24</code>), or a domain name.
                                </p>
                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="action" value="add_block">
                                    <div class="col-md-9">
                                        <input type="text" class="form-control" name="ip" placeholder="Enter IP, range, or domain (e.g. 192.168.1.50)" required>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-danger w-100"><i class="fa-solid fa-ban me-1"></i> Add IP</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Current Blocked IPs Table -->
                        <h5 class="mb-3 text-secondary"><i class="fa-solid fa-list me-1"></i> Currently Blocked IP Addresses</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered align-middle m-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Blocked IP / Range / Domain</th>
                                        <th class="text-center" style="width: 120px;">Actions</th>
                                    </tr>
                                </thead>
<tbody>
                                    <?php 
                                    // Pre-filter the array to remove artifacts like 'echo var="REMOTE_ADDR"'
                                    $valid_blocks = array_filter($blocked_ips, function($b) {
                                        $ip = is_array($b) ? ($b['ip'] ?? '') : $b;
                                        // Strip HTML tags and check if it contains the REMOTE_ADDR artifact
                                        return !empty($ip) && strpos($ip, 'REMOTE_ADDR') === false;
                                    });
                                    ?>
                                    
                                    <?php if (empty($valid_blocks)): ?>
                                        <tr>
                                            <td colspan="2" class="text-center text-muted py-4">No IP addresses or ranges are currently blocked.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($valid_blocks as $b): 
                                            // Extract and clean the IP string
                                            $ip_address = is_array($b) ? ($b['ip'] ?? '') : $b;
                                            $ip_address = trim(strip_tags($ip_address));
                                        ?>
                                            <tr>
                                                <td class="font-monospace text-danger fw-bold"><?= htmlspecialchars($ip_address) ?></td>
                                                <td class="text-center">
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove the block for <?= htmlspecialchars($ip_address) ?>?');">
                                                        <input type="hidden" name="action" value="delete_block">
                                                        <input type="hidden" name="ip" value="<?= htmlspecialchars($ip_address) ?>">
                                                        <button type="submit" class="btn btn-outline-success btn-sm px-3" title="Unblock IP"><i class="fa-solid fa-unlock me-1"></i> Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>