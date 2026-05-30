<?php
include 'db.php'; // Ensure your connection is in db.php

// HANDLE THE UPDATE FROM THE CHART IFRAME
if (isset($_POST['update_result'])) {
    $trade_id = $_POST['trade_id'];
    $new_result = $_POST['update_result']; // 'win', 'loss', or 'setup_not_formed'
    
    $stmt = $conn->prepare("UPDATE prediction_trade_data SET trade_result = ? WHERE raw_trade_id = ?");
    $stmt->bind_param("si", $new_result, $trade_id);
    $stmt->execute();
    
    // Redirect back to the list so the processed trade disappears
    header("Location: manage_trades.php" . (isset($_GET['date']) ? "?date=".$_GET['date'] : ""));
    exit();
}

// Logic to fetch date and trades (Same as previous Code 2)
$active_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$trades_sql = "SELECT * FROM prediction_trade_data WHERE DATE(last_alert_time) = '$active_date' AND trade_result = 'pending'";
$result = $conn->query($trades_sql);
?>