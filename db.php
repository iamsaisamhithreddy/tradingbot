<?php

$servername = "localhost"; // server name       
$username   = "ENTER YOUR OWN DEATILS";     // server username  
$password   = "ENTER YOUR OWN DEATILS";   // password  
$dbname     = "ENTER YOUR OWN DEATILS"; // database name 

$WebsiteURL = 'https://abc.in';

$botToken    = "TELEGRAM BOT TOKEN"; // this bot should be admin in groups for sending files and messages. 
$adminChatId = "ADMIN CHAT ID";  // admin chat id 
$witAiToken = 'WIT AI API KEY '; // WIT.AI API KEY FOR TTS , STT
$CSVBackupChannelId ='CSV FILE BACKUP CHANNEL ID '; // IT STARTS WITH - SIGN 
$witVersion = '20240304'; // WIT.AI VERSION
$BackupChannelID ='';

$cooldownSeconds = 30; //cooldown time in seconds


$chartlink = "$WebsiteURL".'/livechart/?symbol=';
$AI_ENDPOINT = "$WebsiteURL"."/ai_admin_analytics.php";
$conn = new mysqli($servername, $username, $password, $dbname);

// Checking connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
