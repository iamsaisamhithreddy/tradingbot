<?php
// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

include 'db.php'; // database connection

// Define backup table names
$backupTables = [
    'prediction_trade_data' => 'prediction_trade_data_backup',
    'raw_trade_data'        => 'raw_trade_data_backup'
];

foreach ($backupTables as $source => $backup) {
    // Drop old backup table if it exists
    $dropSql = "DROP TABLE IF EXISTS `$backup`";
    if (!$conn->query($dropSql)) {
        die("Error dropping table $backup: " . $conn->error);
    }

    // Create new backup from source
    $createSql = "CREATE TABLE `$backup` AS SELECT * FROM `$source`";
    if (!$conn->query($createSql)) {
        die("Error creating backup $backup: " . $conn->error);
    }

    echo "Backup created!\n";
}

$conn->close();
?>
