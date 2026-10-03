<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/maintenance_check.php';
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

    // Define the Zip filename using current date (e.g., Backup 2026-09-09.zip)
    $zipFilename = 'Backup ' . date('Y-m-d') . '.zip';
    $zipPath = __DIR__ . '/' . $zipFilename;

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        
        // Add each matching CSV to the ZIP archive
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        echo "Zip archive created: {$zipFilename}" . PHP_EOL;

        // Send the single Zip file to Telegram
        $success = sendFileToTelegram($zipPath, $botToken, $chatId);

        if ($success) {

            // Log the zip creation & upload
            file_put_contents(
                $logFile,
                $zipFilename . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            echo "Successfully sent {$zipFilename}" . PHP_EOL;

            // Delete original CSV files after successful Telegram upload
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            echo "Original CSV files successfully deleted." . PHP_EOL;

        } else {

            echo "Failed to send {$zipFilename}. Original CSV files retained." . PHP_EOL;

        }

        // Clean up: delete temporary zip file from server after upload attempt
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

    } else {

        echo "Failed to create Zip file." . PHP_EOL;

    }

} else {

    echo "No CSV files found in {$directoryPath}" . PHP_EOL;

}
?>