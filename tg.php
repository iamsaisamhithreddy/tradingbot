<?php

require 'db.php'; 
require_once 'fpdf.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$TelegramAPI = "https://api.telegram.org/bot$botToken";

// Fetch chat IDs
$chatIds = [];
$result = $conn->query("SELECT chat_id FROM telegram_users");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $chatIds[] = $row['chat_id'];
    }
}


//  TRADE SIGNAL ALERT SECTION (Updated with Horizontal Chart Level Line)

$resultTrades = $conn->query("SELECT * FROM prediction_trade_data WHERE sent_status = 0");
if ($resultTrades && $resultTrades->num_rows > 0) {
    while ($trade = $resultTrades->fetch_assoc()) {

        if (floatval($trade['price_target']) == 0) {
            continue; 
        }

        $rawPriceTarget = floatval($trade['price_target']); // Capture raw float value for drawing lines
        $directionEmoji = strtoupper($trade['trade_direction']) === 'UP' ? "🟢 UP" : "🔴 SELL";

        $caption = "📊 *New Trade Signal*\n" .
                   "🆔 *Trade ID:* `" . $trade['raw_trade_id'] . "`\n" .
                   "📄 *Pair:* `" . $trade['pair_name'] . "`\n" .
                   "💰 *Price Target:* `" . number_format($rawPriceTarget, 5) . "`\n" .
                   "📈 *Direction:* *$directionEmoji*\n\n" .
                   "🔗[View LIVE CHART](" . $chartlink . $trade['pair_name']  . ")";

        // Fetch Chart Data
        $chartData = fetchPatternById($trade['raw_trade_id']);
        $imageSent = false;

        // Generate Image if data exists
        if (is_array($chartData)) {
            $tempImg = "signal_" . $trade['raw_trade_id'] . "_" . time() . ".png";
            
            // --- PASSED RAW FLOAT TARGET AS THE 6TH PARAMETER HERE ---
            $drawRes = drawPatternChart($chartData['data'], $chartData['pair'], $trade['raw_trade_id'], $chartData['time'], $tempImg, $rawPriceTarget);

            if ($drawRes === true && file_exists($tempImg)) {
                // Send Photo to All Users
                sendPhotoBatch($botToken, $chatIds, $tempImg, $caption);
                
                // Cleanup: Delete local image
                unlink($tempImg);
                $imageSent = true;
            }
        }

        // Fallback: If chart generation failed, send text only 
        if (!$imageSent) {
            sendInBatches($botToken, $chatIds, $caption, 25, 1, false);
        }

        // Mark trade as sent
        $update = $conn->prepare("UPDATE prediction_trade_data SET sent_status = 1, last_alert_time = NOW() WHERE raw_trade_id = ?");
        $update->bind_param("s", $trade['raw_trade_id']);
        $update->execute();
        $update->close();
    }
}


//  ECONOMIC NEWS ALERT SECTION 

// AUTO-CLEAN: Only clear events older than 2 hours so price_track.php has time to use them
$cleanupOld = $conn->prepare("DELETE FROM economic_events WHERE event_time < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
$cleanupOld->execute();
$cleanupOld->close();

$nowIST = new DateTime("now", new DateTimeZone('Asia/Kolkata'));
$fiveMinutesLaterIST = clone $nowIST;
$fiveMinutesLaterIST->modify('+6 minutes');

// Only pull events that have NOT been sent yet (sent_status = 0)
$resultEvents = $conn->query("SELECT * FROM economic_events WHERE sent_status = 0");
$eligibleEvents = [];

if ($resultEvents && $resultEvents->num_rows > 0) {
    while ($event = $resultEvents->fetch_assoc()) {
        try {
            $eventTimeIST = new DateTime(
                $event['event_time'],
                new DateTimeZone('Asia/Kolkata')
            );
        } catch (Exception $e) { 
            continue; 
        }

        if ($eventTimeIST >= $nowIST && $eventTimeIST <= $fiveMinutesLaterIST) {
            $event['event_time_ist'] = $eventTimeIST;
            $eligibleEvents[] = $event;
        }
    }
}

if (!empty($eligibleEvents)) {
    $groups = [];
    foreach ($eligibleEvents as $ev) {
        $key = $ev['event_time'];
        if (!isset($groups[$key])) $groups[$key] = [];
        $groups[$key][] = $ev;
    }

    foreach ($groups as $groupKey => $groupEvents) {
        $validEvents = [];
        foreach ($groupEvents as $g) {
            $hasId = isset($g['id']) && $g['id'] !== '';
            $hasTime = isset($g['event_time']) && $g['event_time'] !== '';
            $hasName = isset($g['event_name']) && trim($g['event_name']) !== '' && strtolower(trim($g['event_name'])) !== 'na';
            $hasImpact = isset($g['impact']) && $g['impact'] !== '';

            if ($hasId && $hasTime && $hasName && $hasImpact) {
                $g['impact'] = (int)$g['impact'];
                $validEvents[] = $g;
            }
        }

        if (empty($validEvents)) continue;

        usort($validEvents, function($a, $b) {
            return (int)$b['impact'] <=> (int)$a['impact'];
        });

        $impactLabel = function($impact) {
            if ((int)$impact === 3) return "🔴 High";
            if ((int)$impact === 2) return "🟡 Medium";
            if ((int)$impact === 1) return "⚪️ Low";
            return "⚪️ Unknown";
        };

        $headerIST = $validEvents[0]['event_time_ist']->format('Y-m-d H:i');
        $messageLines = [];
        $messageLines[] = "📰 *Economic Events (IST " . $headerIST . ")*\n";

        $counter = 1;
        foreach ($validEvents as $ve) {
            $numEmoji = $counter === 1 ? "1️⃣" : ($counter === 2 ? "2️⃣" : ($counter === 3 ? "3️⃣" : $counter . "️⃣"));
            $line = $numEmoji . " " . $ve['event_name'] . " — " . $impactLabel($ve['impact']);
            $messageLines[] = $line;
            $counter++;
        }

        $top = $validEvents[0];
        $topImpact = (int)$top['impact'];

        if ($topImpact === 1) {
            $noteText = "⚪️ Low \nNOTE : Wait this 1 candle if structure is forming.\n\nTHERE SHOULD BE ATLEAST 3 CONSECUITVE SAME CANDLES (INCLUDING BREAKOUT CANDLE)";
        } elseif ($topImpact === 2) {
            $noteText = "🟡 Medium\nNOTE : Wait 2 more candles after this if structure is forming.\n\nTHERE SHOULD BE ATLEAST 3 CONSECUITVE SAME CANDLES (INCLUDING BREAKOUT CANDLE)\n\nIF we are targeting 50% level (should consider 5 candles *including news candle*)";
        } elseif ($topImpact === 3) {
            $noteText = "🔴 High\nNOTE : Wait 2 more candles after this if structure is forming.\n\nTHERE SHOULD BE ATLEAST 3 CONSECUITVE SAME CANDLES (INCLUDING BREAKOUT CANDLE)\n\nIF we are targeting 50% level (should consider 8 candles *including news candle*)";
        } else {
            $noteText = "⚪️ Unknown Impact";
        }

        $messageBody = implode("\n", $messageLines) . "\n\n" .
                       "💥 *Impact Note (from highest priority):*\n" .
                       $noteText;

        // 1. Send the messages
        sendInBatches($botToken, $chatIds, $messageBody, 25, 1, true);

        // 2. UPDATE FIX: Mark as sent instead of deleting
        $updateStmt = $conn->prepare("UPDATE economic_events SET sent_status = 1 WHERE id = ?");
        foreach ($validEvents as $toUpdate) {
            $updateStmt->bind_param("i", $toUpdate['id']);
            $updateStmt->execute();
        }
        $updateStmt->close();
    }
}

echo "Alerts sent successfully";


//  FUNCTIONS 

// Standard Text Sender
function sendInBatches($botToken, $chatIds, $message, $batchSize = 20, $delaySeconds = 1, $pin = false) {
    $total = count($chatIds);
    for ($i = 0; $i < $total; $i += $batchSize) {
        $batch = array_slice($chatIds, $i, $batchSize);
        foreach ($batch as $chatId) {
            $messageId = sendToTelegram($botToken, $chatId, $message);
            if ($pin) pinMessage($botToken, $chatId, $messageId);
        }
        if ($i + $batchSize < $total) sleep($delaySeconds);
    }
}


// Photo Batch Sender (Uploads once, uses File ID for rest)
function sendPhotoBatch($botToken, $chatIds, $filePath, $caption) {
    if (empty($chatIds)) return;

    // Send to the first user to get the File ID
    $firstChatId = $chatIds[0];
    $fileId = sendPhotoCURL($botToken, $firstChatId, $filePath, $caption);

    if (!$fileId) return;

    // Send to the rest using the File ID (Faster)
    $rest = array_slice($chatIds, 1);
    $batchSize = 25;
    $total = count($rest);

    // Loop through remaining users
    for ($i = 0; $i < $total; $i += $batchSize) {
        $batch = array_slice($rest, $i, $batchSize);
        foreach ($batch as $chatId) {
            // Pass fileId instead of filePath
            sendPhotoCURL($botToken, $chatId, $fileId, $caption, true); 
        }
        if ($i + $batchSize < $total) sleep(1);
    }
}


function sendToTelegram($botToken, $chatId, $message) {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'Markdown'];
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type:application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $response = @file_get_contents($url, false, stream_context_create($options));
    $result = $response ? json_decode($response, true) : null;
    return $result['result']['message_id'] ?? null;
}

// Handles actual Photo sending
function sendPhotoCURL($botToken, $chatId, $fileSource, $caption, $isFileId = false){
    $url = "https://api.telegram.org/bot$botToken/sendPhoto";
    
    $post = [
        'chat_id' => $chatId, 
        'caption' => $caption, 
        'parse_mode' => 'Markdown'
    ];

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

    $json = json_decode($response, true);
    
    // Return the largest file_id from the result array to reuse it
    if (isset($json['result']['photo'])) {
        $photos = $json['result']['photo'];
        return end($photos)['file_id'];
    }
    return null;
}

function pinMessage($botToken, $chatId, $messageId) {
    if (!$messageId) return;
    $url = "https://api.telegram.org/bot$botToken/pinChatMessage";
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'disable_notification' => true];
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type:application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    @file_get_contents($url, false, stream_context_create($options));
}


//  CHARTING FUNCTIONS 

function fetchPatternById($id) {
    global $conn; 
    $sql = "SELECT pair_name, created_at, O1, H1, L1, C1, O2, H2, L2, C2, O3, H3, L3, C3, O4, H4, L4, C4, O5, H5, L5, C5 FROM raw_trade_data WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    $row = [];
    $stmt->bind_result(
        $row['pair_name'], $row['created_at'],
        $row['O1'], $row['H1'], $row['L1'], $row['C1'],
        $row['O2'], $row['H2'], $row['L2'], $row['C2'],
        $row['O3'], $row['H3'], $row['L3'], $row['C3'],
        $row['O4'], $row['H4'], $row['L4'], $row['C4'],
        $row['O5'], $row['H5'], $row['L5'], $row['C5']
    );

    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();

    $candles = [];
    for ($i = 1; $i <= 5; $i++) {
        $candles[] = [
            'o' => (float)$row["O$i"], 'h' => (float)$row["H$i"],
            'l' => (float)$row["L$i"], 'c' => (float)$row["C$i"]
        ];
    }
    return ['data' => $candles, 'pair' => $row['pair_name'], 'time' => $row['created_at']];
}

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
    
    // Bright neon orange color for the Target Line level
    $orangeLine = imagecolorallocate($img, 255, 120, 0); 
    
    imagefill($img, 0, 0, $bg);

    $min = 999999999; $max = -999999999;
    foreach ($candles as $c) {
        if ($c['l'] < $min) $min = $c['l'];
        if ($c['h'] > $max) $max = $c['h'];
    }
    
    // Adapt boundaries dynamically so horizontal line stays in bounds
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
    
    // --- RENDER THE HORIZONTAL MARKING LINE ---
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
?>