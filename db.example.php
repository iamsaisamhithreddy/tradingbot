<?php

// Copy this file to db.php and fill in your own details

$servername = "localhost";
$username   = "YOUR_DB_USERNAME";
$password   = "YOUR_DB_PASSWORD";
$dbname     = "YOUR_DB_NAME";

$WebsiteURL = 'https://yourdomain.com';

$botToken       = "YOUR_TELEGRAM_BOT_TOKEN";
$adminChatId    = "YOUR_ADMIN_CHAT_ID";
$witAiToken     = 'YOUR_WIT_AI_API_KEY';
$CSVBackupChannelId = 'YOUR_CSV_BACKUP_CHANNEL_ID';
$witVersion     = '20240304';
$BackupChannelID = '';

$cooldownSeconds = 30;

$chartlink  = "$WebsiteURL" . '/livechart/?symbol=';
$AI_ENDPOINT = "$WebsiteURL" . "/ai_admin_analytics.php";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
