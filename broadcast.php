<?php

/// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

include 'db.php';
session_start();

// Check if user is admin
$isAdmin = $_SESSION['admin_logged_in'] ?? false;

$messageStatus = "";

// --- Handle new broadcast submission (only if admin) ---
if ($isAdmin && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $message = trim($_POST['message']);
    $sendType = $_POST['send_type'] ?? "all";
    $selectedUsers = isset($_POST['users']) ? $_POST['users'] : [];
    $scheduleType = $_POST['schedule_type'] ?? "now";
    $scheduledTime = null;

    if ($scheduleType === "later" && !empty($_POST['scheduled_time'])) {
        $scheduledTime = $_POST['scheduled_time']; // IST
    } else {
        $nowIST = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
        $scheduledTime = $nowIST->format("Y-m-d H:i:s");
    }

    if (empty($message)) {
        $messageStatus = "<p class='error'>⚠️ Message cannot be empty.</p>";
    } else {
        $stmt = $conn->prepare("INSERT INTO scheduled_broadcasts (message, send_type, user_ids, scheduled_time, sent) VALUES (?, ?, ?, ?, 0)");
        $userIds = ($sendType === "selected") ? implode(",", array_map('intval', $selectedUsers)) : "";
        $stmt->bind_param("ssss", $message, $sendType, $userIds, $scheduledTime);
        $stmt->execute();
        $stmt->close();

        $messageStatus = "<p class='success'>✅ Broadcast scheduled successfully.</p>";
    }
}

// --- Process due broadcasts whenever page is loaded ---
$nowIST = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
$currentIST = $nowIST->format("Y-m-d H:i:s");

$result = $conn->query("SELECT * FROM scheduled_broadcasts WHERE sent = 0 AND scheduled_time <= '$currentIST'");
$sentCount = 0;

while ($row = $result->fetch_assoc()) {
    $messageText = $row['message'];
    $sendType = $row['send_type'];
    $userIds = $row['user_ids'];
    $broadcastId = $row['id'];

    if ($sendType === "all") {
        $usersRes = $conn->query("SELECT chat_id FROM telegram_users");
    } else {
        $usersRes = $conn->query("SELECT chat_id FROM telegram_users WHERE id IN ($userIds)");
    }

    while ($u = $usersRes->fetch_assoc()) {
        $chatId = $u['chat_id'];
        $url = "https://api.telegram.org/bot$botToken/sendMessage";
        $data = ['chat_id' => $chatId, 'text' => $messageText];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && $response !== false) {
            $sentCount++;
        }
    }

    $conn->query("UPDATE scheduled_broadcasts SET sent = 1 WHERE id = $broadcastId");
}

if ($sentCount > 0) {
    $messageStatus .= "<p class='success'>📤 $sentCount messages sent from pending broadcasts.</p>";
}

// --- Fetch all pending broadcasts ---
$pendingBroadcasts = $conn->query("SELECT * FROM scheduled_broadcasts WHERE sent = 0 ORDER BY scheduled_time ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Broadcast Message</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7f9;margin:0;padding:20px;}
.container {max-width:950px;margin:auto;background:#fff;padding:30px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);}
h2{text-align:center;margin-bottom:20px;color:#2d3e50;}
textarea {width:100%;padding:15px;border-radius:8px;border:1px solid #ccc;resize:vertical;font-size:1rem;}
button {background:#2d89ef;color:white;padding:12px 25px;border:none;border-radius:8px;font-size:1rem;cursor:pointer;transition:background 0.3s;margin-top:15px;}
button:hover {background:#1b5fbd;}
.success {color:green;font-weight:bold;margin-bottom:10px;}
.error {color:red;font-weight:bold;margin-bottom:10px;}
.users-box {margin-top:20px;padding:15px;background:#f9fbfd;border:1px solid #ddd;border-radius:8px;max-height:300px;overflow-y:auto;display:none;}
.users-box label {display:block;padding:6px 0;font-size:0.95rem;}
.top-bar {margin-top:15px;margin-bottom:10px;}
.select-all {font-size:0.9rem;color:#2d3e50;}
#searchUser {width:100%;padding:8px;margin-bottom:10px;border-radius:6px;border:1px solid #bbb;font-size:0.95rem;}
table {width:100%;border-collapse:collapse;margin-top:30px;}
th, td {padding:12px;border:1px solid #ddd;text-align:left;}
th {background:#f0f0f0;}
.status-pending {color:orange;font-weight:bold;}
.status-sent {color:green;font-weight:bold;}
</style>
</head>
<body>
<div class="container">
    <h2>📢 Broadcast Message</h2>

    <?php echo $messageStatus; ?>

    <?php if(!$isAdmin): ?>
        <p style="color:red;font-weight:bold;">⚠️ You do not have permission to send broadcasts.</p>
    <?php endif; ?>

    <form method="POST">
        <textarea name="message" rows="4" placeholder="Enter your broadcast message..." <?php echo !$isAdmin ? 'disabled' : ''; ?>><?php echo isset($message) ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : ''; ?></textarea>

        <div class="top-bar">
            <label><input type="radio" name="send_type" value="all" checked onchange="toggleUsers(false)" <?php echo !$isAdmin ? 'disabled' : ''; ?>> Send to All Users</label>
            &nbsp;&nbsp;
            <label><input type="radio" name="send_type" value="selected" onchange="toggleUsers(true)" <?php echo !$isAdmin ? 'disabled' : ''; ?>> Select Specific Users</label>
        </div>

        <div class="users-box" id="usersBox">
            <input type="text" id="searchUser" placeholder="🔍 Search by name or chat_id..." <?php echo !$isAdmin ? 'disabled' : ''; ?>>
            <label class="select-all"><input type="checkbox" id="selectAll" <?php echo !$isAdmin ? 'disabled' : ''; ?>> Select All</label><hr>
            <div id="userList">
                <?php
                $users = $conn->query("SELECT id, chat_id, description FROM telegram_users ORDER BY created_at DESC");
                while ($u = $users->fetch_assoc()) {
                    echo '<label><input type="checkbox" name="users[]" value="'.intval($u['id']).'" '.(!$isAdmin?'disabled':'').' > '
                        . htmlspecialchars($u['description'], ENT_QUOTES, 'UTF-8')
                        . ' ('.htmlspecialchars($u['chat_id'], ENT_QUOTES, 'UTF-8').')</label>';
                }
                ?>
            </div>
        </div>

        <div style="margin-top:15px;">
            <label><input type="radio" name="schedule_type" value="now" checked onclick="toggleSchedule(false)" <?php echo !$isAdmin ? 'disabled' : ''; ?>> Send Now</label>
            &nbsp;&nbsp;
            <label><input type="radio" name="schedule_type" value="later" onclick="toggleSchedule(true)" <?php echo !$isAdmin ? 'disabled' : ''; ?>> Schedule for Later</label>
            <div id="scheduleBox" style="display:none;margin-top:10px;">
                <input type="datetime-local" name="scheduled_time" <?php echo !$isAdmin ? 'disabled' : ''; ?>>
                <small>⏰ Enter time in IST</small>
            </div>
        </div>

        <button type="submit" <?php echo !$isAdmin ? 'disabled' : ''; ?>>🚀 Send / Schedule Broadcast</button>
    </form>

    <h2>⏳ Pending / Scheduled Broadcasts</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Message</th>
            <th>Scheduled Time (IST)</th>
            <th>Send Type</th>
            <th>Status</th>
        </tr>
        <?php while ($b = $pendingBroadcasts->fetch_assoc()): ?>
            <tr>
                <td><?php echo $b['id']; ?></td>
                <td><?php echo htmlspecialchars($b['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $b['scheduled_time']; ?></td>
                <td><?php echo ucfirst($b['send_type']); ?></td>
                <td class="status-pending">Pending</td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>

<script>
function toggleUsers(show) { document.getElementById('usersBox').style.display = show ? 'block' : 'none'; }
function toggleSchedule(show) { document.getElementById('scheduleBox').style.display = show ? 'block' : 'none'; }
document.getElementById('selectAll').addEventListener('change', function() {
    let checkboxes = document.querySelectorAll('input[name="users[]"]');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
document.getElementById('searchUser').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let labels = document.querySelectorAll('#userList label');
    labels.forEach(label => {
        let text = label.innerText.toLowerCase();
        label.style.display = text.includes(filter) ? "" : "none";
    });
});
</script>
</body>
</html>
