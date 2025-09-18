<?php
session_start(); // Start session

// only admins can access this . 
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); // Redirect to login page
    exit;
}

require 'db.php'; // database connection

$msg = "";

// Handle form submissions: Add/Edit/Delete

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Logout handling
    if (isset($_POST['logout'])) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Adding User
    if (isset($_POST['add_user'])) {
        $chat_id = trim($_POST['chat_id']);
        $description = trim($_POST['description']);
        if ($chat_id) {
            $stmt = $conn->prepare("INSERT INTO telegram_users (chat_id, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $chat_id, $description);
            if ($stmt->execute()) {
                $msg = "User added successfully!";
            } else {
                $msg = "Error adding user: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $msg = "Chat ID cannot be empty.";
        }
    }

    // Edit User
    if (isset($_POST['edit_user'])) {
        $id = intval($_POST['id']);
        $description = trim($_POST['description']);
        $stmt = $conn->prepare("UPDATE telegram_users SET description = ? WHERE id = ?");
        $stmt->bind_param("si", $description, $id);
        if ($stmt->execute()) {
            $msg = "Description updated successfully!";
        } else {
            $msg = "Error updating description: " . $stmt->error;
        }
        $stmt->close();
    }

    // Delete User
    if (isset($_POST['delete_user'])) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM telegram_users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $msg = "User deleted successfully!";
        } else {
            $msg = "Error deleting user: " . $stmt->error;
        }
        $stmt->close();
    }
}

// get telegram users
$users = [];
$res = $conn->query("SELECT * FROM telegram_users ORDER BY created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Manage Telegram Users</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 900px; margin: 30px auto; }
    table { border-collapse: collapse; width: 100%; margin-top: 20px; }
    th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
    th { background-color: #f5f5f5; }
    input[type=text], textarea { width: 100%; padding: 8px; box-sizing: border-box; }
    .btn { padding: 6px 12px; cursor: pointer; border: none; border-radius: 3px; }
    .btn-add { background-color: #4CAF50; color: white; }
    .btn-edit { background-color: #2196F3; color: white; }
    .btn-delete { background-color: #f44336; color: white; }
    .btn-logout { background-color: #6c757d; color: white; float: right; }
    .message { margin-top: 15px; padding: 10px; background: #dff0d8; color: #3c763d; border-radius: 4px; }
    form.inline { display: inline; }
    h1 { display: flex; justify-content: space-between; align-items: center; }
</style>
</head>
<body>

<h1>
    Manage Telegram Users
    <form method="POST" style="margin:0;">
        <button type="submit" name="logout" class="btn btn-logout">Logout</button>
    </form>
</h1>

<?php if (!empty($msg)): ?>
    <div class="message"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<h2>Add New User</h2>
<form method="POST" action="">
    <label>Chat ID:</label><br/>
    <input type="text" name="chat_id" required /><br/><br/>

    <label>Description:</label><br/>
    <textarea name="description" rows="2"></textarea><br/><br/>

    <button type="submit" name="add_user" class="btn btn-add">Add User</button>
</form>

<h2>Existing Users</h2>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Chat ID</th>
            <th>Description</th>
            <th>Created At</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($users) === 0): ?>
            <tr><td colspan="5" style="text-align:center;">No users found.</td></tr>
        <?php else: ?>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?= $user['id'] ?></td>
                <td><?= htmlspecialchars($user['chat_id']) ?></td>
                <td>
                    <form method="POST" action="" class="inline">
                        <input type="hidden" name="id" value="<?= $user['id'] ?>" />
                        <input type="text" name="description" value="<?= htmlspecialchars($user['description']) ?>" />
                        <button type="submit" name="edit_user" class="btn btn-edit">Save</button>
                    </form>
                </td>
                <td><?= $user['created_at'] ?></td>
                <td>
                    <form method="POST" action="" class="inline" onsubmit="return confirm('Delete this user?');">
                        <input type="hidden" name="id" value="<?= $user['id'] ?>" />
                        <button type="submit" name="delete_user" class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>
