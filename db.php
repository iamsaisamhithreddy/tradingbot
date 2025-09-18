<?php

// Database configuration
$servername = "";   // servername localhost etc..    
$username   = "";   //  databse username
$password   = "";   // databse password
$dbname     = "";   // database name 

// Telegram Bot configuration
$botToken    = "Telegram BOT TOKEN"; // paste the bot token from botfather
$adminChatId = ""; // admin chat id.

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
