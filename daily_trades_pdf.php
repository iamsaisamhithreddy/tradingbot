<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require('fpdf.php'); // for pdf creation. 
require 'db.php'; // database connection

ini_set('display_errors',1);
error_reporting(E_ALL);

$website = "https://api.telegram.org/bot$botToken";


// get users chat ids 
$resChats = $conn->query("SELECT chat_id FROM telegram_users");
$chatIds = [];
while($row = $resChats->fetch_assoc()){
    $chatIds[] = $row['chat_id'];
}

// --- Trades Query (convert to IST) ---
$SOURCE_TZ = '+08:00'; // DB timezone
$IST_TZ    = '+05:30';

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
        CONVERT_TZ(updated_at, '$SOURCE_TZ', '$IST_TZ') AS updated_ist
    FROM prediction_trade_data
    WHERE updated_at BETWEEN
          CONVERT_TZ('$startIST', '$IST_TZ', '$SOURCE_TZ') AND
          CONVERT_TZ('$endIST',   '$IST_TZ', '$SOURCE_TZ')
    ORDER BY updated_at ASC
";
$res = $conn->query($sql);

if($res && $res->num_rows > 0){
    // --- Build PDF ---
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',16);
    $pdf->SetTextColor(0,0,128);
    $pdf->Cell(0,10,"Today's Trades (IST) - ".$todayISTDate,0,1,'C');
    $pdf->Ln(5);

    // Table Header
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(200,200,200);
    $pdf->SetTextColor(0,0,0);

    $headers = ['Trade ID','Pair','Target','Direction','Status','Updated (IST)'];
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
        $timeIST = date('d M Y H:i:s', strtotime($row['updated_ist'] ?? ''));

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
    $pdf->Cell(0,6,"Generated on ".date('d M Y H:i:s')." IST",0,1,'L');

    $filePath = __DIR__."/trades_".$todayISTDate.".pdf"; // file name will be with date. 
    $pdf->Output('F',$filePath);

    // Debug log
    if(file_exists($filePath)){
        error_log("✅ PDF created: ".$filePath);
    } else {
        error_log("❌ PDF not created at ".$filePath);
    }

    // --- Send to all users ---
    foreach($chatIds as $chatId){
        sendDocument($chatId,$filePath,"📊 Daily Trades Report (IST)");
    }

    // Delete only if file exists
    if(file_exists($filePath)){
        unlink($filePath);
    }

} else {
    foreach($chatIds as $chatId){
        sendMessage($chatId,"⚠️ No trades found for today's IST window.");
    }
}

$conn->close();

// --- Functions ---
function sendMessage($chatId,$text){
    global $website;
    $params = ['chat_id'=>$chatId,'text'=>$text];
    $ch = curl_init($website.'/sendMessage');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    $resp = curl_exec($ch);
    curl_close($ch);
    error_log("📨 Sent message to $chatId: $text");
}

function sendDocument($chatId,$filePath,$caption=''){
    global $website;
    if(!file_exists($filePath)){
        sendMessage($chatId, "⚠️ Report file missing, could not send.");
        error_log("❌ File missing when trying to send to $chatId: $filePath");
        return;
    }
    $params = [
        'chat_id'=>$chatId,
        'document'=>new CURLFile($filePath),
        'caption'=>$caption
    ];
    $ch = curl_init($website.'/sendDocument');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$params);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    $resp = curl_exec($ch);
    curl_close($ch);
    error_log("📄 Sent PDF to $chatId: $filePath");
}
?>
