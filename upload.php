<?php
session_start();

// Only admins can access this
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); // Redirect to login page
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$targetDir = "dataset/";

// Create folder if not exists
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$message = "";

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    foreach ($_FILES['files']['name'] as $key => $name) {
        $fileName = basename($_FILES['files']['name'][$key]);
        $tmpName  = $_FILES['files']['tmp_name'][$key];
        $fileSize = $_FILES['files']['size'][$key];
        $error    = $_FILES['files']['error'][$key];

        // Safe file name
        $safeName = time() . "_" . preg_replace("/[^a-zA-Z0-9.\-_]/", "_", $fileName);
        $targetFile = $targetDir . $safeName;
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        $allowedTypes = ['jpg','jpeg','png','gif','pdf','csv','txt'];

        // Check upload errors
        if ($error !== UPLOAD_ERR_OK) {
            $message .= "❌ $fileName : Upload error code $error<br>";
            continue;
        }
        if (!in_array($fileType, $allowedTypes)) {
            $message .= "❌ $fileName : Invalid type ($fileType)<br>";
            continue;
        }
        if ($fileSize > 10 * 1024 * 1024) {
            $message .= "❌ $fileName : File too large (" . round($fileSize/1024/1024,2) . " MB)<br>";
            continue;
        }

        if (move_uploaded_file($tmpName, $targetFile)) {
            $message .= "✅ $fileName uploaded as $safeName<br>";
        } else {
            $message .= "❌ $fileName : move_uploaded_file() failed (check folder permissions)<br>";
        }
    }
}

// Handle file deletion
if (isset($_GET['delete'])) {
    $file = basename($_GET['delete']);
    $filePath = $targetDir . $file;
    if (file_exists($filePath)) {
        unlink($filePath);
        $message = "🗑️ File $file deleted.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin File Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f9fa; }
        .upload-box { max-width:700px; margin:40px auto; }
        .card { border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.1); }
        .alert { white-space: pre-line; }
    </style>
</head>
<body>
<div class="container">
    <div class="upload-box">
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h4 class="m-0">📂 Dataset File Manager</h4>
                <a href="?logout=1" class="btn btn-light btn-sm">Logout</a>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <form action="upload.php" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Select Files</label>
                        <input type="file" class="form-control" name="files[]" multiple required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">Upload</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Uploaded files -->
    <div class="mt-5">
        <h5>📑 Uploaded Files</h5>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>File Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $files = array_diff(scandir($targetDir), ['.','..']);
                if (count($files) > 0) {
                    $i = 1;
                    foreach ($files as $file) {
                        echo "<tr>
                                <td>{$i}</td>
                                <td><a href='{$targetDir}{$file}' target='_blank'>{$file}</a></td>
                                <td><a href='upload.php?delete={$file}' class='btn btn-danger btn-sm' onclick='return confirm(\"Delete $file?\")'>Delete</a></td>
                              </tr>";
                        $i++;
                    }
                } else {
                    echo "<tr><td colspan='3' class='text-center'>No files uploaded yet.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
