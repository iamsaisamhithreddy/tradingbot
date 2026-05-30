<?php

$servername = "localhost";        
$username   = "";     
$password   = "";    
$dbname     = ""; 

$WebsiteURL = 'https://saireddy.site';

$botToken    = "TELEGRAM BOT TOKEN"; 
$adminChatId = "TELEGRAM ADMINCHAT ID"; 
$witAiToken = 'WIT AI TOKEN'; 
$witVersion = '20240304';

$cooldownSeconds = 30; //cooldown time in seconds


$chartlink = "$WebsiteURL".'/livechart/?symbol=';
$AI_ENDPOINT = "$WebsiteURL"."/ai_admin_analytics.php";
$conn = new mysqli($servername, $username, $password, $dbname);

// Checking connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
