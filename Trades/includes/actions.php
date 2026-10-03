<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Upload File
    if ($action === 'upload' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'csv') {
            $filename = time() . '_' . basename($file['name']);
            $target_path = $upload_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $_SESSION['current_file'] = $target_path;
                $_SESSION['display_name'] = basename($file['name']);
                $success_msg = "File uploaded successfully!";
            } else {
                $error_msg = "Upload failed. Check folder permissions.";
            }
        } else {
            $error_msg = "Invalid file. Please upload a valid .csv file.";
        }
    }
    // 2. Reset/Close File
    elseif ($action === 'reset') {
        unset($_SESSION['current_file']);
        unset($_SESSION['display_name']);
        $_SESSION['filters'] = ['start_date' => '', 'end_date' => '', 'asset' => 'All', 'market' => 'All'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    // 3. Apply Filters
    elseif ($action === 'filter') {
        $_SESSION['filters']['start_date'] = $_POST['start_date'] ?? '';
        $_SESSION['filters']['end_date'] = $_POST['end_date'] ?? '';
        $_SESSION['filters']['asset'] = $_POST['asset'] ?? 'All';
        $_SESSION['filters']['market'] = $_POST['market'] ?? 'All';
    }
    // 4. Clear Filters
    elseif ($action === 'clear_filters') {
        $_SESSION['filters'] = ['start_date' => '', 'end_date' => '', 'asset' => 'All', 'market' => 'All'];
    }
}