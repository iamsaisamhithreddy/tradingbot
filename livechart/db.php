<?php

$servername = "localhost";        
$username   = "sairedd1_sai";     
$password   = "saireddyA1@#$";    
$dbname     = "sairedd1_trading"; 

$WebsiteURL = 'https://saireddy.site';

$botToken    = "8065156652:AAHrE8sh2w8F8LXtBePwPgI8sSicBWrCqio"; 
$adminChatId = "655708526"; 
$witAiToken = 'RWG6LN52R44VEONELKE4C4VV7D67F3PN'; 
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
