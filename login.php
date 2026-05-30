<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

$error = "";

if (isset($_GET['id']) && is_numeric($_GET['id'])) {

    $tg_id = (int) $_GET['id'];

    $stmt = $conn->prepare("
        SELECT id, username, permissions
        FROM admin_users
        WHERE telegram_chat_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $tg_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {

        $stmt->bind_result($id, $db_username, $permissions);
        $stmt->fetch();

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_username'] = $db_username;
        $_SESSION['admin_permissions'] = json_decode($permissions, true) ?? [];

        header("Location: admin_dashboard.php");
        exit;

    } else {
        $error = "❌ Telegram account not linked to an admin.";
    }

    $stmt->close();
}

// NORMAL USERNAME + PASSWORD LOGIN

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $id;
            $_SESSION['admin_username'] = $db_username;
            $_SESSION['admin_permissions'] = json_decode($permissions, true) ?? [];

            header("Location: admin_dashboard.php");
            exit;

        } else {
            $error = "❌ Invalid password.";
        }

    } else {
        $error = "❌ Username not found.";
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

    <!-- Telegram Login Widget -->
    <div style="margin-bottom:15px;">
        <script async src="https://telegram.org/js/telegram-widget.js?22"
                data-telegram-login="sasmhithstradingbot"
                data-size="large"
                data-userpic="true"
                data-request-access="write"
                data-auth-url="https://saireddy.site/login.php">
        </script>
    </div>

    <div style="margin:10px 0;color:#555;font-size:13px;">
        or login with username & password
    </div>

    <form method="post">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="submit" value="Login">
    </form>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
</div>
</body>
</html>
