<?php
// Detect if the script is being run by a Cron Job (CLI)
$isCron = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

// Only start sessions and output buffering if accessed via a web browser
if (!$isCron) {
    ob_start();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

include 'db.php'; // database connection


// OPTIMIZATION: Prevent the 500 Internal Server Error
set_time_limit(0); 

$isAdmin = $_SESSION['admin_logged_in'] ?? false;
$messageStatus = "";

// --- Delete SCHEDULED (Pending) broadcast ---
if ($isAdmin && isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $getFile = $conn->query("SELECT file_path FROM scheduled_broadcasts WHERE id = $deleteId AND sent = 0");

    if ($getFile && $getFile->num_rows > 0) {
        $fileRow = $getFile->fetch_assoc();
        if (!empty($fileRow['file_path'])) {
            $absolutePath = __DIR__ . "/" . $fileRow['file_path'];
            if (file_exists($absolutePath)) { unlink($absolutePath); }
        }
        $conn->query("DELETE FROM scheduled_broadcasts WHERE id = $deleteId AND sent = 0");
        $messageStatus .= "<p class='success'>🗑️ Scheduled broadcast deleted successfully.</p>";
    }
}

// --- RECALL (Delete) SENT broadcast from Telegram ---
if ($isAdmin && isset($_GET['recall_id'])) {
    $recallId = intval($_GET['recall_id']);

    // Fetch all message IDs tied to this broadcast
    $q = $conn->query("SELECT chat_id, message_id FROM telegram_broadcasts WHERE broadcast_id = $recallId");

    if ($q && $q->num_rows > 0) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $recallCount = 0;

        while ($row = $q->fetch_assoc()) {
            $data = [
                'chat_id' => $row['chat_id'],
                'message_id' => $row['message_id']
            ];

            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$botToken/deleteMessage");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode == 200) {
                $recallCount++;
            }
        }
        curl_close($ch);

        // Delete the tracking records from DB so it stays clean
        $conn->query("DELETE FROM telegram_broadcasts WHERE broadcast_id = $recallId");
        
        // Delete the main broadcast record
        $conn->query("DELETE FROM scheduled_broadcasts WHERE id = $recallId");

        $messageStatus .= "<p class='success'>🔥 Successfully recalled $recallCount messages from users' inboxes!</p>";
    } else {
        $messageStatus .= "<p class='error'>⚠️ No tracking data found for this broadcast. It may have already been recalled or is too old.</p>";
    }
}

// --- Handle broadcast creation ---
if ($isAdmin && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $message = trim($_POST['message']);
    $sendType = $_POST['send_type'] ?? "all";
    $selectedUsers = isset($_POST['users']) ? $_POST['users'] : [];
    $scheduleType = $_POST['schedule_type'] ?? "now";
    $scheduledTime = null;
    $filePath = null;

    if ($scheduleType === "later" && !empty($_POST['scheduled_time'])) {
        $scheduledTime = $_POST['scheduled_time']; 
    } else {
        $nowIST = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
        $scheduledTime = $nowIST->format("Y-m-d H:i:s");
    }

    // File upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . "/uploads/";
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $fileName = time() . "_" . basename($_FILES['attachment']['name']);
        $filePath = "uploads/" . $fileName;
        move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fileName);
    }

    if (empty($message) && !$filePath) {
        $messageStatus .= "<p class='error'>⚠️ Message or file is required.</p>";
    } else {
        $stmt = $conn->prepare("INSERT INTO scheduled_broadcasts (message, send_type, user_ids, scheduled_time, sent, file_path) VALUES (?, ?, ?, ?, 0, ?)");
        $userIds = ($sendType === "selected") ? implode(",", array_map('intval', $selectedUsers)) : "";
        $stmt->bind_param("sssss", $message, $sendType, $userIds, $scheduledTime, $filePath);
        $stmt->execute();
        $stmt->close();

        $messageStatus .= "<p class='success'>✅ Broadcast scheduled successfully.</p>";
    }
}

// --- Process due broadcasts ---
$nowIST = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
$currentIST = $nowIST->format("Y-m-d H:i:s");

$result = $conn->query("SELECT * FROM scheduled_broadcasts WHERE sent = 0 AND scheduled_time <= '$currentIST'");
$sentCount = 0;

$ch = curl_init();
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

while ($row = $result->fetch_assoc()) {
    $messageText = $row['message'];
    $sendType = $row['send_type'];
    $userIds = $row['user_ids'];
    $broadcastId = $row['id'];
    $filePath = $row['file_path'];

    if ($sendType === "all") {
        $usersRes = $conn->query("SELECT chat_id FROM telegram_users");
    } else {
        $safeIds = implode(',', array_map('intval', explode(',', $userIds)));
        $usersRes = $conn->query("SELECT chat_id FROM telegram_users WHERE id IN ($safeIds)");
    }

    if ($usersRes) {
        $trackStmt = $conn->prepare("INSERT INTO telegram_broadcasts (chat_id, message_id, broadcast_id) VALUES (?, ?, ?)");

        while ($u = $usersRes->fetch_assoc()) {
            $chatId = $u['chat_id'];

            if ($filePath) {
                $url = "https://api.telegram.org/bot$botToken/sendDocument";
                $absolutePath = __DIR__ . "/" . $filePath;
                $data = [
                    'chat_id' => $chatId,
                    'document' => new CURLFile(realpath($absolutePath)),
                ];
                if (!empty($messageText)) { $data['caption'] = $messageText; }
            } else {
                $url = "https://api.telegram.org/bot$botToken/sendMessage";
                $data = [
                    'chat_id' => $chatId,
                    'text'    => $messageText,
                ];
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode == 200 && $response !== false) {
                $sentCount++;

                $responseData = json_decode($response, true);
                if (isset($responseData['ok']) && $responseData['ok'] === true) {
                    $messageId = $responseData['result']['message_id'];
                    $trackStmt->bind_param("sii", $chatId, $messageId, $broadcastId);
                    $trackStmt->execute();
                }
            }
        }
        if (isset($trackStmt)) { $trackStmt->close(); }
    }

    if ($filePath) {
        $absolutePath = __DIR__ . "/" . $filePath;
        if (file_exists($absolutePath)) { unlink($absolutePath); }
        $conn->query("UPDATE scheduled_broadcasts SET file_path=NULL WHERE id=$broadcastId");
    }

    $conn->query("UPDATE scheduled_broadcasts SET sent = 1 WHERE id = $broadcastId");
}

curl_close($ch);

if ($sentCount > 0) {
    $messageStatus .= "<p class='success'>📤 $sentCount messages sent from pending broadcasts.</p>";
}

// Fetch tables data
$pendingBroadcasts = $conn->query("SELECT * FROM scheduled_broadcasts WHERE sent = 0 ORDER BY scheduled_time ASC");
$sentBroadcasts = $conn->query("SELECT * FROM scheduled_broadcasts WHERE sent = 1 ORDER BY scheduled_time DESC LIMIT 30");
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
h2{text-align:center;margin-top:30px;margin-bottom:20px;color:#2d3e50;}
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
table {width:100%;border-collapse:collapse;margin-top:15px; font-size: 0.95rem;}
th, td {padding:12px;border:1px solid #ddd;text-align:left;}
th {background:#f0f0f0;}
.status-pending {color:orange;font-weight:bold;}
.status-sent {color:green;font-weight:bold;}
.delete-btn {background:#e74c3c;color:white;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:0.9rem;}
.delete-btn:hover {background:#c0392b;}
.recall-btn {background:#e67e22;color:white;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:0.9rem;}
.recall-btn:hover {background:#d35400;}
</style>
</head>
<body>
<div class="container">
    <h2>📣 Broadcast Message</h2>

    <?php echo $messageStatus; ?>

    <?php if(!$isAdmin): ?>
        <p style="color:red;font-weight:bold;">⚠️ You do not have permission to send broadcasts.</p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <textarea name="message" rows="4" placeholder="Enter your broadcast message..." <?php echo !$isAdmin ? 'disabled' : ''; ?>><?php echo isset($message) ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : ''; ?></textarea>

        <div style="margin-top:15px;">
            <label>📎 Attach File:</label>
            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.txt,.zip" <?php echo !$isAdmin ? 'disabled' : ''; ?>>
        </div>

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
            </div>
        </div>

        <button type="submit" <?php echo !$isAdmin ? 'disabled' : ''; ?>> Send / Schedule Broadcast</button>
    </form>

    <hr style="margin-top: 40px; border: 0; border-top: 1px solid #ddd;">

    <h2>⏳ Pending Broadcasts</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Message</th>
            <th>Scheduled Time</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php while ($b = $pendingBroadcasts->fetch_assoc()): ?>
            <tr>
                <td><?php echo $b['id']; ?></td>
                <td><?php echo htmlspecialchars(substr($b['message'], 0, 50)) . '...'; ?></td>
                <td><?php echo $b['scheduled_time']; ?></td>
                <td class="status-pending">Pending</td>
                <td>
                    <?php if($isAdmin): ?>
                        <a href="?delete_id=<?php echo $b['id']; ?>" class="delete-btn" onclick="return confirm('Cancel this scheduled broadcast?')">Cancel</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>

    <hr style="margin-top: 40px; border: 0; border-top: 1px solid #ddd;">

    <h2>✅ Sent Broadcasts (Last 30)</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Message</th>
            <th>Sent Time</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php while ($s = $sentBroadcasts->fetch_assoc()): ?>
            <tr>
                <td><?php echo $s['id']; ?></td>
                <td><?php echo htmlspecialchars(substr($s['message'], 0, 50)) . '...'; ?></td>
                <td><?php echo $s['scheduled_time']; ?></td>
                <td class="status-sent">Sent</td>
                <td>
                    <?php if($isAdmin): ?>
                        <a href="?recall_id=<?php echo $s['id']; ?>" class="recall-btn" onclick="return confirm('WARNING: This will delete the message from all users\' Telegram chats (if sent within 48 hrs). Proceed?')">Recall / Delete</a>
                    <?php endif; ?>
                </td>
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