<?php
// Set timezone to IST for the backup filename
date_default_timezone_set('Asia/Kolkata');

// 1. Include your existing database connection (which has $botToken and $BackupChannelID)
require 'db.php'; 

// Ensure the connection uses UTF-8 to prevent weird character encoding issues
$conn->set_charset("utf8");

$tables = array();
$sqlScript = "";

// 2. Get the name of the database (to name the downloaded file)
$result = $conn->query("SELECT DATABASE()");
$db_name_row = $result->fetch_row();
$database_name = $db_name_row[0] ? $db_name_row[0] : 'database';

// 3. Get all tables in the database
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

// 4. Loop through each table to extract its structure and data
foreach ($tables as $table) {
    
    // Get the table creation SQL (CREATE TABLE...)
    $result = $conn->query("SHOW CREATE TABLE `$table`");
    $row = $result->fetch_row();
    $sqlScript .= "\n\n" . $row[1] . ";\n\n";
    
    // Get the table data
    $result = $conn->query("SELECT * FROM `$table`");
    $columnCount = $result->field_count;

    // Loop through every single row in the table
    while ($row = $result->fetch_row()) {
        $sqlScript .= "INSERT INTO `$table` VALUES(";
        
        for ($j = 0; $j < $columnCount; $j++) {
            // Escape special characters to prevent SQL errors
            if (isset($row[$j])) {
                $escaped_value = $conn->real_escape_string($row[$j]);
                $sqlScript .= '"' . $escaped_value . '"';
            } else {
                $sqlScript .= 'NULL';
            }
            
            // Add a comma between values, but not after the last one
            if ($j < ($columnCount - 1)) {
                $sqlScript .= ',';
            }
        }
        $sqlScript .= ");\n";
    }
}

// 5. Generate the dynamic filename and save to a temporary server folder
$backup_file_name = $database_name . '_backup_' . date('Y-m-d_H-i-s') . '.sql';
$temp_file_path = sys_get_temp_dir() . '/' . $backup_file_name;

// Write the SQL script to the temp file
file_put_contents($temp_file_path, $sqlScript);

// 6. Push the file to Telegram via cURL using variables from db.php
$telegramUrl = "https://api.telegram.org/bot" . $botToken . "/sendDocument";
$document = new CURLFile($temp_file_path);

$postData = array(
    'chat_id' => $BackupChannelID,
    'document' => $document,
    'caption' => "✅ Database Backup\nDatabase: `" . $database_name . "`\nDate: " . date('Y-m-d H:i:s'),
    'parse_mode' => 'Markdown'
);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $telegramUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 7. Delete the temporary file from the server to save space
if (file_exists($temp_file_path)) {
    unlink($temp_file_path);
}

// 8. Output success or error message
if ($http_code == 200) {
    echo "Success! Backup sent to Telegram channel.";
} else {
    echo "Error sending to Telegram. HTTP Code: " . $http_code . "<br>";
    echo "Telegram Response: " . $response;
}

// Close the connection
$conn->close();
exit;
?>