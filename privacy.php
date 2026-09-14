<?php
// Enable error reporting to debug any issues instantly
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration for cPanel connection
define('CPANEL_HOST', 'saireddy.site'); 
define('CPANEL_PORT', '2083');
define('CPANEL_USER', 'sairedd1'); 
define('CPANEL_TOKEN', 'N74T0ON6VR80TMK7IMCPMXOHAV93LK82'); 

$message = '';
$error = '';
$action = $_POST['action'] ?? '';

// Capture and format directory path safely
$dir = trim($_POST['dir'] ?? $_GET['dir'] ?? '/home/sairedd1/public_html');
if (!empty($dir) && $dir[0] !== '/') {
    $dir = '/' . ltrim($dir, '/');
}

// Helper function to execute cPanel UAPI requests via cURL
function call_cpanel_uapi($module, $function, $params = []) {
    $url = 'https://' . CPANEL_HOST . ':' . CPANEL_PORT . '/execute/' . $module . '/' . $function;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_TOKEN
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

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($dir)) {
        $error = "Directory path cannot be empty.";
    } else {
        if ($action === 'configure') {
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $authname = trim($_POST['authname'] ?? 'Protected Area');
            
            $params = [
                'dir' => $dir,
                'enabled' => $enabled,
                'authname' => $authname
            ];
            
            $result = call_cpanel_uapi('DirectoryPrivacy', 'configure_directory_protection', $params);
            if (isset($result['status']) && $result['status'] == 1) {
                $message = "Directory protection settings updated successfully!";
            } else {
                $error = "Failed to update: " . implode(', ', $result['errors'] ?? ['Unknown error']);
            }
        } elseif ($action === 'add_user') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                $error = "Username and password are required.";
            } else {
                // Fixed: Explicitly passed 'password' argument to satisfy UAPI structural checks
                $result = call_cpanel_uapi('DirectoryPrivacy', 'add_user', [
                    'dir'      => $dir,
                    'user'     => $username,
                    'password' => $password
                ]);
                
                if (isset($result['status']) && $result['status'] == 1) {
                    $message = "Authorized user '$username' added successfully!";
                } else {
                    $error = "Failed to add user: " . implode(', ', $result['errors'] ?? ['Verify cPanel UAPI password constraint regulations.']);
                }
            }
        } elseif ($action === 'delete_user') {
            $username = trim($_POST['username'] ?? '');
            if (empty($username)) {
                $error = "Username is required for deletion.";
            } else {
                $result = call_cpanel_uapi('DirectoryPrivacy', 'delete_user', [
                    'dir'  => $dir,
                    'user' => $username
                ]);
                if (isset($result['status']) && $result['status'] == 1) {
                    $message = "Authorized user '$username' removed successfully!";
                } else {
                    $error = "Failed to delete user: " . implode(', ', $result['errors'] ?? ['Unknown error']);
                }
            }
        } elseif ($action === 'disable_protection') {
            // Option to fully remove privacy controls from the target folder
            $result = call_cpanel_uapi('DirectoryPrivacy', 'configure_directory_protection', [
                'dir' => $dir,
                'enabled' => 0
            ]);
            if (isset($result['status']) && $result['status'] == 1) {
                $message = "Directory protection completely removed.";
            } else {
                $error = "Failed to remove protection: " . implode(', ', $result['errors'] ?? ['Unknown error']);
            }
        }
    }
}

// Fetch details for the requested directory if specified
$is_protected = false;
$users = [];

if (!empty($dir)) {
    $prot_check = call_cpanel_uapi('DirectoryPrivacy', 'is_directory_protected', ['dir' => $dir]);
    if (isset($prot_check['status']) && $prot_check['status'] == 1) {
        $is_protected = !empty($prot_check['data']);
    }

    $users_res = call_cpanel_uapi('DirectoryPrivacy', 'list_users', ['dir' => $dir]);
    if (isset($users_res['status']) && $users_res['status'] == 1) {
        $users = $users_res['data'] ?? [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>cPanel Directory Privacy Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="fa-solid fa-folder-lock me-2"></i>Directory Privacy Manager</h4>
                        <?php if ($is_protected): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-lock me-1"></i> Protected</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="fa-solid fa-lock-open me-1"></i> Public</span>
                        <?php endif; ?>
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

                        <!-- Directory Selection Form -->
                        <form method="GET" class="mb-4">
                            <label for="dir" class="form-label fw-bold">Absolute Directory Path:</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="dir" name="dir" value="<?= htmlspecialchars($dir) ?>" placeholder="/home/sairedd1/public_html" required>
                                <button class="btn btn-outline-primary" type="submit"><i class="fa-solid fa-refresh me-1"></i> Load & Sync</button>
                            </div>
                            <div class="form-text">Target: <code><?= htmlspecialchars($dir) ?></code></div>
                        </form>

                        <?php if (!empty($dir)): ?>
                            <hr>
                            <!-- Protection Configuration Form -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="text-secondary m-0"><i class="fa-solid fa-shield-halved me-1"></i> Setup Privacy Profile</h5>
                                <?php if ($is_protected): ?>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to completely turn off password restrictions for this directory?');">
                                        <input type="hidden" name="action" value="disable_protection">
                                        <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-unlock me-1"></i> Disable & Remove Protection</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <form method="POST" class="mb-4 bg-white p-3 border rounded">
                                <input type="hidden" name="action" value="configure">
                                <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= $is_protected ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="enabled">Password protect this directory</label>
                                </div>

                                <div class="mb-3">
                                    <label for="authname" class="form-label">Protected Region Label (User Alert Message)</label>
                                    <input type="text" class="form-control" id="authname" name="authname" placeholder="Protected Area" value="Members Only">
                                </div>

                                <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-save me-1"></i> Save Configuration</button>
                            </form>

                            <!-- Authorized Users Management -->
                            <h5 class="mb-3 text-secondary"><i class="fa-solid fa-users me-1"></i> Manage Directory User Access</h5>
                            
                            <div class="card mb-4">
                                <div class="card-body bg-light">
                                    <form method="POST" class="row g-3">
                                        <input type="hidden" name="action" value="add_user">
                                        <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                                        
                                        <div class="col-md-5">
                                            <label for="username" class="form-label fw-bold text-muted small">Username</label>
                                            <input type="text" class="form-control form-control-sm" id="username" name="username" placeholder="e.g. webmaster" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label for="password" class="form-label fw-bold text-muted small">Access Password</label>
                                            <input type="password" class="form-control form-control-sm" id="password" name="password" placeholder="••••••••" required>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary btn-sm w-100 py-2"><i class="fa-solid fa-user-plus me-1"></i> Add</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Users Table -->
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered align-middle m-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Authorized Username</th>
                                            <th class="text-center" style="width: 120px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($users)): ?>
                                            <tr>
                                                <td colspan="2" class="text-center text-muted py-3">No active users verified for this scope path.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($users as $u): 
                                                $current_user = is_array($u) ? ($u['user'] ?? '') : $u;
                                                if(empty($current_user)) continue;
                                            ?>
                                                <tr>
                                                    <td class="fw-bold text-dark"><?= htmlspecialchars($current_user) ?></td>
                                                    <td class="text-center">
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke user privileges for <?= htmlspecialchars($current_user) ?>?');">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                                                            <input type="hidden" name="username" value="<?= htmlspecialchars($current_user) ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm px-3" title="Remove Access"><i class="fa-solid fa-user-minus me-1"></i> Remove</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>