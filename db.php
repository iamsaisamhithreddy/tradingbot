<?php

$servername = "localhost";        
$username   = "USERNAME";     
$password   = "PASSWORD";    
$dbname     = "DB NAME"; 

$botToken    = "TELEGRAM BOT TOKEN"; 

$WebsiteURL = 'WEBSITE URL';
$adminChatId = "ADMIN CHAT ID"; 

$cpanel_user = "USERNAME";
$cpanel_token ="CPANEL TOKEN ";

$witAiToken = 'WIT API'; 
$CSVBackupChannelId ='TELEGRAM CHANNEL ID'; // STARTS WITH MINUS SIGN (-) AND THEN NUMBERS.

$witVersion = '20240304';
$BackupChannelID ='TELEGRAM CHANNEL ID'; // STARTS WITH MINUS SIGN (-) AND THEN NUMBERS.

$cooldownSeconds = 30; //cooldown time in seconds
$email = "ABC@gmail.com"; // used for backup of hosting is sent here. 

$chartlink = "$WebsiteURL".'/livechart/?symbol=';
$AI_ENDPOINT = "$WebsiteURL"."/ai_admin_analytics.php";
$conn = new mysqli($servername, $username, $password, $dbname);

// Checking connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
