<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';
require 'totp_lib.php';

$error = "";
$max_attempts = 5;
$lockout_time = 300;      // 5 minutes
$pending_ttl = 300;       // pending 2FA state expires after 5 minutes

// Must have passed step 1 (password or Telegram) first.
if (empty($_SESSION['pending_2fa_id'])) {
    header("Location: login.php");
    exit;
}

// Expire stale pending logins (e.g. user got the QR page open and walked away).
if (time() - ($_SESSION['pending_2fa_time'] ?? 0) > $pending_ttl) {
    unset($_SESSION['pending_2fa_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_permissions'], $_SESSION['pending_2fa_time']);
    header("Location: login.php");
    exit;
}

// Rate limiting specific to the 2FA step (separate from the password step).
if (isset($_SESSION['tfa_attempts']) && $_SESSION['tfa_attempts'] >= $max_attempts) {
    $elapsed = time() - $_SESSION['tfa_last_attempt'];
    if ($elapsed < $lockout_time) {
        $remaining = ceil(($lockout_time - $elapsed) / 60);
        die("Too many failed 2FA attempts. Please try again in {$remaining} minutes.");
    } else {
        $_SESSION['tfa_attempts'] = 0;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    $id = $_SESSION['pending_2fa_id'];

    $stmt = $conn->prepare("SELECT totp_secret FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($secret);
    $stmt->fetch();
    $stmt->close();

    if ($secret && totp_verify($secret, $_POST['code'] ?? '', 1)) {
        // 2FA passed — now actually finish logging in.
        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_username'] = $_SESSION['pending_2fa_username'];
        $_SESSION['admin_permissions'] = json_decode($_SESSION['pending_2fa_permissions'], true) ?? [];

        unset($_SESSION['pending_2fa_id'], $_SESSION['pending_2fa_username'], $_SESSION['pending_2fa_permissions'], $_SESSION['pending_2fa_time']);
        unset($_SESSION['tfa_attempts'], $_SESSION['tfa_last_attempt']);

        header("Location: admin_dashboard.php");
        exit;
    } else {
        $_SESSION['tfa_attempts'] = ($_SESSION['tfa_attempts'] ?? 0) + 1;
        $_SESSION['tfa_last_attempt'] = time();
        $error = "❌ Invalid code.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Two-Factor Verification</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { display:flex; justify-content:center; align-items:center; height:100vh; background:#f5f7fa; }
    .box { background:#fff; padding:30px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.1); width:320px; text-align:center; }
    h2 { margin-bottom:8px; color:#1e293b; }
    p.sub { color:#64748b; font-size:13px; margin-bottom:20px; }
    input[type="text"] {
        width:100%; padding:12px; margin:8px 0; border:1px solid #ccc; border-radius:8px;
        text-align:center; font-size:20px; letter-spacing:6px;
    }
    input[type="submit"] {
        width:100%; padding:12px; margin-top:15px; background:#1d4ed8; color:#fff;
        border:none; border-radius:8px; font-weight:600; cursor:pointer;
    }
    input[type="submit"]:hover { background:#2563eb; }
    .error { color:red; margin-top:10px; font-size:14px; }
</style>
</head>
<body>
<div class="box">
    <h2>🔐 Two-Factor Code</h2>
    <p class="sub">Enter the 6-digit code from your authenticator app</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" autofocus required>
        <input type="submit" value="Verify">
    </form>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</div>
</body>
</html>
