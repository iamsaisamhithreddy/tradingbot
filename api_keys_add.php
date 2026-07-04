<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

// INSERT
if (isset($_POST['save'])) {

    $provider = strtolower(trim($_POST['provider']));
    $api_key  = trim($_POST['api_key']);
    $base_url = trim($_POST['base_url']);
    $model    = trim($_POST['model']);

    // FORCE HTTPS
    $base_url = preg_replace('/^http:/i', 'https:', $base_url);
    $base_url = rtrim($base_url, '/');

    // VALIDATION
    if (!in_array($provider, ['groq','openai','xai','gemini'])) {
        die("❌ Invalid provider");
    }

    if (!filter_var($base_url, FILTER_VALIDATE_URL)) {
        die("❌ Invalid Base URL");
    }

    // ESCAPE
    $provider = mysqli_real_escape_string($conn, $provider);
    $api_key  = mysqli_real_escape_string($conn, $api_key);
    $base_url = mysqli_real_escape_string($conn, $base_url);
    $model    = mysqli_real_escape_string($conn, $model);

    mysqli_query($conn, "
        INSERT INTO api_keys (provider, api_key, base_url, model, status)
        VALUES ('$provider','$api_key','$base_url','$model','active')
    ");

    $msg = "✅ API Key Added Successfully!";
}

// DELETE
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM api_keys WHERE id=$id");
    header("Location: api_keys_add.php");
    exit;
}

// SET ACTIVE
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];

    mysqli_query($conn, "UPDATE api_keys SET status='inactive'");
    mysqli_query($conn, "UPDATE api_keys SET status='active' WHERE id=$id");

    header("Location: api_keys_add.php");
    exit;
}

// DISABLE ALL
if (isset($_GET['disable_all'])) {
    mysqli_query($conn, "UPDATE api_keys SET status='inactive'");
    header("Location: api_keys_add.php");
    exit;
}

//  FETCH 
$res = mysqli_query($conn, "SELECT * FROM api_keys ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>API Keys Admin</title>

<style>
body{font-family:Arial;background:#f5f7fa}
.container{max-width:900px;margin:auto;background:#fff;padding:20px}

/* Header Flexbox to align title and disable button */
.header-row {
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom:10px;
}
h2{margin: 0;}

input,select{
    width:100%;
    padding:10px;
    margin:8px 0;
    border-radius:8px;
    border:1px solid #ccc;
    box-sizing: border-box; /* ensures padding doesn't overflow */
}

button{
    padding:10px 15px;
    border:0;
    border-radius:8px;
    background:#2563eb;
    color:#fff;
    cursor:pointer;
}

.btn-danger {
    background: #dc2626;
    color: #fff;
    padding: 10px 15px;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: bold;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}

th,td{
    padding:10px;
    border-bottom:1px solid #ddd;
    text-align:left;
}

.active{
    color:green;
    font-weight:bold;
}

.actions a{
    margin-right:10px;
    text-decoration:none;
    font-weight:bold;
}

.delete{color:red}
.activate{color:green}
</style>

</head>
<body>

<div class="container">

<div class="header-row">
    <h2> API Key Manager</h2>
    <a href="?disable_all=true" class="btn-danger" onclick="return confirm('Are you sure you want to disable ALL API keys?')">⛔ Disable All APIs</a>
</div>

<?php if(isset($msg)) echo "<p>$msg</p>"; ?>

<form method="post">

    <select name="provider" id="provider" required onchange="autoFill()">
        <option value="">Select Provider</option>
        <option value="groq">Groq</option>
        <option value="openai">OpenAI</option>
        <option value="xai">xAI (Grok)</option>
        <option value="gemini">Google Gemini</option>
    </select>

    <input id="api_key" name="api_key" placeholder="API Key" required>

    <input id="base_url" name="base_url" placeholder="Base URL" required>

    <input id="model" name="model" placeholder="Model (enter manually)" required>

    <button name="save">Add API Key</button>

</form>

<table>
<tr>
    <th>ID</th>
    <th>Provider</th>
    <th>Model</th>
    <th>Status</th>
    <th>Actions</th>
</tr>

<?php while($row = mysqli_fetch_assoc($res)): ?>
<tr>
    <td><?= $row['id'] ?></td>
    <td><?= htmlspecialchars($row['provider']) ?></td>
    <td><?= htmlspecialchars($row['model']) ?></td>
    <td class="<?= $row['status']=='active'?'active':'' ?>">
        <?= $row['status'] ?>
    </td>
    <td class="actions">
        <a class="activate" href="?activate=<?= $row['id'] ?>">Activate</a>
        <a class="delete" href="?delete=<?= $row['id'] ?>" onclick="return confirm('Delete?')">Delete</a>
    </td>
</tr>
<?php endwhile; ?>

</table>

</div>

<script>
function autoFill() {
    const provider = document.getElementById("provider").value;
    const baseURL = document.getElementById("base_url");

    if (provider === "groq") {
        baseURL.value = "https://api.groq.com/openai/v1";
    }
    else if (provider === "openai") {
        baseURL.value = "https://api.openai.com/v1";
    }
    else if (provider === "xai") {
        baseURL.value = "https://api.x.ai/v1";
    }
    else if (provider === "gemini") {
        baseURL.value = "https://generativelanguage.googleapis.com/v1";
    }
}
</script>

</body>
</html>