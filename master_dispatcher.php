<?php
require_once 'db.php';

// =========================================================================
// 1. AJAX API ROUTER (Handles the instant background toggles)
// =========================================================================
// If the incoming request is JSON, we process it and EXIT immediately.
if (isset($_SERVER["CONTENT_TYPE"]) && strpos($_SERVER["CONTENT_TYPE"], "application/json") !== false) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id']) || !isset($data['is_active'])) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    $id = (int) $data['id'];
    $is_active = (int) $data['is_active'];

    $stmt = $conn->prepare("UPDATE cron_jobs SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $is_active, $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database update failed']);
    }
    
    $stmt->close();
    exit; // STOP SCRIPT HERE. Do not render HTML for background API requests.
}

// =========================================================================
// 2. STANDARD FORM HANDLING (Add, Edit, Delete)
// =========================================================================
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = $_POST['task_name'];
        $path = $_POST['file_path'];
        $stmt = $conn->prepare("INSERT INTO cron_jobs (task_name, file_path, is_active) VALUES (?, ?, 1)");
        $stmt->bind_param("ss", $name, $path);
        if ($stmt->execute()) $message = "<div class='alert success'>Task added successfully!</div>";
    }
    
    elseif ($_POST['action'] === 'edit') {
        $id = $_POST['task_id'];
        $name = $_POST['task_name'];
        $path = $_POST['file_path'];
        $stmt = $conn->prepare("UPDATE cron_jobs SET task_name = ?, file_path = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $path, $id);
        if ($stmt->execute()) $message = "<div class='alert success'>Task updated successfully!</div>";
    }
    
    elseif ($_POST['action'] === 'delete') {
        $id = $_POST['task_id'];
        $stmt = $conn->prepare("DELETE FROM cron_jobs WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) $message = "<div class='alert success'>Task deleted!</div>";
    }
}

// Fetch tasks for display
$result = $conn->query("SELECT * FROM cron_jobs ORDER BY task_name ASC");
$tasks = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) $tasks[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Control Panel</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f4f7f6; padding: 20px; color: #333; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; color: white; font-weight: 500; }
        .btn-primary { background: #2563eb; }
        .btn-danger { background: #dc2626; padding: 6px 10px; font-size: 12px; }
        .btn-edit { background: #f59e0b; padding: 6px 10px; font-size: 12px; }
        .task-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #eee; }
        .task-info { flex-grow: 1; }
        .task-path { font-size: 12px; color: #666; margin-top: 4px; }
        .task-actions { display: flex; gap: 15px; align-items: center; }
        
        /* Toggle Switch CSS */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(20px); }
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 4px; }
        .success { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>

<div class="container">
    <?= $message ?>

    <div class="card">
        <h3 id="formTitle">➕ Add New Task</h3>
        <form method="POST" id="taskForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="task_id" id="taskId" value="">
            <div class="form-group">
                <label>Task Name</label>
                <input type="text" name="task_name" id="taskName" required>
            </div>
            <div class="form-group">
                <label>File Path</label>
                <input type="text" name="file_path" id="filePath" required>
            </div>
            <button type="submit" class="btn btn-primary" id="submitBtn">Save Task</button>
            <button type="button" class="btn" style="background:#6b7280; display:none;" id="cancelBtn" onclick="resetForm()">Cancel Edit</button>
        </form>
    </div>

    <div class="card">
        <h3>📋 Active Tasks</h3>
        <?php if (empty($tasks)): ?>
            <p>No active tasks found. Add one above!</p>
        <?php else: ?>
            <?php foreach ($tasks as $task): ?>
                <div class="task-row">
                    <div class="task-info">
                        <strong><?= htmlspecialchars($task['task_name']) ?></strong>
                        <div class="task-path"><?= htmlspecialchars($task['file_path']) ?></div>
                    </div>
                    
                    <div class="task-actions">
                        <label class="switch">
                            <input type="checkbox" onchange="toggleTask(<?= $task['id'] ?>, this.checked)" <?= $task['is_active'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                        <button class="btn btn-edit" onclick="editTask(<?= $task['id'] ?>, '<?= addslashes($task['task_name']) ?>', '<?= addslashes($task['file_path']) ?>')">Edit</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this task?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function editTask(id, name, path) {
    document.getElementById('formTitle').innerText = '✏️ Edit Task';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('taskId').value = id;
    document.getElementById('taskName').value = name;
    document.getElementById('filePath').value = path;
    document.getElementById('submitBtn').innerText = 'Update Task';
    document.getElementById('cancelBtn').style.display = 'inline-block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('formTitle').innerText = '➕ Add New Task';
    document.getElementById('formAction').value = 'add';
    document.getElementById('taskId').value = '';
    document.getElementById('taskForm').reset();
    document.getElementById('submitBtn').innerText = 'Save Task';
    document.getElementById('cancelBtn').style.display = 'none';
}

function toggleTask(taskId, isChecked) {
    const isActive = isChecked ? 1 : 0;
    
    // Notice the fetch URL is now dynamically grabbing the current page's URL!
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: taskId, is_active: isActive })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Failed to update task.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Check console.');
    });
}
</script>

</body>
</html>