<?php
session_start();

// Directory for storing CSVs securely
$upload_dir = __DIR__ . '/../uploads/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

$success_msg = ""; 
$error_msg = "";

// Initialize Filter Sessions
if (!isset($_SESSION['filters'])) {
    $_SESSION['filters'] = ['start_date' => '', 'end_date' => '', 'asset' => 'All', 'market' => 'All'];
}