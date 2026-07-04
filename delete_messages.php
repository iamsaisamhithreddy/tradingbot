<?php

require 'db.php';

date_default_timezone_set('Asia/Kolkata');

$file = __DIR__ . '/delete_queue.json';

// If queue file does not exist
if (!file_exists($file)) {
    exit("Queue file not found.\n");
}

// Read queue
$queue = json_decode(file_get_contents($file), true) ?? [];

if (empty($queue)) {
    exit("Queue empty.\n");
}

$newQueue = [];

foreach ($queue as $msg) {

    // Skip invalid entries
    if (
        !isset($msg['chat_id']) ||
        !isset($msg['message_id']) ||
        !isset($msg['delete_at'])
    ) {
        continue;
    }

    // Time to delete
    if (time() >= $msg['delete_at']) {

        deleteTelegramMessage(
            $msg['chat_id'],
            $msg['message_id']
        );

        echo "Deleted Message ID: {$msg['message_id']}\n";

    } else {

        // Keep pending messages
        $newQueue[] = $msg;
    }
}

// Save remaining queue
file_put_contents(
    $file,
    json_encode($newQueue, JSON_PRETTY_PRINT),
    LOCK_EX
);



// -----------------------------
// TELEGRAM DELETE FUNCTION
// -----------------------------

function deleteTelegramMessage($chatId, $messageId) {

    global $botToken;

    $url = "https://api.telegram.org/bot{$botToken}/deleteMessage";

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];

    $options = [
        'http' => [
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 15
        ]
    ];

    $context = stream_context_create($options);

    @file_get_contents($url, false, $context);
}

?>