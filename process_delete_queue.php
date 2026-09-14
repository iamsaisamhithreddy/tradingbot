<?php

require 'db.php'; // only needed if your $botToken lives in db.php

date_default_timezone_set('Asia/Kolkata');

$file = __DIR__ . '/delete_queue.json';

if (!file_exists($file)) {
    echo "No delete queue file found.\n";
    exit;
}

// Read with a lock so concurrent runs don't collide
$fp = fopen($file, 'r+');
if (!flock($fp, LOCK_EX)) {
    echo "Could not acquire lock on delete queue.\n";
    fclose($fp);
    exit;
}

$queue = json_decode(stream_get_contents($fp), true) ?? [];
$now   = time();

$remaining = [];
$deleted   = 0;
$skipped   = 0;

foreach ($queue as $item) {
    if ($now >= $item['delete_at']) {
        deleteTelegramMessage($item['chat_id'], $item['message_id']);
        echo "Deleted message {$item['message_id']} from chat {$item['chat_id']}.\n";
        $deleted++;
    } else {
        $remaining[] = $item; // not time yet, keep it
        $skipped++;
    }
}

// Write back only the un-processed items
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($remaining));
flock($fp, LOCK_UN);
fclose($fp);

echo "Done. Deleted: $deleted | Still pending: $skipped\n";


// --- SAME FUNCTION FROM YOUR MAIN FILE ---

function deleteTelegramMessage($chatId, $messageId) {
    global $botToken;
    $url  = "https://api.telegram.org/bot$botToken/deleteMessage";
    $data = ['chat_id' => $chatId, 'message_id' => $messageId];
    $options = ['http' => [
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data)
    ]];
    $context = stream_context_create($options);
    $result  = @file_get_contents($url, false, $context);
    $resp    = json_decode($result, true);

    if (!isset($resp['ok']) || !$resp['ok']) {
        echo "Failed to delete message $messageId from chat $chatId: " . ($resp['description'] ?? 'unknown error') . "\n";
    }
}