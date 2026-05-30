<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php';
session_start();

// Admin check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    exit("Access denied.");
}

// ------------------ DELETE EXPIRED TOKENS SAFELY IN PHP ------------------
$result = $conn->query("SELECT id, expires_at FROM access_tokens WHERE expires_at IS NOT NULL");
while ($row = $result->fetch_assoc()) {
    if (strtotime($row['expires_at']) <= time()) {
        $stmt = $conn->prepare("DELETE FROM access_tokens WHERE id=?");
        $stmt->bind_param("i", $row['id']);
        $stmt->execute();
        $stmt->close();
    }
}

// ------------------ DELETE TOKEN MANUALLY ------------------
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM access_tokens WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: generate_token.php"); // redirect after deletion
    exit;
}

$message = '';

// ------------------ GENERATE TOKEN ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_type = $_POST['token_type'] ?? 'temporary'; // 'temporary' or 'cron'
    $valid_minutes = intval($_POST['valid_minutes'] ?? 60); // default 60 min

    $token = bin2hex(random_bytes(16));

    if ($token_type === 'cron') {
        $expires_at = NULL; // never expires
    } else {
        $expires_at = date('Y-m-d H:i:s', strtotime("+$valid_minutes minutes"));
    }

    $stmt = $conn->prepare("INSERT INTO access_tokens (token, expires_at) VALUES (?, ?)");
    $stmt->bind_param("ss", $token, $expires_at);
    $stmt->execute();
    $stmt->close();

    $message = "Token Generated: https://saireddy.site/valid_pairs.php?token=<b>$token</b><br>";
    $message .= $expires_at ? "Expires at: $expires_at" : "Permanent token (for cron jobs)";
}

// ------------------ FETCH EXISTING TOKENS ------------------
$result = $conn->query("SELECT * FROM access_tokens ORDER BY created_at DESC");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Token Generator</title>
    <style>
        body { font-family: Arial; margin: 30px; }
        label { display: inline-block; width: 150px; }
        input, select { margin-bottom: 10px; padding: 5px; }
        button { padding: 5px 10px; }
        .message { margin-top: 20px; font-weight: bold; }
        table { border-collapse: collapse; width: 95%; margin-top: 20px; }
        th, td { border: 1px solid #444; padding: 8px 12px; text-align: center; }
        th { background-color: #222; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        a.delete { color: red; text-decoration: none; }
    </style>
</head>
<body>

<h2>Generate Access Token</h2>

<p><b>Current Time:</b> <span id="current-time"><?= date('Y-m-d H:i:s') ?></span></p>

<form method="POST">
    <label>Token Type:</label>
    <select name="token_type">
        <option value="temporary">Temporary</option>
        <option value="cron">Cron (Never Expire)</option>
    </select><br>

    <label>Validity (minutes):</label>
    <input type="number" name="valid_minutes" value="60"><br>

    <button type="submit">Generate Token</button>
</form>

<?php if ($message): ?>
    <div class="message"><?= $message ?></div>
<?php endif; ?>

<h3>Existing Tokens</h3>
<table>
    <tr>
        <th>ID</th>
        <th>Token</th>
        <th>Expires At</th>
        <th>Remaining Time</th>
        <th>Created At</th>
        <th>Action</th>
    </tr>
    <?php while ($row = $result->fetch_assoc()):
        $expires_at_js = $row['expires_at'] ? strtotime($row['expires_at']) * 1000 : 'null';
    ?>
    <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['token']) ?></td>
        <td><?= $row['expires_at'] ?? 'Permanent' ?></td>
        <td>
            <?php if ($row['expires_at']): ?>
                <span class="countdown" data-expire="<?= $expires_at_js ?>">Calculating...</span>
            <?php else: ?>
                Permanent
            <?php endif; ?>
        </td>
        <td><?= $row['created_at'] ?></td>
        <td><a class="delete" href="generate_token.php?delete_id=<?= $row['id'] ?>" onclick="return confirm('Delete this token?');">Delete</a></td>
    </tr>
    <?php endwhile; ?>
</table>

<script>
// Update current time every second
setInterval(() => {
    const now = new Date();
    document.getElementById('current-time').textContent = now.toISOString().replace('T', ' ').split('.')[0];
}, 1000);

// Update countdowns every second
function updateCountdowns() {
    document.querySelectorAll('.countdown').forEach(el => {
        const expireTime = parseInt(el.dataset.expire);
        const now = new Date().getTime();
        const diff = expireTime - now;
        if (diff > 0) {
            const hours = Math.floor(diff / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            el.textContent = `${hours}h ${minutes}m ${seconds}s`;
        } else {
            el.textContent = 'Expired';
        }
    });
}
setInterval(updateCountdowns, 1000);
updateCountdowns();
</script>

</body>
</html>
