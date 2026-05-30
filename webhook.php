<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php';
require('fpdf.php');
ini_set('display_errors',1);
error_reporting(E_ALL);

$TelegramAPI = "https://api.telegram.org/bot$botToken";
$update = file_get_contents("php://input");
$update_array = json_decode($update, TRUE);

// timezone
$userTimezone = 'Asia/Kolkata'; 

// Set the timezone and get the current hour (0-23)
date_default_timezone_set($userTimezone);
$hour = (int)date('G');

// Determining the greeting
if ($hour < 12) 
    {
        $greeting = "Good morning";
    } elseif ($hour < 17) {
        $greeting = "Good afternoon";
    } else 
    {
        $greeting = "Good evening";
    }

http_response_code(200);


if(isset($update_array['callback_query'])) 
{
    $callbackId = $update_array['callback_query']['id'];
    $chatId     = $update_array['callback_query']['message']['chat']['id'];
    $messageId  = $update_array['callback_query']['message']['message_id'];
    $data       = $update_array['callback_query']['data']; 
    
    $parts  = explode('|', $data);
    $action = $parts[0];
    $file   = $parts[1];
    $filePath = sys_get_temp_dir() . '/' . $file;

    if (!file_exists($filePath)) 
    {
        file_get_contents($TelegramAPI . "/answerCallbackQuery?callback_query_id=$callbackId&text=" . urlencode("❌ Session expired."));
        exit;
    }

    $aiText = file_get_contents($filePath);

    if ($action === 'v') 
    {
        @file_get_contents($TelegramAPI . "/sendChatAction?chat_id={$chatId}&action=record_voice");
        $chTTS = curl_init("https://api.wit.ai/synthesize?v=20240304");
    
        curl_setopt_array($chTTS, [
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_POST => true, 
            CURLOPT_POSTFIELDS => json_encode(['q' => "$greeting. this is smart trading bot ................... " . $aiText,  'voice' => 'Rebecca']),
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $witAiToken", "Content-Type: application/json", "Accept: audio/wav"]
        ]);
    
        $ttsAudio = curl_exec($chTTS); 
        curl_close($chTTS);

        if ($ttsAudio) 
        {
            $tWav = sys_get_temp_dir() . '/tts_' . uniqid() . '.wav';
            $tOgg = sys_get_temp_dir() . '/tts_' . uniqid() . '.ogg';
            
            file_put_contents($tWav, $ttsAudio);
            @chmod(__DIR__ . '/ffmpeg', 0755);
            
            // Execute FFmpeg
            shell_exec(__DIR__ . "/ffmpeg -y -i " . escapeshellarg($tWav) . " -c:a libopus " . escapeshellarg($tOgg));
            
            // Check if FFmpeg successfully created the file before using CURLFile
            if (file_exists($tOgg)) 
            {
                $postV = [
                    'chat_id' => $chatId, 
                    'voice' => new CURLFile($tOgg),
                    'caption' => "🤖 AI Voice Response"
                ];
                
                $chV = curl_init($TelegramAPI . '/sendVoice');
                curl_setopt_array($chV, [
                    CURLOPT_RETURNTRANSFER => true, 
                    CURLOPT_POST => true, 
                    CURLOPT_POSTFIELDS => $postV
                ]);
                
                curl_exec($chV); 
                curl_close($chV);
            } else {
                // Log if FFmpeg fails so you know why no audio was sent
                error_log("FFmpeg failed to create OGG file at $tOgg");
            }
            
            // Clean up temporary files
            @unlink($tWav); 
            @unlink($tOgg);
        }
    } else {
        sendMessage($chatId, "🤖 *AI Text Response:*\n\n" . $aiText, true);
    }
    
    @unlink($filePath);
    file_get_contents($TelegramAPI . "/deleteMessage?chat_id=$chatId&message_id=$messageId");
    file_get_contents($TelegramAPI . "/answerCallbackQuery?callback_query_id=$callbackId");
    exit;
}

            if(isset($update_array['message']))
{
    $chatId    = $update_array['message']['chat']['id'];
    $firstName = $update_array['message']['from']['first_name'] ?? "User";
    $username  = $update_array['message']['from']['username'] ?? "N/A";

    $isAdmin = false;

    // Check admin
    if(isset($conn))
    {
        $stmt = $conn->prepare("SELECT id FROM admin_users WHERE telegram_chat_id = ? LIMIT 1");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $stmt->store_result();

        if($stmt->num_rows > 0)
        {
            $isAdmin = true;

        }

        $stmt->close();
    }

    $text = strtolower(trim($update_array['message']['text'] ?? ''));
    $text = explode('@', $text)[0];

    $isAuthenticated = false;

// Admins bypass authorization
if($isAdmin)
{
    $isAuthenticated = true;
}
else
{
    // Normal user authentication
    $stmt = $conn->prepare("SELECT id FROM telegram_users WHERE chat_id = ? LIMIT 1");
    $stmt->bind_param("i", $chatId);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows > 0)
    {
        $isAuthenticated = true;
    }

    $stmt->close();
}

    //  PUBLIC COMMANDS

            if($text == '/start')
            {
                $msg  = "Hello, $firstName ! 😊\n\n";
                $msg .= "Thank you for using our bot, we wish you a happy trading!\n\n";
                $msg .= "🆔 Your Chat ID is: *$chatId*\n";
                $msg .= "📌 Please send your Chat ID to admin for activation. *t.me/saigaming1_owner*";
                sendMessage($chatId,$msg,true);
            }

elseif ($text === '/status') 
{
    $dbStatus  = checkDatabaseStatus() ? "✅ ONLINE" : "❌ OFFLINE";
    // Updated line below:
    $aiStatus  = checkAIStatus() ? "✅ ONLINE" : "❌ OFFLINE (Keys Disabled)";
    $webStatus = checkWebsiteStatus("$WebsiteURL") ? "✅ ONLINE" : "❌ OFFLINE";
                
                $statsSql = "SELECT 
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as total_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as total_losses,
    SUM(CASE WHEN trade_result = 'setup_not_formed' THEN 1 ELSE 0 END) as total_invalid
FROM prediction_trade_data
WHERE 1=1";

$statsResult = $conn->query($statsSql);
$stats = $statsResult->fetch_assoc();

$totalSignals = $stats['total_signals'] ?? 0;
$wins = $stats['total_wins'] ?? 0;
$losses = $stats['total_losses'] ?? 0;
$invalid = $stats['total_invalid'] ?? 0;

$validTrades = $wins + $losses;
$winRate = ($validTrades > 0) ? round(($wins / $validTrades) * 100, 2) : 0;

                
                
                $msg  = "🖥 *SYSTEM STATUS*\n\n";
                $msg .= "🗄 *Database* : $dbStatus\n";
                $msg .= "🤖 *AI Service* : $aiStatus\n";
                $msg .= "🌐 *Website* : $webStatus\n\n";
                $msg .= "⏱ Checked at: " . date('d M Y H:i:s') . " IST";
                $msg .= "\n\n📈 Win rate: $winRate"."%";    
                sendMessage($chatId, $msg, true);
            }


            elseif($text == '/admin')
            {
                $msg = "Contact admin at . *t.me/saigaming1_owner*"; 
                sendMessage($chatId,$msg,true);
            }

            // UNAUTHORIZED BLOCK

            elseif(!$isAuthenticated)
            {

                if(isset($conn)) 
                {
                    $log = $conn->prepare("INSERT INTO unauthorized_attempts (chat_id, username, first_name, command) VALUES (?,?,?,?)");
                    $log->bind_param("isss", $chatId, $username, $firstName, $text);
                    $log->execute();
                    $log->close();
                }

                sendMessage($chatId,"⚠️ You are not authorized.\nSend your Chat ID ($chatId) to admin.",true);
        
        
                // Notify Admin
                if(isset($adminChatId))
                {
                    sendMessage($adminChatId,"🚨 Unauthorized attempt\nChat ID: $chatId\nName: $firstName (@$username)\nCmd: $text",true);
                }
             exit;   
            }

            $question = "";

            // Detect Voice Input
            if(isset($update_array['message']['voice'])) 
            {
                $fileId = $update_array['message']['voice']['file_id'];
                $getFile = json_decode(file_get_contents($TelegramAPI . "/getFile?file_id=$fileId"), true);
                if ($getFile['ok']) 
                {
                    $oggPath = sys_get_temp_dir() . '/in_' . uniqid() . '.ogg';
                    $wavPath = sys_get_temp_dir() . '/in_' . uniqid() . '.wav';
            
                    $downloadUrl = "https://api.telegram.org/file/bot" . explode('bot', $TelegramAPI)[1] . "/" . $getFile['result']['file_path'];
                    file_put_contents($oggPath, file_get_contents($downloadUrl));
            
                    @chmod(__DIR__ . '/ffmpeg', 0755);
                    shell_exec(__DIR__ . "/ffmpeg -y -i ".escapeshellarg($oggPath)." -ar 16000 -ac 1 ".escapeshellarg($wavPath));
            
                    $ch = curl_init("https://api.wit.ai/speech?v=20240304");
                    
                    curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, 
                    CURLOPT_POST => true, 
                    CURLOPT_POSTFIELDS => file_get_contents($wavPath), 
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer $witAiToken", "Content-Type: audio/wav"]
                    ]);
                    
                    
                    $stt = curl_exec($ch); curl_close($ch);
                    @unlink($oggPath); @unlink($wavPath);
                    if (preg_match_all('/"text"\s*:\s*"([^"]+)"/', $stt, $m)) 
                    {
                        $question = end($m[1]);
                    }
                }
            } 

            elseif(strpos($text, '/ai') === 0) 
            {
                $question = trim(substr($text, 4)); // Skip "/ai "
            }
            
            if (!empty($question)) 
            {
                // Show typing...
                @file_get_contents($TelegramAPI . "/sendChatAction?chat_id={$chatId}&action=typing");
        
                $aiReply = callTradingAI($question, $AI_ENDPOINT);
        
                // Save reply to temp file for the callback handler at the top
                $tempFileName = 'ai_' . $chatId . '_' . time() . '.txt';
                file_put_contents(sys_get_temp_dir() . '/' . $tempFileName, $aiReply);

                $keyboard = [
                 'inline_keyboard' => [[
                        ['text' => '🎙️ Get Voice', 'callback_data' => 'v|' . $tempFileName],
                        ['text' => '✍️ Get Text', 'callback_data' => 't|' . $tempFileName]
                    ]]
                ];
        
             sendMessageWithKeyboard($chatId, "🎯 *Response Ready!*\n_Heard: \"$question\"_\n\nHow do you want to receive the answer?", $keyboard);
            }
    

            elseif ($text === '/login') 
            {

                // Check if this chat_id is an admin
                $stmt = $conn->prepare(" SELECT id FROM admin_users WHERE telegram_chat_id = ? LIMIT 1");
                $stmt->bind_param("i", $chatId);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows === 1) 
                {
                    $loginUrl = "$WebsiteURL/login.php?id=" . $chatId;
                } else 
                {
                    // ❌ Not admin 
                    $loginUrl = "$WebsiteURL/login.php";
                }

                $stmt->close();
                
                $keyboard = 
                [
                 'inline_keyboard' => 
                    [
                        [
                           [
                                'text' => '🔐 Open Admin Login',
                             'url'  => $loginUrl
                            ]
                        ]
                    ]
                ];

                sendMessageWithKeyboard($chatId,"🔐 *Admin Login*\n\nTap below to continue 👇",$keyboard);
            }


            // /dashboard → Open Trading Mini App
            elseif ($text === '/dashboard') 
            {

                $keyboard = 
                [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '📊 Open Trading Dashboard',
                                'web_app' => [
                                    'url' => "$WebsiteURL/forex.php" // ✅ FIXED: Double quotes
                                ]
                            ]
                        ]
                    ]
                ];

                sendMessageWithKeyboard($chatId,"📈 *Trading Analytics App*\n\nTap below to open the dashboard 👇",$keyboard);
            }

         elseif (strpos($text, '/trade_enquiry') === 0)
        {
            $id = intval(substr($text, strlen('/trade_enquiry')));
        
            // Added price_target field raw allocation variable to the select statement to fetch it separately from formatted string
            $stmt = $conn->prepare("SELECT raw_trade_id, pair_name, price_target, trade_direction, sent_status, trade_result, updated_at FROM prediction_trade_data WHERE raw_trade_id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->bind_result($rid, $pair, $price_raw, $direction, $sent_status, $Trade_result ,$updated_at);
            $found = $stmt->fetch();
            $stmt->close(); 

            if($found)
            {
                $price = number_format(floatval($price_raw), 5); // Format target cleanly for text description display
                $dirIcon = ($direction == 'UP') ? '🟢' : '🔴';
                $status = ($sent_status == 1) ? '✅ Sent' : '⌛ Pending';
                
                $msg = "📊 *Trade Details*\n\n";
                $msg .= "🆔 Trade ID: *$rid*\n";
                $msg .= "💱 Pair: *$pair*\n";
                $msg .= "🎯 Target: *$price*\n";
                $msg .= "📈 Direction: $dirIcon *$direction*\n";
                $msg .= "📤 Status: $status\n";
                $msg .= "\n\nTrade Result: $Trade_result\n\n";
                $msg .= "⏰ Updated: " . ($updated_at ?? "N/A");
                $msg .= "\n🔗 [View LIVE CHART](" . $chartlink . $pair . ")";
                
                // Chart Plotting
                $chartData = fetchPatternById($rid);
                if (is_array($chartData)) 
                {
                    $tempImg = "trade_" . $rid . "_" . time() . ".png";
                    
                    // --- PASSED $price_raw AS THE 6TH PARAMETER HERE ---
                    $drawRes = drawPatternChart($chartData['data'], $chartData['pair'], $rid, $chartData['time'], $tempImg, floatval($price_raw));
                    
                    if ($drawRes === true) 
                    {
                        sendPhoto($chatId, $tempImg, $msg);
                        unlink($tempImg);
                    }
                    else 
                    {
                        sendMessage($chatId, $msg . "\n\n⚠️ Chart Error: $drawRes", true);
                    }
                }
                else
                {
                    sendMessage($chatId, $msg, true);
                }

            } else 
            {
                sendMessage($chatId, "⚠️ No trade found with ID *$id*", true);
            }
        }
    
  //Generate PDF of last N trade charts
        elseif (strpos($text, '/all') === 0) 
        {

            // Parse limit: /all or /all 50
            $parts = explode(' ', $text);
            $limit = isset($parts[1]) && is_numeric($parts[1]) ? intval($parts[1]) : 50;

            if ($limit < 1)  $limit = 1;
            if ($limit > 500) $limit = 500; // prevent memory crash

            $result = $conn->query("
                SELECT raw_trade_id, pair_name,trade_result, price_target, trade_direction, updated_at
                FROM prediction_trade_data
                ORDER BY raw_trade_id DESC
                LIMIT $limit"
                );

            if (!$result || $result->num_rows === 0) 
            {
                sendMessage($chatId, "⚠️ No trades available.", true);
                exit;
            }

            $pdf = new FPDF();
            $pdf->SetAutoPageBreak(true, 15);
            $tempImages = [];
            $pdfFile = null;

            sendMessage($chatId, "📄 Generating PDF for last *$limit* trades… ⏳", true);

            try {
                
                while ($row = $result->fetch_assoc()) 
                {

                    $rid       = $row['raw_trade_id'];
                    $pair      = $row['pair_name'];
                    $Trade_Result = $row['trade_result'];
                    $rawTarget = floatval($row['price_target']); // Raw float value for drawing the line
                    $target    = number_format($rawTarget, 5);  // Formatted string for text display
                    $direction = strtoupper($row['trade_direction']) === 'DOWN' ? 'SELL' : 'BUY';
                    $displayTime = $row['updated_at'];
                    $ts = strtotime($displayTime);

                    $chartData = fetchPatternById($rid);
                    if (!is_array($chartData)) continue;

                    // Unique filename
                    $img = "trade_{$rid}_" . microtime(true) . ".png";

                    // --- PASSED RAW TARGET AS THE 6TH PARAMETER HERE ---
                    $draw = drawPatternChart($chartData['data'], $chartData['pair'], $rid, $chartData['time'], $img, $rawTarget);

                    if ($draw !== true || !file_exists($img)) continue;

                    $tempImages[] = $img;
            
                    // This is the link that will open livechart
                    $chartUrl = "$chartlink".$chartData['pair'];

                    // PDF PAGE
                    $pdf->AddPage();
            
                    // Header: Trade ID
                    $pdf->SetFont('Arial', 'B', 14);
                    $pdf->Cell(0, 10, "Trade ID: $rid", 0, 1);

                    // Details
                    $pdf->SetFont('Arial', '', 11);
                    $pdf->Cell(0, 7, "Pair: $pair", 0, 1);
                    $pdf->Cell(0, 7, "Target: $target", 0, 1);
                    $pdf->Cell(0, 7, "Direction: $direction", 0, 1);
                    $pdf->Cell(0, 7, "Trade Result: $Trade_Result", 0, 1);
                    
            
                    // --- ADD CLICKABLE LINK ---
                    $pdf->SetFont('Arial', 'B', 11);
                    $pdf->SetTextColor(0, 0, 255); // Blue color for link
                    $pdf->Cell(0, 8, "VIEW INTERACTIVE CHART (Click Here)", 0, 1, 'L', false, $chartUrl);
                    $pdf->SetTextColor(0, 0, 0); // Reset color to black
                    $pdf->Ln(2);

                    // Chart Image
                    $pdf->Image($img, 10, $pdf->GetY(), 190);
                }

                    if (count($tempImages) === 0) 
                    {
                        sendMessage($chatId, "⚠️ Charts could not be generated.", true);
                        exit;
                    }

                    $pdfFile = "ALL_TRADES_LAST_{$limit}_" . date('Ymd_His') . ".pdf";
                    $pdf->Output('F', $pdfFile);

                    sendDocument($chatId, $pdfFile, "📄 Last $limit Trade Charts");

            } finally 
            {
                // CLEANUP
                foreach ($tempImages as $img) 
                {
                    if (file_exists($img)) unlink($img);
                }
                    if ($pdfFile && file_exists($pdfFile)) 
                    {
                        unlink($pdfFile);
                    }
                }
            }
    
        
            elseif($text == '/news') 
            {

                $todayISTDate = date('Y-m-d');
        
                /** * SQL Logic:
                * 1. DATE_ADD adds 10h 30m to your DB time to convert it to IST for the PDF.
                * 2. The WHERE clause ensures we only pick events that fall within today's IST 24-hour window.
                */
         
                $sql = "SELECT id, event_name, impact, event_time AS ist_time, sent_status 
                FROM economic_events WHERE event_time BETWEEN '$todayISTDate 00:00:00' AND '$todayISTDate 23:59:59'
                ORDER BY ist_time ASC, impact DESC, id ASC";
                
                $res = $conn->query($sql);

                if($res && $res->num_rows > 0) 
                {
                    $newsCount = $res->num_rows;
            
                    // Initialize FPDF
                    $pdf = new FPDF();
                    $pdf->AddPage();
            
                    // Header Section
                    $pdf->SetFont('Arial','B',16);
                    $pdf->SetTextColor(150, 0, 0); // Dark Red
                    $pdf->Cell(0, 10, "Daily Economic Calendar (IST)", 0, 1, 'C');
            
                    $pdf->SetFont('Arial', '', 11);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Cell(0, 7, "Date: " . date('d M Y', strtotime($todayISTDate)), 0, 1, 'C');
                    $pdf->Cell(0, 7, "Total Events: $newsCount", 0, 1, 'C');
                    $pdf->Ln(5);

                    // Table Header
                    $pdf->SetFont('Arial', 'B', 10);
                    $pdf->SetFillColor(220, 220, 220);
                    $headers = ['ID', 'Event Name', 'Impact', 'Time (IST)', 'Status'];
                    $widths  = [15, 80, 25, 40, 30];

                    for($i=0; $i<count($headers); $i++) 
                    {
                        $pdf->Cell($widths[$i], 9, $headers[$i], 1, 0, 'C', true);
                    }
                     $pdf->Ln();

                    // Table Data
                    $pdf->SetFont('Arial', '', 9);
                    $fill = false;
                    while($row = $res->fetch_assoc()) 
                    {
                        // Convert impact numbers to readable text if necessary
                        $impactLabel = $row['impact'];
                        if($impactLabel == '3') $impactLabel = "HIGH";
                        if($impactLabel == '2') $impactLabel = "MEDIUM";
                        if($impactLabel == '1') $impactLabel = "LOW";

                        $statusText = ($row['sent_status'] == 1) ? 'SENT' : 'PENDING';
                        $timeFormatted = date('h:i A', strtotime($row['ist_time'])); // Format: 05:30 PM

                        // Alternating row colors
                        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                
                        $pdf->Cell($widths[0], 8, $row['id'], 1, 0, 'C', true);
                        $pdf->Cell($widths[1], 8, " " . $row['event_name'], 1, 0, 'L', true);
                
                        // Color code the Impact text
                        if($row['impact'] == '3') $pdf->SetTextColor(200, 0, 0); // Red for High
                        else $pdf->SetTextColor(0, 0, 0);
                
                        $pdf->Cell($widths[2], 8, $impactLabel, 1, 0, 'C', true);
                        $pdf->SetTextColor(0, 0, 0); // Reset to black
                
                        $pdf->Cell($widths[3], 8, $timeFormatted, 1, 0, 'C', true);
                        $pdf->Cell($widths[4], 8, $statusText, 1, 1, 'C', true);
                
                        $fill = !$fill;
                    }

                $pdf->Ln(10);
                $pdf->SetFont('Arial', 'I', 8);
                $pdf->Cell(0, 5, "Report generated at: " . date('d-m-Y H:i:s') . " IST", 0, 1, 'R');

                // Save and Send File
                $fileName = "News_Report_" . $todayISTDate . "_" . $chatId . ".pdf";
                $filePath = __DIR__ . "/" . $fileName;
            
                $pdf->Output('F', $filePath);
                sendDocument($chatId, $filePath);
            
                // Cleanup
                if(file_exists($filePath)) 
                {
                    unlink($filePath);
                }
            
            } else 
            {
            sendMessage($chatId, "⚠️ No economic events found in the database for today ($todayISTDate).", true);
            }
        }
    
        // /trades (PDF)
        elseif ($text == '/trades') 
        {

            $tzIST = new DateTimeZone('Asia/Kolkata');
            $tzUTC = new DateTimeZone('UTC');
            $nowIST = new DateTime('now', $tzIST);

            // IST day window
            $startIST = (clone $nowIST)->setTime(0, 0, 0);
            $endIST   = (clone $nowIST)->setTime(23, 59, 59);

            // Convert IST → UTC for DB filtering
            $startUTC = (clone $startIST)->setTimezone($tzUTC)->format('Y-m-d H:i:s');
            $endUTC   = (clone $endIST)->setTimezone($tzUTC)->format('Y-m-d H:i:s');

            $todayISTDate = $nowIST->format('Y-m-d');


            $sql = "SELECT raw_trade_id, pair_name, price_target, trade_direction, updated_at,
            CONVERT_TZ(last_alert_time, '+00:00', '+05:30') AS last_alert_ist
            FROM prediction_trade_data WHERE last_alert_time BETWEEN '$startUTC' AND '$endUTC'
            ORDER BY raw_trade_id ASC";

            $res = $conn->query($sql);

            if ($res && $res->num_rows > 0) 
            {

                $tradeCount = $res->num_rows;

                // PDF CREATION
                $pdf = new FPDF();
                $pdf->AddPage();
                $pdf->SetFont('Arial', 'B', 16);
                $pdf->SetTextColor(0, 0, 128);
                $pdf->Cell(0, 10, "Today's Trades (IST) - " . $todayISTDate, 0, 1, 'C');

                $pdf->SetFont('Arial', '', 12);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Cell(0, 8, "Total Trades: $tradeCount", 0, 1, 'C');
                $pdf->Ln(5);

                // Table header
                $pdf->SetFont('Arial', 'B', 10);
                $pdf->SetFillColor(200, 200, 200);
                
                // Changed "Status" to "Chart"
                $headers = ['Trade ID','Pair','Target','Direction','Chart','Last Alert (IST)'];
                $widths  = [25,35,30,25,30,45];

                foreach ($headers as $i => $h) 
                {
                    $pdf->Cell($widths[$i], 9, $h, 1, 0, 'C', true);
                }
                $pdf->Ln();

                // Table rows
                $pdf->SetFont('Arial', '', 9);
                $fill = false;

                while ($row = $res->fetch_assoc()) 
                {

                    $rid       = $row['raw_trade_id'];
                    $pair      = $row['pair_name'];
                    $target    = number_format($row['price_target'], 5);
                    $direction = strtoupper($row['trade_direction']) === 'DOWN' ? 'SELL' : 'BUY';
                    $displayTime = $row['updated_at'];
                    $ts = strtotime($displayTime);
                    $timeIST = date('d M Y H:i:s', strtotime($row['last_alert_ist']));

                    // --- URL GENERATION (From Code 1) ---
                    $fileName = "FX_" . $pair . ".csv";
                    $datasetPath = "dataset/" . $fileName; 
                    $chartUrl = "$WebsiteURL/trail/plot.php?goto={$ts}&file=" . urlencode($datasetPath) . "&id=" . urlencode($rid) . "&pair=" . urlencode($pair) . "&time=" . urlencode($displayTime) . "&price=" . urlencode($target) . "&dir=" . urlencode($direction);

                    $pdf->SetFillColor($fill ? 245 : 255);
                    $pdf->SetTextColor(0, 0, 0); // Reset to black for standard cells
            
                    $pdf->Cell($widths[0], 8, $rid, 1, 0, 'C', true);
                    $pdf->Cell($widths[1], 8, $pair, 1, 0, 'C', true);
                    $pdf->Cell($widths[2], 8, $target, 1, 0, 'C', true);
                    $pdf->Cell($widths[3], 8, $direction, 1, 0, 'C', true);
            
                    // --- CLICKABLE CHART LINK CELL ---
                    $pdf->SetTextColor(0, 0, 255); // Blue for link
                    $pdf->SetFont('Arial', 'U', 9); // Underlined
                    $pdf->Cell($widths[4], 8, "VIEW", 1, 0, 'C', true, $chartUrl);
            
                    // Reset font and color for the last cell
                    $pdf->SetFont('Arial', '', 9);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Cell($widths[5], 8, $timeIST, 1, 1, 'C', true);

                    $fill = !$fill;
                }

                // Footer
                $pdf->Ln(5);
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 6, "Generated on " . date('d M Y H:i:s') . " IST for $firstName (@$username)", 0, 1, 'L');

                // Send PDF
                $filePath = __DIR__ . "/trades_" . $todayISTDate . "_" . $chatId . ".pdf";
                $pdf->Output('F', $filePath);
                sendDocument($chatId, $filePath);
                unlink($filePath);

            } else 
            {
                sendMessage($chatId, "⚠️ No trades found for today's IST window.", true);
            }
        }
        
        elseif($text=='/faq')
        {
            sendMessage($chatId,"FAQ page – coming soon.",true);
        }

        elseif (strpos($text, '/swing') === 0) 
        {
            $parts = explode(" ", $text);
            
            if (count($parts) == 3 && is_numeric($parts[1]) && is_numeric($parts[2])) 
            {
                $openingValue = floatval($parts[1]);
                $candleHL = floatval($parts[2]);
                
                $swing = (sqrt($openingValue) / 40) * $candleHL;
                $swing = round($swing, 2);

                $reply = "📊 Opening value: *$openingValue*\n";
                $reply .= "⏱ Last 5 mins candle (H - L): *$candleHL*\n";
                $reply .= "⚡ Swing calculation: *$swing*";
            } 
            else 
            {
                $reply = "❌ Invalid format.\nUsage: `/swing <opening_value> <candle_HL>`\nExample: `/swing 57559 49`";
            }
            
            sendMessage($chatId, $reply, true);
        }

        elseif($text=='/session')
        {
            $now = new DateTime("now");
            $current_time = $now->format("H:i");
            $dayOfWeek = $now->format("l");

            $sessions = 
            [
            "Sydney"   => ["02:30", "11:30"],
            "Tokyo"    => ["05:30", "14:30"],
            "London"   => ["12:30", "21:30"],
            "New York" => ["17:30", "02:30"],
            ];

            $active = [];
            foreach ($sessions as $name => [$start, $end]) 
            {
                if ($start < $end) 
                {
                    if ($current_time >= $start && $current_time <= $end) $active[] = $name;
                } else 
                {
                if ($current_time >= $start || $current_time <= $end) $active[] = $name;
                }
            }

            if (count($active) > 1) $session = "Overlap: " . implode(" + ", $active);
            elseif (count($active) == 1) $session = $active[0];
            else $session = "No Active Session";

            $confidence = "Normal";
            if (strpos($session, "London") !== false && strpos($session, "New York") !== false) $confidence = "Very High (London + NY Overlap)";
            elseif (strpos($session, "London") !== false && $current_time >= "13:30") $confidence = "Boosted";
            elseif (strpos($session, "London") !== false || strpos($session, "New York") !== false) $confidence = "High";
            if ($current_time >= "21:30" && strpos($session, "New York") !== false) $confidence = "Low (Late NY)";

            if ($dayOfWeek === "Monday") $note = "⚠️ Markets can be volatile on Monday.";
            elseif ($dayOfWeek === "Friday" && $current_time >= "20:00") $note = "⚠️ Friday late session: liquidity often drops.";
            else $note = "ℹ️ Moderate volatility expected.";

            $msg  = "⏰ Current Time: *$current_time*\n";
            $msg .= "📅 Day: *$dayOfWeek*\n";
            $msg .= "🌍 Active Session(s): *$session*\n";
            $msg .= "📊 Confidence Level: *$confidence*\n";
            $msg .= "📝 Note: $note";
            sendMessage($chatId,$msg,true);
        }
    
        if(isset($conn)) $conn->close();
    }


// FUNCTIONS

function sendPhotoByURL($chatId, $url, $caption="")
{
    global $TelegramAPI;

    $post = 
    [
        'chat_id' => $chatId,
        'photo'   => $url,
        'caption' => $caption,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($TelegramAPI . '/sendPhoto');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}


function sendMessageReturnId($chatId, $text, $markdown=false)
{
    global $TelegramAPI;

    $params = 
    [
        'chat_id' => $chatId,
        'text' => $text
    ];

    if($markdown) $params['parse_mode'] = 'Markdown';

    $ch = curl_init($TelegramAPI.'/sendMessage');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);

    $res = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    return $json['result']['message_id'] ?? null;
}


function editMessage($chatId, $messageId, $text)
{
    global $TelegramAPI;
    
    $params = 
    [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($TelegramAPI.'/editMessageText');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_exec($ch);
    curl_close($ch);
}


function deleteMessage($chatId, $messageId)
{
    global $TelegramAPI;

    $params = 
    [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];

    $ch = curl_init($TelegramAPI.'/deleteMessage');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_exec($ch);
    curl_close($ch);
}


function checkDatabaseStatus() 
{
    global $conn;
    if (!isset($conn)) return false;
    return ($conn->ping());
}


function checkWebsiteStatus($url) 
{
    $ch = curl_init($url);
    curl_setopt_array($ch, 
    [
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 400);
}


function checkAIStatus() 
{
    global $conn;
    if (!isset($conn)) return false;

    // Check if there is at least one active API key in the database
    $keyCheck = $conn->query("SELECT id FROM api_keys WHERE status = 'active' LIMIT 1");
    if ($keyCheck && $keyCheck->num_rows > 0) {
        return true;
    }
    return false;
}


function callLearnAI($endpoint) 
{

    $prompt = " You are a professional trading educator.

        Your task:
            - Choose ONE random but useful trading-related topic by yourself.
            - Topic can be about ALL macroeconomics, institutions, INTEREST RATES GLOBAL POLITICS AFFECTS ON COMMODITIES ,psychology, liquidity, riskor market behavior,ETC...
            - if possible give suggestions for BINARY TRADING AS WELL

        Rules:
            - Educational only
            - If possible send links as well 
            - No instructions to trade
            - No prices or levels
            - Max 1000 words
            - Clear and practical tone humanizied text only...

        Format exactly like this:
        Title: <short title>
        Explanation: <content>";

    $post = 
    [
        'source'   => 'learn',
        'question' => $prompt
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, 
    [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 20
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) 
    {
        return false;
    }

    $json = json_decode($res, true);
    return $json['reply'] ?? false;
}


function sendMessageWithKeyboard($chatId, $text, $keyboard) 
{
    global $TelegramAPI;

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ];

    $ch = curl_init($TelegramAPI . '/sendMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}


function callTradingAI($question, $endpoint)
{
    $post = ['source' => 'telegram', 'question' => $question];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch,
    [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 20
    ]);
    
    $res = curl_exec($ch);
    curl_close($ch);
    if(!$res) return "⚠️ AI service unavailable.";
    $json = json_decode($res,true);
    return $json['reply'] ?? "⚠️ AI error.";
}


function sendMessage($chatId,$text,$markdown=false)
{
    global $TelegramAPI;
    $params = ['chat_id'=>$chatId,'text'=>$text];
    if($markdown) $params['parse_mode']='Markdown';
    $ch = curl_init($TelegramAPI.'/sendMessage');
    
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_exec($ch);
    curl_close($ch);
}


function sendPhoto($chatId, $filePath, $caption)
{
    global $TelegramAPI;
    $cfile = new CURLFile(realpath($filePath), 'image/png', 'chart.png');
    $post = ['chat_id' => $chatId, 'photo' => $cfile, 'caption' => $caption, 'parse_mode' => 'Markdown'];
    $ch = curl_init($TelegramAPI.'/sendPhoto');
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}


function sendDocument($chatId, $filePath)
{
    global $TelegramAPI;
    $cfile = new CURLFile(realpath($filePath));
    $post = ['chat_id' => $chatId, 'document' => $cfile];
    $ch = curl_init($TelegramAPI.'/sendDocument');
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}


function fetchPatternById($id) 
{
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

    if (!$stmt->fetch()) 
    {
        $stmt->close();
        return null;
    }
    
    $stmt->close();

    $candles = [];
    for ($i = 1; $i <= 5; $i++) 
    {
        $candles[] = 
        [
            'o' => (float)$row["O$i"], 'h' => (float)$row["H$i"],
            'l' => (float)$row["L$i"], 'c' => (float)$row["C$i"]
        ];
    }
    
    return ['data' => $candles, 'pair' => $row['pair_name'], 'time' => $row['created_at']];
}


function drawPatternChart($candles, $pairName, $id, $time, $outputPath, $targetPrice = null) 
{
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
    foreach ($candles as $c) 
    {
        if ($c['l'] < $min) 
            $min = $c['l'];
            
        if ($c['h'] > $max) 
            $max = $c['h'];
    }
    
    // If a target price is provided, adapt the chart's max/min scale 
    // so the target line is never cut off the top or bottom of the image
    if ($targetPrice !== null) {
        if ($targetPrice > $max) $max = $targetPrice;
        if ($targetPrice < $min) $min = $targetPrice;
    }

    $pad = ($max - $min) * 0.15; 
    
    if ($pad == 0) 
        $pad = 0.001;
        
    $max += $pad; 
    $min -= $pad; 
    $range = $max - $min;

    $plotW = $w - $margin;
    $plotH = $h - $margin;
    
    // Draw Grid and Price Labels
    for($i=0; $i<=5; $i++) 
    {
        $y = $margin + $plotH - ($i * ($plotH/5));
        imageline($img, $margin, $y, $w, $y, $grid);
        
        $lbl = number_format($min + ($i*($range/5)), 3);
        imagestring($img, 5, 5, $y-7, $lbl, $text);
    }
    
    // DRAW THE HORIZONTAL TARGET LEVEL LINE
    if ($targetPrice !== null && $range > 0) 
    {
        // Convert the target price to the exact pixel coordinate on the Y-axis
        $yTarget = $margin + $plotH - (($targetPrice - $min) / $range * $plotH);
        
        // Increase line thickness for better visibility
        imagesetthickness($img, 2);
        
        // Draw the horizontal line from left margin edge to the right edge of the chart
        imageline($img, $margin, $yTarget, $w, $yTarget, $orangeLine);
        
        // Reset line thickness back to default 
        imagesetthickness($img, 1);
        
        // Add a text label right above the level line
        $targetLabel = "TARGET LEVEL: " . number_format($targetPrice, 5);
        imagestring($img, 4, $margin + 10, $yTarget - 18, $targetLabel, $orangeLine);
    }
    
    imagestring($img, 5, $margin, 20, "ID: $id | Pair: $pairName | Time: $time", $text);

    $count = count($candles); 
    $candleW = ($plotW / $count) * 0.6; 
    $spacing = $plotW / $count;
    
    // Draw Candlesticks
    foreach ($candles as $i => $c) 
    {
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