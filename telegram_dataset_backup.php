<?php
require_once __DIR__ . '/db.php';

$chatId = $CSVBackupChannelId;

$directoryPath = __DIR__ . '/dataset/dataset/';
$logFile = __DIR__ . '/sent_files_log.txt';

date_default_timezone_set('UTC');

// ==========================================
// FUNCTION TO SEND FILE VIA TELEGRAM API
// ==========================================
function sendFileToTelegram($filePath, $botToken, $chatId)
{
    $url = "https://api.telegram.org/bot{$botToken}/sendDocument";

    $cFile = new CURLFile(realpath($filePath));

    $currentDate = date('Y-m-d H:i:s');

    $postData = [
        'chat_id'  => $chatId,
        'document' => $cFile,
        'caption'  => "Data Backup [{$currentDate}]: " . basename($filePath)
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "cURL Error: " . curl_error($ch) . PHP_EOL;
    }

    curl_close($ch);

    return ($httpCode == 200);
}

// ==========================================
// MAIN LOGIC
// ==========================================

$files = glob($directoryPath . 'FX_*.csv');

if ($files !== false && count($files) > 0) {

    foreach ($files as $file) {

        $filename = basename($file);

        $success = sendFileToTelegram($file, $botToken, $chatId);

        if ($success) {

            file_put_contents(
                $logFile,
                $filename . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            echo "Successfully sent {$filename}" . PHP_EOL;

            // Pause to avoid Telegram rate limits
            sleep(3);

        } else {

            echo "Failed to send {$filename}" . PHP_EOL;

        }
    }

} else {

    echo "No CSV files found in {$directoryPath}" . PHP_EOL;

}
?>