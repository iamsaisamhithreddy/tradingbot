<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';
require 'totp_lib.php';

if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmt = $conn->prepare("SELECT totp_enabled, totp_secret FROM admin_users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($totp_enabled, $stored_secret);
$stmt->fetch();
$stmt->close();

$message = "";
$messageType = "";

// ---- Disable 2FA ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $stmt = $conn->prepare("UPDATE admin_users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $stmt->close();
    unset($_SESSION['pending_totp_secret']);
    
    $totp_enabled = 0;
    $stored_secret = null;
    $message = "2FA has been disabled for this account.";
    $messageType = "ok";
}

// ---- Confirm & enable 2FA (finishing enrollment) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $pendingSecret = $_SESSION['pending_totp_secret'] ?? '';
    if ($pendingSecret && totp_verify($pendingSecret, $_POST['code'] ?? '', 1)) {
        $stmt = $conn->prepare("UPDATE admin_users SET totp_enabled = 1, totp_secret = ? WHERE id = ?");
        $stmt->bind_param("si", $pendingSecret, $admin_id);
        $stmt->execute();
        $stmt->close();
        unset($_SESSION['pending_totp_secret']);

        // Redirect to dashboard after successful setup
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $message = "That code didn't match. Scan the QR again and try the current code.";
        $messageType = "fail";
    }
}

// ---- Start enrollment (generate a not-yet-saved secret) ----
$enrolling = false;
$otpauth = "";
if (!$totp_enabled) {
    if (empty($_SESSION['pending_totp_secret'])) {
        $_SESSION['pending_totp_secret'] = totp_generate_secret();
    }
    $enrolling = true;
    $issuer = parse_url($WebsiteURL, PHP_URL_HOST) ?: 'AdminPanel';
    $otpauth = totp_build_otpauth($_SESSION['pending_totp_secret'], $issuer, $admin_username);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Two-Factor Authentication Setup</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { display:flex; justify-content:center; align-items:center; min-height:100vh; background:#f5f7fa; padding:20px; }
    .box { background:#fff; padding:30px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.1); width:360px; text-align:center; }
    h2 { margin-bottom:8px; color:#1e293b; }
    p.sub { color:#64748b; font-size:13px; margin-bottom:16px; }
    #qrcode { margin:16px auto; background:#fff; padding:12px; border:1px solid #eee; border-radius:8px; width:fit-content; }
    code { background:#f1f5f9; padding:4px 8px; border-radius:4px; display:inline-block; margin-top:8px; word-break:break-all; font-size:12px; }
    input[type="text"] {
        width:100%; padding:12px; margin:8px 0; border:1px solid #ccc; border-radius:8px;
        text-align:center; font-size:20px; letter-spacing:6px;
    }
    input[type="submit"], button {
        width:100%; padding:12px; margin-top:10px; border:none; border-radius:8px;
        font-weight:600; cursor:pointer; color:#fff;
    }
    input[type="submit"] { background:#1d4ed8; }
    input[type="submit"]:hover { background:#2563eb; }
    .danger { background:#b91c1c; }
    .danger:hover { background:#dc2626; }
    .msg { margin-top:12px; font-size:14px; }
    .ok { color:#16a34a; }
    .fail { color:#dc2626; }
    .enabled-badge { display:inline-block; background:#dcfce7; color:#166534; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; margin-bottom:12px; }
</style>
</head>
<body>
<div class="box">
    <h2>🔐 Two-Factor Authentication</h2>

    <?php if ($message): ?>
        <p class="msg <?= $messageType ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($totp_enabled): ?>
        <p class="enabled-badge">2FA is ON</p>
        <p class="sub">This account requires a 6-digit code at every login.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="disable">
            <button type="submit" class="danger" onclick="return confirm('Disable 2FA for this account?');">Disable 2FA</button>
        </form>
    <?php else: ?>
        <p class="sub">Scan this with Google Authenticator, then enter the current code to confirm.</p>
        <div id="qrcode"></div>
        <code><?= htmlspecialchars($_SESSION['pending_totp_secret'], ENT_QUOTES, 'UTF-8') ?></code>

        <form method="post" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="confirm">
            <input type="text" name="code" maxlength="6" inputmode="numeric" placeholder="000000" required>
            <input type="submit" value="Confirm & Enable">
        </form>
    <?php endif; ?>
</div>

<?php if ($enrolling): ?>
<script>
new QRCode(document.getElementById('qrcode'), {
    text: <?= json_encode($otpauth) ?>,
    width: 200,
    height: 200
});
</script>
<?php endif; ?>
</body>
</html>
