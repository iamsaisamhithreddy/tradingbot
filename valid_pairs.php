<?php

// Detect if the script is being run by a Cron Job (CLI)
$isCron = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

// Only start sessions and output buffering if accessed via a web browser
if (!$isCron) {
    ob_start();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

include 'db.php'; // database connection
header("Refresh:15"); // refresh after 15 seconds
//  ERROR REPORTING 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


//  FUNCTIONS
require_once __DIR__ . '/dataset/trading_core_functions.php';

// FETCH AND UPDATE DATA 

$sql = "
SELECT  
    p.*,  
    r.O1, r.H1, r.L1, r.C1,
    r.O2, r.H2, r.L2, r.C2,
    r.O3, r.H3, r.L3, r.C3,
    r.O4, r.H4, r.L4, r.C4,
    r.O5, r.H5, r.L5, r.C5,
    r.created_at
FROM prediction_trade_data p
LEFT JOIN raw_trade_data r ON p.raw_trade_id = r.id
ORDER BY p.raw_trade_id DESC
";

$result = $conn->query($sql);
if($result && $result->num_rows>0){
    while($row=$result->fetch_assoc()){
        $candles = [
            ['O'=>$row['O1'],'H'=>$row['H1'],'L'=>$row['L1'],'C'=>$row['C1']],
            ['O'=>$row['O2'],'H'=>$row['H2'],'L'=>$row['L2'],'C'=>$row['C2']],
            ['O'=>$row['O3'],'H'=>$row['H3'],'L'=>$row['L3'],'C'=>$row['C3']],
            ['O'=>$row['O4'],'H'=>$row['H4'],'L'=>$row['L4'],'C'=>$row['C4']],
            ['O'=>$row['O5'],'H'=>$row['H5'],'L'=>$row['L5'],'C'=>$row['C5']]
        ];
        $price_target_calculated = calculate_price_target_from_candles($candles);
        if($price_target_calculated!==null){
            $stmt=$conn->prepare("UPDATE prediction_trade_data SET price_target=? WHERE raw_trade_id=?");
            $stmt->bind_param("di",$price_target_calculated,$row['raw_trade_id']);
            $stmt->execute();
            $stmt->close();
        }
    }
    $result->free();
}

// HTML DISPLAY
$result = $conn->query($sql); // re-fetch
?>
<!DOCTYPE html>
<html>
<head>
<title>Prediction Trade Data</title>
<style>
body { font-family: Arial; margin:30px; background-color:#fafafa; }
h2 { text-align:center; margin-bottom:20px; color:#222; }
table { border-collapse:collapse; width:95%; margin:0 auto; font-size:14px; background:white; }
th, td { border:1px solid #ddd; padding:10px 12px; text-align:center; }
th { background-color:#222; color:white; }
tr:nth-child(even){ background-color:#f9f9f9; }
</style>
</head>
<body>

<h2>Prediction Trade Data Table</h2>
<table>
<thead>
<tr>
<th>Raw Trade ID</th>
<th>Pair Name</th>
<th>Price Target</th>
<th>Trade Direction</th>
<th>O1</th>
<th>Created At (IST)</th>
</tr>
</thead>
<tbody>
<?php
if($result && $result->num_rows>0){
    while($row=$result->fetch_assoc()){
        $price_target_display = $row['price_target']!==null ? number_format(floatval($row['price_target']),5) : "N/A";
        $utc_time = new DateTime($row['created_at'], new DateTimeZone('UTC'));
        $utc_time->setTimezone(new DateTimeZone('Asia/Kolkata'));
        $ist_time = $utc_time->format('Y-m-d H:i:s');
        echo "<tr>
            <td>".htmlspecialchars($row['raw_trade_id'])."</td>
            <td>".htmlspecialchars($row['pair_name'])."</td>
            <td>$price_target_display</td>
            <td>".htmlspecialchars($row['trade_direction'])."</td>
            <td>".htmlspecialchars($row['O1'])."</td>
            <td>$ist_time</td>
        </tr>";
    }
}else{
    echo "<tr><td colspan='6'>No prediction data found.</td></tr>";
}
$conn->close();
?>
</tbody>
</table>
</body>
</html>