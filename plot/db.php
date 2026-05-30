<?php

// Database configuration
$servername = "localhost";        
$username   = "";     
$password   = "";    
$dbname     = ""; 

// Telegram Bot configuration
$botToken    = "TELEGRAM BOT TOJEN"; 
$adminChatId = "ADMIN CHAT ID"; // optional

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
