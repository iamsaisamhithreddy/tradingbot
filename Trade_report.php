<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy


session_start();

// ✅ Restrict to admins only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php"); // redirect to login page
    exit;
}

require('fpdf.php'); // for pdf creation. 
require 'db.php'; // database connection

class PDF extends FPDF {
    public $headers = ['Trade ID','Pair','Target','Direction','Last Alert','Session'];
    public $widths  = [25,40,30,25,40,40];

    // Table header on new page
    function Header() {
        if($this->PageNo() > 1) {
            $this->SetFont('Arial','B',10);
            $this->SetTextColor(0,0,0);
            $this->SetFillColor(200,200,200);
            foreach($this->headers as $i => $h){
                $this->Cell($this->widths[$i],9,$h,1,0,'C',true);
            }
            $this->Ln();
        }
    }

    // Page footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(128,128,128);
        $this->Cell(0,10,'Page '.$this->PageNo().' of {nb}',0,0,'C');
    }
}

// Determine Forex session
function getForexSession($time){
    $time = date('H:i', strtotime($time));
    $sessions = [
        "Sydney"   => ["02:30", "11:30"],
        "Tokyo"    => ["05:30", "14:30"],
        "London"   => ["12:30", "21:30"],
        "New York" => ["17:30", "02:30"],
    ];

    $active = [];
    foreach($sessions as $name => [$start, $end]){
        if ($start < $end){
            if ($time >= $start && $time <= $end) $active[] = $name;
        } else {
            if ($time >= $start || $time <= $end) $active[] = $name;
        }
    }

    return count($active) > 0 ? implode(" + ", $active) : "No Session";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';

    if ($startDate && $endDate) {

        // Detect MySQL server timezone
        $tzResult = $conn->query("SELECT @@global.time_zone AS global_tz, @@session.time_zone AS session_tz");
        $tzRow = $tzResult->fetch_assoc();
        $serverTZ = $tzRow['session_tz'] ?: 'SYSTEM'; // fallback to SYSTEM

        $start = $startDate . " 00:00:00";
        $end   = $endDate . " 23:59:59";

        // Fetch trades ordered by raw_trade_id
        $sql = "
            SELECT
                raw_trade_id,
                pair_name,
                price_target,
                trade_direction,
                sent_status,
                CONVERT_TZ(last_alert_time, '$serverTZ', '+05:30') AS last_alert_ist
            FROM prediction_trade_data
            WHERE last_alert_time BETWEEN CONVERT_TZ('$start', '+05:30', '$serverTZ')
              AND CONVERT_TZ('$end', '+05:30', '$serverTZ')
            ORDER BY raw_trade_id ASC
        ";

        $res = $conn->query($sql);

        if ($res && $res->num_rows > 0) {
            $pdf = new PDF();
            $pdf->AliasNbPages();
            $pdf->AddPage();
            $pdf->SetFont('Arial','B',16);
            $pdf->SetTextColor(0,0,128);
            $pdf->Cell(0,10,"Trade Report",0,1,'C');
            $pdf->Ln(2);

            $pdf->SetFont('Arial','B',11);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(0,8,"From: $startDate   To: $endDate",0,1,'C');
            $pdf->Ln(2);

            $pdf->SetFont('Arial','',9);
            $fill = false;
            $currentDay = '';

            $dailyTotal = 0;
            $dailyUP = 0;
            $dailyDOWN = 0;
            $sessionCount = ["Sydney"=>0,"Tokyo"=>0,"London"=>0,"New York"=>0];

            foreach($res as $row){
                $timeIST = $row['last_alert_ist']; // Already converted to IST
                $timeStr = date('H:i:s', strtotime($timeIST));
                $dateOnly = date('d M Y', strtotime($timeIST));
                $dayOnly  = date('l', strtotime($timeIST));
                $session  = getForexSession($timeIST);

                // Print summary when day changes
                if ($currentDay && $currentDay !== $dateOnly) {
                    $pdf->Ln(2);
                    $pdf->SetFont('Arial','B',9);
                    $pdf->SetTextColor(0,0,0);
                    $pdf->Cell(0,6,"Summary for $currentDay:",0,1);
                    $pdf->SetFont('Arial','',9);
                    $pdf->Cell(0,6,"Total Trades: $dailyTotal | UP: $dailyUP | DOWN: $dailyDOWN",0,1);
                    $pdf->Cell(0,6,"Session-wise Trades: Sydney={$sessionCount['Sydney']} | Tokyo={$sessionCount['Tokyo']} | London={$sessionCount['London']} | New York={$sessionCount['New York']}",0,1);
                    $pdf->Ln(3);

                    $dailyTotal = $dailyUP = $dailyDOWN = 0;
                    $sessionCount = ["Sydney"=>0,"Tokyo"=>0,"London"=>0,"New York"=>0];
                }

                if ($currentDay !== $dateOnly) {
                    $pdf->Ln(5);
                    $pdf->SetFont('Arial','B',11);
                    $pdf->SetTextColor(0,0,128);
                    $pdf->Cell(0,8,"DATE: $dateOnly   DAY: $dayOnly",0,1,'L');

                    $pdf->SetFont('Arial','B',10);
                    $pdf->SetTextColor(0,0,0);
                    $pdf->SetFillColor(200,200,200);
                    foreach($pdf->headers as $i => $h){
                        $pdf->Cell($pdf->widths[$i],9,$h,1,0,'C',true);
                    }
                    $pdf->Ln();
                    $currentDay = $dateOnly;
                }

                $dailyTotal++;
                if($row['trade_direction']=='UP') $dailyUP++; else $dailyDOWN++;
                $sessionNames = explode(" + ", $session);
                foreach($sessionNames as $s) {
                    if(isset($sessionCount[$s])) $sessionCount[$s]++;
                }

                $dirTxt = ($row['trade_direction']==='UP') ? 'UP' : 'DOWN';
                $pdf->SetFillColor($fill?245:255,$fill?245:255,$fill?245:255);
                $pdf->SetFont('Arial','',9);
                $pdf->Cell(25,8,$row['raw_trade_id'],1,0,'C',$fill);
                $pdf->Cell(40,8,$row['pair_name'],1,0,'C',$fill);
                $pdf->Cell(30,8,$row['price_target'],1,0,'C',$fill);
                $pdf->Cell(25,8,$dirTxt,1,0,'C',$fill);
                $pdf->Cell(40,8,$timeStr,1,0,'C',$fill);
                $pdf->Cell(40,8,$session,1,1,'C',$fill);
                $fill = !$fill;
            }

            // Last day summary
            if($currentDay){
                $pdf->Ln(2);
                $pdf->SetFont('Arial','B',9);
                $pdf->SetTextColor(0,0,0);
                $pdf->Cell(0,6,"Summary for $currentDay:",0,1);
                $pdf->SetFont('Arial','',9);
                $pdf->Cell(0,6,"Total Trades: $dailyTotal | UP: $dailyUP | DOWN: $dailyDOWN",0,1);
                $pdf->Cell(0,6,"Session-wise Trades: Sydney={$sessionCount['Sydney']} | Tokyo={$sessionCount['Tokyo']} | London={$sessionCount['London']} | New York={$sessionCount['New York']}",0,1);
                $pdf->Ln(3);
            }

            // Footer info
            $pdf->Ln(5);
            $pdf->SetFont('Arial','I',9);
            $pdf->SetTextColor(0,0,0);
            date_default_timezone_set('Asia/Kolkata');
            $pdf->Cell(0,6,"Generated on ".date('d M Y H:i:s')." IST",0,1,'L');

            // Links
            $pdf->Ln(5);
            $pdf->SetFont('Arial','U',10);
            $pdf->SetTextColor(0,0,255);
            $url = "https:t.me/sasmhithstradingbot";
            $pdf->Cell(0,6,"Telegram Bot Link",0,1,'C',false,$url);
            $url = "https://www.linkedin.com/in/saisamhithreddy/";
            $pdf->Cell(0,6,"Linkedin Profile",0,1,'C',false,$url);

            $fileName = "trades_".$startDate."_to_".$endDate.".pdf";
            $pdf->Output('D', $fileName);
        } else {
            echo "<p>No trades found between $startDate and $endDate.</p>";
        }
    } else {
        echo "<p>Please select both start and end dates.</p>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin - Download Trades</title>
</head>
<body>
    <h2>Download Trades Report (Date Range)</h2>
    <form method="post">
        <label for="start_date">Start Date:</label>
        <input type="date" id="start_date" name="start_date" required>
        <br><br>
        <label for="end_date">End Date:</label>
        <input type="date" id="end_date" name="end_date" required>
        <br><br>
        <button type="submit">Download PDF</button>
    </form>
</body>
</html>
