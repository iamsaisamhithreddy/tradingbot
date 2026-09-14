<?php
// Note: Disable error reporting in a production environment
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php'; // This brings in your $conn and $botToken variables
require 'totp_lib.php';

// Finalizes login OR, if 2FA is enabled for this account, parks the session
// in a "pending" state and redirects to the challenge page instead.
function complete_or_challenge_2fa($conn, $id, $db_username, $permissions) {
    $stmt = $conn->prepare("SELECT totp_enabled FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($totp_enabled);
    $stmt->fetch();
    $stmt->close();

    session_regenerate_id(true);
    unset($_SESSION['login_attempts']);
    unset($_SESSION['last_login_attempt']);

    if ($totp_enabled) {
        // 2FA is enabled -> redirect to 2FA verification step
        $_SESSION['pending_2fa_id'] = $id;
        $_SESSION['pending_2fa_username'] = $db_username;
        $_SESSION['pending_2fa_permissions'] = $permissions;
        $_SESSION['pending_2fa_time'] = time();
        header("Location: verify_2fa.php");
        exit;
    } else {
        // 2FA is NOT enabled -> log them in and direct them straight to setup
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_username'] = $db_username;
        $_SESSION['admin_permissions'] = json_decode($permissions, true) ?? [];
        
        header("Location: 2fa.php");
        exit;
    }
}

$error = "";
$max_attempts = 5;
$lockout_time = 300; // 5 minutes in seconds

// ==========================================
// 1. RATE LIMITING (Session-based)
// ==========================================
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= $max_attempts) {
    $time_since_last_attempt = time() - $_SESSION['last_login_attempt'];
    if ($time_since_last_attempt < $lockout_time) {
        $remaining = ceil(($lockout_time - $time_since_last_attempt) / 60);
        die("Too many failed attempts. Please try again in {$remaining} minutes.");
    } else {
        // Reset attempts after lockout period passes
        $_SESSION['login_attempts'] = 0;
    }
}

function record_failed_login() {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['last_login_attempt'] = time();
}

// ==========================================
// 2. CSRF TOKEN GENERATION
// ==========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// 3. TELEGRAM OAUTH LOGIN (Secured)
// ==========================================
if (isset($_GET['hash']) && isset($_GET['id'])) {
    
    $auth_data = $_GET;
    $check_hash = $auth_data['hash'];
    unset($auth_data['hash']);
    
    // Sort array keys alphabetically
    ksort($auth_data);
    
    $data_check_arr = [];
    foreach ($auth_data as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
    }
    $data_check_string = implode("\n", $data_check_arr);
    
    // Calculate the hash using the $botToken from db.php
    $secret_key = hash('sha256', $botToken, true);
    $calculated_hash = hash_hmac('sha256', $data_check_string, $secret_key);
    
    // Verify hash and check if the payload is fresh (within 24 hours)
    if (hash_equals($calculated_hash, $check_hash) && (time() - $auth_data['auth_date']) < 86400) {
        
        // IMPORTANT: Cast ID to a string to prevent 32-bit integer overflow issues
        $tg_id = (string) $auth_data['id'];

        $stmt = $conn->prepare("
            SELECT id, username, permissions
            FROM admin_users
            WHERE telegram_chat_id = ?
            LIMIT 1
        ");
        
        // Bind as a string ("s")
        $stmt->bind_param("s", $tg_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $db_username, $permissions);
            $stmt->fetch();
            $stmt->close();

            complete_or_challenge_2fa($conn, $id, $db_username, $permissions);
        } else {
            // Uniform error for missing account
            $error = "❌ Invalid credentials.";
            record_failed_login();
            $stmt->close();
        }
        
    } else {
        // Uniform error for invalid/forged signature
        $error = "❌ Invalid credentials.";
        record_failed_login();
    }
}

// ==========================================
// 4. NORMAL USERNAME + PASSWORD LOGIN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("
        SELECT id, username, password_hash, permissions
        FROM admin_users
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($id, $db_username, $password_hash, $permissions);
        $stmt->fetch();

        if (password_verify($password, $password_hash)) {
            $stmt->close();
            complete_or_challenge_2fa($conn, $id, $db_username, $permissions);
        } else {
            // Uniform error for bad password
            $error = "❌ Invalid credentials.";
            record_failed_login();
        }
    } else {
        // Uniform error for missing username
        // Prevent username enumeration attacks
        $error = "❌ Invalid credentials."; 
        record_failed_login();
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Login</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background: #f5f7fa;
    }
    .login-box {
        background: #fff;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        width: 320px;
        text-align: center;
    }
    h2 { margin-bottom: 20px; color: #1e293b; }
    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 12px;
        margin: 8px 0;
        border: 1px solid #ccc;
        border-radius: 8px;
    }
    input[type="submit"] {
        width: 100%;
        padding: 12px;
        margin-top: 15px;
        background: #1d4ed8;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
    }
    input[type="submit"]:hover { background: #2563eb; }
    .error {
        color: red;
        margin-top: 10px;
        font-size: 14px;
    }
</style>
</head>

<body>
<div class="login-box">
    <h2>🔐 Admin Login</h2>

<div style="margin-bottom:15px;">
    <script async src="https://telegram.org/js/telegram-widget.js?22"
            data-telegram-login="sasmhithstradingbot"
            data-size="large"
            data-userpic="true"
            data-request-access="write"
            data-auth-url="<?php echo htmlspecialchars($WebsiteURL, ENT_QUOTES, 'UTF-8'); ?>/login.php">
    </script>
</div>

    <div style="margin:10px 0;color:#555;font-size:13px;">
        or login with username & password
    </div>

    <form method="post">
        <!-- Hidden CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="submit" value="Login">
    </form>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</div>
</body>
</html>
