<?php
// add_admin.php
require 'db.php';


session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); // redirecting to login page if not logged in
    exit;
}

// Check if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        // Hash the password securely
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Prepare SQL insert
        $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $passwordHash);

        if ($stmt->execute()) {
            echo "✅ Admin user added successfully!";
        } else {
            echo "❌ Error: " . $stmt->error;
        }

        $stmt->close();
    } else {
        echo "⚠️ Please fill in both fields.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Admin</title>
</head>
<body>
    <h2>Add New Admin</h2>
    <form method="POST" action="">
        <label>Username:</label><br>
        <input type="text" name="username" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Add Admin</button>
    </form>
    
    
    <a href="permissions.php">👤 USER PERMISSIONS</a>
    
</body>
</html>
