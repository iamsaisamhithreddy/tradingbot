<?php
// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; // database connection

// get chat ids of users 
$chatIds = [];
$result = $conn->query("SELECT chat_id FROM telegram_users");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $chatIds[] = $row['chat_id'];
    }
}

// 2Send all pending trades
$resultTrades = $conn->query("SELECT * FROM prediction_trade_data WHERE sent_status = 0");
if ($resultTrades && $resultTrades->num_rows > 0) {
    while ($trade = $resultTrades->fetch_assoc()) {
        $directionEmoji = strtoupper($trade['trade_direction']) === 'UP' ? "🟢 UP" : "🔴 SELL";

        // this is the format of message which will be sent.
        $message = "📊 *New Trade Signal*\n" .
                   "🆔 *Trade ID:* `" . $trade['raw_trade_id'] . "`\n" .
                   "📄 *Pair:* `" . $trade['pair_name'] . "`\n" .
                   "💰 *Price Target:* `" . $trade['price_target'] . "`\n" .
                   "📈 *Direction:* *$directionEmoji*";

        foreach ($chatIds as $chatId) {
            sendToTelegram($botToken, $chatId, $message);
        }

        // Mark trades as sent
        $update = $conn->prepare("UPDATE prediction_trade_data SET sent_status = 1, last_alert_time = NOW() WHERE raw_trade_id = ?");
        $update->bind_param("s", $trade['raw_trade_id']);
        $update->execute();
        $update->close();
    }
}

//  Economic events are sent to users 5 mins before the event. 

$nowIST = new DateTime("now", new DateTimeZone('Asia/Kolkata'));
$fiveMinutesLaterIST = clone $nowIST;
$fiveMinutesLaterIST->modify('+5 minutes'); 

$resultEvents = $conn->query("SELECT * FROM economic_events");
$eligibleEvents = [];

if ($resultEvents && $resultEvents->num_rows > 0) {
    while ($event = $resultEvents->fetch_assoc()) {
        $eventTimeGMT4 = new DateTime($event['event_time'], new DateTimeZone('America/New_York'));
        $eventTimeIST = clone $eventTimeGMT4;
        $eventTimeIST->setTimezone(new DateTimeZone('Asia/Kolkata'));

        if ($eventTimeIST >= $nowIST && $eventTimeIST <= $fiveMinutesLaterIST) {
            $event['event_time_ist'] = $eventTimeIST;
            $eligibleEvents[] = $event;
        }
    }
}

if (!empty($eligibleEvents)) {
    // slecting highest impact
    usort($eligibleEvents, function($a, $b) {
        return (int)$b['impact'] <=> (int)$a['impact'];
    });
    $event = $eligibleEvents[0];

    // sending note with news deatils. like some conditions etc.. 
    if ((int)$event['impact'] === 1) {
        $impactEmoji = "⚪️ Low \nNOTE : Wait this 1 candle if structure is forming.\n\nTHERE SHOULD BE ATLEAST 3 CONSECUITVE SAME CANDLES (INCLUDING BREAKOUT CANDLE)";
    } elseif ((int)$event['impact'] === 2) {
        $impactEmoji = "🟡 Medium\nNOTE : Wait 2 more candles after this if structure is forming.\n\nTHERE SHOULD BE ATLEAST 3 CONSECUITVE SAME CANDLES (INCLUDING BREAKOUT CANDLE)\n\nIF we are targeting 50% level (should consider 5 candles *including news candle*)";
    } elseif ((int)$event['impact'] === 3) {
        $impactEmoji = "🔴 High\nNOTE : Wait 2 more candles after this if structure is forming.\n\nTHERE SHOULD BE ATLEAST 3 CONSECUITVE SAME CANDLES (INCLUDING BREAKOUT CANDLE)\n\nIF we are targeting 50% level (should consider 8 candles *including news candle*)";
    } else {
        $impactEmoji = "⚪️ Unknown Impact";
    }

    $eventTimeStr = $event['event_time_ist']->format('Y-m-d H:i');

    // Economic Calendar events message format 

    $message = "📰 *Economic Calendar Event*\n" .
               "📅 *Time (IST):* `" . $eventTimeStr . "`\n" .
               "📌 *Event:* `" . $event['event_name'] . "`\n" .
               "💥 *Impact:* *$impactEmoji*";

    foreach ($chatIds as $chatId) {
        $messageId = sendToTelegram($botToken, $chatId, $message);
        pinMessage($botToken, $chatId, $messageId);
    }

    // Delete only the sent event
    $del = $conn->prepare("DELETE FROM economic_events WHERE id = ?");
    $del->bind_param("i", $event['id']);
    $del->execute();
    $del->close();
}

echo "✅ Alerts sent successfully";


function sendToTelegram($botToken, $chatId, $message) {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'Markdown'
    ];
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type:application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $response = file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);

    return $result['result']['message_id'] ?? null;
}

function pinMessage($botToken, $chatId, $messageId) {
    if (!$messageId) return;
    $url = "https://api.telegram.org/bot$botToken/pinChatMessage";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'disable_notification' => true
    ];
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type:application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    file_get_contents($url, false, stream_context_create($options));
}
?>
