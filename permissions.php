<?php
session_start();
require 'db.php';

// Check login
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if (!in_array('add_admin', $_SESSION['admin_permissions'])) {
    die("❌ You do not have permission to access this page.");
}

// Define all possible permissions
$all_permissions = [
    "add_admin" => "Add Admins",
    "manage_users" => "Manage Users",
    "add_news" => "Add News Event",
    "broadcast" => "Broadcast Message",
    "view_events" => "View Events",
    "trade_reports" => "Trade Reports",
    "backup" => "Backup Tables",
    "validate" => "Validate",
    "valid_pairs" => "Valid Pairs",
    "trade_enquiry" => "Trade Enquiry",
    "upload_files" => "Upload Files",
    "send_mail" => "Send Mail"
];

$selected_admin_id = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_id'])) {
    $admin_id = (int)$_POST['admin_id'];
    
    // FIX: If no checkboxes are checked, default to an empty array
    $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : []; 

    $json_permissions = json_encode($permissions);

    $stmt = $conn->prepare("UPDATE admin_users SET permissions=? WHERE id=?");
    $stmt->bind_param("si", $json_permissions, $admin_id);
    $stmt->execute();

    $msg = "✅ Permissions updated successfully!";
    
    $selected_admin_id = $admin_id; 
}

// Fetch all admins
$admins = [];
$result = $conn->query("SELECT id, username, permissions FROM admin_users ORDER BY id ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['permissions'] = json_decode($row['permissions'], true) ?? [];
        $admins[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup Permissions</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
    body {font-family:'Inter',sans-serif;background:#f5f7fa;padding:30px;}
    .container {max-width:800px;margin:auto;background:white;padding:20px;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,0.1);}
    h2 {margin-bottom:20px;color:#1e293b;}
    
    /* Dropdown Styling */
    .admin-selector {width: 100%; padding: 10px; margin-bottom: 20px; border-radius: 8px; border: 1px solid #ccc; font-size: 16px; font-family: 'Inter', sans-serif;}
    
    /* Hide forms by default */
    .admin-form {display: none; padding-top: 15px; border-top: 1px solid #e2e8f0;}
    
    label {display:block;margin-bottom:5px;font-weight:600;}
    input[type="submit"] {padding:10px 20px;background:#1d4ed8;color:white;border:none;border-radius:8px;cursor:pointer;margin-top:10px;}
    input[type="submit"]:hover {background:#2563eb;}
    .permissions {display:flex;flex-wrap:wrap;}
    .permissions label {width:200px;font-weight:400; margin-bottom: 10px; cursor: pointer;}
    .msg {color:green;margin-bottom:20px; padding: 10px; background: #e6fffa; border-left: 4px solid #38b2ac; border-radius: 4px;}
</style>
</head>
<body>
<div class="container">
    <h2>Setup Admin Permissions</h2>
    
    <?php if(!empty($msg)) echo "<div class='msg'>{$msg}</div>"; ?>

    <select class="admin-selector" id="adminDropdown" onchange="showAdminForm(this.value)">
        <option value="">-- Select an Admin to Edit --</option>
        <?php foreach($admins as $admin): ?>
            <option value="<?php echo $admin['id']; ?>" <?php echo ($admin['id'] === $selected_admin_id) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($admin['username']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div id="formsContainer">
        <?php foreach($admins as $admin): ?>
            <form method="post" id="form_<?php echo $admin['id']; ?>" class="admin-form">
                <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                <label style="font-size: 1.2em; margin-bottom: 15px; color: #333;">Editing permissions for: <strong><?php echo htmlspecialchars($admin['username']); ?></strong></label>
                
                <div class="permissions">
                    <?php foreach($all_permissions as $key => $label): ?>
                        <label>
                            <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>"
                            <?php echo in_array($key, $admin['permissions']) ? "checked" : ""; ?>>
                            <?php echo $label; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="submit" value="Save Permissions">
            </form>
        <?php endforeach; ?>
    </div>
</div>

<script>
    // Function to hide all forms and show only the selected one
    function showAdminForm(adminId) {
        // Hide all forms
        let forms = document.querySelectorAll('.admin-form');
        forms.forEach(function(form) {
            form.style.display = 'none';
        });

        // Show the targeted form if an ID is selected
        if (adminId) {
            document.getElementById('form_' + adminId).style.display = 'block';
        }
    }
    window.onload = function() {
        let dropdown = document.getElementById('adminDropdown');
        if (dropdown.value) {
            showAdminForm(dropdown.value);
        }
    };
</script>
</body>
</html>