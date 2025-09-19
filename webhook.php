<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; // database connection
require('fpdf.php'); // for pdf creation. 

ini_set('display_errors',1);
error_reporting(E_ALL);


$website = "https://api.telegram.org/bot$botToken"; // Telegram Bot Token fetched from db.php


// Get webhook update
$update = file_get_contents("php://input");
$update_array = json_decode($update, TRUE);

// Always respond 200 OK to Telegram
http_response_code(200);

if(isset($update_array['message'])){
    $chatId = $update_array['message']['chat']['id'];
    $firstName = $update_array['message']['from']['first_name'] ?? "User";
    $username = $update_array['message']['from']['username'] ?? "N/A";

    // Normalize text command
    $text = strtolower(trim($update_array['message']['text'] ?? ''));
    $text = explode('@', $text)[0]; // remove @BotName if exists


    // --- Check if user is authenticated ---
    $isAuthenticated = false;
    $check = $conn->prepare("SELECT id FROM telegram_users WHERE chat_id=?");
    $check->bind_param("s", $chatId);
    $check->execute();
    $check->store_result();
    if($check->num_rows > 0){
        $isAuthenticated = true;
    }
    $check->close();

    // --- Commands --- 
    if($text == '/start'){
        $msg  = "Hello, $firstName ! 😊\n\n";
        $msg .= "Thank you for using our bot, we wish you a happy trading!\n\n";
        $msg .= "🆔 Your Chat ID is: *$chatId*\n";
        $msg .= "📌 Please send your Chat ID to admin for activation. *t.me/saigaming1_owner*";
        sendMessage($chatId,$msg,true);
    }

    elseif(!$isAuthenticated){
        // Log unauthorized attempt
        $log = $conn->prepare("INSERT INTO unauthorized_attempts (chat_id, username, first_name, command) VALUES (?,?,?,?)");
        $log->bind_param("isss", $chatId, $username, $firstName, $text);
        $log->execute();
        $log->close();

        // Inform user
        sendMessage($chatId,"⚠️ You are not authorized to use this bot.\n\n📌 Please send your Chat ID ($chatId) to admin for activation.",true);

        // Optional: Alert admin about unauthorized attempt
        sendMessage($adminChatId,"🚨 Unauthorized attempt detected:\n\n🆔 Chat ID: $chatId\n👤 Name: $firstName (@$username)\n💬 Command: $text\n⏰ Time: ".date('d M Y H:i:s'),true);
    }

    elseif(strpos($text, '/trade_enquiry') === 0){
        $id = intval(substr($text, strlen('/trade_enquiry')));
        $stmt = $conn->prepare("SELECT raw_trade_id, pair_name, price_target, trade_direction, sent_status, updated_at FROM prediction_trade_data WHERE raw_trade_id=?");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        $stmt->bind_result($rid,$pair,$price,$direction,$sent_status,$updated_at);
        if($stmt->fetch()){
            $dirIcon = ($direction=='UP')?'🟢':'🔴';
            $status = ($sent_status==1)?'✅ Sent':'⌛ Pending';
            $msg = "📊 *Trade Details*\n\n";
            $msg .= "🆔 Trade ID: *$rid*\n";
            $msg .= "💱 Pair: *$pair*\n";
            $msg .= "🎯 Target: *$price*\n";
            $msg .= "📈 Direction: $dirIcon *$direction*\n";
            $msg .= "📤 Status: $status\n";
            $msg .= "⏰ Updated: ".($updated_at ?? "N/A");
        } else {
            $msg = "⚠️ No trade found with ID *$id*";
        }
        $stmt->close();
        sendMessage($chatId,$msg,true);
    }
    
    elseif($text=='/faq'){
        $msg = "THIS IS FAQ PAGE.. NEED UPDATE";
        sendMessage($chatId,$msg,true);
        
    }
    
    elseif($text=='/learn'){
        $msg = "Welcome to Trading. we'll update this page in future. ";
        sendMessage($chatId,$msg,true);
        
    }
    
    elseif($text=='/trades'){
        // --- We determined DB timestamps behave like UTC+08:00 ---
        $SOURCE_TZ = '+08:00';      
        $IST_TZ     = '+05:30';

        $tzIST = new DateTimeZone('Asia/Kolkata');
        $nowIST = new DateTime('now', $tzIST);
        $startISTdt = clone $nowIST; $startISTdt->setTime(0,0,0);
        $endISTdt   = clone $nowIST; $endISTdt->setTime(23,59,59);
        $startIST = $startISTdt->format('Y-m-d H:i:s');
        $endIST   = $endISTdt->format('Y-m-d H:i:s');
        $todayISTDate = $nowIST->format('Y-m-d');

        $sql = "
            SELECT
                raw_trade_id,
                pair_name,
                price_target,
                trade_direction,
                sent_status,
                CONVERT_TZ(last_alert_time, '$SOURCE_TZ', '$IST_TZ') AS last_alert_ist
            FROM prediction_trade_data
            WHERE last_alert_time BETWEEN
                  CONVERT_TZ('$startIST', '$IST_TZ', '$SOURCE_TZ') AND
                  CONVERT_TZ('$endIST',   '$IST_TZ', '$SOURCE_TZ')
            ORDER BY raw_trade_id ASC
        ";
        $res = $conn->query($sql);

        if($res && $res->num_rows > 0){
            $tradeCount = $res->num_rows; // Count of today's trades

            $pdf = new FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial','B',16);
            $pdf->SetTextColor(0,0,128);
            $pdf->Cell(0,10,"Today's Trades (IST) - ".$todayISTDate,0,1,'C');
            $pdf->SetFont('Arial','',12);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(0,8,"Total Trades: $tradeCount",0,1,'C');
            $pdf->Ln(5);

            // Table Header
            $pdf->SetFont('Arial','B',10);
            $pdf->SetFillColor(200,200,200);
            $pdf->SetTextColor(0,0,0);

            $headers = ['Trade ID','Pair','Target','Direction','Status','Last Alert (IST)'];
            $widths  = [25,40,30,25,25,45];

            for($i=0;$i<count($headers);$i++){
                $pdf->Cell($widths[$i],9,$headers[$i],1,0,'C',true);
            }
            $pdf->Ln();

            // Table Body
            $pdf->SetFont('Arial','',9);
            $fill = false;
            while($row = $res->fetch_assoc()){
                $status = ($row['sent_status']==1)?'Sent':'Pending';
                $dirTxt = ($row['trade_direction']==='UP') ? 'UP' : 'DOWN';
                $timeIST = date('d M Y H:i:s', strtotime($row['last_alert_ist'] ?? ''));

                $pdf->SetFillColor($fill?245:255,$fill?245:255,$fill?245:255);
                $pdf->Cell($widths[0],8,$row['raw_trade_id'],1,0,'C',true);
                $pdf->Cell($widths[1],8,$row['pair_name'],1,0,'C',true);
                $pdf->Cell($widths[2],8,$row['price_target'],1,0,'C',true);
                $pdf->Cell($widths[3],8,$dirTxt,1,0,'C',true);
                $pdf->Cell($widths[4],8,$status,1,0,'C',true);
                $pdf->Cell($widths[5],8,$timeIST,1,1,'C',true);
                $fill = !$fill;
            }

            // Footer
            $pdf->Ln(5);
            $pdf->SetFont('Arial','I',9);
            $pdf->SetTextColor(0,0,0);
            date_default_timezone_set('Asia/Kolkata');
            $pdf->Cell(0,6,"Generated on ".date('d M Y H:i:s')." IST for $firstName (@$username)",0,1,'L');

            $filePath = __DIR__."/trades_".$todayISTDate."_".$chatId.".pdf";
            $pdf->Output('F',$filePath);
            sendDocument($chatId,$filePath);
            unlink($filePath);
        } else {
            sendMessage($chatId,"⚠️ No trades found for today's IST window.",true);
        }
    }
    elseif($text=='/session'){
        // --- Forex Session Logic ---
        date_default_timezone_set("Asia/Kolkata");
        $now = new DateTime("now");
        $current_time = $now->format("H:i");
        $dayOfWeek = $now->format("l");

        $sessions = [
            "Sydney"   => ["02:30", "11:30"],
            "Tokyo"    => ["05:30", "14:30"],
            "London"   => ["12:30", "21:30"],
            "New York" => ["17:30", "02:30"],
        ];

        $active = [];
        foreach ($sessions as $name => [$start, $end]) {
            if ($start < $end) {
                if ($current_time >= $start && $current_time <= $end) {
                    $active[] = $name;
                }
            } else {
                if ($current_time >= $start || $current_time <= $end) {
                    $active[] = $name;
                }
            }
        }

        if (count($active) > 1) {
            $session = "Overlap: " . implode(" + ", $active);
        } elseif (count($active) == 1) {
            $session = $active[0];
        } else {
            $session = "No Active Session";
        }

        // Confidence
        $confidence = "Normal";
        if (strpos($session, "London") !== false && strpos($session, "New York") !== false) {
            $confidence = "Very High (London + NY Overlap)";
        } elseif (strpos($session, "London") !== false && $current_time >= "13:30") {
            $confidence = "Boosted";
        } elseif (strpos($session, "London") !== false || strpos($session, "New York") !== false) {
            $confidence = "High";
        }
        if ($current_time >= "21:30" && strpos($session, "New York") !== false) {
            $confidence = "Low (Late NY)";
        }

        // Notes
        if ($dayOfWeek === "Monday") {
            $note = "⚠️ Markets can be volatile on Monday.";
        } elseif ($dayOfWeek === "Friday" && $current_time >= "20:00") {
            $note = "⚠️ Friday late session: liquidity often drops.";
        } else {
            $note = "ℹ️ Moderate volatility expected.";
        }

        $msg  = "⏰ Current Time: *$current_time*\n";
        $msg .= "📅 Day: *$dayOfWeek*\n";
        $msg .= "🌍 Active Session(s): *$session*\n";
        $msg .= "📊 Confidence Level: *$confidence*\n";
        $msg .= "📝 Note: $note";
        sendMessage($chatId,$msg,true);
    }

    $conn->close();
}

// --- Functions ---
function sendMessage($chatId,$text,$markdown=false){
    global $website;
    $params = ['chat_id'=>$chatId,'text'=>$text];
    if($markdown) $params['parse_mode']='Markdown';
    $ch = curl_init($website.'/sendMessage');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_exec($ch);
    curl_close($ch);
}

function sendDocument($chatId,$filePath){
    global $website;
    $params = ['chat_id'=>$chatId,'document'=>new CURLFile(realpath($filePath))];
    $ch = curl_init($website.'/sendDocument');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_exec($ch);
    curl_close($ch);
}
?>
