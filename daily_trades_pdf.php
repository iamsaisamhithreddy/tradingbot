<?php
require('fpdf.php'); 
require 'db.php'; 

ini_set('display_errors', 1);
error_reporting(E_ALL);

$website = "https://api.telegram.org/bot$botToken";

// Fetch all users 
$resChats = $conn->query("SELECT chat_id FROM telegram_users");
$chatIds = [];
while($row = $resChats->fetch_assoc()){
    $chatIds[] = $row['chat_id'];
}

// --- Trades Query ---
$SOURCE_TZ = '+00:00'; 
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
        trade_result,
        updated_at,
        CONVERT_TZ(updated_at, '$SOURCE_TZ', '$IST_TZ') AS updated_ist
    FROM prediction_trade_data
    WHERE updated_at BETWEEN
          CONVERT_TZ('$startIST', '$IST_TZ', '$SOURCE_TZ') AND
          CONVERT_TZ('$endIST',   '$IST_TZ', '$SOURCE_TZ')
          AND DATE(last_alert_time) = DATE(updated_at)
    ORDER BY raw_trade_id ASC
";
$res = $conn->query($sql);

if($res && $res->num_rows > 0){
    $pdf = new FPDF('P', 'mm', 'A4'); // Ensure A4 Portrait
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',16);
    $pdf->SetTextColor(0,0,128);
    $pdf->Cell(0,10,"Today's Trades (IST) - ".$todayISTDate,0,1,'C');
    $pdf->Ln(5);

    // Table Header
    $pdf->SetFont('Arial','B',9); // Slightly smaller font to fit 7 columns
    $pdf->SetFillColor(200,200,200);
    $pdf->SetTextColor(0,0,0);

    // Columns: ID, Pair, Target, Dir, Chart, Time, Status (7 cols)
    $headers = ['ID','Pair','Target','Dir','Chart','Time (IST)','Status'];
    $widths  = [12, 22, 22, 15, 15, 45, 45]; // Total 156mm, fits A4 comfortably

    for($i=0; $i<count($headers); $i++){
        $pdf->Cell($widths[$i], 9, $headers[$i], 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Table Body
    $pdf->SetFont('Arial','',8);
    $fill = false;
    while($row = $res->fetch_assoc()){
        
        $rid       = $row['raw_trade_id'];
        $pair      = $row['pair_name'];
        $target    = number_format((float)$row['price_target'], 4);
        $status    = strtoupper($row['trade_result']);
        $direction = strtoupper($row['trade_direction']) === 'DOWN' ? 'SELL' : 'BUY';
        $displayTime = $row['updated_at'];
        $ts = strtotime($displayTime);
        $timeIST = date('d M Y H:i:s', strtotime($row['updated_ist'] ?? ''));

        // URL GENERATION 
        $chartUrl = $chartlink.$row['pair_name'];
        
        $pdf->SetFillColor($fill?245:255);
        $pdf->SetTextColor(0,0,0); 

        $pdf->Cell($widths[0], 8, $rid, 1, 0, 'C', true);
        $pdf->Cell($widths[1], 8, $pair, 1, 0, 'C', true);
        $pdf->Cell($widths[2], 8, $target, 1, 0, 'C', true);
        $pdf->Cell($widths[3], 8, $direction, 1, 0, 'C', true);
        
        // CLICKABLE CHART LINK
        $pdf->SetTextColor(0,0,255); 
        $pdf->SetFont('Arial','U',8);
        $pdf->Cell($widths[4], 8, "VIEW", 1, 0, 'C', true, $chartUrl);
        
        // Time Cell
        $pdf->SetFont('Arial','',8);
        $pdf->SetTextColor(0,0,0);
        $pdf->Cell($widths[5], 8, $timeIST, 1, 0, 'C', true);
        
        // Status Cell
        $pdf->Cell($widths[6], 8, $status, 1, 1, 'C', true); // 1 here ends the row
        
        $fill = !$fill;
    }

    // Footer
    $pdf->Ln(5);
    $pdf->SetFont('Arial','I',9);
    date_default_timezone_set('Asia/Kolkata');
    $pdf->Cell(0,6,"Generated on ".date('d M Y H:i:s')." IST",0,1,'L');

    $filePath = __DIR__."/trades_".$todayISTDate.".pdf";
    $pdf->Output('F', $filePath);

    // --- Send to all users ---
    foreach($chatIds as $chatId){
        sendDocument($chatId, $filePath, "📊 Daily Trades Report (IST)");
    }

    if(file_exists($filePath)) unlink($filePath);

} else {
    foreach($chatIds as $chatId){
        sendMessage($chatId,"❌ No trades found for today's IST window.");
    }
}

$conn->close();

// Functions 
function sendMessage($chatId, $text){
    global $website;
    $params = ['chat_id'=>$chatId, 'text'=>$text];
    $ch = curl_init($website.'/sendMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function sendDocument($chatId, $filePath, $caption=''){
    global $website;
    if(!file_exists($filePath)) return;
    $params = [
        'chat_id'  => $chatId,
        'document' => new CURLFile($filePath),
        'caption'  => $caption
    ];
    $ch = curl_init($website.'/sendDocument');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}
?>