<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; // database connection

$messagesToDelete = []; // store messages to delete later

function checkPriceLevels($conn) {
    global $messagesToDelete, $botToken; 
    
    $trades = $conn->query("
        SELECT raw_trade_id, pair_name, price_target, trade_direction 
        FROM prediction_trade_data 
        WHERE DATE(last_alert_time) = CURDATE()
    ");
    if (!$trades) return;

    while ($trade = $trades->fetch_assoc()) {
        $tradeId = $trade['raw_trade_id'];
        $pair = $trade['pair_name'];
        $target = (float)$trade['price_target'];

        $result = $conn->query("
            SELECT current_price 
            FROM live_price_data 
            WHERE pair_name='$pair' 
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        if (!$result || $result->num_rows == 0) continue;

        $live = $result->fetch_assoc();
        $current = (float)$live['current_price'];

        $tolerance = $target * 0.00005;
        $upperBound = $target + $tolerance;
        $lowerBound = $target - $tolerance;

        if ($current >= $lowerBound && $current <= $upperBound) {
            // this message will be sent
            $message = "$pair Near Trade Zone! 🎯\nID: $tradeId\n$pair at $current (Target: $target)";  

            $stmtChats = $conn->query("SELECT DISTINCT chat_id FROM telegram_users");
            if (!$stmtChats) continue;

            while ($chat = $stmtChats->fetch_assoc()) {
                $chatId = $chat['chat_id'];
                $messageId = sendTelegramAlert($message, $chatId);
                if ($messageId) {
                    $messagesToDelete[] = ['chat_id'=>$chatId, 'message_id'=>$messageId];
                }
            }
            echo "Alert sent for trade $tradeId\n";
        }
    }
}

function sendTelegramAlert($message, $chatId) {
    global $botToken; // get bot token from db.php
    $url = "https://api.telegram.org/bot$botToken/sendMessage";

    $data = ['chat_id'=>$chatId, 'text'=>$message];
    $options = ['http'=>['header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'method'=>'POST','content'=>http_build_query($data)]];
    $context = stream_context_create($options);
    $response = @file_get_contents($url,false,$context);

    $responseData = json_decode($response,true);
    if(isset($responseData['ok']) && $responseData['ok']) return $responseData['result']['message_id'];
    return false;
}

function deleteTelegramMessage($chatId,$messageId) {
    global $botToken; // get bot token from db.php
    $url = "https://api.telegram.org/bot$botToken/deleteMessage";
    $data = ['chat_id'=>$chatId,'message_id'=>$messageId];
    $options = ['http'=>['header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'method'=>'POST','content'=>http_build_query($data)]];
    $context = stream_context_create($options);
    @file_get_contents($url,false,$context);
}

// Schedule deletion after script ends
register_shutdown_function(function() use (&$messagesToDelete) {
    foreach($messagesToDelete as $msg) {
        ignore_user_abort(true);
        // For safety, remove pcntl_fork() if running on shared hosting
        deleteTelegramMessage($msg['chat_id'],$msg['message_id']);
    }
});

// Run the price check
checkPriceLevels($conn);
?>
