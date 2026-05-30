<?php

// Database configuration
$servername = "localhost";        
$username   = "sairedd1_sai";     
$password   = "saireddyA1@#$";    
$dbname     = "sairedd1_trading"; 

// Telegram Bot configuration
$botToken    = "8065156652:AAFoy3EhYGlaHuFGFk_prHUzAvQFrz48Uus"; 
$adminChatId = "655708526"; // optional

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
