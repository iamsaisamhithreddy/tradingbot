<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require('fpdf.php'); // for pdf creation. 
require 'db.php'; // databse connection

ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata'); // IST

$website = "https://api.telegram.org/bot$botToken";

// Get chat IDs of users
$chatIds = [];
$resChats = $conn->query("SELECT chat_id FROM telegram_users");
if($resChats){
    while($row = $resChats->fetch_assoc()){
        $chatIds[] = $row['chat_id'];
    }
}


echo "📌 Found chat IDs: "; // displays all chat ids of users from table.
print_r($chatIds);

// Today's economic events
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT event_name, impact, event_time 
                        FROM economic_events 
                        WHERE DATE(event_time)=? 
                        ORDER BY event_time ASC");
$stmt->bind_param("s", $today);
$stmt->execute();
$stmt->bind_result($event_name, $impact, $event_time);

$events = [];
while ($stmt->fetch()) {
    $events[] = [
        'event_name' => $event_name,
        'impact'     => $impact,
        'event_time' => $event_time
    ];
}
$stmt->close();

echo "<br>📊 Events fetched: ";
print_r($events);

// creaating NEWS pdf. 
if($events){
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',16);
    $pdf->SetTextColor(0,0,128);
    $pdf->Cell(0,10,'Economic Calendar - '.date('d M Y'),0,1,'C');
    $pdf->Ln(10);
    $pdf->Cell(0,5,'* Time is in GMT-4 Hrs.',0,1);
    $pdf->Ln(15);

    // Table Header
    $pdf->SetFont('Arial','B',12);
    $pdf->SetFillColor(200,200,200);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(30,10,'Time',1,0,'C',true);
    $pdf->Cell(30,10,'Impact',1,0,'C',true);
    $pdf->Cell(0,10,'Event',1,1,'C',true);

    $pdf->SetFont('Arial','',11);
    $fill=false;

    foreach($events as $row){
        $time = date('H:i', strtotime($row['event_time']));
        $impactVal = intval($row['impact']);
        // Impact color
        switch($impactVal){
            case 1: $pdf->SetTextColor(0,128,0); break;   // Green for low impact news
            case 2: $pdf->SetTextColor(255,140,0); break; // Dark Orange for medium impcat news
            case 3: $pdf->SetTextColor(255,0,0); break;   // Red for high impact news such as interest rates, CPI etc..
            default: $pdf->SetTextColor(0,0,0);
        }
        $impactText = "Level $impactVal";

        // Alternating row colors
        $pdf->SetFillColor($fill?240:255,$fill?240:255,$fill?240:255);
        $pdf->Cell(30,8,$time,1,0,'C',true);
        $pdf->Cell(30,8,$impactText,1,0,'C',true);
        $pdf->Cell(0,8,utf8_decode($row['event_name']),1,1,'L',true);
        $fill = !$fill;
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial','I',10);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(0,6,"Auto Sent | Time: ".date('d M Y H:i:s').' IST',0,1,'L');

    $filePath = __DIR__."/news_".$today."_auto.pdf"; // file name with date 
    $pdf->Output('F',$filePath);
    echo "<br>✅ PDF created at: $filePath<br>";

    // Send to all users with caption
    foreach($chatIds as $chatId){
        echo "<br>📤 Sending PDF to chat_id: $chatId<br>";
        sendDocument($chatId,$filePath,"Today's Economic Calendar 📅"); // caption is attached with file 
    }

    unlink($filePath);
    echo "<br>🗑️ Temp PDF deleted<br>";
} else {
    echo "<br>⚠️ No events found for today ($today)";
}

$conn->close();

// Functions


function sendDocument($chatId,$filePath,$caption=''){
    global $website;
    $params = [
        'chat_id'=>$chatId,
        'document'=>new CURLFile(realpath($filePath)),
        'caption'=>$caption,
        'parse_mode'=>'Markdown'
    ];

    $ch = curl_init($website.'/sendDocument');

    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        echo "❌ CURL Error: ".curl_error($ch)."<br>";
    }

    curl_close($ch);
    echo "📨 Telegram response: $response<br>"; // this whill show response recieved from telegram API. 
}
?>
