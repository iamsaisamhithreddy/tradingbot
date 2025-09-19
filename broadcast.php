<?php
// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

include 'db.php'; // database connection

$messageStatus = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $message = trim($_POST['message']);
    $sendType = $_POST['send_type'] ?? "all";
    $selectedUsers = $_POST['users'] ?? [];
    $sendNow = $_POST['send_now'] ?? "1";

    if (empty($message)) {
        $messageStatus = "<p class='error'>⚠️ Message cannot be empty.</p>";
    } else {
        if ($sendNow === "1") {
            // Immediate sending
            if ($sendType === "all") {
                $result = $conn->query("SELECT chat_id FROM telegram_users");
            } else {
                if (!empty($selectedUsers)) {
                    $ids = implode(",", array_map('intval', $selectedUsers));
                    $result = $conn->query("SELECT chat_id FROM telegram_users WHERE id IN ($ids)");
                } else {
                    $result = false;
                }
            }

            $sentCount = 0;
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $chatId = $row['chat_id'];

                    $url = "https://api.telegram.org/bot$botToken/sendMessage";
                    $data = ['chat_id' => $chatId, 'text' => $message];

                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode == 200 && $response !== false) $sentCount++;
                }
            }

            $messageStatus = "<p class='success'>✅ Broadcast sent to $sentCount users.</p>";

        } else {
            // Scheduled sending
            $scheduleTimeIST = $_POST['schedule_time'] ?? "";
            if (empty($scheduleTimeIST)) {
                $messageStatus = "<p class='error'>⚠️ Please select a schedule time.</p>";
            } else {
                // Convert IST to UTC
                $dtIST = new DateTime($scheduleTimeIST, new DateTimeZone("Asia/Kolkata"));
                $dtIST->setTimezone(new DateTimeZone("UTC"));
                $scheduleUTC = $dtIST->format("Y-m-d H:i:s");

                $userIdsStr = "";
                if ($sendType === "selected" && !empty($selectedUsers)) {
                    $userIdsStr = implode(",", array_map('intval', $selectedUsers));
                }

                $stmt = $conn->prepare("INSERT INTO scheduled_broadcasts (message, send_type, user_ids, scheduled_time) VALUES (?,?,?,?)");
                $stmt->bind_param("ssss", $message, $sendType, $userIdsStr, $scheduleUTC);
                $stmt->execute();

                $messageStatus = "<p class='success'>📅 Broadcast scheduled for $scheduleTimeIST (IST).</p>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Broadcast Message</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7f9;margin:0;padding:20px;}
.container {max-width:900px;margin:auto;background:#fff;padding:30px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);}
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
#scheduleBox {margin-top:15px;}
</style>
</head>
<body>
<div class="container">
    <h2>📢 Broadcast Message</h2>

    <?php echo $messageStatus; ?>

    <form method="POST">
        <textarea name="message" rows="4" placeholder="Enter your broadcast message..."><?php echo isset($message) ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : ''; ?></textarea>

        <div class="top-bar">
            <label>
                <input type="radio" name="send_now" value="1" checked onchange="toggleSchedule(false)">
                Send Now
            </label>
            &nbsp;&nbsp;
            <label>
                <input type="radio" name="send_now" value="0" onchange="toggleSchedule(true)">
                Schedule for Later
            </label>
        </div>

        <div id="scheduleBox" style="display:none;">
            <label>📅 Schedule Time (IST):</label><br>
            <input type="datetime-local" name="schedule_time" min="<?php echo date('Y-m-d\TH:i'); ?>">
        </div>

        <div class="top-bar">
            <label>
                <input type="radio" name="send_type" value="all" checked onchange="toggleUsers(false)">
                Send to All Users
            </label>
            &nbsp;&nbsp;
            <label>
                <input type="radio" name="send_type" value="selected" onchange="toggleUsers(true)">
                Select Specific Users
            </label>
        </div>

        <div class="users-box" id="usersBox">
            <input type="text" id="searchUser" placeholder="🔍 Search by name or chat_id...">
            <label class="select-all"><input type="checkbox" id="selectAll"> Select All</label><hr>
            <div id="userList">
                <?php
                $users = $conn->query("SELECT id, chat_id, description FROM telegram_users ORDER BY created_at DESC");
                while ($u = $users->fetch_assoc()) {
                    echo '<label><input type="checkbox" name="users[]" value="'.intval($u['id']).'"> '
                        . htmlspecialchars($u['description'], ENT_QUOTES, 'UTF-8')
                        . ' ('.htmlspecialchars($u['chat_id'], ENT_QUOTES, 'UTF-8').')</label>';
                }
                ?>
            </div>
        </div>

        <button type="submit">🚀 Send Broadcast</button>
    </form>
</div>

<script>
function toggleUsers(show) {
    document.getElementById('usersBox').style.display = show ? 'block' : 'none';
}
function toggleSchedule(show) {
    document.getElementById('scheduleBox').style.display = show ? 'block' : 'none';
}
// select all / deselect all
document.getElementById('selectAll').addEventListener('change', function() {
    let checkboxes = document.querySelectorAll('input[name="users[]"]');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
// live search
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
