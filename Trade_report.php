<?php

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

require('fpdf.php'); 
require 'db.php'; 

class PDF extends FPDF {
    public $headers = ['Trade ID','Chart','Pair','Target','Dir','Time (IST)','Trade Result'];
    public $widths  = [20,15,35,25,15,35,45];

    function Header() {
        if($this->PageNo() > 1) {
            $this->SetFont('Arial','B',9);
            $this->SetTextColor(0,0,0);
            $this->SetFillColor(200,200,200);
            foreach($this->headers as $i => $h){
                $this->Cell($this->widths[$i],9,$h,1,0,'C',true);
            }
            $this->Ln();
        }
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(128,128,128);
        $this->Cell(0,10,'Page '.$this->PageNo().' of {nb}',0,0,'C');
    }
}

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
    return count($active) > 0 ? implode("+", $active) : "None";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';

    if ($startDate && $endDate) {
        $tzResult = $conn->query("SELECT @@session.time_zone AS session_tz");
        $tzRow = $tzResult->fetch_assoc();
        $serverTZ = $tzRow['session_tz'] ?: 'SYSTEM';

        $start = $startDate . " 00:00:00";
        $end   = $endDate . " 23:59:59";

        $sql = "
            SELECT
                raw_trade_id,
                pair_name,
                price_target,trade_result,
                trade_direction,
                updated_at,
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
            
            // Title 
            $pdf->SetFont('Arial','B',16);
            $pdf->SetTextColor(0,0,128);
            $pdf->Cell(0,10,"Trade Analytics Report",0,1,'C');
            $pdf->SetFont('Arial','B',10);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(0,7,"Period: $startDate to $endDate",0,1,'C');
            $pdf->Ln(5);

            $currentDay = '';
            $dailyTotal = $dailyUP = $dailyDOWN = 0;
            $sessionCount = ["Sydney"=>0,"Tokyo"=>0,"London"=>0,"New York"=>0];
            $fill = false;

            while($row = $res->fetch_assoc()){
                $rid         = $row['raw_trade_id'];
                $pair        = $row['pair_name'];
                $target      = number_format($row['price_target'], 5);
                $direction   = ($row['trade_direction']==='UP') ? 'BUY' : 'SELL';
                $Trderesult  = $row['trade_result'];
                $displayTime = $row['updated_at'];
                $timeIST     = $row['last_alert_ist'];
                $ts          = strtotime($displayTime);
                $timeStr     = date('H:i', strtotime($timeIST));
                $dateOnly    = date('d M Y', strtotime($timeIST));
                $session     = getForexSession($timeIST);

                // URL GENERATION 
                $fileNameDataset = "FX_" . $pair . ".csv";
                $chartUrl = "$WebsiteURL"."/trail/plot.php?goto={$ts}&file=" . urlencode("dataset/".$fileNameDataset) . "&id=" . urlencode($rid) . "&pair=" . urlencode($pair) . "&time=" . urlencode($displayTime) . "&price=" . urlencode($target) . "&dir=" . urlencode($direction);

                // Handle Day Headers and Summaries
                if ($currentDay !== $dateOnly) {
                    if ($currentDay) {
                        $pdf->Ln(2);
                        $pdf->SetFont('Arial','B',8);
                        $pdf->Cell(0,5,"Summary: Total $dailyTotal | UP $dailyUP | DOWN $dailyDOWN",0,1);
                        $pdf->Ln(4);
                    }
                    $pdf->SetFont('Arial','B',10);
                    $pdf->SetTextColor(0,0,128);
                    $pdf->Cell(0,8,"DATE: $dateOnly",0,1,'L');
                    
                    // Table Header
                    $pdf->SetFont('Arial','B',8);
                    $pdf->SetTextColor(0,0,0);
                    $pdf->SetFillColor(220,220,220);
                    foreach($pdf->headers as $i => $h){
                        $pdf->Cell($pdf->widths[$i],8,$h,1,0,'C',true);
                    }
                    $pdf->Ln();
                    $currentDay = $dateOnly;
                    $dailyTotal = $dailyUP = $dailyDOWN = 0;
                }

                $dailyTotal++;
                if($row['trade_direction']=='UP') $dailyUP++; else $dailyDOWN++;

                // Row Printing
                $pdf->SetFont('Arial','',8);
                $pdf->SetFillColor($fill?245:255);
                
                $pdf->Cell($pdf->widths[0],7,$rid,1,0,'C',$fill);
                
                // Link Column
                $pdf->SetTextColor(0,0,255);
                $pdf->SetFont('Arial','U',8);
                $pdf->Cell($pdf->widths[1],7,"VIEW",1,0,'C',$fill,$chartUrl);
                
                $pdf->SetTextColor(0,0,0);
                $pdf->SetFont('Arial','',8);
                $pdf->Cell($pdf->widths[2],7,$pair,1,0,'C',$fill);
                $pdf->Cell($pdf->widths[3],7,$target,1,0,'C',$fill);
                $pdf->Cell($pdf->widths[4],7,$direction,1,0,'C',$fill);
                $pdf->Cell($pdf->widths[5],7,$timeStr,1,0,'C',$fill);
                $pdf->Cell($pdf->widths[6],7,$Trderesult,1,1,'C',$fill);
                
                $fill = !$fill;
            }

            $pdf->Output('D', "Report_".$startDate."_to_".$endDate.".pdf");
        } else {
            echo "No trades found.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Trade Reports</title>
    <style>
        body { font-family: sans-serif; padding: 50px; background: #f4f4f9; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: auto; }
        h2 { color: #333; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;}
        button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Generate Report</h2>
        <form method="post">
            <label>Start Date</label>
            <input type="date" name="start_date" required>
            <label>End Date</label>
            <input type="date" name="end_date" required>
            <button type="submit">Download PDF Report</button>
        </form>
    </div>
</body>
</html>