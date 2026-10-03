<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['firmware'])) {
        echo json_encode(['status' => 'error', 'message' => 'No file received']);
        exit;
    }
    $file = $_FILES['firmware'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]);
        exit;
    }
    // Only accept .bin files
    if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'bin') {
        echo json_encode(['status' => 'error', 'message' => 'Only .bin files accepted']);
        exit;
    }
    $dest = __DIR__ . '/firmware.bin';
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['status' => 'ok', 'size' => $file['size']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
    }
    exit;
}