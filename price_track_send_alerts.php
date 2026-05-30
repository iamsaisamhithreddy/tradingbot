<?php

require 'db.php'; // database connection

// Force IST timezone for accurate 10-minute news scanning
date_default_timezone_set('Asia/Kolkata');

$messagesToDelete = []; // store messages to delete later

// --- NEW LIVE API FUNCTIONS ---

function fetchBars(string $instrument, int $limit): array {
    $bust   = time() . rand(1000, 9999);
    $apiUrl = "https://mds-api.forexfactory.com/bars?to=0&interval=M5"
            . "&instrument={$instrument}&per_page={$limit}&extra_fields=&_={$bust}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Origin: https://www.forexfactory.com',
            'Referer: https://www.forexfactory.com/',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['error' => "cURL error: $curlErr"];
    if ($httpCode !== 200) return ['error' => "API returned HTTP $httpCode"];

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return ['error' => 'Invalid JSON from API'];
    if (empty($decoded['data'])) return ['error' => 'No data returned from API'];

    return $decoded['data'];
}

function formatBars(array $rawBars): array {
    $out = [];
    foreach (array_reverse($rawBars) as $bar) {
        $out[] = [
            'time'   => (int)$bar['timestamp'],
            'open'   => (float)$bar['open'],
            'high'   => (float)$bar['high'],
            'low'    => (float)$bar['low'],
            'close'  => (float)$bar['close'],
        ];
    }
    return $out;
}

// --- LOGIC FUNCTIONS ---

function is_bullish_prox($open, $close){ return floatval($close) > floatval($open); }
function is_bearish_prox($open, $close){ return floatval($close) < floatval($open); }

function get_forced_prominent_wick_target($candles, $direction) {
    $c_prev1 = $candles[2]; 
    $c_prev2 = $candles[1]; 
    
    $total_size = 0;
    foreach([$candles[0], $candles[1], $candles[2]] as $c) {
        $total_size += (floatval($c['h']) - floatval($c['l']));
    }
    $avg_candle_size = $total_size / 3.0;

    if (strtoupper($direction) === 'DOWN' || strtoupper($direction) === 'SELL') {
        $u_wick1 = floatval($c_prev1['h']) - max(floatval($c_prev1['o']), floatval($c_prev1['c']));
        if($avg_candle_size > 0 && $u_wick1 > ($avg_candle_size * 0.15)) return floatval($c_prev1['h']);
        return max(floatval($c_prev1['h']), floatval($c_prev2['h']));
    } else {
        $l_wick1 = min(floatval($c_prev1['o']), floatval($c_prev1['c'])) - floatval($c_prev1['l']);
        if($avg_candle_size > 0 && $l_wick1 > ($avg_candle_size * 0.15)) return floatval($c_prev1['l']);
        return min(floatval($c_prev1['l']), floatval($c_prev2['l']));
    }
}

function checkPriceLevels($conn) {
    global $messagesToDelete, $botToken; 
    
    // Skip alerts on Saturday (6) and Sunday (7)
    $dayOfWeek = date('N');
    if ($dayOfWeek == 6 || $dayOfWeek == 7) {
        echo "Weekend (Saturday/Sunday) -> No alerts sent.\n";
        return;
    }

    $trades = $conn->query("
        SELECT raw_trade_id, pair_name, price_target, trade_direction
        FROM prediction_trade_data
        WHERE 
            TIME(CONVERT_TZ(last_alert_time, @@session.time_zone, '+05:30'))
                BETWEEN '12:30:00' AND '21:30:00'
            AND DATE(CONVERT_TZ(last_alert_time, @@session.time_zone, '+05:30'))
                = DATE(CONVERT_TZ(NOW(), @@session.time_zone, '+05:30'))
    ");
    if (!$trades) return;

    $alertedTodayFile = sys_get_temp_dir() . '/alerted_trades_' . date('Y-m-d') . '.json';
    $alertedToday = [];
    if (file_exists($alertedTodayFile)) {
        $alertedToday = json_decode(file_get_contents($alertedTodayFile), true) ?? [];
    }

    while ($trade = $trades->fetch_assoc()) {
        $tradeId = $trade['raw_trade_id'];

        if (in_array($tradeId, $alertedToday)) continue;

        $pair = $trade['pair_name'];
        $target = (float)$trade['price_target'];
        $direction = strtoupper($trade['trade_direction']);

        // Format pair for ForexFactory API (EURUSD -> EUR/USD)
        $apiPair = (strlen($pair) === 6) ? substr($pair, 0, 3) . '/' . substr($pair, 3, 3) : $pair;

        // 1. Fetch LIVE Chart Data from ForexFactory API
        $rawBars = fetchBars(urlencode($apiPair), 5); // Fetch exactly 5 candles for the UI
        if (isset($rawBars['error']) || empty($rawBars)) continue;

        $liveCandles = formatBars($rawBars);
        if (count($liveCandles) < 5) continue; // Safety check

        // Map the new API response to the old 'o','h','l','c' format
        $candles = [];
        foreach ($liveCandles as $bar) {
            $candles[] = [
                'o' => $bar['open'],
                'h' => $bar['high'],
                'l' => $bar['low'],
                'c' => $bar['close']
            ];
        }

        // Get the absolute real-time current price (Close of the latest candle)
        $current = end($candles)['c'];
        $latestTimestamp = end($liveCandles)['time'];

        // 2. CHECK FOR NEWS OVERRIDE (Using LIVE market structure)
        $isNewsTargetAdjusted = false;
        $c3 = $candles[2];
        $c3_range = floatval($c3['h']) - floatval($c3['l']);
        $touches_half = false;

        if ($direction === 'DOWN' || $direction === 'SELL') {
            $c3_midpoint = floatval($c3['l']) + ($c3_range / 2.0);
            for($i = 3; $i < 5; $i++) {
                if (is_bullish_prox($candles[$i]['o'], $candles[$i]['c']) && floatval($candles[$i]['h']) >= $c3_midpoint) $touches_half = true;
            }
        } else {
            $c3_midpoint = floatval($c3['h']) - ($c3_range / 2.0);
            for($i = 3; $i < 5; $i++) {
                if (is_bearish_prox($candles[$i]['o'], $candles[$i]['c']) && floatval($candles[$i]['l']) <= $c3_midpoint) $touches_half = true;
            }
        }

        if (!$touches_half) {
            $nowISTStr = date('Y-m-d H:i:s');
            $plus10MinStr = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            $newsCheck = $conn->query("SELECT id FROM economic_events WHERE impact IN (2, 3) AND event_time BETWEEN '$nowISTStr' AND '$plus10MinStr' LIMIT 1");
            
            if ($newsCheck && $newsCheck->num_rows > 0) {
                $target = get_forced_prominent_wick_target($candles, $direction);
                $isNewsTargetAdjusted = true;
            }
        }

        // 3. CHECK IF LIVE PRICE IS IN ZONE
        $tolerance = $target * 0.00005;
        $upperBound = $target + $tolerance;
        $lowerBound = $target - $tolerance;

        if ($current >= $lowerBound && $current <= $upperBound) {
            global $chartlink;
            $imageSent = false;
            $noticeTag = $isNewsTargetAdjusted ? "\n\n⚠️ *ALERT: TARGET MODIFIED TO PROMINENT WICK DUE TO INCOMING NEWS!*\n_We'll rollback if it does not hit the target._" : "";
            
            $message =
            "🎯 *$pair Near Trade Zone!*\n" .
            "🆔 ID: `$tradeId`\n" .
            "💵 Current: `$current`\n" .
            "🎯 Target: `" . number_format($target, 5) . "`" .
            $noticeTag .
            "\n🔗 [View LIVE CHART](" . $chartlink . $pair . ")"; 

            $tempImg = "zone_alert_" . $tradeId . "_" . time() . ".png";
            
            // Format time for the chart (UTC -> IST handled inside draw function)
            $chartTimeUTC = gmdate('Y-m-d H:i:s', $latestTimestamp);

            // Draw the chart with the LIVE candles
            $drawRes = drawPatternChart($candles, $pair, $tradeId, $chartTimeUTC, $tempImg, $target);

            if ($drawRes === true && file_exists($tempImg)) {
                $stmtChats = $conn->query("SELECT DISTINCT chat_id FROM telegram_users");
                $chatIds = [];
                if ($stmtChats) {
                    while ($chat = $stmtChats->fetch_assoc()) {
                        $chatIds[] = $chat['chat_id'];
                    }
                }
                
                if(!empty($chatIds)){
                    sendPhotoBatchProx($botToken, $chatIds, $tempImg, $message);
                    $imageSent = true;
                }
                
                unlink($tempImg); // Cleanup
            }
            
            // Fallback: If image fails, send text
            if(!$imageSent){
                $stmtChats = $conn->query("SELECT DISTINCT chat_id FROM telegram_users");
                if (!$stmtChats) continue;

                while ($chat = $stmtChats->fetch_assoc()) {
                    $chatId = $chat['chat_id'];
                    $messageId = sendTelegramAlert($message, $chatId);
                    if ($messageId) {
                        queueMessageDeletion($chatId, $messageId, 2);
                    }
                }
            }

            // Mark this trade as alerted
            $alertedToday[] = $tradeId;
            file_put_contents($alertedTodayFile, json_encode($alertedToday));

            echo "Alert sent for trade $tradeId using live data.\n";
        }
    }
}

// --- TELEGRAM SENDING FUNCTIONS ---
// (Your existing telegram functions remain completely unchanged)

function sendTelegramAlert($message, $chatId) {
    global $botToken; 
    $url = "https://api.telegram.org/bot$botToken/sendMessage";

    $data = ['chat_id'=>$chatId, 'text'=>$message, 'parse_mode' => 'Markdown'];
    $options = ['http'=>['header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'method'=>'POST','content'=>http_build_query($data)]];
    $context = stream_context_create($options);
    $response = @file_get_contents($url,false,$context);

    $responseData = json_decode($response,true);
    if(isset($responseData['ok']) && $responseData['ok']) return $responseData['result']['message_id'];
    return false;
}

function sendPhotoBatchProx($botToken, $chatIds, $filePath, $caption) {
    global $messagesToDelete;
    if (empty($chatIds)) return;

    $firstChatId = $chatIds[0];
    $response = sendPhotoCURLProx($botToken, $firstChatId, $filePath, $caption);
    
    if ($response && isset($response['result']['message_id'])) {
        queueMessageDeletion($firstChatId, $response['result']['message_id'], 2);
    }
    
    $fileId = null;
    if ($response && isset($response['result']['photo'])) {
        $fileId = end($response['result']['photo'])['file_id'];
    }

    $rest = array_slice($chatIds, 1);

    if (!$fileId) {
        foreach ($rest as $chatId) {
            $resp = sendPhotoCURLProx($botToken, $chatId, $filePath, $caption, false);
            if ($resp && isset($resp['result']['message_id'])) {
                queueMessageDeletion($chatId, $resp['result']['message_id'], 2);
            }
        }
        return;
    }

    $batchSize = 25;
    $total = count($rest);

    for ($i = 0; $i < $total; $i += $batchSize) {
        $batch = array_slice($rest, $i, $batchSize);
        foreach ($batch as $chatId) {
            $resp = sendPhotoCURLProx($botToken, $chatId, $fileId, $caption, true); 
            if ($resp && isset($resp['result']['message_id'])) {
                queueMessageDeletion($chatId, $resp['result']['message_id'], 2);
            }
        }
        if ($i + $batchSize < $total) sleep(1);
    }
}

function sendPhotoCURLProx($botToken, $chatId, $fileSource, $caption, $isFileId = false){
    $url = "https://api.telegram.org/bot$botToken/sendPhoto";
    $post = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'Markdown'];

    if ($isFileId) {
        $post['photo'] = $fileSource;
    } else {
        $post['photo'] = new CURLFile(realpath($fileSource));
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function deleteTelegramMessage($chatId,$messageId) {
    global $botToken; 
    $url = "https://api.telegram.org/bot$botToken/deleteMessage";
    $data = ['chat_id'=>$chatId,'message_id'=>$messageId];
    $options = ['http'=>['header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'method'=>'POST','content'=>http_build_query($data)]];
    $context = stream_context_create($options);
    @file_get_contents($url,false,$context);
}

function queueMessageDeletion($chatId, $messageId, $minutes = 2) {
    $file = __DIR__ . '/delete_queue.json';
    $queue = [];

    if (file_exists($file)) {
        $queue = json_decode(file_get_contents($file), true) ?? [];
    }

    $queue[] = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'delete_at' => time() + ($minutes * 60)
    ];

    file_put_contents($file, json_encode($queue), LOCK_EX);
}

// --- CHART DRAWING FUNCTIONS ---

function drawPatternChart($candles, $pairName, $id, $time, $outputPath, $targetPrice = null) {
    if (!function_exists('imagecreatetruecolor')) return "GD Library Missing.";

    $dt = new DateTime($time, new DateTimeZone('UTC')); 
    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $istTime = $dt->format('d-M-Y h:i A'); 

    $w = 800; $h = 600; $margin = 60;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 19, 23, 34);
    $grid = imagecolorallocate($img, 42, 46, 57);
    $text = imagecolorallocate($img, 178, 181, 190);
    $green = imagecolorallocate($img, 8, 153, 129);
    $red = imagecolorallocate($img, 242, 54, 69);
    $orangeLine = imagecolorallocate($img, 255, 120, 0); 
    
    imagefill($img, 0, 0, $bg);

    $min = 999999999; $max = -999999999;
    foreach ($candles as $c) {
        if ($c['l'] < $min) $min = $c['l'];
        if ($c['h'] > $max) $max = $c['h'];
    }
    
    if ($targetPrice !== null && $targetPrice > 0) {
        if ($targetPrice > $max) $max = $targetPrice;
        if ($targetPrice < $min) $min = $targetPrice;
    }

    $pad = ($max - $min) * 0.15; if ($pad == 0) $pad = 0.001;
    $max += $pad; $min -= $pad; $range = $max - $min;

    $plotW = $w - $margin; $plotH = $h - $margin;
    for($i=0; $i<=5; $i++) {
        $y = $margin + $plotH - ($i * ($plotH/5));
        imageline($img, $margin, $y, $w, $y, $grid);
        $lbl = number_format($min + ($i*($range/5)), 3);
        imagestring($img, 5, 5, $y-7, $lbl, $text);
    }
    
    if ($targetPrice !== null && $targetPrice > 0 && $range > 0) 
    {
        $yTarget = $margin + $plotH - (($targetPrice - $min) / $range * $plotH);
        imagesetthickness($img, 2);
        imageline($img, $margin, $yTarget, $w, $yTarget, $orangeLine);
        imagesetthickness($img, 1);
        
        $targetLabel = "TARGET LEVEL: " . number_format($targetPrice, 5);
        imagestring($img, 4, $margin + 10, $yTarget - 18, $targetLabel, $orangeLine);
    }

    imagestring($img, 5, $margin, 20, "ID: $id | Pair: $pairName | Time: $istTime", $text);

    $count = count($candles); $candleW = ($plotW / $count) * 0.6; $spacing = $plotW / $count;
    foreach ($candles as $i => $c) {
        $cx = $margin + ($i * $spacing) + ($spacing/2);
        $yH = $margin + $plotH - (($c['h']-$min)/$range * $plotH);
        $yL = $margin + $plotH - (($c['l']-$min)/$range * $plotH);
        $yO = $margin + $plotH - (($c['o']-$min)/$range * $plotH);
        $yC = $margin + $plotH - (($c['c']-$min)/$range * $plotH);
        $col = ($c['c'] >= $c['o']) ? $green : $red;
        imageline($img, $cx, $yH, $cx, $yL, $col);
        imagefilledrectangle($img, $cx-$candleW/2, min($yO,$yC), $cx+$candleW/2, max($yO,$yC), $col);
    }
    imagepng($img, $outputPath);
    imagedestroy($img);
    return true;
}

// Run the price check
checkPriceLevels($conn);
?>